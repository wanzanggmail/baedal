<?php

declare(strict_types=1);

require_once __DIR__ . '/OpenBankingGateway.php';
require_once __DIR__ . '/BankAccount.php';

/**
 * 오픈뱅킹 이체 실행 래퍼 (LOGIC §5.4 · §7 #10·#17).
 *
 * 대리점 계좌(핀테크이용번호) → 수취 계좌로 이체. DailyPayout/AgencyPayout/라이더 출금이 사용.
 * 실 연동 전에는 MockOpenBankingGateway가 결과를 시뮬레이션한다.
 * 계좌 미등록이어도 진행은 되도록(모의) 하되, 실 연동 시 fintech 없으면 실패시킬 것.
 */
final class Disbursement
{
    /**
     * @return TransferResult
     */
    public static function transfer(int $agencyId, string $toBankCode, string $toAccount, int $amount, array $meta = []): TransferResult
    {
        $fintech = BankAccount::fintechNum($agencyId);
        // 실 연동 시: if ($fintech === '') return TransferResult::fail('대리점 오픈뱅킹 계좌 미등록');
        $gateway = OpenBankingGatewayFactory::make();

        return $gateway->transfer($fintech, $toBankCode, $toAccount, $amount, $meta);
    }
}
