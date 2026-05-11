<?php

declare(strict_types=1);

/**
 * 관리자 화면 URL — /admin/dashboard 처럼 경로만 표시 (admin/.htaccess + mod_rewrite 필요)
 */
function admin_url(string $path = ''): string
{
    $base = ADMIN_BASE;
    $path = trim($path, '/');
    if (defined('ADMIN_USE_QUERY_URL') && ADMIN_USE_QUERY_URL) {
        if ($path === '') {
            return $base . '/';
        }

        return $base . '/index.php?route=' . rawurlencode($path);
    }
    if ($path === '') {
        return $base . '/';
    }
    $segments = array_map('rawurlencode', explode('/', $path));

    return $base . '/' . implode('/', $segments);
}

/** 로그인 — rewrite 시 /admin/login, 쿼리 모드 시 index.php?route=login */
function admin_login_url(): string
{
    if (defined('ADMIN_USE_QUERY_URL') && ADMIN_USE_QUERY_URL) {
        return ADMIN_BASE . '/index.php?route=login';
    }

    return ADMIN_BASE . '/login';
}

function admin_logout_url(): string
{
    return ADMIN_BASE . '/logout.php';
}

/**
 * 웹 루트 기준 정적 파일(/assets/…) 접두사.
 * ADMIN_BASE가 /admin 이면 /assets, /baedal/admin 이면 /baedal/assets.
 * (깊은 가상 경로 /admin/settlement/upload 에서도 CSS·JS가 깨지지 않게 절대 경로로 씀)
 */
function web_assets_base(): string
{
    $admin = rtrim(str_replace('\\', '/', ADMIN_BASE), '/');
    $parent = str_replace('\\', '/', dirname($admin));
    if ($parent === '/' || $parent === '.' || $parent === '' || $parent === '\\') {
        return '/assets';
    }

    return rtrim($parent, '/') . '/assets';
}

/** $relativePath: media/logos/favicon.ico 처럼 assets/ 다음 구간 */
function web_asset(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');

    return web_assets_base() . '/' . $relativePath;
}

/**
 * GET route 파라미터가 없을 때 REQUEST_URI에서 경로 복구 (일부 nginx/리라이트 환경)
 */
function admin_requested_route(): string
{
    if (isset($_GET['route']) && (string) $_GET['route'] !== '') {
        return trim((string) $_GET['route'], '/');
    }
    $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
    $uriPath = str_replace('\\', '/', $uriPath);
    $base = ADMIN_BASE;
    if ($base !== '' && str_starts_with($uriPath, $base)) {
        $rest = trim(substr($uriPath, strlen($base)), '/');
        if ($rest === '' || strcasecmp($rest, 'index.php') === 0) {
            return '';
        }
        if (str_starts_with($rest, 'index.php/')) {
            $rest = trim(substr($rest, 10), '/');
        }
        if (strcasecmp($rest, 'login.php') === 0) {
            return 'login';
        }

        return $rest;
    }

    return '';
}

/**
 * 라이더(PWA) 화면 URL — /rider/home 형태
 */
function rider_url(string $path = ''): string
{
    $base = RIDER_BASE;
    $path = trim($path, '/');
    if (defined('RIDER_USE_QUERY_URL') && RIDER_USE_QUERY_URL) {
        if ($path === '') {
            return $base . '/';
        }

        return $base . '/index.php?route=' . rawurlencode($path);
    }
    if ($path === '') {
        return $base . '/';
    }
    $segments = array_map('rawurlencode', explode('/', $path));

    return $base . '/' . implode('/', $segments);
}

function rider_requested_route(): string
{
    if (isset($_GET['route']) && (string) $_GET['route'] !== '') {
        return trim((string) $_GET['route'], '/');
    }
    $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
    $uriPath = str_replace('\\', '/', $uriPath);
    $base = RIDER_BASE;
    if ($base !== '' && str_starts_with($uriPath, $base)) {
        $rest = trim(substr($uriPath, strlen($base)), '/');
        if ($rest === '' || strcasecmp($rest, 'index.php') === 0) {
            return '';
        }
        if (str_starts_with($rest, 'index.php/')) {
            $rest = trim(substr($rest, 10), '/');
        }

        return $rest;
    }

    return '';
}

function nav_active(string $key): string
{
    global $route;

    return (($route ?? '') === $key) ? ' active' : '';
}

/**
 * 상단 앱 헤더 메가메뉴: 접두사 일치 시 menu-link 에 active (사이드바용 show 와 구분)
 */
function nav_header_menu_active(string $prefix): string
{
    global $route;
    $r = $route ?? '';

    return str_starts_with($r, $prefix) ? ' active' : '';
}

/** 헤더 운영 메뉴 등 복수 접두사 중 하나라도 일치 */
function nav_header_menu_active_any(array $prefixes): string
{
    global $route;
    $r = $route ?? '';
    foreach ($prefixes as $p) {
        if (str_starts_with($r, $p)) {
            return ' active';
        }
    }

    return '';
}

/** 아코디언 메뉴: 해당 접두사 라우트일 때 펼침 */
function nav_accordion_show(string $prefix): string
{
    global $route;
    $r = $route ?? '';

    return str_starts_with($r, $prefix) ? ' show' : '';
}

/** 라이더 앱: 현재 라우트와 일치 */
function rider_nav_active(string $key): string
{
    global $riderRoute;
    $r = $riderRoute ?? '';

    return ($r === $key) ? ' active' : '';
}

/** 라이더 앱: 접두사 일치 시 아코디언 펼침 ($prefix에 trailing slash 없음, 예: notices, settlement, profile) */
function rider_nav_accordion_show(string $prefix): string
{
    global $riderRoute;
    $r = $riderRoute ?? '';
    if ($r === $prefix) {
        return ' show';
    }

    return str_starts_with($r, $prefix . '/') ? ' show' : '';
}
