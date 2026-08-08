<?php

/**
 * 라이더 미수금(대여금/리스/선지급) 관리 API
 *  GET  ?rider_id=N            — 미수금 목록 + 각 미수금 이력
 *  POST action=create          — 미수금 등록
 *  POST action=update          — 미수금 수정(제목·일납·채권자·상태·잔액보정 등)
 *  POST action=repay           — 차감 실행(일납×일수 또는 금액) → deduction_entries 생성
 *  POST action=reverse         — 차감 이력 취소
 *  POST action=delete          — 미수금 삭제(이력 없을 때만)
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/AuditLog.php';
require_once INC_PATH . '/Org.php';
require_once INC_PATH . '/RiderDebt.php';

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

if (!RiderDebt::tableReady()) {
    $err('미수금 원장 테이블이 없습니다. php migrate.php 를 실행하세요.', 500);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/** 라이더 접근 권한 확인(대리점 스코프) 후 라이더 행 반환 */
$loadRider = static function (int $riderId) use ($err): array {
    if ($riderId <= 0) {
        $err('라이더 ID가 없습니다.', 400);
    }
    $rider = db_row('SELECT id, rider_code, name, agency_id FROM riders WHERE id = ?', [$riderId]);
    if ($rider === null) {
        $err('라이더를 찾을 수 없습니다.', 404);
    }
    if (!Org::canAccessAgency((int) ($rider['agency_id'] ?? 0))) {
        $err('이 라이더에 접근할 권한이 없습니다.', 403);
    }

    return $rider;
};

// ── GET: 목록 ──────────────────────────────────────────────────
if ($method === 'GET') {
    $rider = $loadRider((int) ($_GET['rider_id'] ?? 0));
    $debts = RiderDebt::forRider((int) $rider['id']);
    foreach ($debts as &$d) {
        $d['entries'] = RiderDebt::entries((int) $d['id']);
    }
    unset($d);
    echo json_encode(['ok' => true, 'debts' => $debts, 'kinds' => RiderDebt::KINDS], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── POST ───────────────────────────────────────────────────────
if ($method !== 'POST' && $method !== 'PATCH') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method Not Allowed']);
    exit;
}

admin_deny_write_json('deduction');

$raw  = file_get_contents('php://input');
$body = (array) json_decode($raw ?: '{}', true);
if (empty($body)) {
    $body = $_POST;
}
$action = trim((string) ($body['action'] ?? ''));

try {
    if ($action === 'create') {
        $rider = $loadRider((int) ($body['rider_id'] ?? 0));
        $id = RiderDebt::create([
            'rider_id'         => (int) $rider['id'],
            'kind'             => (string) ($body['kind'] ?? ''),
            'title'            => (string) ($body['title'] ?? ''),
            'principal_amount' => (int) ($body['principal_amount'] ?? 0),
            'daily_amount'     => (int) ($body['daily_amount'] ?? 0),
            'creditor'         => (string) ($body['creditor'] ?? ''),
            'opened_on'        => (string) ($body['opened_on'] ?? ''),
            'planned_end_on'   => (string) ($body['planned_end_on'] ?? ''),
            'note'             => (string) ($body['note'] ?? ''),
            // 리스 전용 — 제공 주체·차대번호·수수료 배분(일 단위 정액)
            'lease_provider'   => (string) ($body['lease_provider'] ?? ''),
            'vin'              => (string) ($body['vin'] ?? ''),
            'fee_hq'           => (int) ($body['fee_hq'] ?? 0),
            'fee_distributor'  => (int) ($body['fee_distributor'] ?? 0),
            'fee_agency'       => (int) ($body['fee_agency'] ?? 0),
        ]);
        AuditLog::record('rider.debt.create', (string) $rider['rider_code'], RiderDebt::kindLabel((string) $body['kind']) . ' 등록 #' . $id);
        echo json_encode(['ok' => true, 'id' => $id, 'message' => '미수금이 등록되었습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 이하 액션은 debt_id 기준 — 소유 라이더 스코프 확인
    $debtId = (int) ($body['debt_id'] ?? 0);
    $debt   = RiderDebt::find($debtId);
    if ($debt === null) {
        $err('미수금을 찾을 수 없습니다.', 404);
    }
    $rider = $loadRider((int) $debt['rider_id']);

    if ($action === 'update') {
        $fields = array_intersect_key($body, array_flip([
            'title', 'daily_amount', 'creditor', 'note', 'opened_on', 'planned_end_on', 'status', 'balance_amount', 'principal_amount',
            'lease_provider', 'vin', 'fee_hq', 'fee_distributor', 'fee_agency',
        ]));
        RiderDebt::update($debtId, $fields);
        AuditLog::record('rider.debt.update', (string) $rider['rider_code'], '미수금 #' . $debtId . ' 수정');
        echo json_encode(['ok' => true, 'message' => '수정되었습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'repay') {
        $appliedDate = trim((string) ($body['applied_date'] ?? ''));
        $days        = (int) ($body['days'] ?? 0);
        $amount      = array_key_exists('amount', $body) && $body['amount'] !== '' && $body['amount'] !== null
            ? (int) $body['amount']
            : null;
        $memo = (string) ($body['memo'] ?? '');
        $r = RiderDebt::applyRepayment($debtId, $appliedDate, $days, $amount, $memo);
        AuditLog::record('rider.debt.repay', (string) $rider['rider_code'], sprintf('미수금 #%d 차감 %s원(잔액 %s)', $debtId, number_format($r['amount']), number_format($r['balance_after'])));
        echo json_encode([
            'ok'      => true,
            'message' => sprintf('%s원 차감했습니다. (차감후 잔액 %s원)', number_format($r['amount']), number_format($r['balance_after'])),
            'result'  => $r,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'reverse') {
        $entryId = (int) ($body['entry_id'] ?? 0);
        // 이력이 이 미수금 소속인지 확인
        $entry = db_row('SELECT id, debt_id FROM rider_debt_entries WHERE id = ?', [$entryId]);
        if ($entry === null || (int) $entry['debt_id'] !== $debtId) {
            $err('차감 이력을 찾을 수 없습니다.', 404);
        }
        RiderDebt::reverseEntry($entryId);
        AuditLog::record('rider.debt.reverse', (string) $rider['rider_code'], '미수금 #' . $debtId . ' 차감 취소 #' . $entryId);
        echo json_encode(['ok' => true, 'message' => '차감을 취소했습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'delete') {
        $cnt = (int) (db_row('SELECT COUNT(*) AS c FROM rider_debt_entries WHERE debt_id = ?', [$debtId])['c'] ?? 0);
        if ($cnt > 0) {
            $err('차감 이력이 있는 미수금는 삭제할 수 없습니다. 먼저 이력을 취소하거나 상태를 종료로 변경하세요.');
        }
        db_execute('DELETE FROM rider_debts WHERE id = ?', [$debtId]);
        AuditLog::record('rider.debt.delete', (string) $rider['rider_code'], '미수금 #' . $debtId . ' 삭제');
        echo json_encode(['ok' => true, 'message' => '삭제되었습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $err('알 수 없는 액션입니다.');
} catch (InvalidArgumentException $e) {
    $err($e->getMessage());
} catch (Throwable $e) {
    $err('처리 실패: ' . $e->getMessage(), 500);
}
