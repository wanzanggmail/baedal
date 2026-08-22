<?php

declare(strict_types=1);

/**
 * 정산 미리보기 화면에서 미매칭 라이더 빠른 등록 (최소 정보 + 플랫폼 연동)
 * POST JSON: { agency_id, platform, license_id, name, phone,
 *              is_daily_settlement?, withholding_tax_enabled?, bank_code?, bank_account? }
 *
 * 업로드 대리점에 귀속 + rider_platforms(external_id = license_id) 연동 →
 * 이후 정산 파싱에서 자동 매칭된다.
 *
 * 🔧 2026-08-15 — 휴대전화가 필수다(로그인 ID를 항상 여기서 자동 생성하므로 login_id는
 * 더 이상 입력받지 않는다). is_daily_settlement=true면 대리점이 출금을 대행하므로
 * bank_code·bank_account도 함께 받아 저장한다(§5.3 출금 대행 전제).
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
// 정산서 원본 이름은 오타·개명 등으로 riders.name과 완전히 다를 수 있어, 정확 LIKE 매칭에
// 더해 이름 유사도(레벤슈타인 거리, 앞뒤 숫자 접미사 제거 후)로 랭킹한 후보도 함께 준다.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $q  = trim((string) ($_GET['q'] ?? ''));
    $pf = trim((string) ($_GET['platform'] ?? ''));
    [$scopeSql, $scopeParams] = Org::agencyScopeClause('r.agency_id');
    $where  = ['1=1'];
    $params = [];
    if ($scopeSql !== '') {
        $where[] = $scopeSql;
        $params  = array_merge($params, $scopeParams);
    }

    $candidates = db_rows(
        "SELECT r.id, r.rider_code, r.name, r.status,
                (SELECT rp.external_id FROM rider_platforms rp WHERE rp.rider_id = r.id AND rp.platform = ? LIMIT 1) AS platform_ext
           FROM riders r
          WHERE " . implode(' AND ', $where) . "
          ORDER BY r.name ASC LIMIT 300",
        array_merge([$pf !== '' ? $pf : 'coupang'], $params)
    );

    if ($q === '') {
        $rows = array_slice($candidates, 0, 20);
    } else {
        $qCore = (string) preg_replace('/\d+$/', '', $q);
        foreach ($candidates as &$c) {
            $exact  = mb_stripos((string) $c['name'], $q) !== false;
            $nCore  = (string) preg_replace('/\d+$/', '', (string) $c['name']);
            // levenshtein()은 바이트 단위라 한글 정확 편집거리는 아니지만, 후보를 가까운 순으로
            // 줄 세우는 용도로는 충분하다(관리자가 최종 눈으로 확인 후 선택).
            $dist   = levenshtein($nCore, $qCore);
            $c['_score'] = ($exact ? -1000 : 0) + $dist;
        }
        unset($c);
        usort($candidates, static fn (array $a, array $b): int => $a['_score'] <=> $b['_score']);
        $rows = array_slice($candidates, 0, 10);
        foreach ($rows as &$r) {
            unset($r['_score']);
        }
        unset($r);
    }

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

// 확정된 업로드에서 미매칭 건을 사후 연결/등록하는 경우(row_id 전달) — 해당 행 +
// 같은 업로드 내 동일 엑셀 이름(rider_name_raw)의 다른 원본 테이블 행까지 rider_id를 채운다.
// 정산 반영(applyUpload)은 rider_id IS NOT NULL만 대상으로 하므로, 이후 "정산 반영"을
// 다시 실행하면 새로 매칭된 건만 추가로 반영된다(이미 반영된 건은 UNIQUE로 스킵, 멱등).
$uploadId = (int) ($body['upload_id'] ?? 0);
$rowId    = (int) ($body['row_id'] ?? 0);
$force    = !empty($body['force']);

/**
 * 행을 riderId로 (재)연결한다. 미매칭 행은 그냥 채우고, 이미 다른 라이더로 매칭된 행은
 * force=true일 때만 교정을 허용한다 — 단, 그 행이 이미 "정산 반영"되어 지갑에 적립까지
 * 끝난 경우는 여기서 자동으로 되돌리지 않고 명시적으로 막는다(돈이 걸린 되돌리기는
 * 관리자가 대여금/수동조정 등 기존 도구로 직접 처리해야 함).
 *
 * @return array{updated_daily:int, updated_siblings:int, ok_rows:int, error_rows:int, previous_rider_id:?int}|null
 */
$propagateMatch = static function (int $uploadId, int $rowId, int $riderId, bool $force) use ($err): ?array {
    if ($uploadId < 1 || $rowId < 1 || $riderId < 1) {
        return null;
    }
    $row = db_row(
        'SELECT id, upload_id, settlement_date, platform, rider_name_raw, rider_id FROM settlement_daily_riders WHERE id = ? AND upload_id = ? LIMIT 1',
        [$rowId, $uploadId]
    );
    if ($row === null) {
        return null;
    }
    $previousRiderId = $row['rider_id'] !== null ? (int) $row['rider_id'] : null;
    if ($previousRiderId !== null) {
        if ($previousRiderId === $riderId) {
            return null; // 이미 같은 라이더로 매칭됨 — 할 일 없음
        }
        if (!$force) {
            $err('이미 다른 라이더로 매칭된 행입니다. 재연결하려면 "재연결" 옵션으로 확인 후 진행하세요.');
        }
        $appliedCycle = db_row(
            'SELECT id FROM settlement_rider_cycles WHERE rider_id = ? AND settlement_date = ? AND platform = ? LIMIT 1',
            [$previousRiderId, (string) $row['settlement_date'], (string) $row['platform']]
        );
        if ($appliedCycle !== null) {
            $err('이미 정산 반영되어 지갑에 적립된 건입니다. 먼저 해당 사이클을 취소하거나 수동조정으로 정리한 뒤 재연결해 주세요.');
        }
    }
    $nameRaw = (string) $row['rider_name_raw'];

    db_execute('UPDATE settlement_daily_riders SET rider_id = ? WHERE id = ?', [$riderId, $rowId]);
    $siblingUpdated = 0;
    foreach (['settlement_order_details', 'settlement_hourly_insurance', 'settlement_support_amounts', 'settlement_weekly_deductions'] as $tbl) {
        if (!db_table_exists($tbl)) {
            continue;
        }
        $ridCond = $previousRiderId !== null ? 'rider_id = ?' : 'rider_id IS NULL';
        $params  = $previousRiderId !== null ? [$riderId, $uploadId, $nameRaw, $previousRiderId] : [$riderId, $uploadId, $nameRaw];
        $siblingUpdated += db_execute(
            "UPDATE {$tbl} SET rider_id = ? WHERE upload_id = ? AND rider_name_raw = ? AND {$ridCond}",
            $params
        );
    }

    $cnt = db_row(
        'SELECT SUM(CASE WHEN rider_id IS NOT NULL THEN 1 ELSE 0 END) AS ok_rows,
                SUM(CASE WHEN rider_id IS NULL THEN 1 ELSE 0 END) AS error_rows
           FROM settlement_daily_riders WHERE upload_id = ?',
        [$uploadId]
    ) ?: [];
    $okRows    = (int) ($cnt['ok_rows'] ?? 0);
    $errorRows = (int) ($cnt['error_rows'] ?? 0);
    db_execute('UPDATE settlement_uploads SET ok_rows = ?, error_rows = ? WHERE id = ?', [$okRows, $errorRows, $uploadId]);

    return ['updated_daily' => 1, 'updated_siblings' => $siblingUpdated, 'ok_rows' => $okRows, 'error_rows' => $errorRows, 'previous_rider_id' => $previousRiderId];
};

if ($uploadId > 0) {
    $uploadRow = db_row('SELECT id, agency_id FROM settlement_uploads WHERE id = ? LIMIT 1', [$uploadId]);
    if ($uploadRow === null || !Org::canAccessAgency((int) ($uploadRow['agency_id'] ?? 0))) {
        $err('이 업로드에 접근할 권한이 없습니다.', 403);
    }
}

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
    // 한 라이더가 팀지역별로 여러 플랫폼ID를 가질 수 있으므로 기존 ID를 덮어쓰지 않고 **추가**한다.
    // (같은 ID가 이미 있으면 그대로 두고 넘어감 — 재연결을 반복해도 안전)
    $already = db_row(
        'SELECT id FROM rider_platforms WHERE rider_id = ? AND platform = ? AND external_id = ? LIMIT 1',
        [$targetId, $platform, $licenseId]
    );
    if ($already === null) {
        db_insert('INSERT INTO rider_platforms (rider_id, platform, is_connected, external_id) VALUES (?, ?, 1, ?)', [$targetId, $platform, $licenseId]);
    }
    AuditLog::record('rider.platform', (string) $target['rider_code'], "정산 미매칭 → 기존 라이더 연결 · {$platform}:{$licenseId}");
    $match = $propagateMatch($uploadId, $rowId, $targetId, $force);
    if ($match !== null && $match['previous_rider_id'] !== null) {
        AuditLog::record('rider.platform', (string) $target['rider_code'], "정산 매칭 교정(오매칭 수정) · upload#{$uploadId} row#{$rowId} · 이전 rider#{$match['previous_rider_id']} → #{$targetId}");
    }
    echo json_encode([
        'ok'      => true,
        'message' => (string) $target['name'] . ' 라이더에 연결되었습니다.',
        'rider'   => ['id' => (int) $target['id'], 'name' => (string) $target['name'], 'rider_code' => (string) $target['rider_code']],
        'match'   => $match,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$name      = trim((string) ($body['name'] ?? ''));
$phone     = trim((string) ($body['phone'] ?? ''));
// 신규 라이더 비밀번호는 초기값(0000)으로 통일 — 최초 로그인 시 변경 강제
require_once INC_PATH . '/RiderAuth.php';
$password  = RiderAuth::INITIAL_PASSWORD;

if ($name === '') {
    $err('이름을 입력하세요.');
}
// 🔧 2026-08-15 휴대전화 필수화 — 로그인 ID를 손으로 받지 않고 항상 전화번호 기반으로
// 자동 생성한다(RiderLoginId::generate, 겹치면 접미사 부여). 전화번호가 없으면 로그인
// ID가 임의 문자열이 되어 라이더가 자기 계정을 못 외우므로 여기서 막는다.
// 국번 010/011/016~019 + 7~8자리(총 10~11자리)만 허용 — 유선전화·엉터리 숫자는 거른다.
$phoneDigits = preg_replace('/\D/', '', $phone) ?? '';
if (!preg_match('/^01[016789]\d{7,8}$/', $phoneDigits)) {
    $err('휴대전화 번호 형식이 올바르지 않습니다(예: 01012345678).');
}
// 🆕 2026-08-22 같은 대리점 안에서는 번호 중복 금지. 여기는 정산 미매칭을 급히 메우는
// 화면이라 이미 있는 사람을 실수로 또 만들기 쉽다(그러면 정산이 두 계정으로 쪼개진다).
// 다른 대리점의 같은 번호는 정상이므로 대리점 범위 안에서만 본다.
require_once INC_PATH . '/RiderRegistration.php';
try {
    RiderRegistration::assertPhoneFreeInAgency($phone, $agencyId);
} catch (InvalidArgumentException $e) {
    $err($e->getMessage());
}
require_once INC_PATH . '/RiderLoginId.php';
$loginId = RiderLoginId::generate($phone);
if (!in_array($platform, ['baemin', 'coupang', 'other'], true)) {
    $platform = '';
}

// 🆕 2026-08-15 빠른 등록에서 일정산/원천세 여부와, 일정산이면 출금 대행용 계좌를 함께 받는다.
$isDaily   = !empty($body['is_daily_settlement']);
$withhold  = !empty($body['withholding_tax_enabled']);
$bankCode  = trim((string) ($body['bank_code'] ?? ''));
$bankAcct  = trim((string) ($body['bank_account'] ?? ''));
if ($isDaily && ($bankCode === '' || $bankAcct === '')) {
    $err('일정산 라이더는 출금 대행을 위해 은행·계좌번호가 필요합니다.');
}

// rider_code 자동 생성
do {
    $riderCode = 'R-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
} while (db_row('SELECT id FROM riders WHERE rider_code = ? LIMIT 1', [$riderCode]) !== null);

try {
    $newId = db_transaction(static function () use ($riderCode, $loginId, $password, $name, $phone, $agencyId, $platform, $licenseId, $isDaily, $withhold, $bankCode, $bankAcct): int {
        // 초기 비밀번호(0000) 통일 + 최초 로그인 시 변경 강제
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $id = db_insert(
            'INSERT INTO riders (rider_code, login_id, password_hash, must_change_password, name, phone, status, team_code, vehicle_type, agency_id,
                                  is_daily_settlement, withholding_tax_enabled, bank_code, bank_account, account_holder)
             VALUES (?, ?, ?, 1, ?, ?, \'active\', \'etc\', \'motor\', ?, ?, ?, ?, ?, ?)',
            [$riderCode, $loginId, $hash, $name, $phone, $agencyId,
                $isDaily ? 1 : 0, $withhold ? 1 : 0, $bankCode, $bankAcct, $bankAcct !== '' ? $name : '']
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
    $match = $propagateMatch($uploadId, $rowId, $newId, $force);

    echo json_encode([
        'ok'    => true,
        'message' => '라이더가 등록되었습니다.',
        'rider' => ['id' => $newId, 'name' => $name, 'rider_code' => $riderCode, 'login_id' => $loginId],
        'match' => $match,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $err('등록 실패: ' . $e->getMessage(), 500);
}
