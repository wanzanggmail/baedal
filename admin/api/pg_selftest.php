<?php

declare(strict_types=1);

/**
 * PG 연동 자가진단 — 본사 super 전용.
 *
 * **돈이 움직이지 않는 호출만** 쓴다. 존재하지 않는 빌키/주문번호로 실 API를 두드려
 * "요청이 규격에 맞는가 · 인증이 되는가 · 가맹점이 조회되는가"만 본다.
 * 카드정보를 넣지 않으므로 승인이 날 수 없다.
 *
 * PG 쪽 설정(가맹점 활성화 등)이 바뀐 뒤 다시 눌러 확인하는 용도다.
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/auth.php';
require_once INC_PATH . '/Org.php';
require_once INC_PATH . '/PgConfig.php';
require_once INC_PATH . '/PgGateway.php';
require_once INC_PATH . '/RealPgGateway.php';
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
    $err('본사 최고관리자만 실행할 수 있습니다.', 403);
}

$cfg = PgConfig::get();
if (trim((string) $cfg['mid']) === '' || trim((string) $cfg['pay_key']) === '') {
    $err('가맹점 ID(MID)와 결제 KEY를 먼저 저장하세요.', 422);
}

// 드라이버 설정과 무관하게 **실 API**로 진단한다 — mock 으로 두고도 확인할 수 있어야 한다.
$gw    = new RealPgGateway($cfg);
$probe = 'DIAG-' . date('YmdHis');
$tests = [];

// ① 빌키 삭제 — 없는 키. 인증·가맹점·요청 규격을 한 번에 본다.
$r = $gw->deleteBillingKey('NOEXIST-DIAG-KEY', $probe);
$tests[] = [
    'name'   => '빌키 삭제 (없는 키)',
    'expect' => '"빌키가 존재하지 않습니다" 가 나오면 정상',
    'pass'   => !$r->success && str_contains($r->failReason, '빌키'),
    'detail' => $r->success ? '예상과 달리 성공 응답' : $r->failReason,
];

// ② 빌키 결제 — 없는 빌키. 돈이 나갈 수 없다.
$c = $gw->charge(new PgChargeRequest(
    billingKey: 'NOEXIST-DIAG-KEY',
    amount: 1000,
    orderNo: $probe . '-P',
    buyerName: '연결테스트',
    buyerPhone: '01000000000'
));
$tests[] = [
    'name'   => '빌키 결제 (없는 빌키)',
    'expect' => '"빌키가 존재하지 않습니다" 가 나오면 정상 — 승인은 일어나지 않습니다',
    'pass'   => !$c->success && str_contains($c->failReason, '빌키'),
    'detail' => $c->success ? '⚠️ 예상과 달리 승인됨 — 즉시 확인 필요' : $c->failReason,
];

// ③ 빌키 발급 — 가맹점 조회 여부. 카드번호는 형식만 맞는 더미라 승인이 날 수 없고,
//    "가맹점을 찾을 수 없습니다"(RV406) 가 나오면 **PG 쪽 가맹점 설정 문제**다.
//    2026-08-23 실제로 이 상태였다 — 계약은 「빌키결제」로 돼 있는데 계약 시작일·종료일이
//    비어 있었다. 가맹점 조회가 계약기간으로 거르는 것으로 보인다.
$b = $gw->issueBillingKey(new PgBillingKeyRequest(
    cardNumber: '4000000000000000',
    expiry: '3012',
    authNum: '900101',
    cardPw: '00',
    orderNo: $probe . '-B',
    buyerName: '연결테스트',
    buyerPhone: '01000000000'
));
$merchantMissing = str_contains($b->failReason, '가맹점');
$tests[] = [
    'name'   => '빌키 발급 (가맹점 조회)',
    'expect' => '가맹점이 조회되면 카드 오류가 나옵니다. "가맹점을 찾을 수 없습니다" 면 계약기간·결제한도 등 PG 가맹점 설정 문제입니다',
    'pass'   => !$merchantMissing,
    'detail' => $b->success
        ? '⚠️ 더미 카드로 발급됨 — 확인 필요'
        : ($merchantMissing
            ? 'MID ' . (string) $cfg['mid'] . ' 로 가맹점이 조회되지 않습니다. 가맹점 관리자에서 **계약 시작일·종료일**이 비어 있지 않은지, **결제한도**가 0으로 막혀 있지 않은지 확인하세요. (' . $b->failReason . ')'
            : $b->failReason),
];

$passed = count(array_filter($tests, static fn (array $t): bool => (bool) $t['pass']));

echo json_encode([
    'ok'      => true,
    'mid'     => (string) $cfg['mid'],
    'driver'  => (string) $cfg['driver'],
    'passed'  => $passed,
    'total'   => count($tests),
    'tests'   => $tests,
    'message' => sprintf('%d/%d 통과', $passed, count($tests)),
], JSON_UNESCAPED_UNICODE);
