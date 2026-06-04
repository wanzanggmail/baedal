<?php

declare(strict_types=1);

/**
 * 자동 일일정산 API
 * GET  ?action=dates|source|preview
 * POST ?action=preview|commit  (JSON body)
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/DailySettlement.php';
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

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = trim((string) ($_GET['action'] ?? $_POST['action'] ?? ''));

if ($method === 'POST') {
    $raw  = file_get_contents('php://input');
    $ct   = $_SERVER['CONTENT_TYPE'] ?? '';
    $body = str_contains($ct, 'application/json')
        ? (array) json_decode($raw ?: '{}', true)
        : $_POST;
    if ($action === '' && isset($body['action'])) {
        $action = trim((string) $body['action']);
    }
} else {
    $body = $_GET;
}

if ($action === 'dates') {
    try {
        $platform = trim((string) ($body['platform'] ?? 'baemin'));
        echo json_encode([
            'ok'     => true,
            'dates'  => DailySettlement::availableDates($platform),
            'latest' => DailySettlement::availableDates($platform)[0] ?? null,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        if (str_contains($e->getMessage(), 'withdrawal_requests') || str_contains($e->getMessage(), 'settlement')) {
            $err('DB 준비가 필요합니다. migrate_daily_settlement.php 를 실행하세요.', 500);
        }
        $err($e->getMessage(), 500);
    }
    exit;
}

$settlementDate = trim((string) ($body['settlement_date'] ?? ''));
if ($settlementDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $settlementDate)) {
    $err('정산 귀속일 형식이 올바르지 않습니다.');
}

$params = DailySettlement::normalizeParams(is_array($body['params'] ?? null) ? $body['params'] : $body);

if ($action === 'source') {
    if ($settlementDate === '') {
        $err('settlement_date 가 필요합니다.');
    }
    try {
        $sources = DailySettlement::fetchSettlementSources($settlementDate, $params['platform']);
        echo json_encode([
            'ok'      => true,
            'date'    => $settlementDate,
            'sources' => $sources,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        $err('조회 실패: ' . $e->getMessage(), 500);
    }
    exit;
}

if ($action === 'preview') {
    if ($settlementDate === '') {
        $err('settlement_date 가 필요합니다.');
    }
    try {
        $result = DailySettlement::preview($settlementDate, $params);
        echo json_encode(['ok' => true, 'date' => $settlementDate] + $result, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        if (str_contains($e->getMessage(), 'withdrawal_requests')) {
            $err('withdrawal_requests 테이블이 없습니다. migrate_daily_settlement.php 를 실행하세요.', 500);
        }
        $err('미리보기 실패: ' . $e->getMessage(), 500);
    }
    exit;
}

if ($action === 'commit') {
    admin_deny_write_json('settlement');
    if ($settlementDate === '') {
        $err('settlement_date 가 필요합니다.');
    }
    $admin = admin_user();
    $adminId = $admin ? (int) $admin['id'] : 0;

    try {
        $result = DailySettlement::commit($settlementDate, $params, $adminId);
        $created = (int) ($result['created'] ?? 0);
        AuditLog::record(
            'settlement.daily_auto.commit',
            $settlementDate,
            "자동 일일정산 출금 {$created}건 생성"
        );
        echo json_encode(['ok' => true, 'date' => $settlementDate] + $result, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        if (str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), 'uq_wr')) {
            $err('이미 생성된 자동 일일정산 출금이 있습니다.', 409);
        }
        if (str_contains($e->getMessage(), 'withdrawal_requests')) {
            $err('withdrawal_requests 테이블이 없습니다. migrate_daily_settlement.php 를 실행하세요.', 500);
        }
        $err('반영 실패: ' . $e->getMessage(), 500);
    }
    exit;
}

$err('action 이 필요합니다. (dates|source|preview|commit)', 400);
