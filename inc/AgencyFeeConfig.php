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
            // 대행수수료 요율은 2026-09-07 폐지(정산수수료와 통합). 남은 건 선차감뿐이다.
            'prededuct_fee' => 0,
        ];
    }

    /** 선차감 컬럼이 마이그레이션됐는지 — 없으면 0(사용 안 함)으로 동작. */
    public static function predeductReady(): bool
    {
        if (!db_table_exists('deduction_global_config')) {
            return false;
        }

        return in_array('agency_prededuct_fee', array_column(db_rows('SHOW COLUMNS FROM deduction_global_config'), 'Field'), true);
    }

    /**
     * 대리점 선차감 수수료(배달 건당 정액). org 행 → 전역 → 0 순 폴백.
     *
     * 라이더 정산 기준액을 건당 이 금액만큼 낮추고, 그 돈은 **대리점에 남는다**.
     * 라이더 지갑에 net 만 적립되고 대리점 지갑에서 빼가지 않으므로 별도 이체가 필요 없다.
     */
    public static function prededuct(?int $orgId = null): int
    {
        if (!self::predeductReady()) {
            return 0;
        }
        $row = null;
        if ($orgId !== null && $orgId > 0) {
            $row = db_row('SELECT agency_prededuct_fee v FROM deduction_global_config WHERE org_id = ? LIMIT 1', [$orgId]);
        }
        if ($row === null) {
            $row = db_row('SELECT agency_prededuct_fee v FROM deduction_global_config WHERE org_id IS NULL ORDER BY id ASC LIMIT 1');
        }

        return max(0, (int) ($row['v'] ?? 0));
    }

    public static function tableReady(): bool
    {
        if (!db_table_exists('deduction_global_config')) {
            return false;
        }

        $cols = array_column(db_rows('SHOW COLUMNS FROM deduction_global_config'), 'Field');

        // 2026-09-07: 대행수수료 요율 컬럼(agency_fee_short/long/day_threshold)은 **폐지**됐다.
        // 이 화면이 지금 다루는 건 공제 요율·최저 금액·선차감이므로 그 기준으로 본다.
        return in_array('withholding_tax_pct', $cols, true);
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

        $min = self::minimums();

        // 전역 기본값이 하한보다 낮으면 전용 설정이 없는 대리점이 하한을 우회한다 → 같이 알린다.
        // 2026-09-07: 하한 대상이 **정산수수료 본사 몫**(본사+세무대리+개발사)으로 바뀌었다.
        $globalBelow = false;
        if (db_table_exists('withdrawal_config')) {
            $g = db_row('SELECT * FROM withdrawal_config WHERE org_id IS NULL ORDER BY id ASC LIMIT 1');
            if ($g !== null) {
                $gShort = (int) ($g['hq_fee_short'] ?? 0) + (int) ($g['tax_fee_short'] ?? 0) + (int) ($g['dev_fee_short'] ?? 0);
                $gLong  = (int) ($g['hq_fee_long'] ?? 0) + (int) ($g['tax_fee_long'] ?? 0) + (int) ($g['dev_fee_long'] ?? 0);
                $globalBelow = ($min['fee_per_tx_short'] > 0 && $gShort < $min['fee_per_tx_short'])
                    || ($min['fee_per_tx_long'] > 0 && $gLong < $min['fee_per_tx_long']);
            }
        }

        return [
            'min'          => $min,
            'below'        => self::agenciesBelowMinimum(),
            'global_below' => $globalBelow,
        ];
    }

    /**
     * 공제 요율(원천세·고용보험·산재보험) — **전역 1벌**(2026-09-03 갑).
     *
     * 법정요율이라 대리점이 협상할 값이 아니다. 그래서 대리점별 오버라이드를 열지 않고
     * 본사가 정한 전역값만 쓴다(`SettlementLedger::deductionRates()` 가 org 행 → 전역 순으로
     * 폴백하는데, org 행은 대행수수료 저장 때만 만들어지고 요율은 전역값을 그대로 복사한다).
     *
     * @return array{withholding_tax_pct:float, employment_ins_pct:float, industrial_accident_ins_pct:float}
     */
    public static function rates(): array
    {
        $d = ['withholding_tax_pct' => 3.30, 'employment_ins_pct' => 0.80, 'industrial_accident_ins_pct' => 0.88];
        if (!db_table_exists('deduction_global_config')) {
            return $d;
        }
        $row = db_row(
            'SELECT withholding_tax_pct, employment_ins_pct, industrial_accident_ins_pct
               FROM deduction_global_config WHERE org_id IS NULL ORDER BY id ASC LIMIT 1'
        );
        if ($row === null) {
            return $d;
        }

        return [
            'withholding_tax_pct'         => (float) ($row['withholding_tax_pct'] ?? $d['withholding_tax_pct']),
            'employment_ins_pct'          => (float) ($row['employment_ins_pct'] ?? $d['employment_ins_pct']),
            'industrial_accident_ins_pct' => (float) ($row['industrial_accident_ins_pct'] ?? $d['industrial_accident_ins_pct']),
        ];
    }

    /**
     * 공제 요율 저장 — **본사 전용**(호출부에서 권한 확인).
     *
     * ⚠️ 대리점 전용 행(org_id NOT NULL)이 있으면 그 행의 요율이 우선 적용되므로
     *    **전역과 함께 모든 대리점 행도 같은 값으로 맞춘다.** 안 그러면 화면에서 바꿔도
     *    전용 행이 있는 대리점만 옛 요율로 남아 조용히 어긋난다.
     *
     * @param array<string, mixed> $data
     * @return array{rates:array<string,float>, synced_orgs:int}
     */
    public static function saveRates(array $data): array
    {
        if (!db_table_exists('deduction_global_config')) {
            throw new RuntimeException('deduction_global_config 가 없습니다. php migrate.php 를 실행하세요.');
        }

        $pct = static function ($v, float $def): float {
            $f = is_numeric($v) ? (float) $v : $def;

            return max(0.0, min(100.0, round($f, 2)));
        };
        $d = self::rates();

        $wt  = $pct($data['withholding_tax_pct'] ?? null, $d['withholding_tax_pct']);
        $emp = $pct($data['employment_ins_pct'] ?? null, $d['employment_ins_pct']);
        $acc = $pct($data['industrial_accident_ins_pct'] ?? null, $d['industrial_accident_ins_pct']);

        $exists = db_row('SELECT id FROM deduction_global_config WHERE org_id IS NULL ORDER BY id ASC LIMIT 1');
        if ($exists === null) {
            throw new RuntimeException('전역 기본 설정 행이 없습니다. php migrate.php 를 실행하세요.');
        }

        db_execute(
            'UPDATE deduction_global_config
                SET withholding_tax_pct = ?, employment_ins_pct = ?, industrial_accident_ins_pct = ?
              WHERE id = ?',
            [$wt, $emp, $acc, (int) $exists['id']]
        );

        // 대리점 전용 행들도 같은 요율로 동기화(요율은 대리점별로 다를 수 없다).
        $synced = db_execute(
            'UPDATE deduction_global_config
                SET withholding_tax_pct = ?, employment_ins_pct = ?, industrial_accident_ins_pct = ?
              WHERE org_id IS NOT NULL',
            [$wt, $emp, $acc]
        );

        return ['rates' => self::rates(), 'synced_orgs' => $synced];
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

        // 2026-09-07: 최저 금액이 걸리는 대상이 **정산수수료(withdrawal_config)** 로 바뀌었다.
        // 대행수수료가 폐지되면서 이 하한은 「정산수수료 배분의 본사 몫(본사+세무대리+개발사)」
        // 하한으로만 쓰인다. 그래서 그 값을 가진 테이블에서 미달 대리점을 찾는다.
        if (!db_table_exists('withdrawal_config')) {
            return [];
        }
        $cols = array_column(db_rows('SHOW COLUMNS FROM withdrawal_config'), 'Field');
        foreach (['hq_fee_short', 'hq_fee_long', 'tax_fee_short', 'dev_fee_short'] as $need) {
            if (!in_array($need, $cols, true)) {
                return [];
            }
        }

        return db_rows(
            'SELECT w.org_id,
                    o.name,
                    (w.hq_fee_short + w.tax_fee_short + w.dev_fee_short) AS agency_fee_short,
                    (w.hq_fee_long  + w.tax_fee_long  + w.dev_fee_long)  AS agency_fee_long
               FROM withdrawal_config w
               JOIN organizations o ON o.id = w.org_id
              WHERE w.org_id IS NOT NULL
                AND ((w.hq_fee_short + w.tax_fee_short + w.dev_fee_short) < ?
                  OR (w.hq_fee_long  + w.tax_fee_long  + w.dev_fee_long)  < ?)
              ORDER BY o.name ASC',
            [$min['fee_per_tx_short'], $min['fee_per_tx_long']]
        );
    }

    /**
     * 대리점(org)별 대행 수수료. org 행 → 전역 기본(org_id NULL) → PHP 기본 순 폴백.
     *
     * @return array<string, int>
     */
    /**
     * ⛔ **대행수수료(선정산수수료) 요율은 2026-09-07 폐지됐다.**
     *
     * 갑: "정산수수료랑 대행 수수료랑 같은건데 지금 혼재되어 사용된거 같아" · "합치는게 맞아"
     *
     * 2026-08-12 부터 일일정산에도 정산수수료(주문 건수 × 단가)를 부과하면서 이 수수료와
     * 하는 일이 겹쳤고, 이쪽을 걷어내지 않아 **같은 정산분에 두 번** 붙고 있었다.
     * 이제 정산수수료(`WithdrawalConfig`) 하나만 쓴다 — 주정산은 출금 신청 시,
     * 일정산은 일일이체 시, 둘 다 주문 건수 × 단가.
     *
     * 이 클래스에 남은 것: 공제 요율(원천세·고용·산재) · 최저 금액(정산수수료 본사 몫 하한)
     * · 대리점 선차감.
     */

    /**
     * 선차감 금액만 저장한다 — **대행수수료 값은 건드리지 않는다** (2026-09-06 갑).
     *
     * 「수수료 설정(관리)」(본사가 대리점을 골라 여는 화면)에서 쓴다. 그 화면은 대행수수료
     * 입력칸이 없어서 `save()` 를 쓰면 대행수수료가 기본값으로 덮여 **그 대리점 요율이 조용히
     * 바뀐다.** 그래서 이 컬럼만 쓰는 경로를 따로 둔다.
     *
     * 대상 org 행이 없으면 만든다 — 이때 대행수수료는 **지금 그 대리점에 적용되던 값**
     * (전역 상속분)을 그대로 복사해 동작이 바뀌지 않게 한다.
     *
     * @return int 저장된 금액
     */
    public static function savePrededuct(int $amount, ?int $orgId = null): int
    {
        if (!self::predeductReady()) {
            throw new RuntimeException('선차감 컬럼이 없습니다. php migrate.php 를 실행하세요.');
        }
        if ($amount < 0) {
            throw new InvalidArgumentException('선차감 수수료는 0원 이상이어야 합니다.');
        }
        if ($amount > 100000) {
            throw new InvalidArgumentException('선차감 수수료가 너무 큽니다(건당 100,000원 초과).');
        }

        $hasOrg = $orgId !== null && $orgId > 0;
        $exists = $hasOrg
            ? db_row('SELECT id FROM deduction_global_config WHERE org_id = ? LIMIT 1', [$orgId])
            : db_row('SELECT id FROM deduction_global_config WHERE org_id IS NULL ORDER BY id ASC LIMIT 1');

        if ($exists) {
            db_execute(
                'UPDATE deduction_global_config SET agency_prededuct_fee = ? WHERE id = ?',
                [$amount, (int) $exists['id']]
            );
        } else {
            $rates = self::rates();
            db_insert(
                'INSERT INTO deduction_global_config
                    (org_id, withholding_tax_pct, employment_ins_pct, industrial_accident_ins_pct,
                     agency_fee_pct, agency_prededuct_fee)
                 VALUES (?, ?, ?, ?, 0, ?)',
                [
                    $hasOrg ? $orgId : null,
                    $rates['withholding_tax_pct'],
                    $rates['employment_ins_pct'],
                    $rates['industrial_accident_ins_pct'],
                    $amount,
                ]
            );
        }

        return $amount;
    }
}
