<?php

declare(strict_types=1);

/**
 * 관리자 계정 API (super 전용)
 * GET  — 목록
 * POST { "action": "save", ... } | { "action": "toggle_active", "id": n, "active": bool }
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/AdminAccount.php';
require_once INC_PATH . '/AuditLog.php';

header('Content-Type: application/json; charset=utf-8');

if (!admin_is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => '인증이 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!admin_has_role('super')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => '최고 관리자만 접근할 수 있습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$err = static function (string $msg, int $code = 422): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
};

$method  = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$actorId = (int) ($_SESSION['admin_id'] ?? 0);

if ($method === 'GET') {
    try {
        $rows = AdminAccount::listAll();
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
            $err('관리자 ID가 없습니다.');
        }
        $active = filter_var($body['active'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($active === null) {
            $err('active 값이 올바르지 않습니다.');
        }
        $row = AdminAccount::setActive($id, $active, $actorId);
        AuditLog::record(
            $active ? 'admin.activate' : 'admin.deactivate',
            (string) $id,
            ($active ? '관리자 활성화 · ' : '관리자 비활성화 · ') . (string) $row['login_id']
        );
        echo json_encode(['ok' => true, 'message' => '상태가 변경되었습니다.', 'row' => $row], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action !== 'save') {
        $err('action=save 또는 toggle_active', 400);
    }

    $editId = (int) ($body['id'] ?? 0);
    if ($editId > 0) {
        $row = AdminAccount::update($editId, $body, $actorId);
        AuditLog::record(
            'admin.update',
            (string) $editId,
            '관리자 수정 · ' . (string) $row['login_id'] . ' · ' . (string) $row['role_label']
        );
        echo json_encode(['ok' => true, 'message' => '저장되었습니다.', 'row' => $row], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $row = AdminAccount::create($body);
    AuditLog::record(
        'admin.create',
        (string) $row['id'],
        '관리자 추가 · ' . (string) $row['login_id'] . ' · ' . (string) $row['role_label']
    );
    echo json_encode(['ok' => true, 'message' => '관리자가 추가되었습니다.', 'row' => $row], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    $err($e->getMessage());
} catch (Throwable $e) {
    $err('처리 실패: ' . $e->getMessage(), 500);
}
