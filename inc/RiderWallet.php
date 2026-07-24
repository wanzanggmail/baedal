<?php

declare(strict_types=1);

require_once __DIR__ . '/WithdrawalConfig.php';

/**
 * 라이더 지갑 (누적 잔액·적립 일수 — 정산 반영은 추후 일원화)
 */
final class RiderWallet
{
    /** @return array{balance: int, accrued_days: int} */
    public static function get(int $riderId): array
    {
        self::ensure($riderId);
        $row = db_row(
            'SELECT balance, accrued_days FROM rider_wallets WHERE rider_id = ? LIMIT 1',
            [$riderId]
        );

        return [
            'balance'      => (int) ($row['balance'] ?? 0),
            'accrued_days' => (int) ($row['accrued_days'] ?? 0),
        ];
    }

    public static function ensure(int $riderId): void
    {
        if ($riderId < 1 || !db_table_exists('rider_wallets')) {
            return;
        }

        $exists = db_row('SELECT rider_id FROM rider_wallets WHERE rider_id = ? LIMIT 1', [$riderId]);
        if ($exists !== null) {
            return;
        }

        db_insert(
            'INSERT INTO rider_wallets (rider_id, balance, accrued_days) VALUES (?, 0, 0)',
            [$riderId]
        );
    }

    /**
     * 출금 가능 금액 미리보기 (전액 출금 정책)
     *
     * @return array<string, int|bool|string>
     */
    public static function previewWithdrawal(int $riderId): array
    {
        $wallet = self::get($riderId);
        // 멀티테넌시: 라이더 소속 대리점의 출금 정책 사용 (없으면 전역 기본)
        $agencyId = (int) (db_row('SELECT agency_id FROM riders WHERE id = ? LIMIT 1', [$riderId])['agency_id'] ?? 0);
        $orgId    = $agencyId > 0 ? $agencyId : null;
        $cfg     = WithdrawalConfig::get($orgId);
        $balance = (int) $wallet['balance'];
        $accrued = (int) $wallet['accrued_days'];
        $reserve = (int) $cfg['reserve_amount'];
        $fee     = WithdrawalConfig::feeForAccruedDays($accrued, $orgId);
        $afterReserve = max(0, $balance - $reserve);
        $payout  = max(0, $afterReserve - $fee);

        return [
            'balance'           => $balance,
            'accrued_days'      => $accrued,
            'reserve_amount'    => $reserve,
            'fee_per_tx'        => $fee,
            'fee_day_threshold' => (int) $cfg['fee_day_threshold'],
            'after_reserve'     => $afterReserve,
            'payout_amount'     => $payout,
            'can_apply'         => $payout > 0,
        ];
    }

    /**
     * 출금 완료 후 지갑 정리 — 실제로 빠져나간 금액만큼 **차감**한다.
     *
     * 🐛 2026-07-24 수정: 이전 구현은 `balance = 보증금`으로 **대입**했다. 전액출금이라
     * 정상 경로에서는 결과가 같지만, **출금 신청(pending) ~ 완료 사이에 정산이 반영되면**
     * (`credit()`으로 잔액이 늘어남) 그 금액이 완료 시점에 덮어써져 **소멸**했다.
     *   예) 신청 시 159,000(보증금 50,000·수수료 3,640·지급 105,360)
     *       → 중간에 정산 +40,000 → 잔액 199,000
     *       → 완료 시 `balance = 50,000` 이 되며 40,000 증발
     * 차감 방식으로 바꾸면 199,000 − 109,000 = 90,000 으로 신규 입금분이 보존된다.
     *
     * @param int $deductedTotal 지갑에서 실제로 빠져나가는 총액 = 실지급액 + 정산수수료
     */
    public static function deductAfterWithdrawal(int $riderId, int $deductedTotal): void
    {
        if ($riderId < 1 || !db_table_exists('rider_wallets')) {
            return;
        }

        self::ensure($riderId);
        // GREATEST(0, ...): 완료 전 잔액이 수동조정 등으로 줄어든 경우에도 음수 방지
        db_execute(
            'UPDATE rider_wallets
                SET balance = GREATEST(0, balance - ?), accrued_days = 0, updated_at = NOW()
              WHERE rider_id = ?',
            [max(0, $deductedTotal), $riderId]
        );
    }

    /**
     * 정산 반영용 (추후 일원화 시 사용)
     */
    public static function credit(int $riderId, int $amount, bool $incrementAccruedDay = true): void
    {
        if ($riderId < 1 || $amount === 0 || !db_table_exists('rider_wallets')) {
            return;
        }

        self::ensure($riderId);
        if ($incrementAccruedDay) {
            db_execute(
                'UPDATE rider_wallets
                 SET balance = balance + ?, accrued_days = accrued_days + 1, updated_at = NOW()
                 WHERE rider_id = ?',
                [$amount, $riderId]
            );
        } else {
            db_execute(
                'UPDATE rider_wallets SET balance = balance + ?, updated_at = NOW() WHERE rider_id = ?',
                [$amount, $riderId]
            );
        }
    }
}
