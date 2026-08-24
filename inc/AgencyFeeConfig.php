<?php

declare(strict_types=1);

/**
 * 대행수수료(정산 반영 시 선공제) — 적립 일수 구간별 건당 정액
 *
 * 🆕 **2026-08-15 최저금액(하한).** 본사가 구간별 최저 건당 금액을 정하면 대리점은 그 아래로
 * 설정할 수 없다. 하한은 **전역 행(`org_id IS NULL`)에만** 저장하고 본사만 고친다 —
 * 대리점 행에서 읽으면 대리점이 자기 하한을 정하는 꼴이라 하한이 아니게 된다.
 * 저장 시 조용히 올려주지 않고 **거부**한다(대리점이 자기가 뭘 설정했는지 착각하면 안 되므로).
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

    /** 최저금액 컬럼이 이미 마이그레이션됐는지 — 없으면 하한 0으로 동작(기존 동작 유지). */
    public static function minimumReady(): bool
    {
        if (!db_table_exists('deduction_global_config')) {
            return false;
        }

        $cols = array_column(db_rows('SHOW COLUMNS FROM deduction_global_config'), 'Field');

        return in_array('agency_fee_min_short', $cols, true)
            && in_array('agency_fee_min_long', $cols, true);
    }

    /**
     * 본사가 정한 구간별 최저 건당 금액. **전역 행에서만** 읽는다.
     *
     * @return array{fee_per_tx_short:int, fee_per_tx_long:int}
     */
    public static function minimums(): array
    {
        if (!self::minimumReady()) {
            return ['fee_per_tx_short' => 0, 'fee_per_tx_long' => 0];
        }

        $row = db_row(
            'SELECT agency_fee_min_short, agency_fee_min_long
               FROM deduction_global_config WHERE org_id IS NULL ORDER BY id ASC LIMIT 1'
        );

        return [
            'fee_per_tx_short' => max(0, (int) ($row['agency_fee_min_short'] ?? 0)),
            'fee_per_tx_long'  => max(0, (int) ($row['agency_fee_min_long'] ?? 0)),
        ];
    }

    /**
     * 최저금액 저장 — **본사 전용**(호출부에서 권한 확인). 전역 행에 쓴다.
     *
     * 하한을 올리면 이미 그보다 낮게 설정해둔 대리점이 생긴다. 남의 요율을 말없이
     * 바꾸지 않고, 대신 어느 대리점이 걸리는지 돌려줘 화면에서 알려준다.
     *
     * @param array<string, mixed> $data
     * @return array{min:array{fee_per_tx_short:int,fee_per_tx_long:int}, below:list<array<string,mixed>>}
     */
    public static function saveMinimums(array $data): array
    {
        if (!self::minimumReady()) {
            throw new RuntimeException('최저금액 컬럼이 없습니다. php migrate.php 를 실행하세요.');
        }

        $minShort = max(0, (int) ($data['min_fee_per_tx_short'] ?? 0));
        $minLong  = max(0, (int) ($data['min_fee_per_tx_long'] ?? 0));

        $exists = db_row('SELECT id FROM deduction_global_config WHERE org_id IS NULL ORDER BY id ASC LIMIT 1');
        if ($exists === null) {
            throw new RuntimeException('전역 기본 설정 행이 없습니다. php migrate.php 를 실행하세요.');
        }

        db_execute(
            'UPDATE deduction_global_config SET agency_fee_min_short = ?, agency_fee_min_long = ? WHERE id = ?',
            [$minShort, $minLong, (int) $exists['id']]
        );

        $min    = self::minimums();
        $global = self::get(null);

        return [
            'min'   => $min,
            'below' => self::agenciesBelowMinimum(),
            // 전역 기본값이 하한보다 낮으면 전용 설정이 없는 대리점이 하한을 우회한다 → 같이 알린다.
            'global_below' => ($min['fee_per_tx_short'] > 0 && $global['fee_per_tx_short'] < $min['fee_per_tx_short'])
                || ($min['fee_per_tx_long'] > 0 && $global['fee_per_tx_long'] < $min['fee_per_tx_long']),
        ];
    }

    /**
     * 현재 하한보다 낮게 설정해둔 대리점 목록(하한을 올린 뒤 확인용).
     *
     * @return list<array<string,mixed>>
     */
    public static function agenciesBelowMinimum(): array
    {
        $min = self::minimums();
        if ($min['fee_per_tx_short'] <= 0 && $min['fee_per_tx_long'] <= 0) {
            return [];
        }

        return db_rows(
            'SELECT c.org_id, o.name, c.agency_fee_short, c.agency_fee_long
               FROM deduction_global_config c
               JOIN organizations o ON o.id = c.org_id
              WHERE c.org_id IS NOT NULL
                AND (c.agency_fee_short < ? OR c.agency_fee_long < ?)
              ORDER BY o.name ASC',
            [$min['fee_per_tx_short'], $min['fee_per_tx_long']]
        );
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

        // 본사가 정한 하한 검사 — 전역 기본값(본사 저장)에도 똑같이 건다.
        // 기본값이 하한보다 낮으면 전용 설정이 없는 대리점이 하한을 우회하게 되기 때문.
        $min = self::minimums();
        $tooLow = [];
        if ($min['fee_per_tx_short'] > 0 && $cfg['fee_per_tx_short'] < $min['fee_per_tx_short']) {
            $tooLow[] = sprintf('기준 미만 구간 %d원(최저 %d원)', $cfg['fee_per_tx_short'], $min['fee_per_tx_short']);
        }
        if ($min['fee_per_tx_long'] > 0 && $cfg['fee_per_tx_long'] < $min['fee_per_tx_long']) {
            $tooLow[] = sprintf('기준 이상 구간 %d원(최저 %d원)', $cfg['fee_per_tx_long'], $min['fee_per_tx_long']);
        }
        if ($tooLow !== []) {
            throw new InvalidArgumentException(
                '본사가 정한 최저 금액보다 낮게 설정할 수 없습니다 — ' . implode(' · ', $tooLow)
            );
        }

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
