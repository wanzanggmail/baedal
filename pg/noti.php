<?php

declare(strict_types=1);

/**
 * PG(위루트) 결제통지 수신 엔드포인트 — 가맹점 관리자에 등록할 Noti URL.
 *
 *   https://{도메인}/pg/noti.php
 *
 * 세션 인증이 없는 **공개 경로**다. 대신 발신 IP(기본 221.168.33.162)와 서명으로 막는다.
 * 처리 규칙과 응답 규격은 PgWebhook 에 있다 — 여기서는 입출력만 맡는다.
 *
 * ⚠️ 응답 규격을 어기면 1분 간격으로 재전송된다(문서 §5-4). 어떤 오류가 나도
 *    반드시 JSON 으로 끝내야 하므로 Throwable 까지 잡는다.
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once INC_PATH . '/PgWebhook.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['message' => 'POST 만 허용합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = (string) file_get_contents('php://input');

try {
    $res = PgWebhook::handle($raw, $_SERVER);
} catch (Throwable $e) {
    // 여기까지 온 예외는 우리 버그다. PG에게는 실패를 알려 재전송을 받는 편이
    // 통지를 잃는 것보다 낫다(멱등 처리가 있으므로 중복 저장 걱정은 없다).
    error_log('[pg/noti] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['message' => '내부 오류로 처리하지 못했습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code((int) $res['status']);

// ⚠️ 빈 배열을 그냥 인코딩하면 `[]` 가 된다 — 규격은 `{}` 다(문서 §5-4).
// 다르면 성공인데도 규격 위반으로 보고 1분마다 재전송한다. JSON_FORCE_OBJECT 로 고정한다.
echo json_encode($res['body'], JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT);
