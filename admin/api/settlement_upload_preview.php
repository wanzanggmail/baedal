<?php

declare(strict_types=1);

/**
 * 정산 엑셀 업로드 전 미리보기 (플랫폼 자동 감지)
 * POST /admin/api/settlement_upload_preview.php
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/SettlementUploadInspect.php';

header('Content-Type: application/json; charset=utf-8');

admin_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST 요청만 허용됩니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!admin_can_access_route('settlement/upload')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => '권한이 없습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $errCode = $_FILES['file']['error'] ?? 'N/A';
    echo json_encode(['ok' => false, 'error' => "파일 업로드 실패 (코드: {$errCode})"], JSON_UNESCAPED_UNICODE);
    exit;
}

$origName = (string) ($_FILES['file']['name'] ?? 'upload.xlsx');
$tmpPath  = (string) ($_FILES['file']['tmp_name'] ?? '');
$platform = trim((string) ($_POST['platform'] ?? ''));

$result = settlement_upload_inspect(
    $tmpPath,
    $origName,
    $platform !== '' ? $platform : null,
    (string) ($_POST['excel_password'] ?? '')
);

echo json_encode($result, JSON_UNESCAPED_UNICODE);
