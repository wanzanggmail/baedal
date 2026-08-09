<?php

declare(strict_types=1);

require __DIR__ . '/inc/env.php';
require __DIR__ . '/inc/db.php';

$t0 = microtime(true);
db();
echo 'connect: ' . round((microtime(true) - $t0) * 1000) . "ms\n";

for ($i = 0; $i < 5; $i++) {
    $t1 = microtime(true);
    db_row(
        'SELECT 1 AS ok FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = ?
          LIMIT 1',
        ['agency_wallet_ledger']
    );
    echo 'info_schema #' . ($i + 1) . ': ' . round((microtime(true) - $t1) * 1000) . "ms\n";
}

$t1 = microtime(true);
$c = db_row('SELECT COUNT(*) AS c FROM agency_wallet_ledger');
echo 'ledger count=' . ($c['c'] ?? '?') . ' in ' . round((microtime(true) - $t1) * 1000) . "ms\n";

$from = date('Y-m-d', strtotime('-30 days')) . ' 00:00:00';
$toEx = date('Y-m-d', strtotime('+1 day')) . ' 00:00:00';
$t1 = microtime(true);
$s = db_row(
    "SELECT COUNT(*) AS cnt,
            COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE 0 END), 0) AS credit
       FROM agency_wallet_ledger
      WHERE created_at >= ? AND created_at < ?",
    [$from, $toEx]
);
echo 'sum 30d cnt=' . ($s['cnt'] ?? '?') . ' in ' . round((microtime(true) - $t1) * 1000) . "ms\n";

$t1 = microtime(true);
$rows = db_rows(
    'SELECT l.id
       FROM agency_wallet_ledger l
       INNER JOIN organizations o ON o.id = l.agency_id
       LEFT JOIN admins a ON a.id = l.created_by
      ORDER BY l.id DESC
      LIMIT 500'
);
echo 'list 500 join: ' . count($rows) . ' rows in ' . round((microtime(true) - $t1) * 1000) . "ms\n";
echo 'TOTAL: ' . round((microtime(true) - $t0) * 1000) . "ms\n";
