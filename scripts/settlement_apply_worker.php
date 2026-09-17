<?php

declare(strict_types=1);

/**
 * 정산 반영 백그라운드 워커 — SettlementApplyJob::dispatch() 가 띄운다. 사람이 직접 돌릴 일은 없다.
 *
 *   php scripts/settlement_apply_worker.php <job_id>
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once INC_PATH . '/SettlementApplyJob.php';

$jobId = (int) ($argv[1] ?? 0);
if ($jobId < 1) {
    fwrite(STDERR, "사용법: php scripts/settlement_apply_worker.php <job_id>\n");
    exit(2);
}

ignore_user_abort(true);
set_time_limit(0);

SettlementApplyJob::run($jobId);
