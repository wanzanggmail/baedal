<?php

declare(strict_types=1);

/**
 * 미수금 이중차감 점검 — 같은 라이더·같은 날짜에 대여금/리스/선지급이 **두 번 이상** 빠진 건.
 *
 *   php tools/audit_double_deduction.php
 *
 * 원인: 같은 라이더가 같은 날 팀지역이 다른 두 정산서에 올라오면 사이클이 두 개 생기는데,
 * 정산 반영이 `deduction_entries` 를 «라이더+귀속일» 로만 집어가 둘 다 같은 행을 차감했다
 * (2026-09-24 `deduction_entries.consumed_cycle_id` 로 차단). 이 도구는 **그 전에 생긴 건**을 찾는다.
 *
 * **읽기 전용.** 되돌리지 않는다 — 얼마를 누구에게 돌려줄지는 사람이 정할 일이다.
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';

$n = static fn ($v): string => number_format((int) $v);

$KINDS = ['loan', 'lease', 'advance', 'rental'];
$ph    = implode(',', array_fill(0, count($KINDS), '?'));

echo '미수금 이중차감 점검 — DB: ' . DB_NAME . ' · ' . date('Y-m-d H:i') . "\n";

$dupes = db_rows(
    "SELECT c.rider_id, c.settlement_date, fi.fee_code,
            COUNT(DISTINCT c.id) AS cycles, SUM(fi.amount) AS total, MAX(fi.amount) AS one
       FROM settlement_fee_items fi
       JOIN settlement_rider_cycles c ON c.id = fi.cycle_id
      WHERE fi.fee_code IN ({$ph})
      GROUP BY c.rider_id, c.settlement_date, fi.fee_code
     HAVING cycles > 1
      ORDER BY c.settlement_date DESC, c.rider_id ASC",
    $KINDS
);

echo "\n" . str_repeat('-', 74) . "\n① 같은 라이더·같은 날짜에 두 번 이상 빠진 건\n" . str_repeat('-', 74) . "\n";
if ($dupes === []) {
    echo "  없음.\n";
}

$excessTotal = 0;
foreach ($dupes as $d) {
    $rider = db_row('SELECT name, rider_code FROM riders WHERE id = ? LIMIT 1', [(int) $d['rider_id']]) ?? [];
    // 정상 부과는 1회다 — 나머지가 초과분.
    $excess = (int) $d['total'] - (int) $d['one'];
    $excessTotal += $excess;

    printf(
        "\n%s %s(%s) · %s — %d개 사이클에서 합계 %s원 (초과 %s원)\n",
        (string) $d['settlement_date'],
        (string) ($rider['name'] ?? '?'),
        (string) ($rider['rider_code'] ?? '?'),
        (string) $d['fee_code'],
        (int) $d['cycles'],
        $n($d['total']),
        $n($excess)
    );

    foreach (db_rows(
        "SELECT c.id, c.team_region, c.upload_id, fi.amount
           FROM settlement_fee_items fi
           JOIN settlement_rider_cycles c ON c.id = fi.cycle_id
          WHERE c.rider_id = ? AND c.settlement_date = ? AND fi.fee_code = ?
          ORDER BY c.id ASC",
        [(int) $d['rider_id'], (string) $d['settlement_date'], (string) $d['fee_code']]
    ) as $c) {
        printf(
            "    사이클#%d · 업로드#%d · %s — %s원\n",
            (int) $c['id'],
            (int) $c['upload_id'],
            (string) ($c['team_region'] ?: '팀지역 없음'),
            $n($c['amount'])
        );
    }
}

if ($dupes !== []) {
    printf("\n  합계 초과 차감 %s원 (%d건)\n", $n($excessTotal), count($dupes));
    echo "  → 라이더에게 돌려줄 금액이다. 「정산/잔액 수동 조정」(본사 전용)으로 처리한다.\n";
}

// ── ② 원장 vs 정산 대조 ────────────────────────────────────────────────────
// 미수금 원장(rider_debt_entries)은 «걷었다»는데 정산(settlement_fee_items)에는 없거나,
// 그 반대인 건. 이중차감·취소 후 잔존·고아 차감이 전부 여기 걸린다.
echo "\n" . str_repeat('-', 74) . "\n② 원장 vs 정산 대조 (라이더·날짜별 합계)\n" . str_repeat('-', 74) . "\n";

$ledger = [];
foreach (db_rows(
    'SELECT rider_id, applied_date d, COALESCE(SUM(amount),0) amt
       FROM rider_debt_entries GROUP BY rider_id, applied_date'
) as $r) {
    $ledger[(int) $r['rider_id'] . '|' . (string) $r['d']] = (int) $r['amt'];
}

$charged = [];
foreach (db_rows(
    "SELECT c.rider_id, c.settlement_date d, COALESCE(SUM(fi.amount),0) amt
       FROM settlement_fee_items fi
       JOIN settlement_rider_cycles c ON c.id = fi.cycle_id
      WHERE fi.fee_code IN ({$ph})
      GROUP BY c.rider_id, c.settlement_date",
    $KINDS
) as $r) {
    $charged[(int) $r['rider_id'] . '|' . (string) $r['d']] = (int) $r['amt'];
}

$mismatch = 0;
foreach (array_unique(array_merge(array_keys($ledger), array_keys($charged))) as $k) {
    $l = $ledger[$k] ?? 0;
    $c = $charged[$k] ?? 0;
    if ($l === $c) {
        continue;
    }
    [$rid, $date] = explode('|', (string) $k);
    $rider = db_row('SELECT name, rider_code FROM riders WHERE id = ? LIMIT 1', [(int) $rid]) ?? [];

    // 차감 행의 상태를 같이 본다 — 소비 표시가 다 붙어 있으면 **정산 밖에서 정리된 건**이다
    // (수동 조정으로 돌려줬거나 걷었거나). 그런 건은 영원히 «원장 > 정산» 으로 남으므로
    // 경고가 아니라 «정리됨» 으로 표시해야 진짜 문제가 묻히지 않는다.
    $st = db_row(
        "SELECT COUNT(*) total,
                SUM(consumed_cycle_id IS NOT NULL) done
           FROM deduction_entries
          WHERE rider_id = ? AND applied_date = ? AND kind IN ({$ph})",
        array_merge([(int) $rid, $date], $KINDS)
    ) ?? ['total' => 0, 'done' => 0];
    $pending = (int) $st['total'] - (int) $st['done'];
    $settled = (int) $st['total'] > 0 && $pending === 0 && $c < $l;

    if (!$settled) {
        $mismatch++;
    }
    printf(
        "  %s %s %s(%s) — 원장 %s / 정산 %s · 차이 %s%s\n",
        $settled ? '✓' : '!',
        $date,
        (string) ($rider['name'] ?? '?'),
        (string) ($rider['rider_code'] ?? '?'),
        $n($l),
        $n($c),
        ($c > $l ? '+' : '') . $n($c - $l),
        $settled
            ? '  ← 정산 밖에서 정리됨(수동 조정 등) · 조치 불필요'
            : ($pending > 0 ? sprintf('  ← 미회수 차감 %d건 — 다음 정산에서 걷힌다', $pending) : '')
    );
}
if ($mismatch === 0) {
    echo "  조치가 필요한 건 없음.\n";
} else {
    echo "\n  «정산 > 원장» = 라이더가 더 떼임(이중차감·취소 후 잔존).\n";
    echo "  «원장 > 정산» = 원장엔 걷었다는데 실제로는 안 걷힘(고아 차감).\n";
    echo "  ⚠️ 엑셀 차감내역을 같은 코드로 등록한 건이 있으면 «정산 > 원장» 으로 보일 수 있다 — 건별로 확인할 것.\n";
}

// ── ③ 아직 안 먹힌 차감 행 ─────────────────────────────────────────────────
// 귀속일에 사이클이 이미 있는데 consumed_cycle_id 가 비어 있으면, 그 차감은
// 그 정산에서 안 걷혔다는 뜻이다(귀속일을 과거로 잡은 수동 차감 등).
if (in_array('consumed_cycle_id', array_column(db_rows('SHOW COLUMNS FROM deduction_entries'), 'Field'), true)) {
    echo "\n" . str_repeat('-', 74) . "\n③ 사이클이 지나갔는데 안 먹힌 차감 행\n" . str_repeat('-', 74) . "\n";
    $orphans = db_rows(
        "SELECT de.id, de.rider_id, de.applied_date, de.kind, de.amount, r.name, r.rider_code
           FROM deduction_entries de
           JOIN riders r ON r.id = de.rider_id
          WHERE de.consumed_cycle_id IS NULL
            AND de.kind IN ({$ph})
            AND EXISTS (SELECT 1 FROM settlement_rider_cycles c
                         WHERE c.rider_id = de.rider_id AND c.settlement_date = de.applied_date)
          ORDER BY de.applied_date DESC",
        $KINDS
    );
    if ($orphans === []) {
        echo "  없음.\n";
    } else {
        foreach ($orphans as $o) {
            printf(
                "  %s %s(%s) · %s %s원 (차감행 #%d)\n",
                (string) $o['applied_date'],
                (string) $o['name'],
                (string) $o['rider_code'],
                (string) $o['kind'],
                $n($o['amount']),
                (int) $o['id']
            );
        }
        echo "\n  → 원장엔 걷었다고 남고 실제로는 안 걷힌 건이다.\n";
        echo "     2026-09-25 이후 코드는 **다음 정산 반영에서 자동으로 걷는다**(귀속일 이하의 미소비 차감을 줍는다).\n";
        echo "     이미 수동 조정으로 정리한 건이라면 다시 걷히지 않게 소비 처리해 둘 것:\n";
        echo "       UPDATE deduction_entries de JOIN settlement_rider_cycles c\n";
        echo "         ON c.rider_id = de.rider_id AND c.settlement_date = de.applied_date\n";
        echo "        SET de.consumed_cycle_id = c.id WHERE de.id IN (<위 차감행 번호>);\n";
    }
}

