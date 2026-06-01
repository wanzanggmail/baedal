<?php

declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

echo 'DB: ' . DB_HOST . '/' . DB_NAME . "\n\n";

try {
    $tables = db_rows("SHOW TABLES LIKE 'content_%'");
    echo "content_* tables: " . count($tables) . "\n";
    foreach ($tables as $t) {
        echo '  - ' . implode('', $t) . "\n";
    }
} catch (Throwable $e) {
    echo 'TABLE CHECK ERROR: ' . $e->getMessage() . "\n";
    exit(1);
}

foreach (['content_notices', 'content_banners'] as $table) {
    try {
        $c = (int) (db_row("SELECT COUNT(*) AS c FROM {$table}")['c'] ?? 0);
        echo "\n{$table}: {$c} rows\n";
        if ($c > 0) {
            $sample = db_rows("SELECT * FROM {$table} ORDER BY id DESC LIMIT 5");
            foreach ($sample as $row) {
                $id = $row['public_id'] ?? $row['id'];
                $title = $row['title'] ?? '';
                $st = $row['status'] ?? '';
                echo "  · {$id} | {$title} | {$st}\n";
            }
        }
    } catch (Throwable $e) {
        echo "{$table}: ERROR " . $e->getMessage() . "\n";
    }
}

$activeBanners = (int) (db_row(
    "SELECT COUNT(*) AS c FROM content_banners
     WHERE status = 'active'
       AND slot = 'rider_app'
       AND (start_at IS NULL OR start_at <= CURDATE())
       AND (end_at IS NULL OR end_at >= CURDATE())"
)['c'] ?? 0);
echo "\nrider_app active banners (home): {$activeBanners}\n";

$pubNotices = (int) (db_row(
    "SELECT COUNT(*) AS c FROM content_notices WHERE status = 'published'"
)['c'] ?? 0);
echo "published notices: {$pubNotices}\n";
