<?php

declare(strict_types=1);

/**
 * 출금 정책 설정 API (super 전용)
 * GET  — 현재 설정
 * POST { "action": "save", ... }
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/WithdrawalConfig.php';
require_once INC_PATH . '/AuditLog.php';

header('Content-Type: application/json; charset=utf-8');

if (!admin_is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => '인증이 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!admin_has_role('super')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => '최고 관리자만 변경할 수 있습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$err = static function (string $msg, int $code = 422): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
};

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    try {
        echo json_encode(['ok' => true, 'config' => WithdrawalConfig::get()], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        $err('조회 실패: ' . $e->getMessage(), 500);
    }
    exit;
}

if ($method !== 'POST') {
    $err('허용되지 않은 메서드입니다.', 405);
}

admin_deny_write_json('system');

$raw  = file_get_contents('php://input');
$ct   = $_SERVER['CONTENT_TYPE'] ?? '';
$body = str_contains($ct, 'application/json')
    ? (array) json_decode($raw ?: '{}', true)
    : $_POST;

if (trim((string) ($body['action'] ?? 'save')) !== 'save') {
    $err('action=save', 400);
}

try {
    $adminId = (int) ($_SESSION['admin_id'] ?? 0);
    $cfg = WithdrawalConfig::save($body, $adminId > 0 ? $adminId : null);
    AuditLog::record(
        'withdrawal.config.save',
        'withdrawal_config',
        sprintf(
            '보증금 %s · %d일 미만 %d원 / 이상 %d원(건당)',
            number_format($cfg['reserve_amount']),
            $cfg['fee_day_threshold'],
            $cfg['fee_per_tx_short'],
            $cfg['fee_per_tx_long']
        )
    );
    echo json_encode(['ok' => true, 'message' => '저장되었습니다.', 'config' => $cfg], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $err('저장 실패: ' . $e->getMessage(), 500);
}
