<?php

declare(strict_types=1);

/**
 * 메시지 발송 실패 예외 — 실패 사유 코드와 **대체발송 대상 여부**를 함께 전달한다(2026-09-02 갑).
 *
 * 실 발송사(알림톡) 연동 시, 응답 코드를 아래 사유 코드로 매핑해 이 예외를 던진다.
 * `fallbackEligible = true` 이면(카카오 미설치·차단·미사용자 등) 상위(`MessageQueue::send`)가
 * 같은 내용을 SMS 로 대체발송한다. 그 외(잘못된 템플릿·파라미터 등)는 대체발송하지 않는다.
 */
final class MessageDeliveryException extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        public readonly bool $fallbackEligible,
        string $message = ''
    ) {
        parent::__construct($message !== '' ? $message : $reasonCode);
    }
}
