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

if ($dupes === []) {
    echo "\n  이중차감 없음.\n";
    exit(0);
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

printf("\n합계 초과 차감 %s원 (%d건)\n", $n($excessTotal), count($dupes));
echo "→ 라이더에게 돌려줄 금액이다. 「정산/잔액 수동 조정」(본사 전용)으로 처리한다.\n";
