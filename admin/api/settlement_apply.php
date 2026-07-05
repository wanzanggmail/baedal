<?php

declare(strict_types=1);

/**
 * 정산 반영 API — 업로드 → 라이더별 수수료·지갑 적립
 * POST { "upload_id": n }
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/SettlementLedger.php';
require_once INC_PATH . '/AuditLog.php';

header('Content-Type: application/json; charset=utf-8');

if (!admin_is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => '인증이 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'POST만 허용'], JSON_UNESCAPED_UNICODE);
    exit;
}

admin_deny_write_json('settlement');

$raw  = file_get_contents('php://input');
$ct   = $_SERVER['CONTENT_TYPE'] ?? '';
$body = str_contains($ct, 'application/json')
    ? (array) json_decode($raw ?: '{}', true)
    : $_POST;

$uploadId = (int) ($body['upload_id'] ?? 0);
if ($uploadId < 1) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'upload_id가 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 멀티테넌시: 업로드 소유 대리점 스코프 밖이면 차단
require_once INC_PATH . '/Org.php';
$uploadRow = db_row('SELECT agency_id FROM settlement_uploads WHERE id = ? LIMIT 1', [$uploadId]);
if ($uploadRow === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => '업로드를 찾을 수 없습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!Org::canAccessAgency((int) ($uploadRow['agency_id'] ?? 0))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => '이 업로드에 접근할 권한이 없습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $adminId = (int) ($_SESSION['admin_id'] ?? 0);
    $result  = SettlementLedger::applyUpload($uploadId, $adminId > 0 ? $adminId : null);

    if ($result['applied'] === 0 && $result['errors'] !== []) {
        throw new InvalidArgumentException(implode(' / ', $result['errors']));
    }

    AuditLog::record(
        'settlement.apply',
        (string) $uploadId,
        "정산 반영 {$result['applied']}명 · 건너뜀 {$result['skipped']}명"
    );

    echo json_encode([
        'ok'      => true,
        'message' => "정산 반영 {$result['applied']}명 완료" . ($result['skipped'] > 0 ? " (건너뜀 {$result['skipped']}명)" : ''),
        'result'  => $result,
    ], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => '처리 실패: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
