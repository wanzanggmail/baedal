<?php

declare(strict_types=1);

/**
 * 시스템 코드 마스터 API (super 전용)
 * GET  ?category=bank  — 카테고리별 목록 (생략 시 전체 그룹)
 * POST { "action": "save", ... } | { "action": "delete", "id": n }
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/SystemCode.php';
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

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    try {
        $category = trim((string) ($_GET['category'] ?? ''));
        if ($category !== '') {
            $rows = SystemCode::listByCategory($category);
            echo json_encode(['ok' => true, 'category' => $category, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $grouped = SystemCode::listGrouped();
        echo json_encode(['ok' => true, 'data' => $grouped], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        $err('목록 조회 실패: ' . $e->getMessage(), 500);
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

$action = trim((string) ($body['action'] ?? 'save'));

try {
    if ($action === 'delete') {
        $id = (int) ($body['id'] ?? 0);
        if ($id < 1) {
            $err('코드 ID가 없습니다.');
        }
        $row = SystemCode::find($id);
        if ($row === null) {
            $err('코드를 찾을 수 없습니다.');
        }
        SystemCode::delete($id);
        AuditLog::record(
            'codes.delete',
            (string) ($row['category'] ?? '') . ':' . (string) ($row['code'] ?? ''),
            '코드 삭제 · ' . (string) ($row['label'] ?? '')
        );
        echo json_encode(['ok' => true, 'message' => '삭제되었습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action !== 'save') {
        $err('action=save 또는 delete', 400);
    }

    $row = SystemCode::save($body);
    $isUpdate = (int) ($body['id'] ?? 0) > 0;
    AuditLog::record(
        $isUpdate ? 'codes.update' : 'codes.create',
        (string) ($row['category'] ?? '') . ':' . (string) ($row['code'] ?? ''),
        ($isUpdate ? '코드 수정 · ' : '코드 추가 · ') . (string) ($row['label'] ?? '')
    );
    echo json_encode(['ok' => true, 'message' => '저장되었습니다.', 'row' => $row], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    $err($e->getMessage());
} catch (Throwable $e) {
    $err('처리 실패: ' . $e->getMessage(), 500);
}
