<?php

declare(strict_types=1);

/**
 * 선공제(대행 수수료) 적립일수·건당 정액 컬럼 마이그레이션
 * 실행: php migrate_agency_fee_config.php
 */

require_once __DIR__ . '/inc/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

$sqlFile = __DIR__ . '/sql/agency_fee_config.sql';
if (!is_file($sqlFile)) {
    echo "ERROR sql/agency_fee_config.sql 없음\n";
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
            echo 'OK    ' . (preg_match('/CREATE TABLE IF NOT EXISTS `?(\w+)`?/i', $stmt, $m) ? $m[1] : 'table') . "\n";
        }
    } catch (Throwable $e) {
        echo 'ERROR → ' . $e->getMessage() . "\n";
        exit(1);
    }
}

if (!db_table_exists('deduction_global_config')) {
    echo "ERROR deduction_global_config 테이블 없음\n";
    exit(1);
}

$cols = array_column(db_rows('SHOW COLUMNS FROM deduction_global_config'), 'Field');
$adds = [
    'agency_fee_day_threshold' => "ADD COLUMN agency_fee_day_threshold SMALLINT UNSIGNED NOT NULL DEFAULT 7 COMMENT '적립 일수 기준' AFTER agency_fee_pct",
    'agency_fee_short'         => "ADD COLUMN agency_fee_short INT NOT NULL DEFAULT 80 COMMENT '기준 미만 건당(원)' AFTER agency_fee_day_threshold",
    'agency_fee_long'          => "ADD COLUMN agency_fee_long INT NOT NULL DEFAULT 40 COMMENT '기준 이상 건당(원)' AFTER agency_fee_short",
];
foreach ($adds as $col => $alter) {
    if (in_array($col, $cols, true)) {
        echo "SKIP  deduction_global_config.{$col}\n";
        continue;
    }
    try {
        db_execute("ALTER TABLE deduction_global_config {$alter}");
        echo "OK    deduction_global_config.{$col}\n";
    } catch (Throwable $e) {
        echo "ERROR {$col} → " . $e->getMessage() . "\n";
        exit(1);
    }
}

$exists = db_row('SELECT id FROM deduction_global_config WHERE id = 1 LIMIT 1');
if ($exists === null) {
    db_insert(
        'INSERT INTO deduction_global_config
            (id, withholding_tax_pct, employment_ins_pct, agency_fee_pct,
             agency_fee_day_threshold, agency_fee_short, agency_fee_long)
         VALUES (1, 3.30, 9.12, 0, 7, 80, 40)'
    );
    echo "OK    deduction_global_config row insert\n";
} else {
    db_execute(
        'UPDATE deduction_global_config
         SET agency_fee_day_threshold = IF(agency_fee_day_threshold = 0, 7, agency_fee_day_threshold),
             agency_fee_short = IF(agency_fee_short IS NULL OR agency_fee_short = 0, 80, agency_fee_short),
             agency_fee_long = IF(agency_fee_long IS NULL OR agency_fee_long = 0, 40, agency_fee_long)
         WHERE id = 1'
    );
    echo "OK    deduction_global_config defaults\n";
}

echo "\n완료.\n";
