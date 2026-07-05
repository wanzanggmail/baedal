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

    $err = static function (string $msg, int $code = 422): never {
        http_response_code($code);
        echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    };

    $loginId  = trim((string) ($body['login_id']  ?? ''));
    $password = (string) ($body['password'] ?? '');
    $name     = trim((string) ($body['name']    ?? ''));
    $phone    = trim((string) ($body['phone']   ?? ''));

    if ($loginId === '')    $err('로그인 ID를 입력하세요.');
    if (!preg_match('/^[a-zA-Z0-9_.@\-]{3,60}$/', $loginId)) $err('로그인 ID는 영문·숫자·_·.·@·- 3~60자입니다.');
    if (strlen($password) < 4) $err('비밀번호는 4자 이상이어야 합니다.');
    if ($name === '')       $err('이름을 입력하세요.');
    if ($phone === '')      $err('휴대전화를 입력하세요.');

    if (db_row('SELECT id FROM riders WHERE login_id = ?', [$loginId])) {
        $err('이미 사용 중인 로그인 ID입니다.');
    }

    // 멀티테넌시: 소속 대리점 결정 (대리점 계정은 자기 대리점, 본사/총판은 선택)
    require_once INC_PATH . '/Organization.php';
    if (admin_org_level() === Org::LEVEL_AGENCY) {
        $agencyId = admin_org_id();
    } else {
        $agencyId = (int) ($body['agency_id'] ?? 0);
        if ($agencyId < 1) {
            $err('소속 대리점을 선택하세요.');
        }
        $agencyOrg = Org::find($agencyId);
        if ($agencyOrg === null || (string) $agencyOrg['level'] !== Org::LEVEL_AGENCY || !Org::canAccessAgency($agencyId)) {
            $err('선택한 대리점에 접근할 수 없습니다.');
        }
    }

    // rider_code 생성
    $customCode = trim((string) ($body['rider_code'] ?? ''));
    if ($customCode !== '') {
        if (!preg_match('/^R-[A-Za-z0-9._\-]+$/', $customCode)) $err('라이더 코드는 R-로 시작해야 합니다.');
        if (db_row('SELECT id FROM riders WHERE rider_code = ?', [$customCode])) $err('이미 사용 중인 라이더 코드입니다.');
        $riderCode = $customCode;
    } else {
        do {
            $riderCode = 'R-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
        } while (db_row('SELECT id FROM riders WHERE rider_code = ?', [$riderCode]));
    }

    $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    $vehicleAllowed = ['motor', 'bike', 'car', 'walk', 'kick'];
    $vehicle  = trim((string) ($body['vehicle_type'] ?? 'motor'));
    if (!in_array($vehicle, $vehicleAllowed, true)) $vehicle = 'motor';

    $teamCode    = trim((string) ($body['team_code']      ?? 'etc'));
    $birth       = trim((string) ($body['birth_date']     ?? ''));
    $address     = trim((string) ($body['address']        ?? ''));
    $bankCode    = trim((string) ($body['bank_code']      ?? ''));
    $bankAccount = trim((string) ($body['bank_account']   ?? ''));
    $accHolder   = trim((string) ($body['account_holder'] ?? ''));
    $email       = trim((string) ($body['email']          ?? ''));
    $platform    = trim((string) ($body['platform']       ?? ''));

    $newId = db_transaction(static function () use (
        $riderCode, $loginId, $passwordHash, $name, $phone, $email,
        $birth, $teamCode, $vehicle, $address,
        $bankCode, $bankAccount, $accHolder, $platform, $agencyId
    ): int {
        $id = db_insert(
            'INSERT INTO riders
                (rider_code, login_id, password_hash, name, phone, email, birth_date,
                 status, team_code, vehicle_type, address,
                 bank_code, bank_account, account_holder, agency_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'active\', ?, ?, ?, ?, ?, ?, ?)',
            [
                $riderCode, $loginId, $passwordHash, $name, $phone, $email,
                $birth !== '' ? $birth : null,
                $teamCode, $vehicle, $address,
                $bankCode !== '' ? $bankCode : null,
                $bankAccount !== '' ? $bankAccount : null,
                $accHolder !== '' ? $accHolder : null,
                $agencyId,
            ]
        );

        // 플랫폼 연동 등록
        if ($platform !== '' && in_array($platform, ['baemin', 'coupang', 'other'], true)) {
            db_insert(
                'INSERT INTO rider_platforms (rider_id, platform, is_connected, external_id)
                 VALUES (?, ?, 1, ?)',
                [$id, $platform, '']
            );
        }

        return $id;
    });

    echo json_encode([
        'ok'         => true,
        'message'    => '등록되었습니다.',
        'id'         => $newId,
        'rider_code' => $riderCode,
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
