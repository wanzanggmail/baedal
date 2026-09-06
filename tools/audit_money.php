<?php

declare(strict_types=1);

/**
 * 돈 흐름 불변식 감사 (2026-09-04)
 *
 *   사용:  php tools/audit_money.php
 *
 * "돈이 A에 적립되는데 집계는 B만 조회" 같은 누락을 **실데이터로** 잡는다.
 * 정산 병행 테스트 전/후에 돌려 어긋난 곳이 없는지 확인하는 용도.
 *
 * 종료코드: 오류 있으면 1 (CI/스크립트에서 판정 가능), 없으면 0.
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once INC_PATH . '/AgencyWallet.php';

$FAIL = 0;
$WARN = 0;

$n  = static fn ($v): string => number_format((int) $v);
$hd = static function (string $s): void {
    echo "\n" . str_repeat('-', 74) . "\n{$s}\n" . str_repeat('-', 74) . "\n";
};
$ok  = static fn (string $m): mixed => print("  [OK]   {$m}\n");
$bad = static function (string $m) use (&$FAIL): void { $FAIL++; echo "  [FAIL] {$m}\n"; };
$wrn = static function (string $m) use (&$WARN): void { $WARN++; echo "  [WARN] {$m}\n"; };

echo "돈 흐름 불변식 감사 — DB: " . DB_NAME . " · " . date('Y-m-d H:i') . "\n";

// ── ① 원천세 예수금 == 발생(정산+프로모션) − 수집 ───────────────────────────
$hd('① 원천세 예수금 정합성  (reserve == 발생[정산+프로모션] − 수집)');
$accrued = [];
foreach (db_rows(
    "SELECT r.agency_id AS aid, COALESCE(SUM(fi.amount),0) AS amt
       FROM settlement_fee_items fi
       JOIN settlement_rider_cycles c ON c.id = fi.cycle_id
       JOIN riders r ON r.id = c.rider_id
      WHERE fi.fee_code = 'withholding' AND r.agency_id IS NOT NULL
      GROUP BY r.agency_id"
) as $r) {
    $accrued[(int) $r['aid']] = ($accrued[(int) $r['aid']] ?? 0) + (int) $r['amt'];
}
if (db_table_exists('promotion_entries') && db_table_exists('promotion_batches')) {
    foreach (db_rows(
        "SELECT b.agency_id AS aid, COALESCE(SUM(pe.withholding_amount),0) AS amt
           FROM promotion_entries pe JOIN promotion_batches b ON b.id = pe.batch_id
          WHERE pe.status = 'paid' AND b.agency_id IS NOT NULL
          GROUP BY b.agency_id"
    ) as $r) {
        $accrued[(int) $r['aid']] = ($accrued[(int) $r['aid']] ?? 0) + (int) $r['amt'];
    }
}
$collected = [];
if (db_table_exists('tax_withholding_collections')) {
    foreach (db_rows('SELECT agency_id AS aid, COALESCE(SUM(amount),0) AS amt FROM tax_withholding_collections GROUP BY agency_id') as $r) {
        $collected[(int) $r['aid']] = (int) $r['amt'];
    }
}
$reserve = [];
foreach (db_rows("SELECT w.agency_id AS aid, w.withholding_reserve AS wr
                    FROM agency_wallets w JOIN organizations o ON o.id = w.agency_id
                   WHERE o.level = 'agency'") as $r) {
    $reserve[(int) $r['aid']] = (int) $r['wr'];
}
$mis = 0;
foreach (array_unique(array_merge(array_keys($accrued), array_keys($reserve))) as $aid) {
    $exp = ($accrued[$aid] ?? 0) - ($collected[$aid] ?? 0);
    $got = $reserve[$aid] ?? 0;
    if ($exp !== $got) {
        $bad("org#{$aid} 예수금 {$n($got)} != 기대 {$n($exp)} (발생 {$n($accrued[$aid] ?? 0)} - 수집 {$n($collected[$aid] ?? 0)})");
        $mis++;
    }
}
if ($mis === 0) {
    $ok('전 대리점 원천세 예수금 일치 (' . count($reserve) . '곳)');
}

// ── ② 고용·산재 예수금은 0 (대리점 보유금) ─────────────────────────────────
$hd('② 고용·산재 예수금 0 여부  (2026-09-04 정정: 대리점이 갖는 돈)');
$ins = db_row('SELECT COUNT(*) AS c, COALESCE(SUM(insurance_reserve),0) AS s FROM agency_wallets WHERE insurance_reserve <> 0');
(int) $ins['c'] === 0
    ? $ok('insurance_reserve 전부 0')
    : $bad("insurance_reserve 남은 곳 {$ins['c']}건 합 {$n($ins['s'])}");

// ── ③ 지갑 잔액 == 원장 합 (감사추적 신뢰성) ───────────────────────────────
$hd('③ 지갑 원장 정합성  (balance == credit합 - debit합)');
$mis = 0;
foreach (db_rows(
    "SELECT w.agency_id AS aid, o.name, w.balance AS bal,
            COALESCE((SELECT SUM(CASE WHEN l.direction='credit' THEN l.amount ELSE -l.amount END)
                        FROM agency_wallet_ledger l WHERE l.agency_id = w.agency_id),0) AS led
       FROM agency_wallets w LEFT JOIN organizations o ON o.id = w.agency_id"
) as $r) {
    if ((int) $r['bal'] !== (int) $r['led']) {
        $d = (int) $r['bal'] - (int) $r['led'];
        $bad("org#{$r['aid']} {$r['name']} balance {$n($r['bal'])} != 원장합 {$n($r['led'])} (차 {$n($d)}) — 원장 없이 변한 잔액");
        $mis++;
    }
}
if ($mis === 0) {
    $ok('전 조직 지갑 원장 일치');
}

// ── ④ 사이클 수수료 합 ─────────────────────────────────────────────────────
$hd('④ 정산 사이클: total_fee_amount == fee_items 합');
$r = db_row(
    'SELECT COUNT(*) AS c FROM (
        SELECT c.id, c.total_fee_amount AS tf, COALESCE(SUM(fi.amount),0) AS fs
          FROM settlement_rider_cycles c
          LEFT JOIN settlement_fee_items fi ON fi.cycle_id = c.id
         GROUP BY c.id, c.total_fee_amount HAVING tf <> fs) t'
);
(int) $r['c'] === 0 ? $ok('전 사이클 수수료 합 일치') : $bad("불일치 사이클 {$r['c']}건");

// ── ⑤ 사이클 순액 산식 ─────────────────────────────────────────────────────
$hd('⑤ 정산 사이클: net_amount == gross + support - total_fee');
$r = db_row(
    'SELECT COUNT(*) AS c, COALESCE(SUM(ABS(net_amount-(gross_amount+support_amount-total_fee_amount))),0) AS d
       FROM settlement_rider_cycles
      WHERE net_amount <> gross_amount + support_amount - total_fee_amount'
);
(int) $r['c'] === 0
    ? $ok('전 사이클 순액 산식 일치')
    : $wrn("산식 불일치 {$r['c']}건 (총 차이 {$n($r['d'])}원) — 산식 개정 이전 구 데이터. 레거시 대사 시 이 건들이 차이로 잡힌다");

// ── ⑥ fee_code 커버리지 ────────────────────────────────────────────────────
$hd('⑥ fee_code 커버리지  (명세서·집계에서 처리 안 되는 코드)');
$known = ['excel_deduction', 'manual', 'withholding', 'employment_ins', 'accident_ins', 'carry_forward',
          'hourly_ins', 'agency_fee', 'advance', 'lease', 'rental', 'loan', 'vat', 'ins_refund',
          // 대리점 선차감(2026-09-06) — 라이더 화면에서는 감추지만 **합계에는 반영**된다
          // (RiderStatement 가 총공제에서 빼 정산금액을 낮추는 방식). 원장 항등식도 그대로.
          'agency_prededuct'];
$un = 0;
foreach (db_rows('SELECT fee_code, COUNT(*) AS c, SUM(amount) AS s FROM settlement_fee_items GROUP BY fee_code') as $r) {
    if (!in_array((string) $r['fee_code'], $known, true)) {
        $bad("미처리 fee_code '{$r['fee_code']}' {$r['c']}건 {$n($r['s'])}원 — 명세서 합계에서 누락된다");
        $un++;
    }
}
if ($un === 0) {
    $ok('모든 fee_code 처리됨');
}

// ── ⑥-B 차감 누수 (정산액 < 공제) ──────────────────────────────────────────
$hd('⑥-B 차감 누수  (공제 합이 정산액을 넘어 증발한 금액)');
$r = db_row(
    'SELECT COUNT(*) AS c, COALESCE(SUM(total_fee_amount-(gross_amount+support_amount)),0) AS d
       FROM settlement_rider_cycles
      WHERE total_fee_amount > gross_amount + support_amount'
);
if ((int) $r['c'] === 0) {
    $ok('공제가 정산액을 넘는 사이클 없음');
} else {
    $wrn("누수 {$r['c']}건 {$n($r['d'])}원 — 2026-09-04 이월 도입 **이전** 데이터. 이후 정산에서는 rider_carry_forward 로 이월되므로 늘어나면 안 된다");
}
if (db_table_exists('rider_carry_forward')) {
    $cf = db_row('SELECT COUNT(*) AS c, COALESCE(SUM(remaining_amount),0) AS s FROM rider_carry_forward WHERE remaining_amount > 0');
    (int) $cf['c'] === 0
        ? $ok('미회수 이월분 없음')
        : $wrn("미회수 이월 {$cf['c']}건 {$n($cf['s'])}원 (다음 정산에서 자동 회수 대상)");
}

// ── ⑦ 음수 잔액 ────────────────────────────────────────────────────────────
$hd('⑦ 음수 잔액 (자금 부족 신호)');
$r = db_row('SELECT COUNT(*) AS c, COALESCE(SUM(balance),0) AS s FROM rider_wallets WHERE balance < 0');
(int) $r['c'] === 0 ? $ok('라이더 지갑 음수 없음') : $bad("라이더 음수 잔액 {$r['c']}명 합 {$n($r['s'])}");
$neg = db_rows("SELECT w.agency_id AS aid, o.name, w.balance AS b
                  FROM agency_wallets w JOIN organizations o ON o.id = w.agency_id
                 WHERE w.balance < 0");
if ($neg === []) {
    $ok('조직 지갑 음수 없음');
} else {
    foreach ($neg as $x) {
        $wrn("org#{$x['aid']} {$x['name']} 잔액 {$n($x['b'])}원 (자금조달 전 지급 가능성)");
    }
}

// ── ⑧ 인출가능액 음수화 ────────────────────────────────────────────────────
$hd('⑧ 대리점 인출가능액  (잔액 - 라이더채무 - 원천세예수금)');
$neg = 0;
foreach (db_rows("SELECT w.agency_id AS aid, o.name, w.balance AS b, w.withholding_reserve AS wr
                    FROM agency_wallets w JOIN organizations o ON o.id = w.agency_id
                   WHERE o.level = 'agency'") as $r) {
    $debt  = AgencyWallet::riderDebt((int) $r['aid']);
    $avail = (int) $r['b'] - $debt - (int) $r['wr'];
    if ($avail < 0) {
        $wrn("org#{$r['aid']} {$r['name']} 인출가능 {$n($avail)}원 (잔액 {$n($r['b'])} - 라이더채무 {$n($debt)} - 예수금 {$n($r['wr'])})");
        $neg++;
    }
}
if ($neg === 0) {
    $ok('전 대리점 인출가능액 0 이상');
}

echo "\n" . str_repeat('=', 74) . "\n";
echo "결과: 오류 {$FAIL}건 / 경고 {$WARN}건\n";
exit($FAIL > 0 ? 1 : 0);
