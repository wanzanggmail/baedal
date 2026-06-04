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
        $cfg    = WithdrawalConfig::get();
        $balance = (int) $wallet['balance'];
        $accrued = (int) $wallet['accrued_days'];
        $reserve = (int) $cfg['reserve_amount'];
        $fee     = WithdrawalConfig::feeForAccruedDays($accrued);
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
     * 출금 완료 후 지갑 정리 — 보증금만 남기고 적립 일수 초기화
     */
    public static function finalizeAfterComplete(int $riderId, int $reserveAmount): void
    {
        if ($riderId < 1 || !db_table_exists('rider_wallets')) {
            return;
        }

        self::ensure($riderId);
        db_execute(
            'UPDATE rider_wallets SET balance = ?, accrued_days = 0, updated_at = NOW() WHERE rider_id = ?',
            [max(0, $reserveAmount), $riderId]
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
