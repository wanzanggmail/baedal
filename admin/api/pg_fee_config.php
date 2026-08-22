<?php

declare(strict_types=1);

/**
 * 수수료 설정 API (본사 super 전용) — 대리점 기준.
 *   action=save_platform  : 플랫폼 수수료 3분할(본사/총판/대리점 %)
 *
 * ⚠️ 정산수수료(보증금·경과일 기준·건당 단가)는 여기서 저장하지 않는다. 같은 값을
 *    「출금 정책 설정」과 이 화면이 각자 쓰던 걸 2026-08-23에 전자로 일원화했다
 *    (`admin/api/withdrawal_config.php`).
 *
 * 참고: LOGIC.md §7 #12
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/PgFeeConfig.php';
require_once INC_PATH . '/AuditLog.php';

header('Content-Type: application/json; charset=utf-8');

if (!admin_is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => '인증이 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!admin_has_role('super')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => '본사 최고 관리자만 관리할 수 있습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$err = static function (string $msg, int $code = 422): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
};

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    echo json_encode(['ok' => true, 'rows' => PgFeeConfig::listAgencyConfigs()], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($method !== 'POST') {
    $err('허용되지 않은 메서드입니다.', 405);
}

$raw  = file_get_contents('php://input');
$ct   = $_SERVER['CONTENT_TYPE'] ?? '';
$body = str_contains($ct, 'application/json') ? (array) json_decode($raw ?: '{}', true) : $_POST;

$action  = trim((string) ($body['action'] ?? ''));
$orgId   = (int) ($body['org_id'] ?? 0);
$adminId = (int) ($_SESSION['admin_id'] ?? 0);

$org = db_row("SELECT id, name, level FROM organizations WHERE id = ? LIMIT 1", [$orgId]);
if ($org === null) {
    $err('조직을 찾을 수 없습니다.', 404);
}
if ((string) $org['level'] !== 'agency') {
    $err('수수료는 대리점 단위로만 설정합니다.');
}

try {
    if ($action === 'save_platform') {
        $hq   = (float) ($body['hq_pct'] ?? 0);
        $dist = (float) ($body['distributor_pct'] ?? 0);
        $ag   = (float) ($body['agency_pct'] ?? 0);

        PgFeeConfig::saveForAgency($orgId, $hq, $dist, $ag, $adminId > 0 ? $adminId : null);
        AuditLog::record(
            'org.platform_fee',
            (string) $orgId,
            sprintf('%s 플랫폼 수수료 · 본사 %.2f%% / 총판 %.2f%% / 대리점 %.2f%% (합 %.2f%%)',
                (string) $org['name'], $hq, $dist, $ag, $hq + $dist + $ag)
        );
    } else {

        $err('action이 올바르지 않습니다.', 400);
    }

    echo json_encode([
        'ok'      => true,
        'message' => '저장되었습니다.',
        'rows'    => PgFeeConfig::listAgencyConfigs(),
    ], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    $err($e->getMessage(), 422);
} catch (Throwable $e) {
    $err('저장 실패: ' . $e->getMessage(), 500);
}
