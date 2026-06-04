<?php

/**
 * 감사 로그 테이블 확인 (이미 운영 스키마로 생성돼 있으면 SKIP)
 * 실행: php migrate_audit.php
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once INC_PATH . '/AuditLog.php';

header('Content-Type: text/plain; charset=utf-8');

if (AuditLog::tableExists()) {
    $cnt = (int) (db_row('SELECT COUNT(*) AS c FROM audit_logs')['c'] ?? 0);
    echo "SKIP  audit_logs 테이블 이미 존재 ({$cnt}건)\n";
    echo "코드는 actor_type / target_table / before·after JSON 스키마를 사용합니다.\n";
    exit(0);
}

$sqlFile = __DIR__ . '/sql/audit_tables.sql';
if (!is_file($sqlFile)) {
    echo "ERROR: sql/audit_tables.sql 없음\n";
    exit(1);
}

$sql = file_get_contents($sqlFile);
$parts = preg_split('/;\s*[\r\n]+/', $sql) ?: [];
foreach ($parts as $stmt) {
    $stmt = trim(preg_replace('/--[^\r\n]*/', '', $stmt) ?? '');
    if ($stmt === '') {
        continue;
    }
    try {
        db_execute($stmt);
        echo "OK    audit_logs 테이블 생성\n";
    } catch (Throwable $e) {
        echo 'ERROR SQL → ' . $e->getMessage() . "\n";
        exit(1);
    }
}

echo "완료.\n";
