<?php

declare(strict_types=1);

define('INC_PATH', __DIR__);
define('ROOT_PATH', dirname(INC_PATH));

if (!defined('ADMIN_BASE')) {
    $env = getenv('ADMIN_BASE');
    if (is_string($env) && $env !== '') {
        define('ADMIN_BASE', rtrim(str_replace('\\', '/', $env), '/'));
    } else {
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $dir = str_replace('\\', '/', dirname($script));
        if (basename($dir) === 'admin') {
            define('ADMIN_BASE', $dir === '/' || $dir === '' ? '/admin' : rtrim($dir, '/'));
        } else {
            define('ADMIN_BASE', '/admin');
        }
    }
}

if (!defined('RIDER_BASE')) {
    $envR = getenv('RIDER_BASE');
    if (is_string($envR) && $envR !== '') {
        define('RIDER_BASE', rtrim(str_replace('\\', '/', $envR), '/'));
    } else {
        $scriptR = $_SERVER['SCRIPT_NAME'] ?? '';
        $dirR = str_replace('\\', '/', dirname($scriptR));
        if (basename($dirR) === 'rider') {
            define('RIDER_BASE', ($dirR === '/' || $dirR === '' || $dirR === '.') ? '/rider' : rtrim($dirR, '/'));
        } else {
            define('RIDER_BASE', '/rider');
        }
    }
}

/**
 * 경로 대신 index.php?route=… URL 사용 (Apache rewrite 없이 동작).
 * 플래그: inc/use_query_url.flag(공통) 또는 rider_use_query_url / admin_use_query_url
 * (PHP-FPM이면 SetEnv 만으로는 getenv에 안 올 수 있음 → flag 권장)
 */
if (!defined('RIDER_USE_QUERY_URL') || !defined('ADMIN_USE_QUERY_URL')) {
    $truthy = static function ($v): bool {
        if ($v === '' || $v === false) {
            return false;
        }
        $s = (string) $v;

        return $s === '1' || strcasecmp($s, 'true') === 0 || strcasecmp($s, 'yes') === 0;
    };
    $fromEnv = static function (string $name) use ($truthy): bool {
        foreach (
            [
                $_SERVER[$name] ?? '',
                $_SERVER['REDIRECT_' . $name] ?? '',
                getenv($name) ?: '',
            ] as $rq
        ) {
            if ($truthy($rq)) {
                return true;
            }
        }

        return false;
    };

    $fileCommon = is_file(INC_PATH . '/use_query_url.flag');
    $fileRider = $fileCommon || is_file(INC_PATH . '/rider_use_query_url.flag');
    $fileAdmin = $fileCommon || is_file(INC_PATH . '/admin_use_query_url.flag') || is_file(INC_PATH . '/rider_use_query_url.flag');

    if (!defined('RIDER_USE_QUERY_URL')) {
        define('RIDER_USE_QUERY_URL', $fileRider || $fromEnv('RIDER_USE_QUERY_URL'));
    }
    if (!defined('ADMIN_USE_QUERY_URL')) {
        define('ADMIN_USE_QUERY_URL', $fileAdmin || $fromEnv('ADMIN_USE_QUERY_URL'));
    }
}

$composerAutoload = ROOT_PATH . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

require_once INC_PATH . '/env.php';
require_once INC_PATH . '/helpers.php';
require_once INC_PATH . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once INC_PATH . '/auth.php';
require_once INC_PATH . '/Org.php';
