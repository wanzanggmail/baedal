<?php

declare(strict_types=1);

/**
 * PG 카드결제 게이트웨이 추상화 (LOGIC §5.4 · §7 #8).
 *
 * 실 PG사는 본사가 결정하는 플랫폼 단일사(미정) — 실 스펙 도착 시 RealPgGateway를 추가하고
 * PgGatewayFactory만 교체하면 된다. 현재는 MockPgGateway로 흐름(다건 카드 대체결제)을 검증한다.
 */
interface PgGateway
{
    /**
     * 빌링키로 결제 승인.
     *
     * @param array<string,mixed> $meta 부가정보(mock_limit 등)
     */
    public function charge(string $billingKey, int $amount, array $meta = []): PgChargeResult;
}

/**
 * PG 결제 결과.
 */
final class PgChargeResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $tid = '',
        public readonly string $failCode = '',
        public readonly string $failReason = ''
    ) {
    }

    public static function ok(string $tid): self
    {
        return new self(true, $tid);
    }

    public static function fail(string $code, string $reason): self
    {
        return new self(false, '', $code, $reason);
    }

    /** 한도초과 등 "다음 카드로 재시도" 대상 실패인지. */
    public function isRetriable(): bool
    {
        return in_array($this->failCode, ['LIMIT_EXCEEDED', 'CARD_DECLINED', 'INSUFFICIENT'], true);
    }
}

/**
 * 개발/데모용 모의 PG 게이트웨이.
 * - meta['mock_limit'] > 0 이고 amount 가 그 값을 초과하면 LIMIT_EXCEEDED 실패(대체결제 테스트용)
 * - billingKey 가 'FAIL-'로 시작하면 무조건 실패
 * - 그 외에는 성공(모의 tid 발급)
 */
final class MockPgGateway implements PgGateway
{
    public function charge(string $billingKey, int $amount, array $meta = []): PgChargeResult
    {
        if (str_starts_with($billingKey, 'FAIL-')) {
            return PgChargeResult::fail('CARD_DECLINED', '모의: 카드 승인 거절');
        }
        $limit = (int) ($meta['mock_limit'] ?? 0);
        if ($limit > 0 && $amount > $limit) {
            return PgChargeResult::fail('LIMIT_EXCEEDED', sprintf('모의: 한도초과(%s > %s)', number_format($amount), number_format($limit)));
        }

        return PgChargeResult::ok('MOCK-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(3)), 0, 6));
    }
}

/**
 * 게이트웨이 팩토리 — 현재는 항상 Mock. 실 PG 계약 시 config 기반 분기.
 */
final class PgGatewayFactory
{
    public static function make(): PgGateway
    {
        // TODO(Phase F 실연동): if (config PG != 'mock') return new RealPgGateway(...);
        return new MockPgGateway();
    }
}
