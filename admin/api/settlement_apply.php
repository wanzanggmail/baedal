<?php

declare(strict_types=1);

/**
 * 정산 반영 API — 업로드 → 라이더별 수수료·지갑 적립
 * POST { "upload_id": n }  →  { ok, job_id }
 *
 * 실제 처리는 백그라운드 작업(SettlementApplyJob)이 한다. 화면은 job_id 로
 * `settlement_apply_status.php` 를 폴링해 라이더별 결제·이체 상태를 받아 간다.
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/SettlementApplyJob.php';

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
    $job     = SettlementApplyJob::start($uploadId, $adminId > 0 ? $adminId : null);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => '처리 실패: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

// 세션 파일 잠금을 먼저 푼다 — 안 풀면 팝업의 상태 조회 요청이 이 요청이 끝날 때까지 줄 서서 기다린다.
session_write_close();

// 응답을 먼저 보내고 연결을 끊는다. 팝업은 곧바로 진행 상황 조회를 시작한다.
$payload = json_encode(['ok' => true, 'job_id' => $job['job_id'], 'reused' => $job['reused']], JSON_UNESCAPED_UNICODE);
ignore_user_abort(true);
header('Connection: close');
header('Content-Length: ' . strlen((string) $payload));
echo $payload;
while (ob_get_level() > 0) {
    ob_end_flush();
}
flush();
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

// 이미 진행 중인 작업을 다시 보여주는 경우엔 또 띄우지 않는다.
if (!$job['reused']) {
    SettlementApplyJob::dispatch($job['job_id']);
}
