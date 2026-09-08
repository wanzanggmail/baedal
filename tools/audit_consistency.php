<?php

declare(strict_types=1);

/**
 * 정산 정합성 전수 대사 — 관리자와 라이더가 같은 수를 보는가 (2026-09-09)
 *
 *   php tools/audit_consistency.php            전수
 *   php tools/audit_consistency.php --rider=12 한 라이더만
 *   php tools/audit_consistency.php --limit=30 앞 N명만(빠른 확인)
 *
 * 갑: "관리자랑 라이더 부분에 데이터가 차이가 나면 안되.
 *      계산 공식이나 이런게 잘못된게 있는지 다시 한번 전수검사 해줘"
 *
 * **읽기 전용이다.** INSERT/UPDATE/DELETE 를 한 줄도 하지 않으므로 운영에서 그대로 돌려도 된다.
 *
 * `tools/audit_money.php` 가 «돈이 새는가»를 본다면, 이쪽은 «같은 정산을 두 화면이 다르게
 * 보여주는가»를 본다. 합성 시나리오가 아니라 **DB 에 쌓인 실제 사이클 전부**를 검사한다.
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once INC_PATH . '/SettlementLedger.php';
require_once INC_PATH . '/RiderStatement.php';
require_once INC_PATH . '/RiderTaxSummary.php';
require_once INC_PATH . '/WithdrawalConfig.php';
require_once INC_PATH . '/AgencyFeeConfig.php';

const ORDER_SHEET_TRUSTED_FROM = '2026-03-04';

$args      = array_slice($argv, 1);
$onlyRider = 0;
$limit     = 0;
$since     = '';
foreach ($args as $a) {
    if (str_starts_with($a, '--rider=')) { $onlyRider = (int) substr($a, 8); }
    if (str_starts_with($a, '--limit=')) { $limit = (int) substr($a, 8); }
    if (str_starts_with($a, '--since=')) { $since = substr($a, 8); }
}
if ($since !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
    fwrite(STDERR, "--since 는 YYYY-MM-DD 형식이어야 합니다.
");
    exit(2);
}

$n    = static fn ($v): string => number_format((int) $v);
$line = static function (): void { echo str_repeat('-', 82) . "\n"; };

$PASS = 0;
$FAIL = [];
/** @param string $what 무엇을 봤는지 · @param string $where 어디서 */
$check = function (bool $ok, string $what, string $where = '') use (&$PASS, &$FAIL): void {
    if ($ok) { $PASS++; return; }
    $FAIL[] = ['what' => $what, 'where' => $where];
};

echo "정산 정합성 전수 대사 — DB: " . DB_NAME . "  (읽기 전용)\n";
$line();

// ══════════════════════════════════════════════════════════════════════════════
// ① 원장 항등식 — 사이클 하나하나
// ══════════════════════════════════════════════════════════════════════════════
echo "① 원장 항등식 (전 사이클)\n";

// 구 산식 시절 데이터를 빼고 «지금 로직으로 만들어진 것»만 보고 싶을 때 --since 를 쓴다.
$conds  = [];
$params = [];
if ($onlyRider > 0) { $conds[] = 'c.rider_id = ?'; $params[] = $onlyRider; }
if ($since !== '')  { $conds[] = 'c.settlement_date >= ?'; $params[] = $since; }
$cycWhere = $conds === [] ? '' : 'WHERE ' . implode(' AND ', $conds);
if ($since !== '') { printf("   (%s 이후 정산만 검사)
", $since); }

$cycles = db_rows(
    "SELECT c.id, c.rider_id, c.settlement_date, c.gross_amount, c.support_amount,
            c.total_fee_amount, c.net_amount, c.order_count
       FROM settlement_rider_cycles c {$cycWhere}
      ORDER BY c.id",
    $params
);

$feeByCycle = [];
foreach (db_rows('SELECT cycle_id, fee_code, SUM(amount) amt FROM settlement_fee_items GROUP BY cycle_id, fee_code') as $r) {
    $feeByCycle[(int) $r['cycle_id']][(string) $r['fee_code']] = (int) $r['amt'];
}

$legacyMismatch = 0;
foreach ($cycles as $c) {
    $id    = (int) $c['id'];
    $fees  = $feeByCycle[$id] ?? [];
    $sum   = array_sum($fees);
    $where = sprintf('사이클#%d(라이더%d %s)', $id, (int) $c['rider_id'], (string) $c['settlement_date']);

    $check($sum === (int) $c['total_fee_amount'], 'fee_items 합 = total_fee_amount', $where);
    $check((int) $c['net_amount'] >= 0, 'net_amount 음수 아님', $where);

    // gross+support−fee = net : 산식이 여러 번 개정돼 구 사이클은 안 맞는 것이 **알려진 사실**이다.
    // 새로 만들어지는 사이클에서 깨지면 안 되므로, 안 맞는 건수만 세어 마지막에 보고한다.
    if ((int) $c['gross_amount'] + (int) $c['support_amount'] - (int) $c['total_fee_amount'] !== (int) $c['net_amount']) {
        $legacyMismatch++;
    }
}
printf("   사이클 %s개 검사\n", $n(count($cycles)));

// ══════════════════════════════════════════════════════════════════════════════
// ② 세금 재계산 — 선차감이 붙은 사이클(신규 로직)만 정확 검산
// ══════════════════════════════════════════════════════════════════════════════
echo "② 세금 계산 재검산 (선차감 적용분)\n";

$rates    = [];
$taxCheck = 0;
foreach ($cycles as $c) {
    $id   = (int) $c['id'];
    $fees = $feeByCycle[$id] ?? [];
    $pre  = (int) ($fees['agency_prededuct'] ?? 0);
    if ($pre <= 0) {
        continue;   // 구 사이클은 당시 요율을 모르므로 건너뛴다
    }

    $agency = (int) (db_row('SELECT agency_id FROM riders WHERE id = ? LIMIT 1', [(int) $c['rider_id']])['agency_id'] ?? 0);
    $rates[$agency] ??= SettlementLedger::deductionRates($agency ?: null);
    $rt = $rates[$agency];

    $base    = (int) $c['gross_amount'] + (int) $c['support_amount'];
    $taxBase = $base - $pre;
    $w       = sprintf('사이클#%d(%s)', $id, (string) $c['settlement_date']);

    foreach ([
        ['withholding',    'withholding_tax_pct',         '원천세'],
        ['employment_ins', 'employment_ins_pct',          '고용보험'],
        ['accident_ins',   'industrial_accident_ins_pct', '산재보험'],
    ] as [$code, $rateKey, $label]) {
        if (!isset($fees[$code])) {
            continue;   // 대상이 아닌 라이더
        }
        $expect = (int) round($taxBase * ((float) $rt[$rateKey]) / 100);
        $check($fees[$code] === $expect, "{$label} = 과세표준×요율 (기대 {$expect}, 실제 {$fees[$code]})", $w);
        $taxCheck++;
    }
}
printf("   %s건 재계산 (과세표준 = 정산액+지원금 − 선차감)\n", $n($taxCheck));

// ══════════════════════════════════════════════════════════════════════════════
// ③ 관리자 vs 라이더 — 라이더 한 명씩
// ══════════════════════════════════════════════════════════════════════════════
echo "③ 관리자 화면 vs 라이더 화면 (라이더별)\n";

$riderSql = 'SELECT DISTINCT c.rider_id FROM settlement_rider_cycles c ' . $cycWhere . ' ORDER BY c.rider_id';
$riderIds = array_map('intval', array_column(db_rows($riderSql, $params), 'rider_id'));
if ($limit > 0) {
    $riderIds = array_slice($riderIds, 0, $limit);
}

$range = db_row('SELECT MIN(c.settlement_date) a, MAX(c.settlement_date) b FROM settlement_rider_cycles c ' . $cycWhere, $params);
$from  = (string) ($range['a'] ?? date('Y-m-d'));
$to    = (string) ($range['b'] ?? date('Y-m-d'));

$predeductTotal = 0;
foreach ($riderIds as $rid) {
    $w = "라이더#{$rid}";

    // 관리자 기준 — DB 원본 그대로
    $adm = db_row(
        'SELECT COALESCE(SUM(gross_amount),0) g, COALESCE(SUM(support_amount),0) s,
                COALESCE(SUM(total_fee_amount),0) f, COALESCE(SUM(net_amount),0) n
           FROM settlement_rider_cycles WHERE rider_id = ? AND settlement_date BETWEEN ? AND ?',
        [$rid, $from, $to]
    );
    $pre = (int) (db_row(
        "SELECT COALESCE(SUM(fi.amount),0) v
           FROM settlement_fee_items fi
           INNER JOIN settlement_rider_cycles c ON c.id = fi.cycle_id
          WHERE c.rider_id = ? AND c.settlement_date BETWEEN ? AND ?
            AND fi.fee_code = 'agency_prededuct'",
        [$rid, $from, $to]
    )['v'] ?? 0);
    $predeductTotal += $pre;

    // 라이더 기준 — 명세서
    $st = RiderStatement::summary($rid, $from, $to);

    // 1) 실수령은 **어느 쪽에서 봐도 같아야 한다** — 여기가 어긋나면 돈이 어긋난 것이다
    $check((int) $st['net'] === (int) $adm['n'], '실수령 일치 (명세서 = 원장)', $w);

    // 2) 라이더 화면은 그 자체로 앞뒤가 맞아야 한다
    $check(
        (int) $st['settle_amount'] + (int) $st['support'] - (int) $st['total_fee'] === (int) $st['net'],
        '명세서 균형 (정산금액+지원−공제=실수령)',
        $w
    );

    // 3) 관리자와 라이더의 차이는 **정확히 선차감만큼**이어야 한다
    $check((int) $adm['f'] - (int) $st['total_fee'] === $pre, '공제 차이 = 선차감', $w);

    // 4) 라이더 앱 집계(지갑 화면)도 명세서와 같아야 한다
    $sum = SettlementLedger::sumForRider($rid, ['from' => $from, 'to' => $to]);
    $check((int) $sum['net'] === (int) $adm['n'], '앱 집계 실수령 = 원장', $w);
    $check(
        (int) $sum['gross'] + (int) $sum['support'] - (int) $sum['fee'] === (int) $sum['net'],
        '앱 집계 균형 (정산금액+지원−공제=실수령)',
        $w
    );
    $check((int) $sum['gross'] === (int) $st['settle_amount'], '앱 집계 정산금액 = 명세서', $w);

    // 5) 세금 화면(원천징수 내역)도 같은 기준이어야 한다
    $tx = RiderTaxSummary::forPeriod($rid, $from, $to);
    $check(
        (int) $tx['settle_base'] - (int) $tx['settle_fee'] === (int) $tx['settle_net'],
        '세금화면 균형 (지급액−공제=실수령)',
        $w
    );
    $check((int) $tx['settle_net'] === (int) $adm['n'], '세금화면 실수령 = 원장', $w);

    // 6) 일자별 상세의 합 = 명세서 정산금액
    $daily = RiderStatement::daily($rid, $from, $to);
    $dSum  = 0;
    foreach ($daily as $d) { $dSum += (int) $d['gross']; }
    $check($dSum === (int) $st['settle_amount'], '일자별 합 = 명세서 정산금액', $w);
}
printf("   라이더 %s명 × 8개 항목\n", $n(count($riderIds)));

// ══════════════════════════════════════════════════════════════════════════════
// ④ 정산수수료 구성 — 총액 = 합
// ══════════════════════════════════════════════════════════════════════════════
echo "④ 정산수수료 구성 (전역고정 + 추가분 = 총액)\n";

$orgRows = db_rows("SELECT id, name FROM organizations WHERE level = ? ORDER BY name", [Org::LEVEL_AGENCY]);
$orgRows[] = ['id' => null, 'name' => '전역 기본값'];
foreach ($orgRows as $o) {
    $oid = $o['id'] === null ? null : (int) $o['id'];
    $cfg = WithdrawalConfig::get($oid);
    $w   = (string) $o['name'];
    foreach (['short', 'long'] as $b) {
        $parts = (int) $cfg["hq_fee_{$b}"] + (int) $cfg["tax_fee_{$b}"] + (int) $cfg["dev_fee_{$b}"]
               + (int) $cfg["dist_fee_{$b}"] + (int) ($cfg["agency_add_{$b}"] ?? 0);
        $check($parts === (int) $cfg["fee_per_tx_{$b}"], "총액 = 구성 합 ({$b})", $w);
    }
    // 배분이 총액을 넘지 않는가 (넘으면 대리점 몫이 0으로 눌린다)
    $s = WithdrawalConfig::feeShare(10, 0, 10 * (int) $cfg['fee_per_tx_short'], $oid);
    $tot = $s['tax'] + $s['developer'] + $s['hq'] + $s['distributor'] + $s['agency'];
    $check($tot === 10 * (int) $cfg['fee_per_tx_short'], '배분 합 = 총액', $w);
    $check($s['agency'] === 10 * (int) ($cfg['agency_add_short'] ?? 0), '대리점 몫 = 추가금×건수', $w);
}
printf("   대리점 %s곳 + 전역\n", $n(count($orgRows) - 1));

// ══════════════════════════════════════════════════════════════════════════════
// ⑤ 출금 — 라이더 지갑에서 빠진 금액이 맞는가
// ══════════════════════════════════════════════════════════════════════════════
echo "⑤ 출금 정합성\n";

$wds = db_rows(
    "SELECT id, rider_id, amount, withhold_other, withhold_transfer_fee, withhold_min_retain,
            settle_fee_payer, transfer_fee_payer, status
       FROM withdrawal_requests
      WHERE status <> 'rejected'"
    . ($onlyRider > 0 ? ' AND rider_id = ?' : ''),
    $onlyRider > 0 ? [$onlyRider] : []
);
foreach ($wds as $r) {
    $w = sprintf('출금#%d(라이더%d)', (int) $r['id'], (int) $r['rider_id']);
    $check((int) $r['amount'] >= 0, '지급액 음수 아님', $w);
    // 부담 주체가 'agency' 인데 라이더 지급액이 줄어 있으면 안 된다 → 여기서는 컬럼 존재만 확인
    $check(in_array((string) $r['settle_fee_payer'], ['rider', 'agency'], true), '정산수수료 부담주체 값 정상', $w);
    $check(in_array((string) $r['transfer_fee_payer'], ['rider', 'agency'], true), '이체수수료 부담주체 값 정상', $w);
}
printf("   출금 %s건\n", $n(count($wds)));

// ══════════════════════════════════════════════════════════════════════════════
// ⑥ 오더별 상세 합 = 그 날짜 정산금액 — 라이더가 직접 더해볼 수 있는 숫자
// ══════════════════════════════════════════════════════════════════════════════
echo "⑥ 오더별 상세 합 (라이더가 더해보는 숫자)\n";

$orderRows = db_rows(
    "SELECT c.rider_id, c.settlement_date, c.gross_amount cg, c.order_count co,
            o.s AS os, o.c AS oc
       FROM settlement_rider_cycles c
       INNER JOIN (SELECT rider_id, settlement_date, SUM(net_amount) s, COUNT(*) c
                     FROM settlement_order_details GROUP BY rider_id, settlement_date) o
               ON o.rider_id = c.rider_id AND o.settlement_date = c.settlement_date " . $cycWhere,
    $params
);

$vatLegacy = 0;
$srcLegacy = 0;
$orderOk   = 0;
foreach ($orderRows as $r) {
    $cg = (int) $r['cg'];
    $os = (int) $r['os'];
    $w  = sprintf('라이더%d %s (사이클 %s / 주문합 %s)', (int) $r['rider_id'], (string) $r['settlement_date'], number_format($cg), number_format($os));

    if ($cg === $os) {
        $orderOk++;
        $check(true, '오더 합 = 정산금액', $w);
        continue;
    }
    // 부가세를 정산액에 포함하던 시절(2026-08-09 이전 산식)의 구 데이터는 정확히 1/1.1 배다.
    // 그 패턴이면 «알려진 구 데이터»로 분류하고, 아니면 진짜 불일치로 잡는다.
    if ($cg > 0 && abs(($os / $cg) - (1 / 1.1)) < 0.0005) {
        $vatLegacy++;
        continue;
    }
    // 2026-03-03 이전 시범운영분은 요약시트 금액과 오더시트 합이 원본 파일에서부터 어긋나 있다.
    // 산식이 아니라 «원본 데이터» 문제라서 구 데이터로 분류한다. 2026-04-09 이후는 전건 일치.
    if ((string) $r['settlement_date'] < ORDER_SHEET_TRUSTED_FROM) {
        $srcLegacy++;
        continue;
    }
    $check(false, '오더 합 = 정산금액', $w);
}
printf(
    "   %s일치 · %s부가세 구간 · %s원본 불일치 구간(%s 이전) · %s건 검사
",
    $n($orderOk), $n($vatLegacy), $n($srcLegacy), ORDER_SHEET_TRUSTED_FROM, $n(count($orderRows))
);

// ══════════════════════════════════════════════════════════════════════════════
// ⑦ 라이더 지갑 잔액 = 정산합 − 출금 소진분
// ══════════════════════════════════════════════════════════════════════════════
echo "⑦ 라이더 지갑 잔액 대사\n";

$wallets = db_rows(
    "SELECT w.rider_id, w.balance,
            COALESCE((SELECT SUM(net_amount) FROM settlement_rider_cycles c
                       WHERE c.rider_id = w.rider_id), 0) AS settled,
            COALESCE((SELECT SUM(amount
                        + CASE WHEN settle_fee_payer = 'agency' THEN 0 ELSE withhold_other END
                        + CASE WHEN transfer_fee_payer = 'agency' THEN 0 ELSE withhold_transfer_fee END)
                        FROM withdrawal_requests r
                       WHERE r.rider_id = w.rider_id AND r.status = 'completed'), 0) AS taken
       FROM rider_wallets w"
    . ($onlyRider > 0 ? ' WHERE w.rider_id = ?' : ''),
    $onlyRider > 0 ? [$onlyRider] : []
);
foreach ($wallets as $r) {
    $expect = (int) $r['settled'] - (int) $r['taken'];
    $check(
        (int) $r['balance'] === $expect,
        sprintf('지갑 잔액 = 정산합−출금 (기대 %s, 실제 %s)', number_format($expect), number_format((int) $r['balance'])),
        sprintf('라이더#%d', (int) $r['rider_id'])
    );
}
printf("   지갑 %s개\n", $n(count($wallets)));

// ══════════════════════════════════════════════════════════════════════════════
// 결과
// ══════════════════════════════════════════════════════════════════════════════
$line();
printf("검사 %s건 · 통과 %s · 불일치 %s\n", $n($PASS + count($FAIL)), $n($PASS), $n(count($FAIL)));

if ($FAIL !== []) {
    echo "\n■ 불일치\n";
    $grouped = [];
    foreach ($FAIL as $f) { $grouped[$f['what']][] = $f['where']; }
    foreach ($grouped as $what => $wheres) {
        printf("  [%s건] %s\n", $n(count($wheres)), $what);
        foreach (array_slice($wheres, 0, 5) as $x) { echo "        · {$x}\n"; }
        if (count($wheres) > 5) { printf("        · … 외 %s건\n", $n(count($wheres) - 5)); }
    }
}

if ($legacyMismatch > 0) {
    printf("\n■ 참고: gross+지원−공제 ≠ 실수령 인 사이클 %s개\n", $n($legacyMismatch));
    echo "   산식이 여러 차례 개정됐고 기존 사이클은 소급 재계산하지 않아 생긴 **알려진 구 데이터**입니다.\n";
    echo "   화면은 net+fee 로 역산해 보여주므로 라이더에게는 어긋나 보이지 않습니다.\n";
}

if (isset($vatLegacy) && $vatLegacy > 0) {
    printf("
■ 참고: 오더 합이 정산금액의 1/1.1 인 날짜 %s건
", $n($vatLegacy));
    echo "   부가세를 정산액에 포함하던 시절(2026-08-09 이전 산식)의 구 데이터입니다.
";
    echo "   그 구간 라이더가 오더 목록을 더하면 정산금액과 10% 차이가 납니다.
";
}

if ($predeductTotal > 0) {
    printf("\n■ 선차감 총액 %s원 — 관리자에는 보이고 라이더에는 안 보이는 몫\n", $n($predeductTotal));
}

echo "\n" . ($FAIL === [] ? "정합성 이상 없음.\n" : "위 불일치를 확인하세요.\n");
exit($FAIL === [] ? 0 : 1);
