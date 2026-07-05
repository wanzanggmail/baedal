<?php

declare(strict_types=1);

/**
 * 정산 엑셀 복호화 테스트 (관리자)
 * POST multipart: file, platform, excel_password (선택)
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/XlsxDecrypt.php';
require_once INC_PATH . '/SettlementExcelConfig.php';

header('Content-Type: application/json; charset=utf-8');

if (!admin_is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => '인증이 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!admin_can_access_route('settlement/upload')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => '권한이 없습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'POST only'], JSON_UNESCAPED_UNICODE);
    exit;
}

$platform = trim((string) ($_POST['platform'] ?? 'baemin'));
if (!in_array($platform, ['baemin', 'coupang', 'other'], true)) {
    $platform = 'baemin';
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'message' => '파일 업로드 실패'], JSON_UNESCAPED_UNICODE);
    exit;
}

$tmpPath = (string) ($_FILES['file']['tmp_name'] ?? '');
$uploadPassword = SettlementExcelConfig::normalizePassword((string) ($_POST['excel_password'] ?? ''));

// 멀티테넌시: 대리점=자기 암호 우선 / 그 외=전역
require_once INC_PATH . '/Org.php';
$testOrgId = admin_org_level() === Org::LEVEL_AGENCY ? admin_org_id() : null;

$passwords = SettlementExcelConfig::passwordsToTry(
    $platform,
    $uploadPassword !== '' ? $uploadPassword : null,
    $testOrgId
);

$storedMeta = SettlementExcelConfig::storedPasswordMeta($platform, $testOrgId);

try {
    $test = XlsxDecrypt::testDecrypt($tmpPath, $passwords);
    echo json_encode([
        'ok'              => $test['success'],
        'platform'        => $platform,
        'stored_password' => $storedMeta,
        'upload_password_len' => strlen($uploadPassword),
        'password_candidates' => count($passwords),
        'candidate_lengths'   => array_map(static fn (string $p): int => strlen($p), $passwords),
        'test'            => $test,
        'hint'            => $test['success']
            ? '복호화 성공 — 업로드도 동일 조건이면 됩니다.'
            : 'stored_password.length 가 10이 아니면 DB 저장 문제. decrypt_error 는 msoffcrypto가 거부한 것입니다.',
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
