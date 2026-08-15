<?php

declare(strict_types=1);

/**
 * 대리점 결제 설정 API — 카드(PG) · 오픈뱅킹 계좌 · PG 잔액 충전 (LOGIC §5.4 · §7 #8·#10)
 * GET  — 카드 목록 + 계좌 + 지갑
 * POST { action:'card_add', alias, brand, priority, mock_limit,
 *        card_num, yymm, auth_num, card_pw }  — PG에 빌키 발급 요청
 *        ⚠️ 카드번호·비밀번호는 PG로 전달만 하고 **저장하지 않는다**(PCI-DSS).
 *        (이미 발급받은 키가 있으면 billing_key 로 직접 등록 가능)
 *      { action:'card_toggle', id, active }
 *      { action:'card_priority', id, priority }
 *      { action:'card_delete', id }
 *      { action:'account_save', bank_code, account_no, holder, fintech_use_num? }
 *      { action:'pg_charge', amount }   — 카드로 대리점 잔액 충전(대체결제 포함)
 *
 * 권한: 대리점(agency) 운영·정산·총괄(manager) 계정 = 자기 대리점만.
 *       본사(super) = agency_id 로 대상 대리점을 지정해 대신 설정(감사로그에 「본사 대행」 표기).
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

$isAgencySelf = admin_org_level() === Org::LEVEL_AGENCY;
$myRole   = (string) (admin_user()['role'] ?? '');
$adminId  = (int) ($_SESSION['admin_id'] ?? 0);
$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$body = [];
if ($method === 'POST') {
    $raw  = file_get_contents('php://input');
    $ct   = $_SERVER['CONTENT_TYPE'] ?? '';
    $body = str_contains($ct, 'application/json') ? (array) json_decode($raw ?: '{}', true) : $_POST;
}

// 대상 대리점 결정
// - 대리점 계정: 요청에 뭐가 오든 항상 자기 조직 고정(남의 카드·계좌를 절대 못 봄/못 고침)
// - 본사(super): agency_id 로 대상 대리점을 지정해 대신 설정(지원 업무)
$targetAgency = null;
if ($isAgencySelf) {
    if (!in_array($myRole, ['operation', 'settlement', 'manager'], true)) {
        $err('대리점 운영·정산·총괄 계정만 사용할 수 있습니다.', 403);
    }
    $agencyId = admin_org_id();
} elseif (admin_has_role('super')) {
    $agencyId = (int) ($method === 'GET' ? ($_GET['agency_id'] ?? 0) : ($body['agency_id'] ?? 0));
    if ($agencyId < 1) {
        $err('대상 대리점을 선택하세요.');
    }
    $targetAgency = Org::find($agencyId);
    if ($targetAgency === null || (string) $targetAgency['level'] !== Org::LEVEL_AGENCY) {
        $err('대상 대리점을 찾을 수 없습니다.', 404);
    }
} else {
    $err('대리점 계정 또는 본사 최고관리자만 사용할 수 있습니다.', 403);
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

$action = trim((string) ($body['action'] ?? ''));

// 본사가 남의 대리점을 대신 설정한 기록은 감사로그에 대상을 남긴다(누가 어느 대리점 것을 건드렸는지).
$onBehalf = $targetAgency !== null ? sprintf(' [본사 대행 · %s]', (string) $targetAgency['name']) : '';

try {
    switch ($action) {
        case 'card_add':
            $card = AgencyCard::create($agencyId, $body);
            AuditLog::record('org.card_add', (string) $agencyId, '카드 등록 · ' . (string) $card['alias'] . $onBehalf);
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
            AuditLog::record('org.bank_account', (string) $agencyId, '오픈뱅킹 계좌 저장' . $onBehalf);
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
            AuditLog::record('org.pg_charge', (string) $agencyId, sprintf('PG 충전 %s원(수수료 %s, %d회 시도)', number_format($r['net']), number_format($r['fee']), $r['attempts']) . $onBehalf);
            $msg = sprintf('%s원 충전 완료 (플랫폼 수수료 %s원 포함 %s원 결제, %d번째 카드 승인)', number_format($r['net']), number_format($r['fee']), number_format($r['total']), $r['attempts']);
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
