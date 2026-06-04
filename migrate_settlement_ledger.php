<?php

declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

$sqlFile = __DIR__ . '/sql/settlement_ledger.sql';
if (!is_file($sqlFile)) {
    echo "ERROR sql/settlement_ledger.sql 없음\n";
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
        if (preg_match('/CREATE TABLE IF NOT EXISTS `?(\w+)`?/i', $stmt, $m)) {
            echo "OK    {$m[1]}\n";
        }
    } catch (Throwable $e) {
        echo 'ERROR → ' . $e->getMessage() . "\n";
        exit(1);
    }
}

echo "\n완료.\n";
