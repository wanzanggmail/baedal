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

// 멀티테넌시: 미리보기 복호화 시 대리점 암호 우선 (대리점=자기, 그 외=선택 대리점 또는 전역)
require_once INC_PATH . '/Org.php';
if (admin_org_level() === Org::LEVEL_AGENCY) {
    $previewAgencyId = admin_org_id();
} else {
    $previewAgencyId = (int) ($_POST['agency_id'] ?? 0);
    if ($previewAgencyId < 1 || !Org::canAccessAgency($previewAgencyId)) {
        $previewAgencyId = 0;
    }
}

$result = settlement_upload_inspect(
    $tmpPath,
    $origName,
    $platform !== '' ? $platform : null,
    (string) ($_POST['excel_password'] ?? ''),
    $previewAgencyId > 0 ? $previewAgencyId : null
);

echo json_encode($result, JSON_UNESCAPED_UNICODE);
