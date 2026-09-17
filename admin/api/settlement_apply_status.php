<?php

declare(strict_types=1);

/**
 * 정산 반영 진행 상황 — 팝업이 1~2초마다 호출한다.
 *   GET ?job_id=n             특정 작업
 *   GET ?upload_id=n          이 업로드의 가장 최근 작업(창을 닫았다 다시 열었을 때 이어 보기)
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/SettlementApplyJob.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!admin_is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => '인증이 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}
// 읽기만 한다 — 세션 잠금을 바로 풀어 다른 요청을 막지 않는다.
session_write_close();

if (!SettlementApplyJob::tableExists()) {
    echo json_encode(['ok' => false, 'message' => 'php migrate.php 를 실행하세요.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$jobId    = (int) ($_GET['job_id'] ?? 0);
$uploadId = (int) ($_GET['upload_id'] ?? 0);

$job = $jobId > 0
    ? SettlementApplyJob::find($jobId)
    : ($uploadId > 0
        ? db_row('SELECT * FROM settlement_apply_jobs WHERE upload_id = ? ORDER BY id DESC LIMIT 1', [$uploadId])
        : null);
if ($job === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => '작업을 찾을 수 없습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 멀티테넌시: 업로드 소유 대리점 스코프 밖이면 차단
$agencyId = (int) (db_row('SELECT agency_id FROM settlement_uploads WHERE id = ? LIMIT 1', [(int) $job['upload_id']])['agency_id'] ?? 0);
if (!Org::canAccessAgency($agencyId)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => '이 업로드에 접근할 권한이 없습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true] + SettlementApplyJob::status($job), JSON_UNESCAPED_UNICODE);
