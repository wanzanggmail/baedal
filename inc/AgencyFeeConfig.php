<?php

declare(strict_types=1);

/**
 * 정산 선공제(대행 수수료) — 적립 일수 구간별 건당 정액
 */
final class AgencyFeeConfig
{
    /** @return array<string, int> */
    public static function defaults(): array
    {
        return [
            'fee_day_threshold' => 7,
            'fee_per_tx_short'  => 80,
            'fee_per_tx_long'   => 40,
        ];
    }

    public static function tableReady(): bool
    {
        if (!db_table_exists('deduction_global_config')) {
            return false;
        }

        $cols = array_column(db_rows('SHOW COLUMNS FROM deduction_global_config'), 'Field');

        return in_array('agency_fee_day_threshold', $cols, true)
            && in_array('agency_fee_short', $cols, true)
            && in_array('agency_fee_long', $cols, true);
    }

    /**
     * 대리점(org)별 대행 수수료. org 행 → 전역 기본(org_id NULL) → PHP 기본 순 폴백.
     *
     * @return array<string, int>
     */
    public static function get(?int $orgId = null): array
    {
        if (!self::tableReady()) {
            return self::defaults();
        }

        $row = null;
        if ($orgId !== null && $orgId > 0) {
            $row = db_row(
                'SELECT agency_fee_day_threshold, agency_fee_short, agency_fee_long
                   FROM deduction_global_config WHERE org_id = ? LIMIT 1',
                [$orgId]
            );
        }
        if ($row === null) {
            $row = db_row(
                'SELECT agency_fee_day_threshold, agency_fee_short, agency_fee_long
                   FROM deduction_global_config WHERE org_id IS NULL ORDER BY id ASC LIMIT 1'
            );
        }
        if ($row === null) {
            return self::defaults();
        }

        $d = self::defaults();

        return [
            'fee_day_threshold' => max(1, (int) ($row['agency_fee_day_threshold'] ?? $d['fee_day_threshold'])),
            'fee_per_tx_short'  => max(0, (int) ($row['agency_fee_short'] ?? $d['fee_per_tx_short'])),
            'fee_per_tx_long'   => max(0, (int) ($row['agency_fee_long'] ?? $d['fee_per_tx_long'])),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, int>
     */
    public static function save(array $data, ?int $orgId = null, ?int $adminId = null): array
    {
        if (!self::tableReady()) {
            throw new RuntimeException('deduction_global_config 컬럼이 없습니다. php migrate.php 를 실행하세요.');
        }

        $cfg = [
            'fee_day_threshold' => max(1, min(365, (int) ($data['fee_day_threshold'] ?? 7))),
            'fee_per_tx_short'  => max(0, (int) ($data['fee_per_tx_short'] ?? 0)),
            'fee_per_tx_long'   => max(0, (int) ($data['fee_per_tx_long'] ?? 0)),
        ];

        $hasOrg = $orgId !== null && $orgId > 0;
        $exists = $hasOrg
            ? db_row('SELECT id FROM deduction_global_config WHERE org_id = ? LIMIT 1', [$orgId])
            : db_row('SELECT id FROM deduction_global_config WHERE org_id IS NULL ORDER BY id ASC LIMIT 1');

        if ($exists) {
            db_execute(
                'UPDATE deduction_global_config
                 SET agency_fee_day_threshold = ?, agency_fee_short = ?, agency_fee_long = ?
                 WHERE id = ?',
                [$cfg['fee_day_threshold'], $cfg['fee_per_tx_short'], $cfg['fee_per_tx_long'], (int) $exists['id']]
            );
        } else {
            db_insert(
                'INSERT INTO deduction_global_config
                    (org_id, withholding_tax_pct, employment_ins_pct, industrial_accident_ins_pct, agency_fee_pct,
                     agency_fee_day_threshold, agency_fee_short, agency_fee_long)
                 VALUES (?, 3.30, 0.80, 0.88, 0, ?, ?, ?)',
                [$hasOrg ? $orgId : null, $cfg['fee_day_threshold'], $cfg['fee_per_tx_short'], $cfg['fee_per_tx_long']]
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
