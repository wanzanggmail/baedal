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
 * 배포 경로 접두사 (/baedal 등). 라이더·관리자 URL 모두에서 동일한 /assets·/uploads 를 씁니다.
 */
function web_app_deploy_base(): string
{
    $bases = [];
    if (defined('RIDER_BASE')) {
        $bases[] = RIDER_BASE;
    }
    if (defined('ADMIN_BASE')) {
        $bases[] = ADMIN_BASE;
    }
    foreach ($bases as $base) {
        $base = rtrim(str_replace('\\', '/', (string) $base), '/');
        if ($base === '' || $base === '/admin' || $base === '/rider') {
            continue;
        }
        $parent = str_replace('\\', '/', dirname($base));
        if ($parent !== '/' && $parent !== '.' && $parent !== '' && $parent !== '\\') {
            return rtrim($parent, '/');
        }
    }

    return '';
}

/**
 * 웹 루트 기준 정적 파일(/assets/…) 접두사.
 */
function web_assets_base(): string
{
    $deploy = web_app_deploy_base();

    return ($deploy !== '' ? $deploy : '') . '/assets';
}

/** $relativePath: media/logos/favicon.ico 처럼 assets/ 다음 구간 */
function web_asset(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');

    return web_assets_base() . '/' . $relativePath;
}

/** 업로드 파일 웹 경로 접두사 (/uploads 또는 /baedal/uploads) */
function web_uploads_base(): string
{
    $deploy = web_app_deploy_base();

    return ($deploy !== '' ? $deploy : '') . '/uploads';
}

/** DB 저장 경로(/uploads/banners/…) → 브라우저 URL */
function web_upload_url(string $storedPath): string
{
    $storedPath = '/' . ltrim(str_replace('\\', '/', $storedPath), '/');
    if (!str_starts_with($storedPath, '/uploads/')) {
        return $storedPath;
    }

    return web_uploads_base() . substr($storedPath, strlen('/uploads'));
}

/**
 * 현재 HTTP 요청 기준 오리진 (예: https://example.com).
 * SSL 종단이 앞단(로드밸런서)인 경우 X-Forwarded-Proto / Host 를 반영해야
 * manifest 아이콘·start_url 이 http 로 나가며 PWA 설치가 막히지 않습니다.
 */
function web_request_origin(): string
{
    $proto = 'http';
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $first = strtolower(trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));
        if ($first === 'https') {
            $proto = 'https';
        }
    } elseif (!empty($_SERVER['HTTPS']) && (string) $_SERVER['HTTPS'] !== 'off') {
        $proto = 'https';
    } elseif (!empty($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443') {
        $proto = 'https';
    } elseif (!empty($_SERVER['REQUEST_SCHEME']) && strtolower((string) $_SERVER['REQUEST_SCHEME']) === 'https') {
        $proto = 'https';
    }

    $host = '';
    if (!empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
        $host = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_HOST'])[0]);
    } elseif (!empty($_SERVER['HTTP_HOST'])) {
        $host = (string) $_SERVER['HTTP_HOST'];
    }
    $host = trim($host);
    if ($host === '') {
        $host = 'localhost';
    }

    return $proto . '://' . $host;
}

/** /path?query 형태 또는 이미 절대 URL → https? 절대 URL */
function web_absolute_url(string $pathOrAbsolute): string
{
    $pathOrAbsolute = str_replace('\\', '/', $pathOrAbsolute);
    if (preg_match('#^https?://#i', $pathOrAbsolute)) {
        return $pathOrAbsolute;
    }
    $pathOrAbsolute = '/' . ltrim($pathOrAbsolute, '/');

    return web_request_origin() . $pathOrAbsolute;
}

/** 브라우저 탭 아이콘 — assets/media/favicon 우선 */
function web_favicon_shortcut_href(): string
{
    $dir = ROOT_PATH . '/assets/media/favicon';
    if (is_file($dir . '/favicon.ico')) {
        return web_asset('media/favicon/favicon.ico');
    }

    return web_asset('media/logos/favicon.ico');
}

/** iOS 홈 화면 — 파일이 있을 때만 */
function web_favicon_apple_touch_href(): ?string
{
    $dir = ROOT_PATH . '/assets/media/favicon';
    $file = 'apple-icon-180x180.png';
    if (is_file($dir . '/' . $file)) {
        return web_asset('media/favicon/' . $file);
    }

    return null;
}

/**
 * Web App Manifest icons[] — PNG 세트가 있으면 사용, 없으면 favicon.ico / 로고 폴백
 *
 * @return list<array{src: string, sizes: string, type: string, purpose?: string}>
 */
function web_pwa_icons_from_favicon_dir(): array
{
    $faviconDir = ROOT_PATH . '/assets/media/favicon';
    $specs = [
        ['file' => 'android-icon-512x512.png', 'sizes' => '512x512'],
        ['file' => 'android-icon-192x192.png', 'sizes' => '192x192'],
        ['file' => 'android-icon-144x144.png', 'sizes' => '144x144'],
        ['file' => 'android-icon-96x96.png', 'sizes' => '96x96'],
        ['file' => 'android-icon-72x72.png', 'sizes' => '72x72'],
        ['file' => 'android-icon-48x48.png', 'sizes' => '48x48'],
        ['file' => 'android-icon-36x36.png', 'sizes' => '36x36'],
    ];
    $icons = [];
    foreach ($specs as $spec) {
        if (!is_file($faviconDir . '/' . $spec['file'])) {
            continue;
        }
        $icons[] = [
            'src' => web_asset('media/favicon/' . $spec['file']),
            'sizes' => $spec['sizes'],
            'type' => 'image/png',
            'purpose' => 'any',
        ];
    }
    if ($icons !== []) {
        return $icons;
    }
    if (is_file($faviconDir . '/favicon.ico')) {
        return [
            [
                'src' => web_asset('media/favicon/favicon.ico'),
                'sizes' => '48x48',
                'type' => 'image/x-icon',
                'purpose' => 'any',
            ],
        ];
    }

    return [
        [
            'src' => web_asset('media/logos/favicon.ico'),
            'sizes' => '48x48',
            'type' => 'image/x-icon',
            'purpose' => 'any',
        ],
    ];
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

/** 라이더 PWA Service Worker 스크립트 URL (물리 파일 rider/service-worker.js) */
function rider_pwa_service_worker_url(): string
{
    return rtrim(RIDER_BASE, '/') . '/service-worker.js';
}

/**
 * 라이더(PWA) 화면 URL — /rider/home 형태
 */
/** 라이더 공지 상세 URL (?id= DB id) */
function rider_notice_detail_url(int $id): string
{
    $base = rider_url('notices/detail');
    $sep  = str_contains($base, '?') ? '&' : '?';

    return $base . $sep . 'id=' . rawurlencode((string) $id);
}

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

/**
 * 메뉴 그룹이 접두사 하나로 안 떨어질 때 쓰는 버전 — 라우트를 직접 나열한다.
 * (예: 「정산」과 「수수료·채권」이 둘 다 `settlement/`로 시작해서 접두사로는 구분이 안 된다.)
 *
 * @param list<string> $routes
 */
function nav_accordion_show_any(array $routes): string
{
    global $route;

    return in_array($route ?? '', $routes, true) ? ' show' : '';
}

/** 여러 라우트 중 하나면 활성 (목록↔상세처럼 한 메뉴가 여러 라우트를 대표할 때) */
function nav_active_any(array $routes): string
{
    global $route;

    return in_array($route ?? '', $routes, true) ? ' active' : '';
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

/**
 * 한글 조합형(NFD) → 완성형(NFC) 정규화.
 *
 * 정산서 파일명·시트에서 뽑은 팀/지역명이 업로드 환경(macOS=NFD, Windows=NFC)에 따라
 * **눈에는 같은데 바이트가 다른** 문자열로 들어온다. 이대로 두면 팀지역 UNIQUE 키가
 * 같은 팀지역을 다른 값으로 취급해 중복 정산 사이클이 생긴다(2026-08-04 실데이터에서 발견).
 *
 * intl 확장(Normalizer)이 없는 환경이라, 한글 음절은 알고리즘으로 합성 가능하다는 점을
 * 이용해 직접 구현한다(한글 외 문자는 그대로 통과 — 팀/지역명 용도로는 충분).
 */
function normalize_hangul_nfc(string $s): string
{
    if ($s === '' || !preg_match('/[\x{1100}-\x{11FF}]/u', $s)) {
        return $s;
    }

    $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $n     = count($chars);
    $out   = [];

    for ($i = 0; $i < $n; $i++) {
        $lIndex = mb_ord($chars[$i], 'UTF-8') - 0x1100;      // 초성
        if ($lIndex >= 0 && $lIndex < 19 && $i + 1 < $n) {
            $vIndex = mb_ord($chars[$i + 1], 'UTF-8') - 0x1161; // 중성
            if ($vIndex >= 0 && $vIndex < 21) {
                $tIndex = 0;
                if ($i + 2 < $n) {
                    $cand = mb_ord($chars[$i + 2], 'UTF-8') - 0x11A7; // 종성(0은 없음)
                    if ($cand > 0 && $cand < 28) {
                        $tIndex = $cand;
                        $i++;
                    }
                }
                $out[] = mb_chr(0xAC00 + ($lIndex * 21 + $vIndex) * 28 + $tIndex, 'UTF-8');
                $i++;
                continue;
            }
        }
        $out[] = $chars[$i];
    }

    return implode('', $out);
}

/**
 * 대시보드 기간 선택(daterangepicker) — $_GET[from/to]를 검증해 유효 기간을 반환.
 * 없거나 형식이 틀리면 "이번 주"(월요일~오늘) 기본값. from > to 면 서로 바꾼다.
 *
 * @return array{from: string, to: string}
 */
function dashboard_period_from_get(): array
{
    $isDate = static fn (string $s): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && strtotime($s) !== false;

    $from = trim((string) ($_GET['from'] ?? ''));
    $to   = trim((string) ($_GET['to'] ?? ''));

    if (!$isDate($from)) {
        $from = date('Y-m-d', strtotime('monday this week'));
    }
    if (!$isDate($to)) {
        $to = date('Y-m-d');
    }
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }

    return ['from' => $from, 'to' => $to];
}

/**
 * 라이더 식별용 보조 표기 — 휴대전화 가운데 마스킹.
 *
 * 라이더 코드(rider_code)는 내부 코드성 데이터라 대리점이 알 수 없어 화면에 쓰지 않는다.
 * 대신 동명이인(현재 5쌍)을 구분해야 하는 자리에 이 값을 쓴다.
 */
function rider_phone_hint(?string $phone): string
{
    $d = preg_replace('/\D/', '', (string) $phone);
    if ($d === '') {
        return '';
    }

    return preg_replace('/(\d{3})\d{4}(\d{4})/', '$1-****-$2', $d) ?? $d;
}
