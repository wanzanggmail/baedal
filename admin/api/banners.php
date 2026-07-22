<?php

declare(strict_types=1);

/**
 * 광고 배너 CRUD API (본사 전용 작성 — content 쓰기 권한)
 * GET  — 배너 목록(스코프)
 * POST { action: 'save',   id?, title, subtitle, link_url, image_url, slot, sort_order, status, start_at, end_at }
 * POST { action: 'delete', id }
 *
 * 재구현 배경: 과거 "디비 복구" 커밋에서 이 파일이 빈 파일로 유실됨(배너 관리 동작 불가). 복구.
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/Org.php';
require_once INC_PATH . '/Banner.php';
require_once INC_PATH . '/AuditLog.php';

header('Content-Type: application/json; charset=utf-8');

if (!admin_is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => '인증이 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$err = static function (string $msg, int $code = 422): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
};

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    if (!db_table_exists('content_banners')) {
        echo json_encode(['ok' => true, 'items' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['ok' => true, 'items' => Banner::listAdmin()], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method !== 'POST') {
    $err('허용되지 않은 메서드입니다.', 405);
}

// 공지·배너 작성은 본사 전용(admin_can_write('content') = HQ operation/super)
admin_deny_write_json('content');

if (!db_table_exists('content_banners')) {
    $err('content_banners 테이블이 없습니다. php migrate.php 를 실행하세요.', 500);
}

$raw  = file_get_contents('php://input');
$ct   = $_SERVER['CONTENT_TYPE'] ?? '';
$body = str_contains($ct, 'application/json') ? (array) json_decode($raw ?: '{}', true) : $_POST;
$action  = trim((string) ($body['action'] ?? 'save'));
$adminId = (int) ($_SESSION['admin_id'] ?? 0);

try {
    if ($action === 'delete') {
        $id = Banner::parseId($body['id'] ?? null);
        if ($id === null) {
            $err('삭제할 광고 ID가 없습니다.', 400);
        }
        Banner::delete($id);
        AuditLog::record('content.banner.delete', (string) $id, '광고 배너 삭제');
        echo json_encode(['ok' => true, 'message' => '삭제되었습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'save') {
        $banner = Banner::save($body, $adminId > 0 ? $adminId : null);
        AuditLog::record(
            'content.banner.save',
            (string) ($banner['public_id'] ?? ($banner['id'] ?? '')),
            sprintf('광고 배너 저장 · %s', (string) ($banner['title'] ?? ''))
        );
        echo json_encode(['ok' => true, 'message' => '저장되었습니다.', 'banner' => $banner], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $err('알 수 없는 action 입니다.', 400);
} catch (InvalidArgumentException $e) {
    $err($e->getMessage(), 422);
} catch (Throwable $e) {
    $err('처리 실패: ' . $e->getMessage(), 500);
}
