<?php

declare(strict_types=1);

require_once __DIR__ . '/OpenBankingGateway.php';
require_once __DIR__ . '/BankAccount.php';

/**
 * 오픈뱅킹 이체 실행 래퍼 (LOGIC §5.4 · §7 #10·#17).
 *
 * **출금 원천은 언제나 본사 단일 계좌다**(2026-08-15 갑 확정: "라이더에게 출금하는 계좌는 하나").
 * DailyPayout / AgencyPayout / 라이더 출금이 모두 이 계좌에서 나간다.
 *
 * ⚠️ 이전에는 `$agencyId`의 계좌를 출금 원천으로 조회했다. 그러면 대리점 자체 인출이
 *    **자기 계좌에서 자기 계좌로** 보내는 꼴이 된다(수취 계좌와 같은 행이라서).
 *    지금은 `$agencyId`를 **누구 몫에서 나가는지 기록용**으로만 받는다.
 *
 * 실 연동 전에는 MockOpenBankingGateway가 결과를 시뮬레이션한다.
 */
final class Disbursement
{
    /**
     * @param int $agencyId 어느 조직 몫에서 나가는지(장부 추적용). 출금 계좌 선택과는 무관.
     */
    public static function transfer(int $agencyId, string $toBankCode, string $toAccount, int $amount, array $meta = []): TransferResult
    {
        $fintech = BankAccount::payerFintechNum();
        // 실 연동 시: if ($fintech === '') return TransferResult::fail('본사 출금 계좌 미등록');
        $gateway = OpenBankingGatewayFactory::make();

        return $gateway->transfer($fintech, $toBankCode, $toAccount, $amount, $meta + ['from_org' => $agencyId]);
    }
}
