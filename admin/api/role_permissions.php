<?php

declare(strict_types=1);

/**
 * 역할별 화면 권한 관리 API (본사 super 전용).
 *   GET        : 현재 설정 전체 조회
 *   POST action=save : 역할×area 권한 저장
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/RolePermission.php';
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

if (!RolePermission::tableReady()) {
    $err('role_permissions 테이블이 없습니다. php migrate.php 를 실행하세요.', 500);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    echo json_encode([
        'ok'    => true,
        'roles' => RolePermission::ROLES,
        'areas' => RolePermission::AREAS,
        'grid'  => RolePermission::all(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method !== 'POST') {
    $err('허용되지 않은 메서드입니다.', 405);
}

$raw  = file_get_contents('php://input');
$body = (array) json_decode($raw ?: '{}', true);

$action = trim((string) ($body['action'] ?? ''));
if ($action !== 'save') {
    $err('알 수 없는 action 입니다.');
}

$rows = is_array($body['rows'] ?? null) ? $body['rows'] : [];
if ($rows === []) {
    $err('저장할 항목이 없습니다.');
}

$parsed = [];
foreach ($rows as $r) {
    $role = (string) ($r['role'] ?? '');
    $area = (string) ($r['area'] ?? '');
    if (!in_array($role, RolePermission::ROLES, true) || !array_key_exists($area, RolePermission::AREAS)) {
        $err('유효하지 않은 role/area 조합입니다: ' . $role . '/' . $area);
    }
    $parsed[] = [
        'role'  => $role,
        'area'  => $area,
        'view'  => !empty($r['view']),
        'write' => !empty($r['write']),
    ];
}

RolePermission::save($parsed);

AuditLog::record('role_permission.save', '', '역할별 권한 ' . count($parsed) . '건 저장');

echo json_encode(['ok' => true, 'message' => '저장되었습니다.', 'grid' => RolePermission::all()], JSON_UNESCAPED_UNICODE);
