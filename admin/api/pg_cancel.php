<?php

declare(strict_types=1);

/**
 * PG 결제 취소 — 본사 super 전용.
 *
 * 돈을 되돌리는 작업이라 권한을 가장 좁게 잡았다. 대리점이 자기 결제를 스스로 취소하면
 * 지갑이 줄어드는데(라이더에게 이미 지급했을 수 있다) 그 뒷수습은 본사 몫이 된다.
 *
 * 사유는 필수다 — 취소는 되돌릴 수 없고 나중에 "왜 취소했나"를 반드시 묻게 된다.
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/auth.php';
require_once INC_PATH . '/Org.php';
require_once INC_PATH . '/PgPayment.php';

header('Content-Type: application/json; charset=utf-8');

$err = static function (string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
};

if (!admin_is_logged_in()) {
    $err('인증이 필요합니다.', 401);
}
if (!admin_has_role('super') || admin_org_level() !== Org::LEVEL_ADMIN) {
    $err('결제 취소는 본사 최고관리자만 할 수 있습니다.', 403);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $err('POST 만 허용합니다.', 405);
}

$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
    $err('요청 본문을 해석할 수 없습니다.');
}

$paymentId = (int) ($body['payment_id'] ?? 0);
$reason    = trim((string) ($body['reason'] ?? ''));
if ($paymentId < 1) {
    $err('결제 건을 지정하세요.');
}
if ($reason === '') {
    $err('취소 사유를 입력하세요.', 422);
}

try {
    $res = PgPayment::cancel($paymentId, $reason, (int) ($_SESSION['admin_id'] ?? 0));
    if (empty($res['ok'])) {
        // PG 거절(정산 넘어감 등)은 사용자가 이해할 수 있는 실패다 → 422 로 사유를 그대로 보여준다.
        $err((string) $res['message'], 422);
    }
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    $err($e->getMessage(), 422);
} catch (Throwable $e) {
    $err('취소 처리 실패: ' . $e->getMessage(), 500);
}
