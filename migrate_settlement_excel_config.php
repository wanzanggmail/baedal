<?php

declare(strict_types=1);

/**
 * 정산 엑셀 파일 열기 암호 설정 테이블
 * 실행: php migrate_settlement_excel_config.php
 */

require_once __DIR__ . '/inc/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

$sqlFile = __DIR__ . '/sql/settlement_excel_config.sql';
if (!is_file($sqlFile)) {
    echo "ERROR sql/settlement_excel_config.sql 없음\n";
    exit(1);
}

$sql   = file_get_contents($sqlFile);
$parts = preg_split('/;\s*[\r\n]+/', $sql) ?: [];

foreach ($parts as $stmt) {
    $stmt = trim(preg_replace('/--[^\r\n]*/', '', $stmt) ?? '');
    if ($stmt === '') {
        continue;
    }
    try {
        db_execute($stmt);
        if (stripos($stmt, 'CREATE TABLE') !== false) {
            echo 'OK    settlement_excel_config' . "\n";
        } elseif (stripos($stmt, 'INSERT') !== false) {
            echo "OK    settlement_excel_config seed\n";
        }
    } catch (Throwable $e) {
        echo 'ERROR → ' . $e->getMessage() . "\n";
        exit(1);
    }
}

echo "\n완료.\n";
