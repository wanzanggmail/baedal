<?php

declare(strict_types=1);

/**
 * 출금 정책 설정 (보증금·건당 수수료)
 */
final class WithdrawalConfig
{
    /** @return array<string, int> */
    public static function defaults(): array
    {
        return [
            'reserve_amount'    => 50000,
            'fee_day_threshold' => 7,
            'fee_per_tx_short'  => 80,
            'fee_per_tx_long'   => 40,
        ];
    }

    /**
     * 대리점(org)별 출금 정책. 해당 org 행 → 전역 기본(org_id NULL) → PHP 기본 순 폴백.
     *
     * @return array<string, int>
     */
    public static function get(?int $orgId = null): array
    {
        if (!db_table_exists('withdrawal_config')) {
            return self::defaults();
        }

        $row = null;
        if ($orgId !== null && $orgId > 0) {
            $row = db_row('SELECT * FROM withdrawal_config WHERE org_id = ? LIMIT 1', [$orgId]);
        }
        if ($row === null) {
            $row = db_row('SELECT * FROM withdrawal_config WHERE org_id IS NULL ORDER BY id ASC LIMIT 1');
        }
        if ($row === null) {
            return self::defaults();
        }

        $d = self::defaults();

        return [
            'reserve_amount'    => max(0, (int) ($row['reserve_amount'] ?? $d['reserve_amount'])),
            'fee_day_threshold' => max(1, (int) ($row['fee_day_threshold'] ?? $d['fee_day_threshold'])),
            'fee_per_tx_short'  => max(0, (int) ($row['fee_per_tx_short'] ?? $d['fee_per_tx_short'])),
            'fee_per_tx_long'   => max(0, (int) ($row['fee_per_tx_long'] ?? $d['fee_per_tx_long'])),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, int>
     */
    public static function save(array $data, ?int $orgId = null, ?int $adminId = null): array
    {
        if (!db_table_exists('withdrawal_config')) {
            throw new RuntimeException('withdrawal_config 테이블이 없습니다. php migrate.php 를 실행하세요.');
        }

        $cfg = [
            'reserve_amount'    => max(0, (int) ($data['reserve_amount'] ?? 0)),
            'fee_day_threshold' => max(1, min(365, (int) ($data['fee_day_threshold'] ?? 7))),
            'fee_per_tx_short'  => max(0, (int) ($data['fee_per_tx_short'] ?? 0)),
            'fee_per_tx_long'   => max(0, (int) ($data['fee_per_tx_long'] ?? 0)),
        ];

        $hasOrg  = $orgId !== null && $orgId > 0;
        $exists  = $hasOrg
            ? db_row('SELECT id FROM withdrawal_config WHERE org_id = ? LIMIT 1', [$orgId])
            : db_row('SELECT id FROM withdrawal_config WHERE org_id IS NULL ORDER BY id ASC LIMIT 1');

        if ($exists) {
            db_execute(
                'UPDATE withdrawal_config
                 SET reserve_amount = ?, fee_day_threshold = ?, fee_per_tx_short = ?, fee_per_tx_long = ?,
                     updated_by = ?, updated_at = NOW()
                 WHERE id = ?',
                [
                    $cfg['reserve_amount'],
                    $cfg['fee_day_threshold'],
                    $cfg['fee_per_tx_short'],
                    $cfg['fee_per_tx_long'],
                    ($adminId !== null && $adminId > 0) ? $adminId : null,
                    (int) $exists['id'],
                ]
            );
        } else {
            db_insert(
                'INSERT INTO withdrawal_config
                    (org_id, reserve_amount, fee_day_threshold, fee_per_tx_short, fee_per_tx_long, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $hasOrg ? $orgId : null,
                    $cfg['reserve_amount'],
                    $cfg['fee_day_threshold'],
                    $cfg['fee_per_tx_short'],
                    $cfg['fee_per_tx_long'],
                    ($adminId !== null && $adminId > 0) ? $adminId : null,
                ]
            );
        }

        return self::get($orgId);
    }

    public static function feeForAccruedDays(int $accruedDays, ?int $orgId = null): int
    {
        $cfg = self::get($orgId);

        return $accruedDays < $cfg['fee_day_threshold']
            ? $cfg['fee_per_tx_short']
            : $cfg['fee_per_tx_long'];
    }
}
