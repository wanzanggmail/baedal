<?php

declare(strict_types=1);

/**
 * 오픈뱅킹(금융결제원) 출금이체 게이트웨이 추상화 (LOGIC §5.4 · §7 #10).
 *
 * 대리점 명의 계좌(핀테크이용번호) → 라이더/대리점 계좌로 실시간 이체. 본사가 이용기관 계약주체(미정).
 * 실 연동 도착 시 RealOpenBankingGateway를 추가하고 팩토리만 교체.
 */
interface OpenBankingGateway
{
    /**
     * 출금이체(대리점 계좌 → 수취 계좌).
     *
     * @param array<string,mixed> $meta 부가정보(핀테크이용번호 등)
     */
    public function transfer(string $fromFintechNum, string $toBankCode, string $toAccount, int $amount, array $meta = []): TransferResult;
}

/**
 * 이체 결과.
 */
final class TransferResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $txId = '',
        public readonly string $failReason = ''
    ) {
    }

    public static function ok(string $txId): self
    {
        return new self(true, $txId);
    }

    public static function fail(string $reason): self
    {
        return new self(false, '', $reason);
    }
}

/**
 * 개발/데모용 모의 오픈뱅킹 게이트웨이.
 * - toAccount 가 'FAIL'을 포함하면 실패, 그 외 성공(모의 거래번호).
 * - amount <= 0 이면 성공 처리(탈퇴 라이더 0원 이체 종결용).
 */
final class MockOpenBankingGateway implements OpenBankingGateway
{
    public function transfer(string $fromFintechNum, string $toBankCode, string $toAccount, int $amount, array $meta = []): TransferResult
    {
        if ($amount <= 0) {
            return TransferResult::ok('MOCK-ZERO-' . date('YmdHis'));
        }
        if (str_contains(strtoupper($toAccount), 'FAIL')) {
            return TransferResult::fail('모의: 수취 계좌 오류');
        }

        return TransferResult::ok('OB-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(3)), 0, 6));
    }
}

final class OpenBankingGatewayFactory
{
    public static function make(): OpenBankingGateway
    {
        // TODO(Phase F 실연동): 금융결제원 이용기관 연동 시 교체.
        return new MockOpenBankingGateway();
    }
}
