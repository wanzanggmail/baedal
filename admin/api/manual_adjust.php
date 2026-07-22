<?php

declare(strict_types=1);

/**
 * 정산/잔액 수동 조정 API (본사 super 전용) — LOGIC §5.4a
 * GET  ?type=rider&rider=<code|id>      — 라이더 지갑 현재값
 *      ?type=agency&agency_id=<id>      — 대리점 지갑 현재값
 * POST { action: 'adjust_rider',  rider_id, balance, reason }
 *      { action: 'adjust_agency', agency_id, balance, reason }
 *      { action: 'adjust_reserve', agency_id, reserve, reason }
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/ManualAdjust.php';
require_once INC_PATH . '/AgencyWallet.php';

header('Content-Type: application/json; charset=utf-8');

if (!admin_is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => '인증이 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 본사 super 전용
if (!admin_has_role('super')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => '본사 최고 관리자만 사용할 수 있습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$err = static function (string $msg, int $code = 422): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
};

$adminId = (int) ($_SESSION['admin_id'] ?? 0);
$method  = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $type = trim((string) ($_GET['type'] ?? ''));
    try {
        if ($type === 'rider') {
            $key   = trim((string) ($_GET['rider'] ?? ''));
            $rider = ctype_digit($key)
                ? db_row('SELECT id, name, rider_code FROM riders WHERE id = ? LIMIT 1', [(int) $key])
                : db_row('SELECT id, name, rider_code FROM riders WHERE rider_code = ? LIMIT 1', [$key]);
            if ($rider === null) {
                $err('라이더를 찾을 수 없습니다.', 404);
            }
            RiderWallet::ensure((int) $rider['id']);
            $bal = (int) (db_row('SELECT balance FROM rider_wallets WHERE rider_id = ? LIMIT 1', [(int) $rider['id']])['balance'] ?? 0);
            echo json_encode([
                'ok'      => true,
                'rider'   => ['id' => (int) $rider['id'], 'name' => $rider['name'], 'code' => $rider['rider_code']],
                'balance' => $bal,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($type === 'agency') {
            $agencyId = (int) ($_GET['agency_id'] ?? 0);
            $org = db_row('SELECT id, name, level FROM organizations WHERE id = ? LIMIT 1', [$agencyId]);
            if ($org === null || (string) $org['level'] !== 'agency') {
                $err('대리점을 찾을 수 없습니다.', 404);
            }
            echo json_encode([
                'ok'     => true,
                'agency' => ['id' => (int) $org['id'], 'name' => $org['name']],
                'wallet' => AgencyWallet::withdrawable($agencyId),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $err('type=rider|agency', 400);
    } catch (Throwable $e) {
        $err('조회 실패: ' . $e->getMessage(), 500);
    }
}

if ($method !== 'POST') {
    $err('허용되지 않은 메서드입니다.', 405);
}

$raw  = file_get_contents('php://input');
$ct   = $_SERVER['CONTENT_TYPE'] ?? '';
$body = str_contains($ct, 'application/json') ? (array) json_decode($raw ?: '{}', true) : $_POST;
$action = trim((string) ($body['action'] ?? ''));
$reason = trim((string) ($body['reason'] ?? ''));

try {
    switch ($action) {
        case 'adjust_rider':
            $res = ManualAdjust::adjustRiderWallet((int) ($body['rider_id'] ?? 0), (int) ($body['balance'] ?? 0), $reason, $adminId);
            $msg = sprintf('라이더 지갑 %s → %s원으로 조정되었습니다.', number_format($res['before']), number_format($res['after']));
            break;
        case 'adjust_agency':
            $res = ManualAdjust::adjustAgencyBalance((int) ($body['agency_id'] ?? 0), (int) ($body['balance'] ?? 0), $reason, $adminId);
            $msg = sprintf('대리점 잔액 %s → %s원으로 조정되었습니다.', number_format($res['before']), number_format($res['after']));
            break;
        case 'adjust_reserve':
            $res = ManualAdjust::adjustAgencyReserve((int) ($body['agency_id'] ?? 0), (int) ($body['reserve'] ?? 0), $reason, $adminId);
            $msg = sprintf('원천세 예수금 %s → %s원으로 조정되었습니다.', number_format($res['before']), number_format($res['after']));
            break;
        default:
            $err('알 수 없는 action 입니다.', 400);
    }
    echo json_encode(['ok' => true, 'message' => $msg, 'result' => $res], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    $err($e->getMessage(), 422);
} catch (Throwable $e) {
    $err('조정 실패: ' . $e->getMessage(), 500);
}
