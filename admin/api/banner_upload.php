<?php

declare(strict_types=1);

/**
 * 광고 배너 이미지 업로드
 * POST multipart/form-data  field: image
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/BannerUpload.php';

header('Content-Type: application/json; charset=utf-8');

if (!admin_is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => '인증이 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'POST만 허용됩니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

admin_deny_write_json('content');

try {
    $file = $_FILES['image'] ?? $_FILES['file'] ?? null;
    if (!is_array($file)) {
        throw new InvalidArgumentException('이미지 파일을 선택하세요.');
    }
    $stored = BannerUpload::storeFromUpload($file);
    echo json_encode([
        'ok'         => true,
        'message'    => '업로드되었습니다.',
        'image_url'  => $stored['path'],
        'image_src'  => $stored['src'],
        'filename'   => $stored['filename'],
    ], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => '업로드 실패: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
