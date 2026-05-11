<?php

declare(strict_types=1);

const RIDER_COOKIE_NAME = 'baedal_rider_rm';
const RIDER_COOKIE_LIFETIME = 60 * 60 * 24 * 30;

function rider_cookie_secret(): string
{
    static $s = null;
    if ($s === null) {
        $s = hash('sha256', 'baedal-rider-rm-' . ROOT_PATH);
    }

    return $s;
}

function rider_display_name(string $loginId): string
{
    $loginId = trim($loginId);
    if ($loginId === '') {
        return '라이더';
    }
    if (preg_match('/^010[-\d]{8,}$/', $loginId)) {
        return '라이더 ' . substr(preg_replace('/\D/', '', $loginId), -4);
    }

    return $loginId;
}

function rider_set_session_user(string $loginId): void
{
    $loginId = trim($loginId);
    $_SESSION['rider'] = [
        'login_id' => $loginId,
        'name' => rider_display_name($loginId),
    ];
}

function rider_sign_remember(string $loginId, int $exp): string
{
    return hash_hmac('sha256', $loginId . '|' . $exp, rider_cookie_secret());
}

function rider_cookie_path(): string
{
    $p = RIDER_BASE;
    if ($p === '' || $p === '/') {
        return '/rider';
    }

    return $p;
}

function rider_set_remember_cookie(string $loginId): void
{
    $exp = time() + RIDER_COOKIE_LIFETIME;
    $sig = rider_sign_remember($loginId, $exp);
    $payload = base64_encode(json_encode(['u' => $loginId, 'e' => $exp, 's' => $sig], JSON_THROW_ON_ERROR));
    setcookie(RIDER_COOKIE_NAME, $payload, [
        'expires' => $exp,
        'path' => rider_cookie_path(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function rider_clear_remember_cookie(): void
{
    setcookie(RIDER_COOKIE_NAME, '', [
        'expires' => time() - 3600,
        'path' => rider_cookie_path(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function rider_try_cookie_restore(): void
{
    if (!empty($_SESSION['rider']['login_id'])) {
        return;
    }
    if (empty($_COOKIE[RIDER_COOKIE_NAME]) || !is_string($_COOKIE[RIDER_COOKIE_NAME])) {
        return;
    }
    $json = base64_decode($_COOKIE[RIDER_COOKIE_NAME], true);
    if ($json === false) {
        rider_clear_remember_cookie();

        return;
    }
    try {
        $raw = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        rider_clear_remember_cookie();

        return;
    }
    if (!is_array($raw) || empty($raw['u']) || empty($raw['e']) || empty($raw['s'])) {
        rider_clear_remember_cookie();

        return;
    }
    $exp = (int) $raw['e'];
    $uid = (string) $raw['u'];
    if ($exp < time()) {
        rider_clear_remember_cookie();

        return;
    }
    $expect = rider_sign_remember($uid, $exp);
    if (!hash_equals($expect, (string) $raw['s'])) {
        rider_clear_remember_cookie();

        return;
    }
    rider_set_session_user($uid);
}

function rider_auth_bootstrap(): void
{
    rider_try_cookie_restore();
}

function rider_is_logged_in(): bool
{
    return !empty($_SESSION['rider']['login_id']);
}

/** @return array{login_id: string, name: string}|null */
function rider_current_user(): ?array
{
    if (!rider_is_logged_in()) {
        return null;
    }

    return [
        'login_id' => (string) $_SESSION['rider']['login_id'],
        'name' => (string) ($_SESSION['rider']['name'] ?? rider_display_name((string) $_SESSION['rider']['login_id'])),
    ];
}

function rider_logout(): void
{
    unset($_SESSION['rider']);
    rider_clear_remember_cookie();
}

/** @param array<string, array{title: string, view: string}> $routes */
function rider_safe_next_route(string $next, array $routes): string
{
    $next = trim($next, '/');
    if ($next === '' || $next === 'login' || $next === 'logout') {
        return 'home';
    }

    return isset($routes[$next]) ? $next : 'home';
}
