<?php

declare(strict_types=1);

/**
 * 계좌 실재·예금주 확인 — 계좌를 입력하는 모든 화면이 함께 쓴다.
 *
 * 라이더 등록·수정, 조직 계좌 등록, 일정산 라이더 등록 모달에서 호출한다.
 * 그래서 권한은 **로그인한 관리자**면 되도록 열어 뒀다(본사 super 로 좁히면
 * 정작 계좌를 입력하는 대리점 담당자가 못 쓴다).
 *
 * 조회는 **읽기 전용**이라 돈을 움직이지 않는다. 다만 외부 API 호출이므로
 * 남용되지 않게 간단한 호출 제한을 둔다.
 *
 * 결과를 저장하지는 않는다 — 저장은 각 화면의 저장 로직이 한다.
 * `rider_id` 를 함께 주면 확인 기록(`bank_verified_at`)만 남긴다.
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/auth.php';
require_once INC_PATH . '/AccountVerifier.php';

header('Content-Type: application/json; charset=utf-8');

$err = static function (string $msg, int $code = 422): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'state' => 'error', 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
};

if (!admin_is_logged_in()) {
    $err('인증이 필요합니다.', 401);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $err('POST 만 허용합니다.', 405);
}

$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
    $err('요청 본문을 해석할 수 없습니다.', 400);
}

// ── 간단한 호출 제한 ──
// 외부 API 를 두드리는 경로라 실수로(또는 장난으로) 연타되면 곤란하다.
// 세션 단위로 분당 30회면 사람이 쓰기엔 넉넉하고 자동 반복은 막힌다.
$now    = time();
$bucket = $_SESSION['acct_verify_hits'] ?? [];
$bucket = array_values(array_filter($bucket, static fn (int $t): bool => $t > $now - 60));
if (count($bucket) >= 30) {
    $err('조회가 너무 잦습니다. 잠시 후 다시 시도하세요.', 429);
}
$bucket[]                        = $now;
$_SESSION['acct_verify_hits']    = $bucket;

$bankCode = trim((string) ($body['bank_code'] ?? ''));
$account  = trim((string) ($body['account_no'] ?? ''));
$holder   = trim((string) ($body['holder'] ?? ''));
$riderId  = (int) ($body['rider_id'] ?? 0);

try {
    $res = AccountVerifier::verify($bankCode, $account, $holder);

    // 확인에 성공했고 대상 라이더가 지정됐으면 기록을 남긴다.
    // (계좌가 실제로 저장되는 건 각 화면의 저장 로직이므로 여기서는 기록만.)
    if ($res['ok'] && $riderId > 0) {
        AccountVerifier::markRiderVerified($riderId, $res['holder']);
    }

    echo json_encode([
        'ok'      => $res['ok'],
        'state'   => $res['state'],
        'holder'  => $res['holder'],
        'matched' => $res['matched'],
        'message' => $res['message'],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $err('조회 실패: ' . $e->getMessage(), 500);
}
