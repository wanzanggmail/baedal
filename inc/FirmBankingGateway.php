<?php

declare(strict_types=1);

require_once __DIR__ . '/OpenBankingGateway.php'; // TransferResult 재사용

/**
 * 펌뱅킹 이체 게이트웨이 (쿠콘·하이픈 등 중계사) — LOGIC §5.4 · §7 #10.
 *
 * 기존 흐름은 "은행 이체파일 다운로드 → 은행에서 수동 이체 → 관리자가 입금완료 클릭"이었다.
 * 여기서는 **대리점이 「출금 확정」을 누르면 그 자리에서 이체가 실행**된다.
 *
 * ⚠️ 실 API 미도착 — 현재는 `MockFirmBankingGateway`가 결과를 시뮬레이션한다.
 *    쿠콘/하이픈 스펙이 오면 `FirmBankingGateway`를 구현한 Real 클래스를 추가하고
 *    `FirmBankingGatewayFactory::make()`만 교체하면 된다(호출부 수정 불필요).
 *
 * 오픈뱅킹(`OpenBankingGateway`, 금융결제원)과 별도로 둔 이유 — 사업자·인증 체계가 다르고
 * 라이더 출금은 펌뱅킹으로, 기존 일일정산·대리점 인출은 그대로 오픈뱅킹 경로를 쓰기 때문.
 * 결과 타입(`TransferResult`)은 동일한 것을 공유한다.
 */
interface FirmBankingGateway
{
    /**
     * 이체 실행 (대리점 출금계좌 → 수취 계좌).
     *
     * @param array<string,mixed> $meta 부가정보(요청 id·라이더코드 등 추적용)
     */
    public function transfer(int $agencyId, string $toBankCode, string $toAccount, string $holder, int $amount, array $meta = []): TransferResult;

    /** 중계사 이름(화면·로그 표기용) */
    public function providerLabel(): string;
}

/**
 * 개발/데모용 모의 펌뱅킹 게이트웨이.
 *
 * 실패 재현 규칙(실 연동 전 테스트용):
 *  - 계좌번호에 'FAIL' 포함 → 실패
 *  - 계좌번호가 비어 있음 → 실패(계좌 미등록)
 *  - 금액이 0 이하 → 실패(이체할 금액 없음)
 */
final class MockFirmBankingGateway implements FirmBankingGateway
{
    public function __construct(private readonly string $provider = '쿠콘(모의)')
    {
    }

    public function transfer(int $agencyId, string $toBankCode, string $toAccount, string $holder, int $amount, array $meta = []): TransferResult
    {
        if ($amount <= 0) {
            return TransferResult::fail('이체 금액이 0원입니다.');
        }
        if (trim($toAccount) === '' || trim($toBankCode) === '') {
            return TransferResult::fail('수취 계좌가 등록돼 있지 않습니다.');
        }
        if (str_contains(strtoupper($toAccount), 'FAIL')) {
            return TransferResult::fail('모의: 수취 계좌 오류(예금주 불일치)');
        }

        return TransferResult::ok('FB-' . date('YmdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)));
    }

    public function providerLabel(): string
    {
        return $this->provider;
    }
}

final class FirmBankingGatewayFactory
{
    /**
     * 실 연동 시 이 메서드만 교체한다.
     * 중계사 선택(쿠콘/하이픈)은 스펙 도착 후 설정값으로 분기할 예정.
     */
    public static function make(): FirmBankingGateway
    {
        return new MockFirmBankingGateway();
    }

    /** 현재 모의 게이트웨이로 동작 중인지 — 화면에 "모의" 배지를 띄우는 데 쓴다. */
    public static function isMock(): bool
    {
        return self::make() instanceof MockFirmBankingGateway;
    }
}
