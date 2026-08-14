<?php

declare(strict_types=1);

/**
 * 출금 대행 API — 대리점이 소속 라이더의 출금을 대신 신청·실행한다.
 *
 * GET  ?rider_id=N&to=YYYY-MM-DD  — 그 일자까지 출금하면 얼마·수수료 얼마인지 미리보기
 * POST { action:'apply', rider_id, to }  — 신청 + 즉시 이체까지 실행
 * POST { action:'apply_bulk', items:[{rider_id,to},…] } — 일괄. 건별 처리, 실패해도 계속(부분 성공)
 *
 * 권한: 대리점(agency) 운영·정산·총괄 계정만. 자기 소속 라이더만 대상.
 *
 * ⚠️ 라이더 본인 신청과 **같은 도메인 경로**(Withdrawal::applyForRider → executeTransfers)를
 *    쓴다. 대행이라고 따로 계산하면 수수료·보증금·사이클 점유가 어긋난다.
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/Org.php';
require_once INC_PATH . '/RiderWallet.php';
require_once INC_PATH . '/Withdrawal.php';
require_once INC_PATH . '/WithdrawalCycles.php';
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
$myRole   = (string) (admin_user()['role'] ?? '');
$agencyId = $isAgency ? admin_org_id() : 0;
$adminId  = (int) ($_SESSION['admin_id'] ?? 0);
$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!$isAgency || !in_array($myRole, ['operation', 'settlement', 'manager'], true)) {
    $err('대리점 운영·정산·총괄 계정만 출금 대행을 할 수 있습니다.', 403);
}

/** 대상 라이더가 내 대리점 소속인지 — 남의 라이더를 대신 출금시키면 안 된다. */
$assertMine = static function (int $riderId) use ($agencyId, $err): array {
    $r = db_row(
        'SELECT id, name, rider_code, status, withdrawal_hold, is_daily_settlement, agency_id,
                bank_code, bank_account, account_holder
           FROM riders WHERE id = ? LIMIT 1',
        [$riderId]
    );
    if ($r === null || (int) $r['agency_id'] !== $agencyId) {
        $err('내 대리점 소속 라이더가 아닙니다.', 403);
    }

    return $r;
};

$normDate = static function ($v): ?string {
    $v = trim((string) ($v ?? ''));

    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
};

// ─────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $riderId = (int) ($_GET['rider_id'] ?? 0);
    if ($riderId < 1) {
        $err('라이더를 선택하세요.');
    }
    $rider  = $assertMine($riderId);
    $toDate = $normDate($_GET['to'] ?? null);

    try {
        $preview = RiderWallet::previewWithdrawal($riderId, $toDate);

        // 이 일자까지 실제로 소진될 정산일들 — "출금 가능 일자"로 보여준다.
        // picked_cycles 는 WithdrawalCycles::select() 결과라 키가 `amount`(이번에 소진할 금액)다.
        // unwithdrawn() 의 `remaining` 과 헷갈리지 말 것.
        $picked = array_map(static fn (array $c): array => [
            'date'    => (string) $c['settlement_date'],
            'orders'  => (int) $c['order_count'],
            'amount'  => (int) $c['amount'],
            'partial' => !empty($c['partial']),
        ], (array) ($preview['picked_cycles'] ?? []));

        // 아직 안 나간 전체 정산일(선택 상한과 무관) — 날짜를 뒤로 미루면 얼마나 더 나오는지 참고용
        $allOpen = array_map(static fn (array $c): array => [
            'date'   => (string) $c['settlement_date'],
            'orders' => (int) $c['order_count'],
            'amount' => (int) $c['remaining'],
        ], WithdrawalCycles::unwithdrawn($riderId));

        // 신청 자체가 막히는 사유는 미리 알려준다(버튼 누르고 나서 알면 늦다).
        $block = null;
        if ((string) $rider['status'] !== 'active') {
            $block = '활동 중인 라이더가 아닙니다.';
        } elseif ((int) $rider['withdrawal_hold'] === 1) {
            $block = '출금 보류 상태입니다.';
        } elseif ((int) $rider['is_daily_settlement'] === 1) {
            $block = '선정산 대상이라 「일일정산 지급」으로 처리합니다.';
        } elseif (trim((string) $rider['bank_code']) === '' || trim((string) $rider['bank_account']) === '') {
            $block = '출금 계좌가 등록돼 있지 않습니다.';
        } elseif (Withdrawal::hasOpenRiderRequest($riderId)) {
            $block = '처리 중인 출금 신청이 이미 있습니다.';
        }

        echo json_encode([
            'ok'      => true,
            'rider'   => ['id' => $riderId, 'name' => (string) $rider['name'], 'code' => (string) $rider['rider_code']],
            'to'      => $toDate,
            'preview' => [
                'balance'          => (int) $preview['balance'],
                'reserve_amount'   => (int) $preview['reserve_amount'],
                'payout_amount'    => (int) $preview['payout_amount'],
                'fee'              => (int) $preview['fee_per_tx'],
                'consume_amount'   => (int) $preview['consume_amount'],
                'can_apply'        => (bool) $preview['can_apply'],
                'fee_short_orders' => (int) $preview['fee_short_orders'],
                'fee_long_orders'  => (int) $preview['fee_long_orders'],
                'fee_short_amount' => (int) $preview['fee_short_amount'],
                'fee_long_amount'  => (int) $preview['fee_long_amount'],
                'fee_rate_short'   => (int) $preview['fee_rate_short'],
                'fee_rate_long'    => (int) $preview['fee_rate_long'],
                'fee_threshold'    => (int) $preview['fee_day_threshold'],
                'blocked_shortfall' => (int) ($preview['blocked_shortfall'] ?? 0),
            ],
            'picked'   => $picked,
            'all_open' => $allOpen,
            'block'    => $block,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        $err('미리보기 실패: ' . $e->getMessage(), 500);
    }
    exit;
}

if ($method !== 'POST') {
    $err('허용되지 않은 메서드입니다.', 405);
}

$raw  = file_get_contents('php://input');
$ct   = $_SERVER['CONTENT_TYPE'] ?? '';
$body = str_contains($ct, 'application/json') ? (array) json_decode($raw ?: '{}', true) : $_POST;

$action = trim((string) ($body['action'] ?? ''));
if (!in_array($action, ['apply', 'apply_bulk'], true)) {
    $err('action=apply 또는 apply_bulk', 400);
}

/**
 * 라이더 1명 출금 대행 — 신청 + 즉시 이체.
 *
 * 단건과 일괄이 **같은 함수**를 쓴다. 일괄만 따로 구현하면 수수료·보증금·사이클 점유
 * 규칙이 갈라질 수 있어서다.
 *
 * @return array{ok:bool, name:string, amount:int, request_id:int, message:string}
 */
$payOne = static function (int $riderId, ?string $toDate) use ($assertMine): array {
    $rider = $assertMine($riderId);

    // 1) 신청 — 라이더 본인 신청과 동일 경로(수수료·보증금·사이클 점유 일치)
    $req   = Withdrawal::applyForRider($riderId, $toDate);
    $reqId = (int) ($req['db_id'] ?? 0);
    if ($reqId < 1) {
        throw new RuntimeException('출금 신청 ID를 확인할 수 없습니다.');
    }

    // 2) 즉시 이체 — 대리점이 「출금하기」를 누른 것이므로 확정까지 진행한다.
    $res    = Withdrawal::executeTransfers([$reqId]);
    $first  = $res['results'][0] ?? null;
    $paid   = (int) $res['completed'] > 0;
    $amount = (int) ($req['amount'] ?? 0);

    AuditLog::record(
        'withdrawal.proxy',
        (string) $reqId,
        sprintf(
            '출금 대행 %s(%s) · %s · %s원 · %s',
            (string) $rider['name'],
            (string) $rider['rider_code'],
            $toDate !== null ? $toDate . '까지' : '전액',
            number_format($amount),
            $paid ? '이체 완료' : '이체 실패'
        )
    );

    return [
        'ok'         => $paid,
        'name'       => (string) $rider['name'],
        'amount'     => $amount,
        'request_id' => $reqId,
        'message'    => $paid
            ? sprintf('%s님에게 %s원 지급 완료', (string) $rider['name'], number_format($amount))
            : '이체 실패: ' . (string) ($first['message'] ?? '사유 미상') . ' (신청 #' . $reqId . ' 은 남아 있어 재시도할 수 있습니다)',
    ];
};

// ── 일괄 출금 ────────────────────────────────────────────────
// 건별로 처리하고 **실패해도 멈추지 않는다**(부분 성공 허용, LOGIC §5.4).
// 한 명이 막혔다고 나머지를 못 받으면 대행의 의미가 없다.
if ($action === 'apply_bulk') {
    $items = (array) ($body['items'] ?? []);
    if ($items === []) {
        $err('출금할 라이더를 선택하세요.');
    }
    if (count($items) > 200) {
        $err('한 번에 200명까지만 처리할 수 있습니다.');
    }

    $paidCount = 0;
    $paidTotal = 0;
    $errors    = [];

    foreach ($items as $it) {
        $rid = (int) ($it['rider_id'] ?? 0);
        if ($rid < 1) {
            continue;
        }
        try {
            $r = $payOne($rid, $normDate($it['to'] ?? null));
            if ($r['ok']) {
                $paidCount++;
                $paidTotal += $r['amount'];
            } else {
                $errors[] = $r['name'] . ': ' . $r['message'];
            }
        } catch (Throwable $e) {
            // 이름을 못 가져오는 경우까지 고려해 id로라도 남긴다.
            $nm = (string) (db_row('SELECT name FROM riders WHERE id = ? LIMIT 1', [$rid])['name'] ?? ('#' . $rid));
            $errors[] = $nm . ': ' . $e->getMessage();
        }
    }

    $msg = sprintf('%d명 지급 완료 (합계 %s원)', $paidCount, number_format($paidTotal));
    if ($errors !== []) {
        $msg .= sprintf(' · 실패 %d명', count($errors));
    }

    AuditLog::record('withdrawal.proxy_bulk', 'bulk', sprintf(
        '출금 대행 일괄 — 대상 %d명 · 지급 %d명(%s원) · 실패 %d명',
        count($items),
        $paidCount,
        number_format($paidTotal),
        count($errors)
    ));

    echo json_encode([
        'ok'      => true,
        'message' => $msg,
        'paid'    => $paidCount,
        'amount'  => $paidTotal,
        'failed'  => count($errors),
        'errors'  => $errors,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── 단건 출금 ────────────────────────────────────────────────
$riderId = (int) ($body['rider_id'] ?? 0);
if ($riderId < 1) {
    $err('라이더를 선택하세요.');
}

try {
    $r = $payOne($riderId, $normDate($body['to'] ?? null));

    if (!$r['ok']) {
        echo json_encode(['ok' => false, 'message' => $r['message']], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'ok'         => true,
        'message'    => $r['message'],
        'request_id' => $r['request_id'],
        'amount'     => $r['amount'],
    ], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    $err($e->getMessage(), 422);
} catch (Throwable $e) {
    $err('출금 대행 실패: ' . $e->getMessage(), 500);
}
