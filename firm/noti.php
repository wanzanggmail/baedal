<?php

declare(strict_types=1);

/**
 * 바움P&S 「계좌이체 처리결과 통보」 수신 엔드포인트.
 *
 *   https://{도메인}/firm/noti.php
 *
 * 바움 관리자(또는 `PUT /api/firm/webhook`)에 이 URL 을 등록한다.
 *
 * 세션 인증이 없는 **공개 경로**다. 대신 ① 허용 IP, ② 본문 복호화 성공 여부로 막는다
 * (우리 키로 암호화된 본문이 아니면 복호화 자체가 실패한다).
 *
 * ⚠️ **응답 Body 도 암호화해야 한다.** 평문으로 주면 바움이 실패로 보고 1분 간격
 *    최대 10회 재전송한다. 처리 규칙은 `FirmWebhook` 에 있고 여기서는 입출력만 맡는다.
 *
 * 어떤 오류가 나도 반드시 응답으로 끝내야 하므로 Throwable 까지 잡는다.
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once INC_PATH . '/FirmWebhook.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

/**
 * 응답 출력. `$encrypt` 가 true 면 규격대로 암호문으로 내보낸다.
 *
 * 암호화에 실패하면(키 미설정 등) 평문으로라도 내보낸다 — 아무것도 안 주면
 * 바움 쪽이 타임아웃(60초)까지 기다리게 되어 더 나쁘다.
 *
 * @param array<string,mixed> $body
 */
$respond = static function (int $status, array $body, bool $encrypt): void {
    http_response_code($status);
    $json = (string) json_encode($body, JSON_UNESCAPED_UNICODE);

    if ($encrypt) {
        try {
            echo FirmConfig::crypto()->encrypt($json);

            return;
        } catch (Throwable $e) {
            error_log('[firm/noti] 응답 암호화 실패: ' . $e->getMessage());
        }
    }
    echo $json;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST 만 허용합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = (string) file_get_contents('php://input');

try {
    $res = FirmWebhook::handle($raw, $_SERVER);
    if (($res['note'] ?? '') !== '') {
        error_log('[firm/noti] ' . $res['note']);
    }
    $respond((int) $res['status'], $res['body'], (bool) $res['encrypt']);
} catch (Throwable $e) {
    error_log('[firm/noti] 처리 실패: ' . $e->getMessage());
    // 우리 쪽 오류다 — 실패를 돌려줘 재전송을 받는 게 맞다(그 사이 고칠 수 있다).
    $respond(500, ['success' => false], false);
}
