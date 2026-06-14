<?php

declare(strict_types=1);

/**
 * 프로젝트 루트 .env 로드 (getenv / $_SERVER / $_ENV)
 * bootstrap.php에서 db.php보다 먼저 require 합니다.
 */
function load_dotenv(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }

        $key = trim(substr($line, 0, $pos));
        if ($key === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
            continue;
        }

        $value = trim(substr($line, $pos + 1));
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        // 이미 Apache SetEnv 등으로 설정된 값은 덮어쓰지 않음
        $existing = getenv($key);
        if ($existing !== false && $existing !== '') {
            continue;
        }

        putenv("{$key}={$value}");
        $_ENV[$key]    = $value;
        $_SERVER[$key] = $value;
    }
}

load_dotenv(dirname(__DIR__) . '/.env');
