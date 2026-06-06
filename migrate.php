<?php

declare(strict_types=1);

/**
 * DB 스키마 마이그레이션 (통합)
 * 실행: php migrate.php
 *
 * sql/*.sql 적용 (base_schema → 확장 테이블) + 기존 DB용 ALTER. 초기 관리자·코드는 php seed.php
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once INC_PATH . '/MigrateRunner.php';

header('Content-Type: text/plain; charset=utf-8');

echo "DB migrate: " . DB_NAME . "\n\n";

MigrateRunner::run();
