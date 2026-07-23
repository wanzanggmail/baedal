<?php

/**
 * 라이더 단건 상세 조회 / 상태 변경 / 메모 저장 API
 * GET   ?id=N  — 상세
 * POST  ?id=N  — 상태변경·메모·출금보류
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/AuditLog.php';

header('Content-Type: application/json; charset=utf-8');

if (!admin_is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => '인증이 필요합니다.']);
    exit;
}

$err = static function (string $msg, int $code = 422): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
};

$id     = (int) ($_GET['id'] ?? 0);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($id <= 0) $err('라이더 ID가 없습니다.', 400);

$rider = db_row(
    'SELECT r.*, sc.label AS bank_label
     FROM riders r
     LEFT JOIN system_codes sc ON sc.category = \'bank\' AND sc.code = r.bank_code
     WHERE r.id = ?',
    [$id]
);
if (!$rider) $err('라이더를 찾을 수 없습니다.', 404);

// 멀티테넌시: 소속 대리점 스코프 밖이면 차단
require_once INC_PATH . '/Org.php';
if (!Org::canAccessAgency((int) ($rider['agency_id'] ?? 0))) {
    $err('이 라이더에 접근할 권한이 없습니다.', 403);
}

// ── GET ────────────────────────────────────────────────────────
if ($method === 'GET') {
    $platforms = db_rows(
        'SELECT platform, external_id FROM rider_platforms WHERE rider_id = ? AND is_connected = 1 ORDER BY id',
        [$id]
    );

    $statusLabel  = ['active' => '활동 중', 'suspended' => '일시 정지', 'leave_request' => '탈퇴 요청', 'offboarded' => '계약 종료'];
    $kycLabel     = ['none' => '미진행', 'pending' => '서류 대기', 'verified' => '본인인증 완료', 'rejected' => '인증 거부'];
    $vehicleLabel = ['motor' => '오토바이', 'bike' => '자전거', 'car' => '자동차', 'walk' => '도보', 'kick' => '전동킥보드'];
    $pfLabel      = ['baemin' => '배달의민족', 'coupang' => '쿠팡이츠', 'other' => '기타'];

    $phone   = preg_replace('/\D/', '', $rider['phone'] ?? '');
    $phoneMsk = preg_replace('/(\d{3})\d{4}(\d{4})/', '$1-****-$2', $phone) ?: '—';
    $acct    = $rider['bank_account'] ?? '';
    $acctMsk = $acct !== '' && strlen($acct) > 4
                 ? substr($acct, 0, 3) . str_repeat('*', max(0, strlen($acct) - 5)) . substr($acct, -2)
                 : '****';

    echo json_encode([
        'ok'    => true,
        'rider' => [
            'id'               => (int) $rider['id'],
            'rider_code'       => $rider['rider_code'],
            'login_id'         => $rider['login_id'],
            'name'             => $rider['name'],
            'phone_masked'     => $phoneMsk,
            'email'            => $rider['email'],
            'birth_masked'     => $rider['birth_date'] ? substr((string)$rider['birth_date'], 0, 4) . '-**-**' : '—',
            'status'           => $rider['status'],
            'status_label'     => $statusLabel[$rider['status']] ?? $rider['status'],
            'team_code'        => $rider['team_code'],
            'vehicle_type'     => $rider['vehicle_type'],
            'vehicle_label'    => $vehicleLabel[$rider['vehicle_type']] ?? $rider['vehicle_type'],
            'address'          => $rider['address'] ?? '',
            'bank_name'        => $rider['bank_label'] ?? '',
            'bank_account_masked' => $acctMsk,
            'account_holder'   => $rider['account_holder'] ?? '',
            'kyc_status'       => $rider['kyc_status'],
            'kyc_label'        => $kycLabel[$rider['kyc_status']] ?? $rider['kyc_status'],
            'id_verified_at'   => $rider['id_verified_at'] ? substr((string)$rider['id_verified_at'], 0, 10) : '—',
            'contract_signed_at' => $rider['contract_signed_at'] ? substr((string)$rider['contract_signed_at'], 0, 10) : '',
            'insurance_name'   => $rider['insurance_name'] ?? '',
            'insurance_expires' => $rider['insurance_expires'] ? substr((string)$rider['insurance_expires'], 0, 10) : '',
            'withdrawal_hold'       => (bool) $rider['withdrawal_hold'],
            'is_daily_settlement'   => (int) ($rider['is_daily_settlement'] ?? 0) === 1,
            'withholding_tax_enabled' => (int) ($rider['withholding_tax_enabled'] ?? 0) === 1,
            'admin_memo'       => $rider['admin_memo'] ?? '',
            'created_at'       => substr((string)$rider['created_at'], 0, 10),
            'last_login_at'    => $rider['last_login_at'] ? substr((string)$rider['last_login_at'], 0, 16) : '—',
            'platforms'        => array_map(static fn($p) => [
                'platform'    => $p['platform'],
                'label'       => $pfLabel[$p['platform']] ?? $p['platform'],
                'external_id' => $p['external_id'],
            ], $platforms),
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── POST: 액션 ────────────────────────────────────────────────
if ($method === 'POST' || $method === 'PATCH') {
    admin_deny_write_json('riders');
    $raw  = file_get_contents('php://input');
    $body = (array) json_decode($raw ?: '{}', true);
    if (empty($body)) $body = $_POST;

    $action = trim((string) ($body['action'] ?? ''));

    if ($action === 'status') {
        $allowed = ['active', 'suspended', 'leave_request', 'offboarded'];
        $newStatus = trim((string) ($body['status'] ?? ''));
        if (!in_array($newStatus, $allowed, true)) $err('올바르지 않은 상태값입니다.');
        db_execute('UPDATE riders SET status = ? WHERE id = ?', [$newStatus, $id]);
        AuditLog::record('rider.status', (string) ($rider['rider_code'] ?? $id), "상태 → {$newStatus}");
        echo json_encode(['ok' => true, 'message' => '상태가 변경되었습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'memo') {
        $memo = (string) ($body['memo'] ?? '');
        db_execute('UPDATE riders SET admin_memo = ? WHERE id = ?', [$memo, $id]);
        AuditLog::record('rider.memo', (string) ($rider['rider_code'] ?? $id), '관리자 메모 저장');
        echo json_encode(['ok' => true, 'message' => '메모가 저장되었습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'withdrawal_hold') {
        $hold = !empty($body['hold']) ? 1 : 0;
        db_execute('UPDATE riders SET withdrawal_hold = ? WHERE id = ?', [$hold, $id]);
        AuditLog::record('rider.withdrawal_hold', (string) ($rider['rider_code'] ?? $id), $hold ? '출금 보류 설정' : '출금 보류 해제');
        echo json_encode(['ok' => true, 'message' => $hold ? '출금 보류로 설정했습니다.' : '출금 보류를 해제했습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'daily_settlement') {
        $daily = !empty($body['daily']) ? 1 : 0;
        db_execute('UPDATE riders SET is_daily_settlement = ? WHERE id = ?', [$daily, $id]);
        AuditLog::record('rider.daily_settlement', (string) ($rider['rider_code'] ?? $id), $daily ? '일일정산 대상' : '주간정산 대상');
        echo json_encode([
            'ok'      => true,
            'message' => $daily ? '일일정산 대상으로 설정했습니다.' : '주간 정산 대상으로 변경했습니다.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'zero_close') {
        // #17 탈퇴/정지 라이더 잔여 잔액 0원 이체 종결
        require_once INC_PATH . '/DailyPayout.php';
        try {
            $adminId = (int) ($_SESSION['admin_id'] ?? 0);
            $r = DailyPayout::zeroClose($id, $adminId > 0 ? $adminId : null);
            AuditLog::record('rider.zero_close', (string) ($rider['rider_code'] ?? $id), sprintf('0원 종결(잔여 %s원 정리)', number_format((int) $r['written_off'])));
            echo json_encode(['ok' => true, 'message' => sprintf('0원 이체로 종결했습니다. (잔여 %s원 정리)', number_format((int) $r['written_off']))], JSON_UNESCAPED_UNICODE);
        } catch (InvalidArgumentException $e) {
            $err($e->getMessage());
        } catch (Throwable $e) {
            $err('종결 실패: ' . $e->getMessage(), 500);
        }
        exit;
    }

    if ($action === 'set_platform') {
        // 쿠팡/배민 등 플랫폼 외부 ID 연동/수정/해제 (정산 매칭 키)
        $pf  = trim((string) ($body['platform'] ?? ''));
        $ext = trim((string) ($body['external_id'] ?? ''));
        if (!in_array($pf, ['coupang', 'baemin', 'other'], true)) {
            $err('플랫폼이 올바르지 않습니다.');
        }
        $agencyId = (int) ($rider['agency_id'] ?? 0);

        if ($ext === '') {
            db_execute('DELETE FROM rider_platforms WHERE rider_id = ? AND platform = ?', [$id, $pf]);
            AuditLog::record('rider.platform', (string) ($rider['rider_code'] ?? $id), "{$pf} 연동 해제");
            echo json_encode(['ok' => true, 'message' => '연동을 해제했습니다.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 같은 대리점 내 다른 라이더가 이미 이 플랫폼 ID를 쓰고 있으면 거부
        $dup = db_row(
            'SELECT rp.rider_id FROM rider_platforms rp
               INNER JOIN riders r ON r.id = rp.rider_id
              WHERE rp.platform = ? AND rp.external_id = ? AND r.agency_id = ? AND rp.rider_id <> ? LIMIT 1',
            [$pf, $ext, $agencyId, $id]
        );
        if ($dup !== null) {
            $err('이 ' . ($pf === 'coupang' ? '쿠팡' : ($pf === 'baemin' ? '배민' : '')) . ' ID는 이미 다른 라이더(#' . (int) $dup['rider_id'] . ')에 연결돼 있습니다.');
        }

        $existing = db_row('SELECT id FROM rider_platforms WHERE rider_id = ? AND platform = ? ORDER BY id ASC LIMIT 1', [$id, $pf]);
        if ($existing !== null) {
            db_execute('UPDATE rider_platforms SET external_id = ?, is_connected = 1 WHERE id = ?', [$ext, (int) $existing['id']]);
        } else {
            db_insert('INSERT INTO rider_platforms (rider_id, platform, is_connected, external_id) VALUES (?, ?, 1, ?)', [$id, $pf, $ext]);
        }
        AuditLog::record('rider.platform', (string) ($rider['rider_code'] ?? $id), "{$pf} ID 연동 · {$ext}");
        echo json_encode(['ok' => true, 'message' => '플랫폼 ID가 저장되었습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'withholding_tax') {
        // #15 원천세 공제 대상 여부 — 대리점이 라이더별로 설정. 세율(3.3%)은 고정.
        $on = !empty($body['enabled']) ? 1 : 0;
        db_execute('UPDATE riders SET withholding_tax_enabled = ? WHERE id = ?', [$on, $id]);
        AuditLog::record('rider.withholding_tax', (string) ($rider['rider_code'] ?? $id), $on ? '원천세 공제 대상' : '원천세 비대상');
        echo json_encode([
            'ok'      => true,
            'message' => $on ? '원천세 공제 대상으로 설정했습니다.' : '원천세 비대상으로 변경했습니다.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'update_profile') {
        // 라이더 프로필 정보 일괄 수정 (연락처·소속·계좌 등)
        $vehicleAllowed = ['motor', 'bike', 'car', 'walk', 'kick'];
        $kycAllowed     = ['none', 'pending', 'verified', 'rejected'];

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '')            $err('이름을 입력하세요.');
        if (mb_strlen($name) > 80)   $err('이름이 너무 깁니다.');

        // 휴대전화: 숫자·하이픈만 허용
        $phone = trim((string) ($body['phone'] ?? ''));
        if ($phone !== '' && !preg_match('/^[0-9\-]{9,20}$/', $phone)) {
            $err('휴대전화 형식이 올바르지 않습니다.');
        }

        $email = trim((string) ($body['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err('이메일 형식이 올바르지 않습니다.');
        }
        if (mb_strlen($email) > 120) $err('이메일이 너무 깁니다.');

        // 생년월일: 비었으면 NULL, 있으면 YYYY-MM-DD 유효성
        $birthRaw  = trim((string) ($body['birth_date'] ?? ''));
        $birthDate = null;
        if ($birthRaw !== '') {
            $d = DateTime::createFromFormat('Y-m-d', $birthRaw);
            if (!$d || $d->format('Y-m-d') !== $birthRaw) $err('생년월일 형식이 올바르지 않습니다. (YYYY-MM-DD)');
            $birthDate = $birthRaw;
        }

        $teamCode = trim((string) ($body['team_code'] ?? ''));
        if (mb_strlen($teamCode) > 32) $err('팀 코드가 너무 깁니다.');

        $vehicle = trim((string) ($body['vehicle_type'] ?? ''));
        if ($vehicle !== '' && !in_array($vehicle, $vehicleAllowed, true)) $err('차량 종류가 올바르지 않습니다.');
        if ($vehicle === '') $vehicle = (string) ($rider['vehicle_type'] ?? 'motor');

        $address = trim((string) ($body['address'] ?? ''));
        if (mb_strlen($address) > 255) $err('활동 지역이 너무 깁니다.');

        // 은행코드: 비었으면 NULL, 있으면 system_codes 존재 확인
        $bankCode = trim((string) ($body['bank_code'] ?? ''));
        if ($bankCode !== '') {
            $ok = db_row("SELECT 1 AS x FROM system_codes WHERE category='bank' AND code = ? LIMIT 1", [$bankCode]);
            if ($ok === null) $err('선택한 은행이 올바르지 않습니다.');
        } else {
            $bankCode = null;
        }

        $bankAccount = trim((string) ($body['bank_account'] ?? ''));
        if ($bankAccount !== '' && !preg_match('/^[0-9\-]{1,40}$/', $bankAccount)) {
            $err('계좌번호는 숫자·하이픈만 입력하세요.');
        }
        if ($bankAccount === '') $bankAccount = null;

        $holder = trim((string) ($body['account_holder'] ?? ''));
        if (mb_strlen($holder) > 80) $err('예금주가 너무 깁니다.');
        if ($holder === '') $holder = null;

        $kyc = trim((string) ($body['kyc_status'] ?? ''));
        if ($kyc !== '' && !in_array($kyc, $kycAllowed, true)) $err('본인인증 상태값이 올바르지 않습니다.');
        if ($kyc === '') $kyc = (string) ($rider['kyc_status'] ?? 'none');

        db_execute(
            'UPDATE riders
                SET name = ?, phone = ?, email = ?, birth_date = ?,
                    team_code = ?, vehicle_type = ?, address = ?,
                    bank_code = ?, bank_account = ?, account_holder = ?,
                    kyc_status = ?, updated_at = NOW()
              WHERE id = ?',
            [$name, $phone, $email, $birthDate,
             $teamCode, $vehicle, $address,
             $bankCode, $bankAccount, $holder,
             $kyc, $id]
        );
        AuditLog::record('rider.update_profile', (string) ($rider['rider_code'] ?? $id), '프로필 정보 수정');
        echo json_encode(['ok' => true, 'message' => '라이더 정보가 저장되었습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $err('알 수 없는 액션입니다.');
}

http_response_code(405);
echo json_encode(['ok' => false, 'message' => 'Method Not Allowed']);
