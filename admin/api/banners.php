<?php

declare(strict_types=1);

/**
 * 광고 배너 API
 * GET  — 목록
 * POST { "action": "save", ... } | { "action": "delete", "id": n }
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/Banner.php';

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
$adminId = (int) ($_SESSION['admin_id'] ?? 0);

if ($method === 'GET') {
    try {
        $rows = Banner::listAdmin();
        echo json_encode(['ok' => true, 'rows' => $rows, 'count' => count($rows)], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        $err('목록 조회 실패: ' . $e->getMessage(), 500);
    }
    exit;
}

if ($method === 'POST') {
    $raw  = file_get_contents('php://input');
    $ct   = $_SERVER['CONTENT_TYPE'] ?? '';
    $body = str_contains($ct, 'application/json')
        ? (array) json_decode($raw ?: '{}', true)
        : $_POST;

    $action = trim((string) ($body['action'] ?? 'save'));

    try {
        if ($action === 'delete') {
            $id = Banner::parseId($body['id'] ?? $body['banner_id'] ?? null);
            if (!$id) {
                $err('삭제할 광고 ID가 없습니다.');
            }
            Banner::delete($id);
            echo json_encode(['ok' => true, 'message' => '삭제되었습니다.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action !== 'save') {
            $err('action=save 또는 delete', 400);
        }

        $row = Banner::save($body, $adminId > 0 ? $adminId : null);
        echo json_encode([
            'ok'      => true,
            'message' => '저장되었습니다.',
            'row'     => $row,
        ], JSON_UNESCAPED_UNICODE);
    } catch (InvalidArgumentException $e) {
        $err($e->getMessage());
    } catch (Throwable $e) {
        $err('처리 실패: ' . $e->getMessage(), 500);
    }
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'message' => '허용되지 않은 메서드입니다.'], JSON_UNESCAPED_UNICODE);
