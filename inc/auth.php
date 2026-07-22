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
 * @return array{id:int,login_id:string,name:string,role:string,org_id:int}|null
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
        'org_id'   => admin_org_id(),
    ];
}

/**
 * 현재 계정의 소속 조직 id (없으면 0).
 * 멀티테넌시 도입 전 세션에는 admin_org_id 가 없으므로 DB에서 1회 보충.
 */
function admin_org_id(): int
{
    if (!admin_is_logged_in()) {
        return 0;
    }
    if (!isset($_SESSION['admin_org_id'])) {
        $id  = (int) ($_SESSION['admin_id'] ?? 0);
        $row = $id > 0 ? db_row('SELECT org_id FROM admins WHERE id = ? LIMIT 1', [$id]) : null;
        $_SESSION['admin_org_id'] = ($row && $row['org_id'] !== null) ? (int) $row['org_id'] : 0;
    }

    return (int) $_SESSION['admin_org_id'];
}

/** 현재 계정의 조직 행 (Org::current 래퍼) — @return array<string,mixed>|null */
function admin_org(): ?array
{
    return Org::current();
}

/** 현재 계정의 조직 레벨 (admin|distributor|agency|''). 조직 없으면 super는 admin로 간주 */
function admin_org_level(): string
{
    $org = Org::current();
    if ($org !== null) {
        return (string) $org['level'];
    }

    return admin_has_role('super') ? Org::LEVEL_ADMIN : '';
}

/**
 * 현재 계정이 볼 수 있는 대리점 id 집합 (null=전체). Org::scopeAgencyIds 래퍼.
 *
 * @return list<int>|null
 */
function admin_scope_agency_ids(): ?array
{
    return Org::scopeAgencyIds();
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
        'system/manual-adjust' => ['super'],
        'system/pg-fee' => ['super'],
        'system/settlement-excel' => ['super', 'settlement'],
        'system/audit'  => ['super', 'admin'],
        'settlement/'   => ['super', 'settlement', 'operation'],
        'deduction/'    => ['super', 'settlement'],
        'content/'      => ['super', 'operation'],
        'riders/'       => ['super', 'operation'],
        'withdrawal/settings' => ['super'],
        'withdrawal/'   => ['super', 'admin', 'operation', 'settlement'],
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

    // 멀티테넌시: 조직 관리 — 레벨 기반(본사/총판)
    if (str_starts_with($route, 'system/orgs')) {
        return admin_can_manage_orgs();
    }

    // 대표·서브계정 관리 — 조직 대표계정만
    if (str_starts_with($route, 'system/team')) {
        return admin_can_manage_team();
    }

    // 멀티테넌시: 출금 정책 — 본사(전역) 또는 대리점(자기 설정)
    if ($route === 'withdrawal/settings') {
        return $user['role'] === 'super' || admin_org_level() === Org::LEVEL_AGENCY;
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

    // 2026-07 재설계: 공지·배너 작성은 본사(admin 레벨)만. 총판·대리점은 조회(broadcast 수신)만.
    if ($area === 'content') {
        return $role === 'operation' && admin_org_level() === Org::LEVEL_ADMIN;
    }

    return match ($area) {
        'riders'             => $role === 'operation',
        'withdrawal'         => $role === 'operation',
        'settlement', 'deduction' => $role === 'settlement',
        'system'             => $role === 'super',
        default              => false,
    };
}

/**
 * 멀티테넌시: 조직(총판/대리점) 생성·관리 권한.
 * 2026-07 재설계: 본사(admin 레벨)만 조직을 생성·관리. 총판의 조직 생성 권한은 회수됨
 * (총판은 대시보드에서 하위 대리점을 조회만 함). 최고/운영 역할일 때 허용.
 */
function admin_can_manage_orgs(): bool
{
    if (admin_org_level() !== Org::LEVEL_ADMIN) {
        return false;
    }
    $user = admin_user();

    return $user !== null && in_array($user['role'], ['super', 'operation'], true);
}

/**
 * 대표·서브계정 관리 권한 (LOGIC §2 · §7 #14).
 * 총판·대리점의 "대표계정"(조직 최초 계정)만 자기 조직 서브계정을 관리할 수 있다.
 */
function admin_can_manage_team(): bool
{
    $level = admin_org_level();
    if (!in_array($level, [Org::LEVEL_AGENCY, Org::LEVEL_DISTRIBUTOR], true)) {
        return false;
    }
    $orgId = admin_org_id();
    $user  = admin_user();
    if ($orgId < 1 || $user === null) {
        return false;
    }
    $primary = (int) (db_row('SELECT id FROM admins WHERE org_id = ? ORDER BY id ASC LIMIT 1', [$orgId])['id'] ?? 0);

    return $primary === (int) $user['id'];
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
