<?php

/**
 * 출금·자동 일일정산 테이블 마이그레이션
 * 실행: php migrate_daily_settlement.php
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

$sql = file_get_contents(__DIR__ . '/sql/withdrawal_tables.sql');
if ($sql === false || trim($sql) === '') {
    echo "ERROR sql/withdrawal_tables.sql 을 읽을 수 없습니다.\n";
    exit(1);
}

try {
    db_execute($sql);
    echo "OK    withdrawal_requests\n";
} catch (Throwable $e) {
    echo 'ERROR withdrawal_requests → ' . $e->getMessage() . "\n";
    exit(1);
}

echo "\n완료. 자동 일일정산 화면에서 미리보기·반영을 시도하세요.\n";
