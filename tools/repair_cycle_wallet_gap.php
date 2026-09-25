<?php

declare(strict_types=1);

/**
 * 지갑 ↔ 정산 사이클 격차 점검·복구.
 *
 *   php tools/repair_cycle_wallet_gap.php            점검만(읽기 전용)
 *   php tools/repair_cycle_wallet_gap.php --apply    사이클을 지갑에 맞춰 소진 처리
 *
 * 출금 가능액은 **지갑 잔액**을 한도로 삼고, 실제 소진 대상은 **정산 사이클**이다.
 * 수동 조정으로 지갑만 줄이면 사이클이 지갑보다 많아진 채로 굳는다 — 라이더는 매번 그 차액만큼
 * 못 가져가고, 그 잔여가 다음 출금에 「지난 날짜」로 계속 따라붙는다(2026-09-25 발견).
 * 여기서 그 격차만큼 **오래된 사이클부터** 소진 처리해 둘을 맞춘다.
 *
 * ⚠️ 반대 방향(지갑 > 사이클)은 건드리지 않는다 — 프로모션 지급처럼 사이클 없이 지갑에만
 *    적립되는 정상 경로가 있어서다.
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once INC_PATH . '/WithdrawalCycles.php';

$apply = in_array('--apply', $argv, true);
$n     = static fn ($v): string => number_format((int) $v);

echo '지갑 ↔ 사이클 격차 ' . ($apply ? '복구' : '점검') . ' — DB: ' . DB_NAME . ' · ' . date('Y-m-d H:i') . "\n\n";

$rows = db_rows(
    'SELECT w.rider_id, w.balance, r.name, r.rider_code,
            COALESCE(SUM(GREATEST(0, c.net_amount - c.withdrawn_amount)), 0) AS cyc
       FROM rider_wallets w
       JOIN riders r ON r.id = w.rider_id
       LEFT JOIN settlement_rider_cycles c ON c.rider_id = w.rider_id
      GROUP BY w.rider_id, w.balance, r.name, r.rider_code
     HAVING cyc > w.balance
      ORDER BY (cyc - w.balance) DESC'
);

if ($rows === []) {
    echo "  격차 없음.\n";
    exit(0);
}

$total = 0;
foreach ($rows as $r) {
    $gap    = (int) $r['cyc'] - (int) $r['balance'];
    $total += $gap;
    printf(
        "  %s(%s) — 지갑 %s / 사이클 %s · 격차 %s%s\n",
        (string) $r['name'],
        (string) $r['rider_code'],
        $n($r['balance']),
        $n($r['cyc']),
        $n($gap),
        $apply ? '' : '  (--apply 로 정리)'
    );

    if (!$apply) {
        continue;
    }
    $done = db_transaction(static fn (): int => WithdrawalCycles::consumeOutside((int) $r['rider_id'], $gap));
    printf("      → 오래된 사이클부터 %s원 소진 처리\n", $n($done));
}

printf("\n  대상 %d명 · 격차 합계 %s원\n", count($rows), $n($total));
if (!$apply) {
    echo "  ※ 읽기 전용으로 돌았다. 실제로 맞추려면 --apply.\n";
}
