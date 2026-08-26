<?php

declare(strict_types=1);

require_once __DIR__ . '/OpenBankingGateway.php'; // TransferResult 재사용

/**
 * 펌뱅킹 이체 게이트웨이 (중계사: 바움P&S) — LOGIC §5.4 · §7 #10.
 *
 * 기존 흐름은 "은행 이체파일 다운로드 → 은행에서 수동 이체 → 관리자가 입금완료 클릭"이었다.
 * 여기서는 **대리점이 「출금 확정」을 누르면 그 자리에서 이체가 실행**된다.
 *
 * 중계사는 **바움P&S** 로 확정됐다(2026-08-26, 매뉴얼 v1.1.8). 실 구현은 `BaumFirmGateway`,
 * 설정은 `FirmConfig` 에 있고 `FirmBankingGatewayFactory::make()` 가 둘을 갈라준다.
 *
 * ⚠️ **바움의 이체는 비동기다** — 접수(RECEPTION)만 즉시 응답하고 성공/실패는 웹훅으로 온다.
 *    따라서 `transfer()` 의 성공은 "접수됨" 이지 "돈이 나갔음" 이 아니다.
 *    호출부(`Withdrawal::executeTransfers()`)는 접수를 「접수중」으로 기록하고,
 *    확정은 `FirmWebhook`(통보) 또는 `FirmReconciler`(보정 조회)가 한다.
 *
 * 오픈뱅킹(`OpenBankingGateway`, 금융결제원)과 별도로 둔 이유 — 사업자·인증 체계가 다르고
 * 라이더 출금은 펌뱅킹으로, 기존 일일정산·대리점 인출은 그대로 오픈뱅킹 경로를 쓰기 때문.
 * 결과 타입(`TransferResult`)은 동일한 것을 공유한다.
 */
interface FirmBankingGateway
{
    /**
     * 이체 실행 (**본사 단일 출금계좌** → 수취 계좌).
     *
     * ⚠️ `$agencyId`는 **어느 조직 몫에서 나가는지 기록용**이지 출금 계좌를 고르는 값이 아니다.
     *    2026-08-15 갑 확정으로 실제로 돈이 나가는 계좌는 본사 하나뿐이다(`BankAccount::payerFintechNum()`).
     *
     * @param array<string,mixed> $meta 부가정보(요청 id·라이더코드 등 추적용)
     */
    public function transfer(int $agencyId, string $toBankCode, string $toAccount, string $holder, int $amount, array $meta = []): TransferResult;

    /** 중계사 이름(화면·로그 표기용) */
    public function providerLabel(): string;

    /**
     * 이체 결과가 **나중에** 오는가.
     *
     * true 면 `transfer()` 의 성공은 "접수됨" 일 뿐이다 — 호출부는 이때 **지갑을 건드리면 안 되고**,
     * 웹훅(또는 보정 조회)으로 SUCCESS 를 받은 뒤에 확정해야 한다.
     * false 면 응답이 곧 결과이므로 그 자리에서 완료 처리해도 된다(모의 게이트웨이).
     */
    public function isAsync(): bool;
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
    public function __construct(private readonly string $provider = '모의 펌뱅킹')
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

    /** 모의는 응답이 곧 결과다. */
    public function isAsync(): bool
    {
        return false;
    }
}

final class FirmBankingGatewayFactory
{
    /**
     * 설정(`firm_config.driver`)에 따라 실 게이트웨이/모의를 고른다.
     *
     * 중계사는 **바움P&S** 로 확정됐다(2026-08-26 매뉴얼 v1.1.8 수령).
     * 자격증명이 하나라도 빠지면 **조용히 모의로 떨어진다** — 설정 화면에서 "실 연동 준비됨"
     * 배지로 상태를 확인할 수 있다. 안 되는 걸 되는 척하는 것보다 낫다.
     *
     * ⚠️ 실 연동은 이체 결과가 **비동기**로 온다(`BaumFirmGateway` 주석).
     *    호출부는 `isAsync()` 를 보고 접수와 확정을 나눠 처리한다.
     */
    public static function make(): FirmBankingGateway
    {
        require_once __DIR__ . '/FirmConfig.php';

        if (FirmConfig::isReady()) {
            require_once __DIR__ . '/BaumFirmGateway.php';

            return new BaumFirmGateway();
        }

        return new MockFirmBankingGateway();
    }

    /** 현재 모의 게이트웨이로 동작 중인지 — 화면에 "모의" 배지를 띄우는 데 쓴다. */
    public static function isMock(): bool
    {
        return self::make() instanceof MockFirmBankingGateway;
    }
}
