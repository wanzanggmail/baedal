<?php

declare(strict_types=1);

/**
 * 대리점 자체 정산금 인출 API (LOGIC §5.5)
 * GET  — 인출가능액(대리점 계정) + 인출 내역(스코프)
 * POST { "action": "create", "amount": N } — 대리점 자체 인출(승인 없이 즉시 차감)
 *
 * 권한: 인출 신청은 대리점(agency 레벨) 운영/정산 계정만. 조회는 본사·총판도(스코프).
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/Org.php';
require_once INC_PATH . '/AgencyPayout.php';
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
$myAgency = $isAgency ? admin_org_id() : 0;
$myRole   = (string) (admin_user()['role'] ?? '');
$adminId  = (int) ($_SESSION['admin_id'] ?? 0);
$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    try {
        $out = ['ok' => true, 'scope' => $isAgency ? 'agency' : 'org'];
        if ($isAgency) {
            $out['wallet'] = AgencyWallet::withdrawable($myAgency);
            $out['rows']   = AgencyPayout::listScoped($myAgency);
        } else {
            $out['rows'] = AgencyPayout::listScoped(null);
        }
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        $err('조회 실패: ' . $e->getMessage(), 500);
    }
    exit;
}

if ($method !== 'POST') {
    $err('허용되지 않은 메서드입니다.', 405);
}

// 인출 신청은 대리점 운영/정산 계정만 (승인 절차 없음 — LOGIC §5.5)
if (!$isAgency || !in_array($myRole, ['operation', 'settlement', 'manager'], true)) {
    $err('대리점 운영/정산 계정만 자체 인출을 신청할 수 있습니다.', 403);
}

$raw  = file_get_contents('php://input');
$ct   = $_SERVER['CONTENT_TYPE'] ?? '';
$body = str_contains($ct, 'application/json') ? (array) json_decode($raw ?: '{}', true) : $_POST;

$action = trim((string) ($body['action'] ?? 'create'));
if ($action !== 'create') {
    $err('action=create', 400);
}

try {
    $amount = (int) ($body['amount'] ?? 0);
    $payout = AgencyPayout::create($myAgency, $amount, $adminId > 0 ? $adminId : null);
    AuditLog::record(
        'withdrawal.agency_payout',
        (string) $payout['id'],
        sprintf('대리점 자체 인출 %s원 신청', number_format((int) $payout['amount']))
    );
    echo json_encode([
        'ok'      => true,
        'message' => number_format((int) $payout['amount']) . '원 인출 신청이 접수되었습니다.',
        'payout'  => $payout,
        'wallet'  => AgencyWallet::withdrawable($myAgency),
    ], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    $err($e->getMessage(), 422);
} catch (Throwable $e) {
    $err('인출 신청 실패: ' . $e->getMessage(), 500);
}
