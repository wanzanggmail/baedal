<?php

declare(strict_types=1);

/**
 * 선공제(대행 수수료) 설정 API
 * GET  — 현재 설정
 * POST { "action": "save", fee_day_threshold, fee_per_tx_short, fee_per_tx_long }
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/AgencyFeeConfig.php';
require_once INC_PATH . '/AuditLog.php';

header('Content-Type: application/json; charset=utf-8');

if (!admin_is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => '인증이 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!admin_can_access_route('deduction/agency-fee')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => '권한이 없습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$err = static function (string $msg, int $code = 422): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
};

// 멀티테넌시: 대리점=자기 설정 / 본사=전역 기본 / 총판=전역 기본을 "조회만"
require_once INC_PATH . '/Org.php';
$level    = admin_org_level();
$isAgency = $level === Org::LEVEL_AGENCY;
$isHq     = $level === Org::LEVEL_ADMIN;
$cfgOrgId = $isAgency ? admin_org_id() : null;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ⚠️ 전역 기본값(org_id NULL)은 **본사만** 바꾼다.
// 총판도 이 화면의 라우트·쓰기 권한을 통과하는데, 저장 대상이 NULL이라 그대로 두면
// 총판이 누른 저장이 **전용 설정이 없는 모든 대리점**의 기본값을 덮어쓴다(테넌시 유출).
if ($method === 'POST' && !$isAgency && !$isHq) {
    http_response_code(403);
    echo json_encode([
        'ok'      => false,
        'message' => '총판 계정은 대행수수료 기본값을 변경할 수 없습니다. (조회만 가능 — 전역 기본은 본사, 대리점별 설정은 해당 대리점이 관리)',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'GET') {
    try {
        echo json_encode([
            'ok'           => true,
            'config'       => AgencyFeeConfig::get($cfgOrgId),
            'table_ready'  => AgencyFeeConfig::tableReady(),
            'scope'        => $cfgOrgId !== null ? 'agency' : 'global',
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        $err('조회 실패: ' . $e->getMessage(), 500);
    }
    exit;
}

if ($method !== 'POST') {
    $err('허용되지 않은 메서드입니다.', 405);
}

admin_deny_write_json('deduction');

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
    $cfg = AgencyFeeConfig::save($body, $cfgOrgId, $adminId > 0 ? $adminId : null);
    AuditLog::record(
        'deduction.agency_fee.save',
        'deduction_global_config',
        sprintf(
            '%d일 미만 %d원 / 이상 %d원(건당)',
            $cfg['fee_day_threshold'],
            $cfg['fee_per_tx_short'],
            $cfg['fee_per_tx_long']
        )
    );
    echo json_encode(['ok' => true, 'message' => '저장되었습니다.', 'config' => $cfg], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $err('저장 실패: ' . $e->getMessage(), 500);
}
