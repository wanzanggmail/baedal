<?php

declare(strict_types=1);

/**
 * 영업대행수수료 분배 요율 API (본사 super 전용) — LOGIC §7 #12
 * GET  — 전 조직 요율 목록
 * POST { action:'save', org_id, pct }
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
    echo json_encode(['ok' => true, 'rows' => PgFeeConfig::listAll()], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($method !== 'POST') {
    $err('허용되지 않은 메서드입니다.', 405);
}

$raw  = file_get_contents('php://input');
$ct   = $_SERVER['CONTENT_TYPE'] ?? '';
$body = str_contains($ct, 'application/json') ? (array) json_decode($raw ?: '{}', true) : $_POST;

if (trim((string) ($body['action'] ?? 'save')) !== 'save') {
    $err('action=save', 400);
}

try {
    $orgId   = (int) ($body['org_id'] ?? 0);
    $pct     = (float) ($body['pct'] ?? 0);
    $adminId = (int) ($_SESSION['admin_id'] ?? 0);

    $org = db_row('SELECT id, name FROM organizations WHERE id = ? LIMIT 1', [$orgId]);
    if ($org === null) {
        $err('조직을 찾을 수 없습니다.', 404);
    }
    PgFeeConfig::save($orgId, $pct, $adminId > 0 ? $adminId : null);
    AuditLog::record('org.pg_fee', (string) $orgId, sprintf('%s 영업대행수수료 몫 %.2f%%', (string) $org['name'], $pct));
    echo json_encode(['ok' => true, 'message' => '저장되었습니다.', 'rows' => PgFeeConfig::listAll()], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    $err($e->getMessage(), 422);
} catch (Throwable $e) {
    $err('저장 실패: ' . $e->getMessage(), 500);
}
