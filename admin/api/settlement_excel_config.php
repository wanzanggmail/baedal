<?php

declare(strict_types=1);

/**
 * 정산 엑셀 파일 열기 암호 API
 * GET  — 대리점 계정: 자기 암호만 / 본사·총판: 전역 기본 + 스코프 내 대리점별 목록
 * POST { "action": "save", "org_id": "global"|<대리점id>, "passwords": {...} }
 *   - 대리점 계정은 org_id를 무시하고 항상 자기 조직에 저장
 *   - "global"은 본사만 저장 가능
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/SettlementExcelConfig.php';
require_once INC_PATH . '/AuditLog.php';
require_once INC_PATH . '/Org.php';

header('Content-Type: application/json; charset=utf-8');

if (!admin_is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => '인증이 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!admin_can_access_route('settlement/upload') && !admin_can_access_route('system/settlement-excel')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => '권한이 없습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$err = static function (string $msg, int $code = 422): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
};

$isAgencyLevel = admin_org_level() === Org::LEVEL_AGENCY;
$isHq          = admin_org_level() === Org::LEVEL_ADMIN;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    if ($isAgencyLevel) {
        echo json_encode([
            'ok'          => true,
            'mode'        => 'agency',
            'table_ready' => SettlementExcelConfig::tableExists(),
            'passwords'   => SettlementExcelConfig::allStoredByKind(admin_org_id()),
            'python_hint' => 'pip install -r requirements-settlement.txt',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'ok'          => true,
        'mode'        => 'list',
        'table_ready' => SettlementExcelConfig::tableExists(),
        'is_hq'       => $isHq,
        'global'      => $isHq ? SettlementExcelConfig::allStoredByKind(null) : null,
        'agencies'    => SettlementExcelConfig::listAgencyRows(),
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

if ($isAgencyLevel) {
    $targetOrgId = admin_org_id();
} else {
    $target = trim((string) ($body['org_id'] ?? ''));
    if ($target === 'global') {
        if (!$isHq) {
            $err('전역 기본은 본사만 저장할 수 있습니다.', 403);
        }
        $targetOrgId = null;
    } else {
        $targetOrgId = (int) $target;
        if ($targetOrgId <= 0 || !Org::canAccessAgency($targetOrgId)) {
            $err('대상 대리점을 확인하세요.', 403);
        }
    }
}

$passwords = (array) ($body['passwords'] ?? []);
$adminId   = (int) ($_SESSION['admin_id'] ?? 0);

try {
    $saved = SettlementExcelConfig::save($passwords, $targetOrgId, $adminId > 0 ? $adminId : null);
    AuditLog::record(
        'settlement.excel_password.save',
        'settlement_excel_config',
        '플랫폼별 엑셀 열기 암호 저장' . ($targetOrgId !== null ? " (org_id={$targetOrgId})" : ' (전역 기본)')
    );
    echo json_encode(['ok' => true, 'message' => '저장되었습니다.', 'org_id' => $targetOrgId, 'passwords' => $saved], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $err('저장 실패: ' . $e->getMessage(), 500);
}
