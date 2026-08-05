<?php

declare(strict_types=1);

/**
 * 수수료·차감 통합 조회 — 라이더 1명의 기간 내 발생 건별 내역
 * GET ?rider_id=N&from=YYYY-MM-DD&to=YYYY-MM-DD
 *
 * 출금 시점(withdrawal_requests.withhold_other)과 정산 반영 시점(settlement_fee_items)을
 * 한 목록으로 합쳐 날짜순 정렬한다. 미수금(대여금·리스·선지급)은 수수료가 아닌 차감이라 별도 합계.
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/Org.php';

header('Content-Type: application/json; charset=utf-8');

if (!admin_is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => '인증이 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$err = static function (string $msg, int $code = 422): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
};

$riderId = (int) ($_GET['rider_id'] ?? 0);
$from    = trim((string) ($_GET['from'] ?? ''));
$to      = trim((string) ($_GET['to'] ?? ''));

if ($riderId < 1) {
    $err('rider_id가 필요합니다.', 400);
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $err('기간 형식이 올바르지 않습니다.', 400);
}

$rider = db_row('SELECT id, name, rider_code, agency_id FROM riders WHERE id = ? LIMIT 1', [$riderId]);
if ($rider === null) {
    $err('라이더를 찾을 수 없습니다.', 404);
}
// 멀티테넌시: 소속 대리점 스코프 밖이면 차단
if (!Org::canAccessAgency((int) ($rider['agency_id'] ?? 0))) {
    $err('이 라이더에 접근할 권한이 없습니다.', 403);
}

$DEBT_CODES = ['loan', 'lease', 'advance', 'rental'];
$items      = [];
$feeTotal   = 0;
$debtTotal  = 0;

// ── 출금 시점 정산수수료 ──
foreach (db_rows(
    "SELECT id, requested_at, withhold_other, amount, note, status
       FROM withdrawal_requests
      WHERE rider_id = ? AND status <> 'rejected'
        AND DATE(requested_at) >= ? AND DATE(requested_at) <= ?
        AND withhold_other > 0
      ORDER BY requested_at ASC",
    [$riderId, $from, $to]
) as $w) {
    $amt        = (int) $w['withhold_other'];
    $feeTotal  += $amt;
    $items[]    = [
        'date'    => substr((string) $w['requested_at'], 0, 10),
        'stage'   => 'withdraw',
        'label'   => '정산수수료 (출금 ' . number_format((int) $w['amount']) . '원)',
        'note'    => (string) ($w['note'] ?? ''),
        'amount'  => $amt,
        'is_debt' => false,
    ];
}

// ── 정산 반영 시점 차감 항목 ──
foreach (db_rows(
    'SELECT c.settlement_date, fi.fee_code, fi.label, fi.amount
       FROM settlement_fee_items fi
       INNER JOIN settlement_rider_cycles c ON c.id = fi.cycle_id
      WHERE c.rider_id = ? AND c.settlement_date >= ? AND c.settlement_date <= ?
      ORDER BY c.settlement_date ASC, fi.sort_order ASC, fi.id ASC',
    [$riderId, $from, $to]
) as $f) {
    $amt    = (int) $f['amount'];
    $isDebt = in_array((string) $f['fee_code'], $DEBT_CODES, true);
    if ($isDebt) {
        $debtTotal += $amt;
    } else {
        $feeTotal += $amt;
    }
    $items[] = [
        'date'    => substr((string) $f['settlement_date'], 0, 10),
        'stage'   => 'settlement',
        'label'   => (string) $f['label'],
        'note'    => '',
        'amount'  => $amt,
        'is_debt' => $isDebt,
    ];
}

usort($items, static fn (array $a, array $b): int => [$a['date'], $a['stage']] <=> [$b['date'], $b['stage']]);

echo json_encode([
    'ok'         => true,
    'rider'      => ['id' => (int) $rider['id'], 'name' => (string) $rider['name'], 'rider_code' => (string) $rider['rider_code']],
    'from'       => $from,
    'to'         => $to,
    'items'      => $items,
    'fee_total'  => $feeTotal,
    'debt_total' => $debtTotal,
], JSON_UNESCAPED_UNICODE);
