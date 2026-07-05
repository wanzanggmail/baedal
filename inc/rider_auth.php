<?php

declare(strict_types=1);

require_once INC_PATH . '/RiderAuth.php';

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

/** @param array<string, mixed> $rider RiderAuth::mapSessionRow() 형식 */
function rider_sign_remember(int $riderId, int $exp): string
{
    return hash_hmac('sha256', $riderId . '|' . $exp, rider_cookie_secret());
}

function rider_cookie_path(): string
{
    $p = RIDER_BASE;
    if ($p === '' || $p === '/') {
        return '/rider';
    }

    return $p;
}

/** @param array<string, mixed> $rider */
function rider_set_session_user(array $rider): void
{
    $_SESSION['rider'] = [
        'id'                  => (int) $rider['id'],
        'rider_code'          => (string) ($rider['rider_code'] ?? ''),
        'login_id'            => (string) ($rider['login_id'] ?? ''),
        'name'                => (string) ($rider['name'] ?? ''),
        'phone'               => (string) ($rider['phone'] ?? ''),
        'email'               => (string) ($rider['email'] ?? ''),
        'status'              => (string) ($rider['status'] ?? 'active'),
        'vehicle_type'        => (string) ($rider['vehicle_type'] ?? ''),
        'is_daily_settlement' => !empty($rider['is_daily_settlement']),
    ];
}

function rider_set_remember_cookie(int $riderId): void
{
    $exp = time() + RIDER_COOKIE_LIFETIME;
    $sig = rider_sign_remember($riderId, $exp);
    $payload = base64_encode(json_encode(['id' => $riderId, 'e' => $exp, 's' => $sig], JSON_THROW_ON_ERROR));
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
    if (!empty($_SESSION['rider']['id'])) {
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
    } catch (Throwable) {
        rider_clear_remember_cookie();

        return;
    }
    if (!is_array($raw) || empty($raw['e']) || empty($raw['s'])) {
        rider_clear_remember_cookie();

        return;
    }
    $exp = (int) $raw['e'];
    if ($exp < time()) {
        rider_clear_remember_cookie();

        return;
    }

    $riderId = 0;
    if (!empty($raw['id'])) {
        $riderId = (int) $raw['id'];
        $expect = rider_sign_remember($riderId, $exp);
        if (!hash_equals($expect, (string) $raw['s'])) {
            rider_clear_remember_cookie();

            return;
        }
    } elseif (!empty($raw['u'])) {
        // 구 쿠키(login_id 문자열) 호환
        $legacy = RiderAuth::findByLoginInput((string) $raw['u']);
        if (!$legacy || (string) ($legacy['status'] ?? '') !== 'active') {
            rider_clear_remember_cookie();

            return;
        }
        $riderId = (int) $legacy['id'];
    } else {
        rider_clear_remember_cookie();

        return;
    }

    $rider = RiderAuth::findById($riderId);
    if (!$rider || ($rider['status'] ?? '') !== 'active') {
        rider_clear_remember_cookie();

        return;
    }

    rider_set_session_user($rider);
}

function rider_auth_bootstrap(): void
{
    rider_try_cookie_restore();
}

function rider_is_logged_in(): bool
{
    return !empty($_SESSION['rider']['id']);
}

/** @return array<string, mixed>|null */
function rider_current_user(): ?array
{
    if (!rider_is_logged_in()) {
        return null;
    }

    return [
        'id'                  => (int) $_SESSION['rider']['id'],
        'rider_code'          => (string) ($_SESSION['rider']['rider_code'] ?? ''),
        'login_id'            => (string) ($_SESSION['rider']['login_id'] ?? ''),
        'name'                => (string) ($_SESSION['rider']['name'] ?? ''),
        'phone'               => (string) ($_SESSION['rider']['phone'] ?? ''),
        'email'               => (string) ($_SESSION['rider']['email'] ?? ''),
        'is_daily_settlement' => !empty($_SESSION['rider']['is_daily_settlement']),
    ];
}

/**
 * 멀티테넌시: 현재 라이더의 소속 대리점 id (없으면 0).
 * 세션에 없으면 DB에서 1회 보충 (구 세션 호환).
 */
function rider_current_agency_id(): int
{
    if (!rider_is_logged_in()) {
        return 0;
    }
    if (!isset($_SESSION['rider']['agency_id'])) {
        $rid = (int) $_SESSION['rider']['id'];
        $row = $rid > 0 ? db_row('SELECT agency_id FROM riders WHERE id = ? LIMIT 1', [$rid]) : null;
        $_SESSION['rider']['agency_id'] = ($row && $row['agency_id'] !== null) ? (int) $row['agency_id'] : 0;
    }

    return (int) $_SESSION['rider']['agency_id'];
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
