<?php

/**
 * 라이더 목록 조회 / 신규 등록 API
 * GET  /admin/api/riders.php  — 목록
 * POST /admin/api/riders.php  — 신규 등록
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (!admin_is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => '인증이 필요합니다.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ── POST: 신규 등록 ────────────────────────────────────────────
if ($method === 'POST') {
    admin_deny_write_json('riders');
    $raw  = file_get_contents('php://input');
    $ct   = $_SERVER['CONTENT_TYPE'] ?? '';
    $body = str_contains($ct, 'application/json')
          ? (array) json_decode($raw ?: '{}', true)
          : $_POST;

    require_once INC_PATH . '/Org.php';
    require_once INC_PATH . '/RiderRegistration.php';

    $in = $body;
    // 멀티테넌시: 대리점 계정은 소속 대리점으로 강제(본인 외 대리점 선택 불가)
    if (admin_org_level() === Org::LEVEL_AGENCY) {
        $in['agency_id'] = admin_org_id();
    }
    // 구 단일 platform 선택값도 반영(외부ID 없이 플랫폼만 표시하고 싶을 때)
    $platform = trim((string) ($body['platform'] ?? ''));
    if ($platform === 'coupang' && trim((string) ($body['coupang_id'] ?? '')) === '') {
        $in['coupang_id'] = '';
    }

    try {
        $result = RiderRegistration::create($in);
    } catch (InvalidArgumentException $e) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'ok'         => true,
        'message'    => '등록되었습니다.',
        'id'         => $result['id'],
        'rider_code' => $result['rider_code'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── GET: 목록 ─────────────────────────────────────────────────
if ($method === 'GET') {
    $q      = trim((string) ($_GET['q']      ?? ''));
    $team   = trim((string) ($_GET['team']   ?? ''));
    $status = trim((string) ($_GET['status'] ?? ''));
    $limit  = min(200, max(10, (int) ($_GET['limit'] ?? 100)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));

    $where  = ['1=1'];
    $params = [];

    if ($q !== '') {
        $like    = '%' . $q . '%';
        $where[] = '(r.rider_code LIKE ? OR r.login_id LIKE ? OR r.name LIKE ? OR r.phone LIKE ?)';
        $params  = array_merge($params, [$like, $like, $like, $like]);
    }
    if ($team   !== '') { $where[] = 'r.team_code = ?'; $params[] = $team; }
    if ($status !== '') { $where[] = 'r.status = ?';    $params[] = $status; }

    // 멀티테넌시: 소속 대리점 스코프
    require_once INC_PATH . '/Org.php';

    // 특정 대리점으로 명시적 필터(대리점 먼저 선택 → 그 안에서 라이더 검색하는 화면용).
    // 접근 권한 밖 대리점을 넘기면 무시(스코프 조건만 적용) — 결과가 0건이 되므로 정보 노출 없음.
    $agencyFilter = (int) ($_GET['agency'] ?? 0);
    if ($agencyFilter > 0 && Org::canAccessAgency($agencyFilter)) {
        $where[] = 'r.agency_id = ?';
        $params[] = $agencyFilter;
    }

    [$scopeSql, $scopeParams] = Org::agencyScopeClause('r.agency_id');
    if ($scopeSql !== '') {
        $where[] = $scopeSql;
        $params  = array_merge($params, $scopeParams);
    }

    $whereStr = implode(' AND ', $where);
    $total    = (int) (db_row("SELECT COUNT(*) AS cnt FROM riders r WHERE {$whereStr}", $params)['cnt'] ?? 0);
    $rows     = db_rows(
        "SELECT r.id, r.rider_code, r.login_id, r.name,
                r.phone, r.status, r.team_code, r.vehicle_type,
                r.withdrawal_hold, r.created_at, r.last_login_at,
                (SELECT rp.platform FROM rider_platforms rp
                 WHERE rp.rider_id = r.id AND rp.is_connected = 1
                 ORDER BY rp.id LIMIT 1) AS primary_platform
         FROM riders r
         WHERE {$whereStr}
         ORDER BY r.name ASC
         LIMIT {$limit} OFFSET {$offset}",
        $params
    );

    $statusLabel = ['active' => '활동 중', 'suspended' => '일시 정지', 'leave_request' => '탈퇴 요청', 'offboarded' => '계약 종료'];
    $vehicleLabel = ['motor' => '오토바이', 'bike' => '자전거', 'car' => '자동차', 'walk' => '도보', 'kick' => '전동킥보드'];
    $pfLabel = ['baemin' => '배민', 'coupang' => '쿠팡이츠', 'other' => '기타'];

    $items = array_map(static function (array $r) use ($statusLabel, $vehicleLabel, $pfLabel): array {
        $phone = preg_replace('/\D/', '', $r['phone'] ?? '');
        return [
            'id'              => (int) $r['id'],
            'rider_code'      => $r['rider_code'],
            'login_id'        => $r['login_id'],
            'name'            => $r['name'],
            'phone_masked'    => preg_replace('/(\d{3})\d{4}(\d{4})/', '$1-****-$2', $phone),
            'status'          => $r['status'],
            'status_label'    => $statusLabel[$r['status']] ?? $r['status'],
            'team_code'       => $r['team_code'],
            'vehicle_type'    => $r['vehicle_type'],
            'vehicle_label'   => $vehicleLabel[$r['vehicle_type']] ?? $r['vehicle_type'],
            'platform'        => $r['primary_platform'],
            'platform_label'  => $pfLabel[$r['primary_platform'] ?? ''] ?? '—',
            'withdrawal_hold' => (bool) $r['withdrawal_hold'],
            'created_at'      => substr((string)$r['created_at'], 0, 10),
            'last_login_at'   => $r['last_login_at'] ? substr((string)$r['last_login_at'], 0, 16) : '—',
        ];
    }, $rows);

    echo json_encode(['ok' => true, 'total' => $total, 'items' => $items], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'message' => 'Method Not Allowed']);
