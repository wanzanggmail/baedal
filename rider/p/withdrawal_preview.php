<?php

declare(strict_types=1);

/**
 * 기간 지정 출금 미리보기 (라이더 앱 달력에서 날짜 선택 시 실시간 계산)
 * GET ?to=YYYY-MM-DD  (생략 시 전액)
 *
 * 사이클 소비는 항상 가장 오래된 미출금분부터이므로 "선택일까지 누적" 기준으로 계산한다.
 * 참고: LOGIC.md §7 #18
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/rider_auth.php';
require_once INC_PATH . '/RiderWallet.php';

header('Content-Type: application/json; charset=utf-8');

rider_auth_bootstrap();
$ru = rider_current_user();
if (!$ru) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => '로그인이 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$to = trim((string) ($_GET['to'] ?? ''));
if ($to !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => '날짜 형식이 올바르지 않습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $p = RiderWallet::previewWithdrawal((int) $ru['id'], $to !== '' ? $to : null);
} catch (Throwable) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => '계산에 실패했습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$picked = (array) ($p['picked_cycles'] ?? []);
$dates  = array_column($picked, 'settlement_date');

echo json_encode([
    'ok'                => true,
    'to'                => $to,
    'balance'           => (int) $p['balance'],
    'reserve_amount'    => (int) $p['reserve_amount'],
    'fee_per_tx'        => (int) $p['fee_per_tx'],
    'transfer_fee'      => (int) ($p['transfer_fee'] ?? 0),
    'consume_amount'    => (int) $p['consume_amount'],
    'payout_amount'     => (int) $p['payout_amount'],
    'can_apply'         => (bool) $p['can_apply'],
    'fee_cycle_based'   => (bool) $p['fee_cycle_based'],
    'fee_short_orders'  => (int) $p['fee_short_orders'],
    'fee_long_orders'   => (int) $p['fee_long_orders'],
    'fee_short_amount'  => (int) $p['fee_short_amount'],
    'fee_long_amount'   => (int) $p['fee_long_amount'],
    'fee_rate_short'    => (int) $p['fee_rate_short'],
    'fee_rate_long'     => (int) $p['fee_rate_long'],
    'fee_day_threshold' => (int) $p['fee_day_threshold'],
    'picked_count'      => count($picked),
    'period_from'       => $dates !== [] ? min($dates) : '',
    'period_to'         => $dates !== [] ? max($dates) : '',
], JSON_UNESCAPED_UNICODE);
