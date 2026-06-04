<?php

declare(strict_types=1);

/**
 * 정산 엑셀 파일 열기 암호 API
 * GET  — 플랫폼별 저장값 (비밀번호 마스킹 없음 — super/settlement만 접근)
 * POST { "action": "save", "passwords": { "baemin": "...", ... } }
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/SettlementExcelConfig.php';
require_once INC_PATH . '/AuditLog.php';

header('Content-Type: application/json; charset=utf-8');

if (!admin_is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => '인증이 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!admin_can_access_route('settlement/upload')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => '권한이 없습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$err = static function (string $msg, int $code = 422): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
};

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    echo json_encode([
        'ok'          => true,
        'table_ready' => SettlementExcelConfig::tableExists(),
        'passwords'   => SettlementExcelConfig::allStored(),
        'python_hint' => 'pip install -r requirements-settlement.txt',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method !== 'POST') {
    $err('허용되지 않은 메서드입니다.', 405);
}

admin_deny_write_json('settlement');

$raw  = file_get_contents('php://input');
$ct   = $_SERVER['CONTENT_TYPE'] ?? '';
$body = str_contains($ct, 'application/json')
    ? (array) json_decode($raw ?: '{}', true)
    : $_POST;

if (trim((string) ($body['action'] ?? 'save')) !== 'save') {
    $err('action=save', 400);
}

$passwords = (array) ($body['passwords'] ?? $body);
$adminId   = (int) ($_SESSION['admin_id'] ?? 0);

try {
    $saved = SettlementExcelConfig::save($passwords, $adminId > 0 ? $adminId : null);
    AuditLog::record(
        'settlement.excel_password.save',
        'settlement_excel_config',
        '플랫폼별 엑셀 열기 암호 저장'
    );
    echo json_encode(['ok' => true, 'message' => '저장되었습니다.', 'passwords' => $saved], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $err('저장 실패: ' . $e->getMessage(), 500);
}
