<?php

declare(strict_types=1);

/**
 * 정산 미리보기 화면에서 미매칭 라이더 빠른 등록 (최소 정보 + 플랫폼 연동)
 * POST JSON: { agency_id, platform, license_id, name, phone?, login_id, password }
 *
 * 업로드 대리점에 귀속 + rider_platforms(external_id = license_id) 연동 →
 * 이후 정산 파싱에서 자동 매칭된다.
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/Org.php';
require_once INC_PATH . '/AuditLog.php';

header('Content-Type: application/json; charset=utf-8');

if (!admin_is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => '인증이 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 정산 업로드 중 라이더 보충이므로 settlement 또는 riders 쓰기 권한이면 허용
if (!admin_can_write('settlement') && !admin_can_write('riders')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => '라이더를 등록할 권한이 없습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$err = static function (string $msg, int $code = 422): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
};

// ── GET: 연결할 기존 라이더 검색 (대리점 스코프 내) ──────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $q = trim((string) ($_GET['q'] ?? ''));
    $pf = trim((string) ($_GET['platform'] ?? ''));
    [$scopeSql, $scopeParams] = Org::agencyScopeClause('r.agency_id');
    $where  = ['1=1'];
    $params = [];
    if ($q !== '') {
        $like    = '%' . $q . '%';
        $where[] = '(r.name LIKE ? OR r.rider_code LIKE ? OR r.login_id LIKE ?)';
        $params  = array_merge($params, [$like, $like, $like]);
    }
    if ($scopeSql !== '') {
        $where[] = $scopeSql;
        $params  = array_merge($params, $scopeParams);
    }
    $rows = db_rows(
        "SELECT r.id, r.rider_code, r.name, r.status,
                (SELECT rp.external_id FROM rider_platforms rp WHERE rp.rider_id = r.id AND rp.platform = ? LIMIT 1) AS platform_ext
           FROM riders r
          WHERE " . implode(' AND ', $where) . "
          ORDER BY r.name ASC LIMIT 20",
        array_merge([$pf !== '' ? $pf : 'coupang'], $params)
    );
    echo json_encode(['ok' => true, 'riders' => $rows], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'POST 만 허용'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw  = file_get_contents('php://input');
$ct   = $_SERVER['CONTENT_TYPE'] ?? '';
$body = str_contains($ct, 'application/json')
    ? (array) json_decode($raw ?: '{}', true)
    : $_POST;

// 소속 대리점 결정 (대리점 계정은 자기, 본사/총판은 선택값 검증)
if (admin_org_level() === Org::LEVEL_AGENCY) {
    $agencyId = admin_org_id();
} else {
    $agencyId  = (int) ($body['agency_id'] ?? 0);
    $agencyOrg = $agencyId > 0 ? Org::find($agencyId) : null;
    if ($agencyOrg === null || (string) $agencyOrg['level'] !== Org::LEVEL_AGENCY || !Org::canAccessAgency($agencyId)) {
        $err('대리점을 선택하세요.');
    }
}

$action    = trim((string) ($body['action'] ?? 'create'));
$platform  = trim((string) ($body['platform'] ?? ''));
$licenseId = trim((string) ($body['license_id'] ?? ''));

// ── 기존 라이더에 플랫폼 ID 연결 (쿠팡만/배민만 있던 라이더에 다른 플랫폼 매핑) ──
if ($action === 'link') {
    $targetId = (int) ($body['rider_id'] ?? 0);
    if (!in_array($platform, ['baemin', 'coupang', 'other'], true) || $licenseId === '') {
        $err('플랫폼과 정산서 ID가 필요합니다.');
    }
    $target = db_row('SELECT id, name, rider_code, agency_id FROM riders WHERE id = ? LIMIT 1', [$targetId]);
    if ($target === null || !Org::canAccessAgency((int) $target['agency_id'])) {
        $err('연결할 라이더를 찾을 수 없습니다.', 404);
    }
    // 이 ID가 같은 대리점 다른 라이더에 이미 연결됐는지
    $dup = db_row(
        'SELECT rp.rider_id FROM rider_platforms rp INNER JOIN riders r ON r.id = rp.rider_id
          WHERE rp.platform = ? AND rp.external_id = ? AND r.agency_id = ? AND rp.rider_id <> ? LIMIT 1',
        [$platform, $licenseId, (int) $target['agency_id'], $targetId]
    );
    if ($dup !== null) {
        $err('이 ID는 이미 다른 라이더(#' . (int) $dup['rider_id'] . ')에 연결돼 있습니다.');
    }
    $existing = db_row('SELECT id FROM rider_platforms WHERE rider_id = ? AND platform = ? ORDER BY id ASC LIMIT 1', [$targetId, $platform]);
    if ($existing !== null) {
        db_execute('UPDATE rider_platforms SET external_id = ?, is_connected = 1 WHERE id = ?', [$licenseId, (int) $existing['id']]);
    } else {
        db_insert('INSERT INTO rider_platforms (rider_id, platform, is_connected, external_id) VALUES (?, ?, 1, ?)', [$targetId, $platform, $licenseId]);
    }
    AuditLog::record('rider.platform', (string) $target['rider_code'], "정산 미매칭 → 기존 라이더 연결 · {$platform}:{$licenseId}");
    echo json_encode([
        'ok'      => true,
        'message' => (string) $target['name'] . ' 라이더에 연결되었습니다.',
        'rider'   => ['id' => (int) $target['id'], 'name' => (string) $target['name'], 'rider_code' => (string) $target['rider_code']],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$name      = trim((string) ($body['name'] ?? ''));
$phone     = trim((string) ($body['phone'] ?? ''));
$loginId   = trim((string) ($body['login_id'] ?? ''));
$password  = (string) ($body['password'] ?? '');

if ($name === '') {
    $err('이름을 입력하세요.');
}
// 로그인 ID — 비워두면 휴대전화번호 기반으로 자동생성(충돌 시 대리점코드 접미사)
require_once INC_PATH . '/RiderLoginId.php';
if ($loginId === '') {
    $loginId = RiderLoginId::generate($phone);
} elseif (!preg_match('/^[a-zA-Z0-9_.@\-]{3,60}$/', $loginId)) {
    $err('로그인 ID는 영문·숫자·_·.·@·- 3~60자입니다.');
}
if (strlen($password) < 4) {
    $err('비밀번호는 4자 이상이어야 합니다.');
}
if (db_row('SELECT id FROM riders WHERE login_id = ? LIMIT 1', [$loginId]) !== null) {
    $err('이미 사용 중인 로그인 ID입니다.');
}
if (!in_array($platform, ['baemin', 'coupang', 'other'], true)) {
    $platform = '';
}

// rider_code 자동 생성
do {
    $riderCode = 'R-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
} while (db_row('SELECT id FROM riders WHERE rider_code = ? LIMIT 1', [$riderCode]) !== null);

try {
    $newId = db_transaction(static function () use ($riderCode, $loginId, $password, $name, $phone, $agencyId, $platform, $licenseId): int {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $id = db_insert(
            'INSERT INTO riders (rider_code, login_id, password_hash, name, phone, status, team_code, vehicle_type, agency_id)
             VALUES (?, ?, ?, ?, ?, \'active\', \'etc\', \'motor\', ?)',
            [$riderCode, $loginId, $hash, $name, $phone, $agencyId]
        );

        if ($platform !== '' && $licenseId !== '') {
            db_insert(
                'INSERT INTO rider_platforms (rider_id, platform, is_connected, external_id) VALUES (?, ?, 1, ?)',
                [$id, $platform, $licenseId]
            );
        }

        return $id;
    });

    AuditLog::record('rider.quick_create', $riderCode, "정산 미매칭 라이더 등록 · {$name}" . ($licenseId !== '' ? " · {$platform}:{$licenseId}" : ''));

    echo json_encode([
        'ok'    => true,
        'message' => '라이더가 등록되었습니다.',
        'rider' => ['id' => $newId, 'name' => $name, 'rider_code' => $riderCode, 'login_id' => $loginId],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $err('등록 실패: ' . $e->getMessage(), 500);
}
