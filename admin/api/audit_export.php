<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/AuditLog.php';
require_once INC_PATH . '/download_response.php';

admin_require_login();

if (!AuditLog::tableExists()) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'audit_logs 테이블이 없습니다. migrate_audit.php 를 실행하세요.';
    exit;
}

$csv = AuditLog::csvContent([
    'q'             => $_GET['q'] ?? '',
    'actor'         => $_GET['actor'] ?? '',
    'action_prefix' => $_GET['action_prefix'] ?? '',
]);

AuditLog::record('EXPORT', 'audit_logs', '감사 로그 CSV 내보내기');

send_download_response(
    $csv,
    'audit-log-' . date('Y-m-d') . '.csv',
    'text/csv; charset=utf-8'
);
