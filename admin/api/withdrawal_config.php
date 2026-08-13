<?php

declare(strict_types=1);

/**
 * 출금 정책 설정 API (super 전용)
 * GET  — 현재 설정
 * POST { "action": "save", ... }
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/WithdrawalConfig.php';
require_once INC_PATH . '/AuditLog.php';

header('Content-Type: application/json; charset=utf-8');

if (!admin_is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => '인증이 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 멀티테넌시: 본사(super)=전역 기본, 또는 특정 대리점 지정 시 그 대리점 값 / 대리점 계정=항상 자기 설정만
require_once INC_PATH . '/Org.php';
$isAgency  = admin_org_level() === Org::LEVEL_AGENCY;
$myRole    = (string) (admin_user()['role'] ?? '');

// 총판은 하위 대리점 정책을 **조회**할 수 있다(GET). 쓰기는 아래 POST 구간에서 다시 막는다.
$isDistributor = admin_org_level() === Org::LEVEL_DISTRIBUTOR;
if (!admin_has_role('super') && !$isAgency && !$isDistributor) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => '출금 정책을 조회할 권한이 없습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$err = static function (string $msg, int $code = 422): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
};

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$body = [];
if ($method === 'POST') {
    $raw  = file_get_contents('php://input');
    $ct   = $_SERVER['CONTENT_TYPE'] ?? '';
    $body = str_contains($ct, 'application/json')
        ? (array) json_decode($raw ?: '{}', true)
        : $_POST;
}

// 조회/수정 대상 조직 결정
// - 대리점 계정: 요청에 뭐가 오든 항상 자기 조직 고정(다른 대리점 값을 절대 못 봄/못 고침)
// - 본사(super): agency_id 파라미터가 있으면 그 대리점, 없으면 전역 기본(org_id NULL)
$agencyParam  = $method === 'GET' ? ($_GET['agency_id'] ?? null) : ($body['agency_id'] ?? null);
$targetAgency = null;
if ($isAgency) {
    $cfgOrgId = admin_org_id();
    $scope    = 'agency';
} else {
    $agencyId = (int) ($agencyParam ?? 0);
    if ($agencyId > 0) {
        $targetAgency = Org::find($agencyId);
        if ($targetAgency === null || (string) $targetAgency['level'] !== Org::LEVEL_AGENCY) {
            $err('대상 대리점을 찾을 수 없습니다.', 404);
        }
        // 총판이 남의 총판 소속 대리점을 들여다보지 못하게 스코프를 확인한다.
        if (!Org::canAccessAgency($agencyId)) {
            $err('이 대리점에 접근할 권한이 없습니다.', 403);
        }
        $cfgOrgId = $agencyId;
        $scope    = 'agency_override';
    } else {
        $cfgOrgId = null;
        $scope    = 'global';
    }
}

if ($method === 'GET') {
    try {
        echo json_encode([
            'ok'     => true,
            'config' => WithdrawalConfig::get($cfgOrgId),
            'scope'  => $scope,
            'agency' => $targetAgency !== null ? ['id' => (int) $targetAgency['id'], 'name' => (string) $targetAgency['name'], 'code' => (string) $targetAgency['code']] : null,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        $err('조회 실패: ' . $e->getMessage(), 500);
    }
    exit;
}

if ($method !== 'POST') {
    $err('허용되지 않은 메서드입니다.', 405);
}

// 총판은 조회 전용 — 대리점 정책은 대리점 본인 또는 본사가 정한다.
if ($isDistributor) {
    $err('총판 계정은 출금 정책을 변경할 수 없습니다. (조회만 가능)', 403);
}

// 쓰기 권한: 본사(super) 또는 대리점 운영/정산/총괄관리자(manager) 역할
// manager는 자기 조직 범위 내 전체 화면 조회·쓰기가 원칙(admin_can_write() 규칙과 동일하게 맞춤,
// 대표계정이 흔히 manager 역할이라 이 목록에서 빠지면 자기 조직 설정조차 못 고치는 사고가 남)
if (!admin_has_role('super') && !($isAgency && in_array($myRole, ['operation', 'settlement', 'manager'], true))) {
    $err('출금 정책을 저장할 권한이 없습니다.', 403);
}

if (trim((string) ($body['action'] ?? 'save')) !== 'save') {
    $err('action=save', 400);
}

try {
    $adminId = (int) ($_SESSION['admin_id'] ?? 0);
    $cfg = WithdrawalConfig::save($body, $cfgOrgId, $adminId > 0 ? $adminId : null);
    $scopeLabel = $targetAgency !== null ? ('대리점 ' . (string) $targetAgency['name'] . '(' . (string) $targetAgency['code'] . ')') : ($isAgency ? '자기 대리점' : '전역 기본');
    AuditLog::record(
        'withdrawal.config.save',
        'withdrawal_config',
        sprintf(
            '[%s] 보증금 %s · %d일 미만 %d원 / 이상 %d원(건당)',
            $scopeLabel,
            number_format($cfg['reserve_amount']),
            $cfg['fee_day_threshold'],
            $cfg['fee_per_tx_short'],
            $cfg['fee_per_tx_long']
        )
    );
    echo json_encode(['ok' => true, 'message' => '저장되었습니다.', 'config' => $cfg, 'scope' => $scope], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    // 검증 실패(예: 본사 건당 몫이 건당 수수료보다 큼)는 사용자가 고칠 수 있는 입력 오류다 →
    // 500이 아니라 422로 내려서 화면에 사유가 그대로 보이게 한다.
    $err($e->getMessage(), 422);
} catch (Throwable $e) {
    $err('저장 실패: ' . $e->getMessage(), 500);
}
