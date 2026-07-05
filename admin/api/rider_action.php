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

    $err('알 수 없는 액션입니다.');
}

http_response_code(405);
echo json_encode(['ok' => false, 'message' => 'Method Not Allowed']);
