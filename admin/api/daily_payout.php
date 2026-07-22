<?php

declare(strict_types=1);

/**
 * 일일정산(선정산) 지급 API — LOGIC §5.4
 * GET  — 지급 대상 목록(스코프) + 대리점 잔액
 * POST { action: 'pay', rider_id }              — 라이더 1명 원클릭 지급
 *      { action: 'pay_batch', rider_ids: [..] } — 일괄 지급(부분 성공 허용)
 *
 * 권한: 지급은 대리점(agency) 운영/정산 계정만. 조회는 본사·총판도(스코프).
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/Org.php';
require_once INC_PATH . '/DailyPayout.php';
require_once INC_PATH . '/AuditLog.php';

header('Content-Type: application/json; charset=utf-8');

if (!admin_is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => '인증이 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$err = static function (string $msg, int $code = 422): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
};

$isAgency = admin_org_level() === Org::LEVEL_AGENCY;
$myAgency = $isAgency ? admin_org_id() : 0;
$myRole   = (string) (admin_user()['role'] ?? '');
$adminId  = (int) ($_SESSION['admin_id'] ?? 0);
$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    try {
        $data = DailyPayout::listPayable($isAgency ? $myAgency : null);
        echo json_encode(['ok' => true, 'scope' => $isAgency ? 'agency' : 'org'] + $data, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        $err('조회 실패: ' . $e->getMessage(), 500);
    }
    exit;
}

if ($method !== 'POST') {
    $err('허용되지 않은 메서드입니다.', 405);
}

if (!$isAgency || !in_array($myRole, ['operation', 'settlement'], true)) {
    $err('대리점 운영/정산 계정만 지급할 수 있습니다.', 403);
}

$raw  = file_get_contents('php://input');
$ct   = $_SERVER['CONTENT_TYPE'] ?? '';
$body = str_contains($ct, 'application/json') ? (array) json_decode($raw ?: '{}', true) : $_POST;
$action = trim((string) ($body['action'] ?? ''));

// 지급 대상이 내 대리점 소속인지 확인(스코프 방어)
$assertMine = static function (int $riderId) use ($myAgency, $err): void {
    $r = db_row('SELECT agency_id FROM riders WHERE id = ? LIMIT 1', [$riderId]);
    if ($r === null || (int) $r['agency_id'] !== $myAgency) {
        $err('내 대리점 소속 라이더가 아닙니다.', 403);
    }
};

try {
    if ($action === 'pay') {
        $riderId = (int) ($body['rider_id'] ?? 0);
        $assertMine($riderId);
        $res = DailyPayout::payRider($riderId, $adminId > 0 ? $adminId : null);
        AuditLog::record('withdrawal.daily_payout', (string) $riderId, sprintf('일일정산 지급 %s원', number_format((int) $res['amount'])));
        echo json_encode([
            'ok'      => true,
            'message' => number_format((int) $res['amount']) . '원 지급 완료',
            'result'  => $res,
        ] + DailyPayout::listPayable($myAgency), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'pay_batch') {
        $ids = array_map('intval', (array) ($body['rider_ids'] ?? []));
        foreach ($ids as $rid) {
            $assertMine((int) $rid);
        }
        $res = DailyPayout::payBatch($ids, $adminId > 0 ? $adminId : null);
        AuditLog::record('withdrawal.daily_payout', 'batch', sprintf('일일정산 일괄지급 %d건 %s원', (int) $res['paid'], number_format((int) $res['total_amount'])));
        $msg = sprintf('%d건 지급(%s원)', (int) $res['paid'], number_format((int) $res['total_amount']));
        if ($res['failed'] !== []) {
            $msg .= ' · 실패 ' . count($res['failed']) . '건';
        }
        echo json_encode(['ok' => true, 'message' => $msg, 'result' => $res] + DailyPayout::listPayable($myAgency), JSON_UNESCAPED_UNICODE);
        exit;
    }

    $err('알 수 없는 action 입니다.', 400);
} catch (InvalidArgumentException $e) {
    $err($e->getMessage(), 422);
} catch (Throwable $e) {
    $err('지급 실패: ' . $e->getMessage(), 500);
}
