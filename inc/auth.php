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
        'manager'    => '총괄 관리자',
        default      => $role,
    };
}

/**
 * 라우트 접두사 → 권한관리 화면(system/permissions)에서 설정하는 area 키.
 * 여기 없는 라우트(system/* 등)는 area 권한과 무관하게 별도 규칙으로 처리된다.
 *
 * @return array<string, string>
 */
function admin_route_area_map(): array
{
    return [
        'settlement/' => 'settlement',
        'deduction/'  => 'deduction',
        'promotion'   => 'promotion',
        'withdrawal/' => 'withdrawal',
        'content/'    => 'content',
        'riders/'     => 'riders',
        'dashboard'   => 'dashboard',
    ];
}

function admin_can_access_route(string $route): bool
{
    require_once INC_PATH . '/RolePermission.php';

    $route = $route === '' ? 'dashboard' : $route;
    $user  = admin_user();
    if ($user === null) {
        return false;
    }
    if ($user['role'] === 'super') {
        return true;
    }

    // 멀티테넌시: 조직 관리 — 본사(admin 레벨) 최고관리자만
    if (str_starts_with($route, 'system/orgs')) {
        return admin_can_manage_orgs();
    }

    // 대표·서브계정 관리 — 조직 대표계정만 (시스템관리 메뉴가 아닌 별도 자기조직 관리 기능)
    if (str_starts_with($route, 'system/team')) {
        return admin_can_manage_team();
    }

    // 시스템관리(system/*)는 역할별 권한관리와 무관하게 최고관리자 전용으로 고정
    if (str_starts_with($route, 'system/')) {
        return false;
    }

    // 멀티테넌시: 출금 정책 — 본사(전역) 또는 대리점(자기 설정)
    if ($route === 'withdrawal/settings') {
        return admin_org_level() === Org::LEVEL_AGENCY;
    }

    $map = admin_route_area_map();
    uksort($map, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

    foreach ($map as $prefix => $area) {
        if ($route === $prefix || ($prefix !== 'dashboard' && str_starts_with($route, $prefix))) {
            // manager: 자기 조직 범위 내 전체 화면 조회 가능(시스템관리 제외, 위에서 이미 차단됨)
            if ($user['role'] === 'manager') {
                return true;
            }

            return RolePermission::canView($user['role'], $area);
        }
    }

    return false;
}

function admin_can_write(string $area): bool
{
    require_once INC_PATH . '/RolePermission.php';

    $user = admin_user();
    if ($user === null) {
        return false;
    }

    $role = $user['role'];
    if ($role === 'super') {
        return true;
    }

    // 시스템관리(system/*) 쓰기는 최고관리자 전용으로 고정
    if ($area === 'system') {
        return false;
    }

    if (!array_key_exists($area, RolePermission::AREAS)) {
        return false;
    }

    // manager: 자기 조직 범위 내 전체 화면 쓰기 가능(시스템관리 제외, 위에서 이미 차단됨)
    $canWrite = $role === 'manager' ? true : RolePermission::canWrite($role, $area);

    // 2026-07 재설계: 공지·배너 작성은 본사(admin 레벨)만. 총판·대리점은 조회(broadcast 수신)만.
    if ($area === 'content') {
        return $canWrite && admin_org_level() === Org::LEVEL_ADMIN;
    }

    return $canWrite;
}

/**
 * 멀티테넌시: 조직(총판/대리점) 생성·관리 권한.
 * 2026-07 재설계: 본사(admin 레벨)만 조직을 생성·관리. 총판의 조직 생성 권한은 회수됨
 * (총판은 대시보드에서 하위 대리점을 조회만 함).
 * 시스템관리(system/*) 소속 화면이라 역할별 권한관리와 무관하게 최고관리자 전용.
 */
function admin_can_manage_orgs(): bool
{
    return admin_org_level() === Org::LEVEL_ADMIN && admin_has_role('super');
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
