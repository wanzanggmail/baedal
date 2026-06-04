<?php

declare(strict_types=1);

/**
 * 출금 지갑·설정 테이블 마이그레이션
 * 실행: php migrate_withdrawal_wallet.php
 */

require_once __DIR__ . '/inc/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

$sqlFile = __DIR__ . '/sql/withdrawal_wallet.sql';
if (!is_file($sqlFile)) {
    echo "ERROR sql/withdrawal_wallet.sql 없음\n";
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
        if (stripos($stmt, 'CREATE TABLE') !== false) {
            echo 'OK    ' . (preg_match('/CREATE TABLE IF NOT EXISTS `?(\w+)`?/i', $stmt, $m) ? $m[1] : 'table') . "\n";
        }
    } catch (Throwable $e) {
        echo 'ERROR → ' . $e->getMessage() . "\n";
        exit(1);
    }
}

if (db_table_exists('withdrawal_requests')) {
    $cols = array_column(db_rows('SHOW COLUMNS FROM withdrawal_requests'), 'Field');
    if (!in_array('accrued_days', $cols, true)) {
        try {
            db_execute(
                'ALTER TABLE withdrawal_requests
                 ADD COLUMN accrued_days SMALLINT UNSIGNED NOT NULL DEFAULT 0
                 COMMENT \'신청 시점 적립 일수\' AFTER withhold_round_trim'
            );
            echo "OK    withdrawal_requests.accrued_days\n";
        } catch (Throwable $e) {
            echo 'ERROR accrued_days → ' . $e->getMessage() . "\n";
            exit(1);
        }
    } else {
        echo "SKIP  withdrawal_requests.accrued_days\n";
    }
}

if (db_table_exists('riders') && db_table_exists('rider_wallets')) {
    $missing = (int) (db_row(
        'SELECT COUNT(*) AS c FROM riders r
         LEFT JOIN rider_wallets w ON w.rider_id = r.id
         WHERE w.rider_id IS NULL'
    )['c'] ?? 0);
    if ($missing > 0) {
        db_execute(
            'INSERT INTO rider_wallets (rider_id, balance, accrued_days)
             SELECT r.id, 0, 0 FROM riders r
             LEFT JOIN rider_wallets w ON w.rider_id = r.id
             WHERE w.rider_id IS NULL'
        );
        echo "OK    rider_wallets backfill ({$missing} riders)\n";
    } else {
        echo "SKIP  rider_wallets backfill\n";
    }
}

echo "\n완료.\n";
