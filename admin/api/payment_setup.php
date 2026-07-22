<?php

declare(strict_types=1);

/**
 * 대리점 결제 설정 API — 카드(PG) · 오픈뱅킹 계좌 · PG 잔액 충전 (LOGIC §5.4 · §7 #8·#10)
 * GET  — 카드 목록 + 계좌 + 지갑
 * POST { action:'card_add', alias, brand, last4, priority, mock_limit, billing_key? }
 *      { action:'card_toggle', id, active }
 *      { action:'card_priority', id, priority }
 *      { action:'card_delete', id }
 *      { action:'account_save', bank_code, account_no, holder, fintech_use_num? }
 *      { action:'pg_charge', amount }   — 카드로 대리점 잔액 충전(대체결제 포함)
 *
 * 권한: 대리점(agency) 운영/정산 계정만.
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/Org.php';
require_once INC_PATH . '/AgencyCard.php';
require_once INC_PATH . '/BankAccount.php';
require_once INC_PATH . '/PgPayment.php';
require_once INC_PATH . '/AgencyWallet.php';
require_once INC_PATH . '/AuditLog.php';

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

$isAgency = admin_org_level() === Org::LEVEL_AGENCY;
$myRole   = (string) (admin_user()['role'] ?? '');
$agencyId = $isAgency ? admin_org_id() : 0;
$adminId  = (int) ($_SESSION['admin_id'] ?? 0);
$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!$isAgency || !in_array($myRole, ['operation', 'settlement'], true)) {
    $err('대리점 운영/정산 계정만 사용할 수 있습니다.', 403);
}

if ($method === 'GET') {
    echo json_encode([
        'ok'      => true,
        'cards'   => AgencyCard::listForAgency($agencyId),
        'account' => BankAccount::get($agencyId),
        'wallet'  => AgencyWallet::withdrawable($agencyId),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($method !== 'POST') {
    $err('허용되지 않은 메서드입니다.', 405);
}

$raw  = file_get_contents('php://input');
$ct   = $_SERVER['CONTENT_TYPE'] ?? '';
$body = str_contains($ct, 'application/json') ? (array) json_decode($raw ?: '{}', true) : $_POST;
$action = trim((string) ($body['action'] ?? ''));

try {
    switch ($action) {
        case 'card_add':
            $card = AgencyCard::create($agencyId, $body);
            AuditLog::record('org.card_add', (string) $agencyId, '카드 등록 · ' . (string) $card['alias']);
            $msg = '카드가 등록되었습니다.';
            break;
        case 'card_toggle':
            AgencyCard::setActive($agencyId, (int) ($body['id'] ?? 0), !empty($body['active']));
            $msg = '변경되었습니다.';
            break;
        case 'card_priority':
            AgencyCard::setPriority($agencyId, (int) ($body['id'] ?? 0), (int) ($body['priority'] ?? 100));
            $msg = '우선순위가 변경되었습니다.';
            break;
        case 'card_delete':
            AgencyCard::delete($agencyId, (int) ($body['id'] ?? 0));
            $msg = '카드가 삭제되었습니다.';
            break;
        case 'account_save':
            BankAccount::save($agencyId, $body);
            AuditLog::record('org.bank_account', (string) $agencyId, '오픈뱅킹 계좌 저장');
            $msg = '계좌가 저장되었습니다.';
            break;
        case 'pg_charge':
            $amount = (int) ($body['amount'] ?? 0);
            if ($amount <= 0) {
                $err('충전 금액을 입력하세요.');
            }
            $r = PgPayment::chargeForRider($agencyId, null, $amount, null, $adminId);
            if (!$r['success']) {
                $err('PG 결제 실패(전 카드): ' . $r['fail_reason']);
            }
            AuditLog::record('org.pg_charge', (string) $agencyId, sprintf('PG 충전 %s원(수수료 %s, %d회 시도)', number_format($r['net']), number_format($r['fee']), $r['attempts']));
            $msg = sprintf('%s원 충전 완료 (영업대행수수료 %s원 포함 %s원 결제, %d번째 카드 승인)', number_format($r['net']), number_format($r['fee']), number_format($r['total']), $r['attempts']);
            break;
        default:
            $err('알 수 없는 action 입니다.', 400);
    }
    echo json_encode([
        'ok'      => true,
        'message' => $msg,
        'cards'   => AgencyCard::listForAgency($agencyId),
        'account' => BankAccount::get($agencyId),
        'wallet'  => AgencyWallet::withdrawable($agencyId),
    ], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    $err($e->getMessage(), 422);
} catch (Throwable $e) {
    $err('처리 실패: ' . $e->getMessage(), 500);
}
