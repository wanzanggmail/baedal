<?php

declare(strict_types=1);

/**
 * 대리점 지갑 (agency_wallets) — PG 카드결제로 충전된 잔액 + 원천세 예수금 누적.
 *
 * 자금 흐름(LOGIC §5.4·§5.5):
 *   PG 카드결제(FUND) → balance 충전 → 오픈뱅킹 이체(DISBURSE)로 라이더 지급 → balance 차감
 *   원천세 대상 라이더 공제분은 withholding_reserve로 누적(대리점이 신고·납부할 예수금)
 *   대리점 자체 인출가능액 = balance − 라이더채무(rider_wallets 합계) − withholding_reserve
 */
final class AgencyWallet
{
    public static function tableExists(): bool
    {
        return db_table_exists('agency_wallets');
    }

    public static function ensure(int $agencyId): void
    {
        if ($agencyId < 1 || !self::tableExists()) {
            return;
        }
        $exists = db_row('SELECT agency_id FROM agency_wallets WHERE agency_id = ? LIMIT 1', [$agencyId]);
        if ($exists === null) {
            db_insert(
                'INSERT INTO agency_wallets (agency_id, balance, withholding_reserve) VALUES (?, 0, 0)',
                [$agencyId]
            );
        }
    }

    /** @return array{balance:int, withholding_reserve:int} */
    public static function get(int $agencyId): array
    {
        self::ensure($agencyId);
        $row = db_row('SELECT balance, withholding_reserve FROM agency_wallets WHERE agency_id = ? LIMIT 1', [$agencyId]);

        return [
            'balance'             => (int) ($row['balance'] ?? 0),
            'withholding_reserve' => (int) ($row['withholding_reserve'] ?? 0),
        ];
    }

    /**
     * 라이더 채무 — 이 대리점 소속 라이더들에게 아직 지급해야 할 지갑 잔액 합계.
     */
    public static function riderDebt(int $agencyId): int
    {
        if ($agencyId < 1 || !db_table_exists('rider_wallets') || !db_table_exists('riders')) {
            return 0;
        }
        $row = db_row(
            'SELECT COALESCE(SUM(w.balance), 0) AS debt
               FROM rider_wallets w
               INNER JOIN riders r ON r.id = w.rider_id
              WHERE r.agency_id = ?',
            [$agencyId]
        );

        return (int) ($row['debt'] ?? 0);
    }

    /**
     * 대리점 자체 인출가능액 = balance − 라이더채무 − 원천세 예수금 (0 하한).
     *
     * @return array{balance:int, rider_debt:int, withholding_reserve:int, withdrawable:int}
     */
    public static function withdrawable(int $agencyId): array
    {
        $w        = self::get($agencyId);
        $debt     = self::riderDebt($agencyId);
        $reserve  = (int) $w['withholding_reserve'];
        $avail    = max(0, (int) $w['balance'] - $debt - $reserve);

        return [
            'balance'             => (int) $w['balance'],
            'rider_debt'          => $debt,
            'withholding_reserve' => $reserve,
            'withdrawable'        => $avail,
        ];
    }

    /**
     * 원천세 예수금 누적 (정산 반영 시 원천세 대상 라이더분).
     * balance 이동이 아니라 별도 예수금 accumulator라 원장(ledger)은 남기지 않음.
     */
    public static function addWithholdingReserve(int $agencyId, int $amount): void
    {
        if ($agencyId < 1 || $amount === 0 || !self::tableExists()) {
            return;
        }
        self::ensure($agencyId);
        db_execute(
            'UPDATE agency_wallets SET withholding_reserve = withholding_reserve + ?, updated_at = NOW() WHERE agency_id = ?',
            [$amount, $agencyId]
        );
    }

    /**
     * 잔액 충전(credit) — PG 카드결제 성공 시 등. 원장 기록.
     */
    public static function credit(int $agencyId, int $amount, string $reason, ?int $refId = null, string $note = '', ?int $adminId = null): void
    {
        self::move($agencyId, 'credit', $amount, $reason, $refId, $note, $adminId);
    }

    /**
     * 잔액 차감(debit) — 라이더 지급·대리점 자체 인출 등. 원장 기록.
     */
    public static function debit(int $agencyId, int $amount, string $reason, ?int $refId = null, string $note = '', ?int $adminId = null): void
    {
        self::move($agencyId, 'debit', $amount, $reason, $refId, $note, $adminId);
    }

    /**
     * 잔액을 특정 값으로 직접 설정(수동 조정, 본사 전용). 차액을 원장에 기록.
     */
    public static function setBalance(int $agencyId, int $newBalance, string $note, ?int $adminId = null): void
    {
        if ($agencyId < 1 || !self::tableExists()) {
            return;
        }
        $cur   = self::get($agencyId)['balance'];
        $delta = $newBalance - $cur;
        if ($delta === 0) {
            return;
        }
        self::move($agencyId, $delta > 0 ? 'credit' : 'debit', abs($delta), 'manual_adjust', null, $note, $adminId);
    }

    private static function move(int $agencyId, string $direction, int $amount, string $reason, ?int $refId, string $note, ?int $adminId): void
    {
        if ($agencyId < 1 || $amount <= 0 || !self::tableExists()) {
            return;
        }
        self::ensure($agencyId);

        if ($direction === 'debit') {
            db_execute('UPDATE agency_wallets SET balance = balance - ?, updated_at = NOW() WHERE agency_id = ?', [$amount, $agencyId]);
        } else {
            db_execute('UPDATE agency_wallets SET balance = balance + ?, updated_at = NOW() WHERE agency_id = ?', [$amount, $agencyId]);
        }

        $balanceAfter = self::get($agencyId)['balance'];

        if (db_table_exists('agency_wallet_ledger')) {
            db_insert(
                'INSERT INTO agency_wallet_ledger
                    (agency_id, direction, reason, amount, balance_after, ref_id, note, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $agencyId,
                    $direction,
                    mb_substr($reason, 0, 40),
                    $amount,
                    $balanceAfter,
                    ($refId !== null && $refId > 0) ? $refId : null,
                    mb_substr($note, 0, 300),
                    ($adminId !== null && $adminId > 0) ? $adminId : null,
                ]
            );
        }
    }

    /**
     * 잔액 변동 이력.
     *
     * @return list<array<string,mixed>>
     */
    public static function ledger(int $agencyId, int $limit = 100): array
    {
        if ($agencyId < 1 || !db_table_exists('agency_wallet_ledger')) {
            return [];
        }
        $limit = max(1, min(500, $limit));

        return db_rows(
            'SELECT id, direction, reason, amount, balance_after, ref_id, note, created_at
               FROM agency_wallet_ledger
              WHERE agency_id = ?
              ORDER BY id DESC
              LIMIT ' . $limit,
            [$agencyId]
        );
    }
}
