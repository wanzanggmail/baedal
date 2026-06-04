<?php

declare(strict_types=1);

function admin_is_logged_in(): bool
{
    return !empty($_SESSION['admin_auth']);
}

function admin_require_login(): void
{
    if (!admin_is_logged_in()) {
        header('Location: ' . admin_login_url(), true, 302);
        exit;
    }
}

/**
 * 현재 로그인한 관리자 정보를 반환합니다.
 *
 * @return array{id:int,login_id:string,name:string,role:string}|null
 */
function admin_user(): ?array
{
    if (!admin_is_logged_in()) {
        return null;
    }

    return [
        'id'       => (int) ($_SESSION['admin_id']       ?? 0),
        'login_id' => (string) ($_SESSION['admin_login_id'] ?? ''),
        'name'     => (string) ($_SESSION['admin_name']     ?? ''),
        'role'     => (string) ($_SESSION['admin_role']     ?? ''),
    ];
}

/**
 * 현재 관리자의 역할이 주어진 역할 중 하나인지 확인합니다.
 * 사용 예: admin_has_role('super', 'settlement')
 */
function admin_has_role(string ...$roles): bool
{
    $user = admin_user();
    if ($user === null) {
        return false;
    }

    return in_array($user['role'], $roles, true);
}

/**
 * 역할 라벨 반환 (화면 표시용)
 */
function admin_role_label(string $role): string
{
    return match ($role) {
        'super'      => '최고 관리자',
        'admin'      => '조회 전용',
        'operation'  => '운영',
        'settlement' => '정산',
        default      => $role,
    };
}

/**
 * @return array<string, list<string>>
 */
function admin_route_access_rules(): array
{
    return [
        'system/admins' => ['super'],
        'system/codes'  => ['super'],
        'system/audit'  => ['super', 'admin'],
        'settlement/'   => ['super', 'settlement', 'operation'],
        'promotion/'    => ['super', 'settlement'],
        'deduction/'    => ['super', 'settlement'],
        'content/'      => ['super', 'operation'],
        'riders/'       => ['super', 'operation'],
        'withdrawal/settings' => ['super'],
        'withdrawal/'   => ['super', 'admin', 'operation', 'settlement'],
        'stats/'        => ['super', 'admin', 'operation', 'settlement'],
        'dashboard'     => ['super', 'admin', 'operation', 'settlement'],
    ];
}

function admin_can_access_route(string $route): bool
{
    $route = $route === '' ? 'dashboard' : $route;
    $user  = admin_user();
    if ($user === null) {
        return false;
    }
    if ($user['role'] === 'super') {
        return true;
    }

    $rules = admin_route_access_rules();
    uksort($rules, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

    foreach ($rules as $prefix => $roles) {
        if ($route === $prefix || ($prefix !== 'dashboard' && str_starts_with($route, $prefix))) {
            return in_array($user['role'], $roles, true);
        }
    }

    return false;
}

function admin_can_write(string $area): bool
{
    $user = admin_user();
    if ($user === null) {
        return false;
    }

    $role = $user['role'];
    if ($role === 'super') {
        return true;
    }
    if ($role === 'admin') {
        return false;
    }

    return match ($area) {
        'content', 'riders' => $role === 'operation',
        'withdrawal'         => $role === 'operation',
        'settlement', 'promotion', 'deduction' => $role === 'settlement',
        'system'             => $role === 'super',
        default              => false,
    };
}

function admin_deny_write_json(string $area): void
{
    if (admin_can_write($area)) {
        return;
    }
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => '권한이 없습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 접근 제한 — 허용 역할이 아니면 403 응답 후 종료
 */
function admin_require_role(string ...$roles): void
{
    admin_require_login();

    if (!admin_has_role(...$roles)) {
        http_response_code(403);
        $pageTitle = '접근 권한 없음';
        require_once INC_PATH . '/header.php';
        require_once INC_PATH . '/shell_main_open.php';
        require_once INC_PATH . '/app_content_open.php';
        echo '<div class="alert alert-danger">이 메뉴에 접근할 권한이 없습니다.</div>';
        require_once INC_PATH . '/app_content_close.php';
        require_once INC_PATH . '/shell_close.php';
        exit;
    }
}
