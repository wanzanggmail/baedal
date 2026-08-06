<?php

declare(strict_types=1);

/**
 * 프로모션 지급 재시도 — 미지급(pending)·실패(failed) 건만 다시 카드결제한다.
 * POST { batch_id }
 *
 * Promotion::pay()는 pending 건만 처리하므로, 재시도 전에 failed → pending 으로 되돌린다.
 * 이미 paid 인 건은 절대 다시 결제되지 않는다.
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/Org.php';
require_once INC_PATH . '/Promotion.php';
require_once INC_PATH . '/AuditLog.php';

header('Content-Type: application/json; charset=utf-8');

$err = static function (string $msg, int $code = 422): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
};

if (!admin_is_logged_in()) {
    $err('인증이 필요합니다.', 401);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $err('POST만 허용합니다.', 405);
}
admin_deny_write_json('settlement');

$raw  = file_get_contents('php://input');
$ct   = $_SERVER['CONTENT_TYPE'] ?? '';
$body = str_contains($ct, 'application/json') ? (array) json_decode($raw ?: '{}', true) : $_POST;

$batchId = (int) ($body['batch_id'] ?? 0);
$batch   = Promotion::findBatch($batchId);
if ($batch === null) {
    $err('배치를 찾을 수 없습니다.', 404);
}
if (!Org::canAccessAgency((int) ($batch['agency_id'] ?? 0))) {
    $err('이 배치에 접근할 권한이 없습니다.', 403);
}

try {
    // 실패 건을 다시 대기로 — paid 는 건드리지 않으므로 중복 지급 위험 없음
    db_execute(
        "UPDATE promotion_entries SET status = 'pending', fail_reason = ''
          WHERE batch_id = ? AND status = 'failed' AND rider_id IS NOT NULL AND total_amount > 0",
        [$batchId]
    );

    $adminId = (int) ($_SESSION['admin_id'] ?? 0);
    $r = Promotion::pay($batchId, $adminId > 0 ? $adminId : null);

    AuditLog::record(
        'promotion.retry',
        (string) $batchId,
        sprintf('프로모션 재시도 · 성공 %d명 %s원 / 실패 %d명', $r['paid'], number_format($r['paid_amount']), $r['failed'])
    );

    echo json_encode([
        'ok'      => true,
        'paid'    => $r['paid'],
        'failed'  => $r['failed'],
        'message' => sprintf('%d명 %s원 지급 완료%s', $r['paid'], number_format($r['paid_amount']),
            $r['failed'] > 0 ? " · 실패 {$r['failed']}명" : ''),
        'errors'  => $r['errors'],
    ], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    $err($e->getMessage());
} catch (Throwable $e) {
    $err('재시도 실패: ' . $e->getMessage(), 500);
}
