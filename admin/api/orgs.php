<?php

declare(strict_types=1);

/**
 * 조직(총판/대리점) 관리 API
 * GET  — 관리 가능한 조직 목록
 * POST { "action": "save", ... }            — 생성(조직+계정) / 수정
 *      { "action": "toggle_active", id, active }
 *
 * 권한: 본사(admin)·총판(distributor) 레벨 + 운영/최고 역할 (admin_can_manage_orgs)
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/Organization.php';
require_once INC_PATH . '/AuditLog.php';

header('Content-Type: application/json; charset=utf-8');

if (!admin_is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => '인증이 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!admin_can_manage_orgs()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => '조직을 관리할 권한이 없습니다.'], JSON_UNESCAPED_UNICODE);
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
        $rows = Organization::listManageable();
        echo json_encode(['ok' => true, 'rows' => $rows, 'count' => count($rows)], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        $err('목록 조회 실패: ' . $e->getMessage(), 500);
    }
    exit;
}

if ($method !== 'POST') {
    $err('허용되지 않은 메서드입니다.', 405);
}

$raw  = file_get_contents('php://input');
$ct   = $_SERVER['CONTENT_TYPE'] ?? '';
$body = str_contains($ct, 'application/json')
    ? (array) json_decode($raw ?: '{}', true)
    : $_POST;

$action = trim((string) ($body['action'] ?? 'save'));

try {
    if ($action === 'toggle_active') {
        $id = (int) ($body['id'] ?? 0);
        if ($id < 1) {
            $err('조직 ID가 없습니다.');
        }
        $active = filter_var($body['active'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($active === null) {
            $err('active 값이 올바르지 않습니다.');
        }
        $row = Organization::setActive($id, $active);
        AuditLog::record(
            $active ? 'org.activate' : 'org.deactivate',
            (string) $id,
            ($active ? '조직 활성화 · ' : '조직 비활성화 · ') . (string) $row['name']
        );
        echo json_encode(['ok' => true, 'message' => '상태가 변경되었습니다.', 'row' => $row], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action !== 'save') {
        $err('action=save 또는 toggle_active', 400);
    }

    $editId = (int) ($body['id'] ?? 0);
    if ($editId > 0) {
        $row = Organization::update($editId, $body);
        AuditLog::record('org.update', (string) $editId, '조직 수정 · ' . (string) $row['name']);
        echo json_encode(['ok' => true, 'message' => '저장되었습니다.', 'row' => $row], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $row = Organization::create($body);
    AuditLog::record(
        'org.create',
        (string) $row['id'],
        '조직 생성 · ' . (string) $row['level_label'] . ' · ' . (string) $row['name'] . ' (' . (string) $row['primary_login'] . ')'
    );
    echo json_encode(['ok' => true, 'message' => '조직과 계정이 생성되었습니다.', 'row' => $row], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    $err($e->getMessage());
} catch (Throwable $e) {
    $err('처리 실패: ' . $e->getMessage(), 500);
}
