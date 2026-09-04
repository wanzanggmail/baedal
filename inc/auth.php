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
    // ⚠️ super 단축경로보다 **먼저** 판정한다.
    // 대표·서브계정 관리(system/team)는 총판·대리점이 자기 조직 서브계정을 직접 관리하는
    // 화면이라 본사에는 해당이 없다(본사는 system/admins 로 전체 계정을 관리한다).
    // 아래 super 단축경로가 먼저 걸리면 본사 최고관리자에게 메뉴·화면은 열리는데
    // API(admin_can_manage_team)는 403 을 내는 상태가 된다 — 실제로 그렇게 동작했다.
    if (str_starts_with($route, 'system/team')) {
        return admin_can_manage_team();
    }

    if ($user['role'] === 'super') {
        return true;
    }

    // 총판(distributor) 화면 최소화 (2026-09-01 갑): 총판은 아래 목록만 접근·열람한다
    //  — 대시보드 / 수수료·채권(미수금·리스배분 제외) / 지갑 입출금 / 자체 인출 /
    //    설정(조직 정보·계정 · 수수료 설정 조회). 그 외는 전부 차단(메뉴에서도 자동으로 사라진다).
    //    허용된 화면 안에서도 편집 권한은 각 화면·API가 따로 막는다(총판은 대부분 조회, 자체 인출은
    //    자기 조직 지갑만). 조직 레벨 기준이라 계정 역할(manager 등)과 무관하게 적용된다.
    if (admin_org_level() === Org::LEVEL_DISTRIBUTOR) {
        $distAllow = [
            'dashboard', 'docs/manual',
            'settlement/fees', 'settlement/fee-detail', 'settlement/platform-fee',
            'withdrawal/wallet-ledger', 'withdrawal/agency-payout',
            'system/team', 'deduction/agency-fee', 'withdrawal/payment-setup',
        ];
        $distOk = false;
        foreach ($distAllow as $allowed) {
            if ($route === $allowed || ($allowed !== 'dashboard' && str_starts_with($route, $allowed . '/'))) {
                $distOk = true;
                break;
            }
        }
        if (!$distOk) {
            return false;
        }
    }

    // 세무대리(tax_agent) — 완전 별도 메뉴. 대시보드·매뉴얼·세무(tax/*) + **지갑 입출금·자체 인출**(수집한
    // 원천세를 자기 지갑에서 빼 신고·납입하려면 필요, 2026-09-04 갑)만 접근한다.
    if (admin_org_level() === Org::LEVEL_TAX_AGENT) {
        $exact = ['withdrawal/wallet-ledger', 'withdrawal/agency-payout'];
        if (in_array($route, $exact, true)) {
            return true;
        }
        foreach (['dashboard', 'docs/manual', 'tax'] as $allowed) {
            if ($route === $allowed || str_starts_with($route, $allowed . '/')) {
                return true;
            }
        }

        return false;
    }

    // 매뉴얼은 정보 열람용이라 역할·조직과 무관하게 로그인한 관리자 전원에게 허용
    if (str_starts_with($route, 'docs/manual')) {
        return true;
    }

    // 멀티테넌시: 조직 관리 — 본사(admin 레벨) 최고관리자만
    if (str_starts_with($route, 'system/orgs')) {
        return admin_can_manage_orgs();
    }

    // 리스 수수료 배분 — 본사 전용(2026-08-12 갑 확정: "수수료 배분 기능은 본사에서만 한다.
    // 대리점이나 총판은 볼 필요 없음"). 대리점은 배분 없이 자기 몫을 전액 가져가므로 볼 게 없다.
    if (str_starts_with($route, 'deduction/lease-fees')) {
        return admin_org_level() === Org::LEVEL_ADMIN;
    }

    // 정산 엑셀 열기 암호 — 대리점이 자기 암호를 스스로 설정할 수 있어야 해서
    // "시스템 관리" 메뉴 소속이지만 super 전용이 아니라 'settlement' 영역 권한을 따른다.
    if (str_starts_with($route, 'system/settlement-excel')) {
        if ($user['role'] === 'manager') {
            return true;
        }

        return RolePermission::canView($user['role'], 'settlement');
    }

    // 시스템관리(system/*, 나머지)는 역할별 권한관리와 무관하게 최고관리자 전용으로 고정
    if (str_starts_with($route, 'system/')) {
        return false;
    }

    // 🔧 2026-08-15 펌뱅킹 즉시이체 전환 후 남은 구 경로(백업용) — 장애 대비로만 남겨두고
    // 신규 사용자가 기본 동선으로 오해하면 안 되므로 최고관리자 전용으로 좁힌다.
    // (이 지점에 오면 이미 super가 아니므로 항상 거부.)
    if (str_starts_with($route, 'withdrawal/download') || str_starts_with($route, 'withdrawal/complete')) {
        return false;
    }

    // 출금 대행 — 대리점이 소속 라이더 출금을 대신 실행하는 화면이라 대리점 전용.
    // (본사·총판은 남의 라이더 돈을 직접 빼는 일이 없어야 한다.)
    if (str_starts_with($route, 'withdrawal/proxy')) {
        return admin_org_level() === Org::LEVEL_AGENCY;
    }

    // 결제 설정(카드·계좌) — 대리점·총판 본인(자기 것만) + 본사(대행 설정). 총판은 자체 인출용
    // 정산금 수령 계좌를 스스로 등록해야 하므로 연다(2026-09-01 갑). 카드·충전은 화면·API가 감춤.
    if (str_starts_with($route, 'withdrawal/payment-setup')) {
        return in_array(admin_org_level(), [Org::LEVEL_AGENCY, Org::LEVEL_DISTRIBUTOR], true) || admin_has_role('super');
    }

    // 멀티테넌시: 출금 정책 — 대리점(자기 설정 편집) · 본사(대리점 지정 편집) · 총판(하위 조회만).
    // 총판을 아예 막아두면 자기 하위 대리점의 보증금·수수료 정책을 확인할 방법이 없어진다.
    if ($route === 'withdrawal/settings') {
        return in_array(admin_org_level(), [Org::LEVEL_AGENCY, Org::LEVEL_DISTRIBUTOR, Org::LEVEL_ADMIN], true);
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
