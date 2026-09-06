<?php

declare(strict_types=1);

/**
 * 플랫폼 수수료 (구 "영업대행수수료") 분배 요율 — org_fee_config.
 *
 * 대리점의 PG 카드결제 시 붙는 **플랫폼 수수료**를 본사·총판·대리점이 나눠 갖는다.
 *
 * ⚠️ 2026-08-04 구조 변경: 예전에는 조직마다 "내 몫" 1개를 두고
 *    (대리점몫 + 상위총판몫 + 본사몫)으로 합산했다 — 즉 본사·총판 값이 하위 전체에 공유됐다.
 *    이제는 **대리점 행 하나에 본사/총판/대리점 몫 3개를 모두 저장**하므로
 *    대리점마다 본사·총판 몫까지 다르게 줄 수 있다.
 *    (컬럼: hq_pct / distributor_pct / agency_pct. 구 pg_service_fee_pct는 이관 후 미사용)
 */
final class PgFeeConfig
{
    /**
     * 신규 조직 생성 시 넣어두는 분배비율 기본값.
     *
     * 2026-08-08 갑 확정 — **이 값은 중요하지 않다.** 분배비율은 계약할 때마다 달라지고
     * 조직을 등록할 때 매번 직접 입력하므로, 1%는 "빈 값 방지용 자리값"일 뿐이다.
     * (예전에 "확정값 대기 중인 임시값"으로 관리했으나 그런 성격이 아님이 확인됨)
     */
    private const DEFAULT_PCT = 1.00;

    public static function tableExists(): bool
    {
        return db_table_exists('org_fee_config');
    }

    private static function hasSplitColumns(): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        if (!self::tableExists()) {
            return $cache = false;
        }
        $cols = array_column(db_rows('SHOW COLUMNS FROM org_fee_config'), 'Field');

        return $cache = in_array('hq_pct', $cols, true)
            && in_array('distributor_pct', $cols, true)
            && in_array('agency_pct', $cols, true);
    }

    /**
     * 전역 기본값 — 대리점별 설정이 없을 때 쓰이는 값.
     *
     * @return array{agency:float, distributor:float, hq:float}
     */
    public static function globalBreakdown(): array
    {
        $d = ['agency' => self::DEFAULT_PCT, 'distributor' => self::DEFAULT_PCT, 'hq' => self::DEFAULT_PCT];
        if (!self::hasSplitColumns()) {
            return $d;
        }
        $row = db_row('SELECT hq_pct, distributor_pct, agency_pct FROM org_fee_config WHERE org_id IS NULL LIMIT 1');
        if ($row === null) {
            return $d;
        }

        return [
            'agency'      => (float) $row['agency_pct'],
            'distributor' => (float) $row['distributor_pct'],
            'hq'          => (float) $row['hq_pct'],
        ];
    }

    /** 전역 기본값 저장. 행이 없으면 만든다. */
    public static function saveGlobal(float $hq, float $distributor, float $agency, ?int $adminId = null): void
    {
        if (!self::tableExists() || !self::hasSplitColumns()) {
            throw new RuntimeException('org_fee_config 구조가 준비되지 않았습니다. php migrate.php 를 실행하세요.');
        }
        foreach ([$hq, $distributor, $agency] as $p) {
            if ($p < 0 || $p > 100) {
                throw new InvalidArgumentException('요율은 0~100% 사이여야 합니다.');
            }
        }
        $by = ($adminId !== null && $adminId > 0) ? $adminId : null;
        if (db_row('SELECT id FROM org_fee_config WHERE org_id IS NULL LIMIT 1') !== null) {
            db_execute(
                'UPDATE org_fee_config
                    SET hq_pct = ?, distributor_pct = ?, agency_pct = ?, updated_by = ?, updated_at = NOW()
                  WHERE org_id IS NULL',
                [$hq, $distributor, $agency, $by]
            );
        } else {
            db_insert(
                'INSERT INTO org_fee_config (org_id, pg_service_fee_pct, hq_pct, distributor_pct, agency_pct, updated_by)
                 VALUES (NULL, ?, ?, ?, ?, ?)',
                [$agency, $hq, $distributor, $agency, $by]
            );
        }
    }

    /**
     * 대리점 하나에 적용되는 플랫폼 수수료 분해.
     *
     * @return array{agency:float, distributor:float, hq:float, total:float}
     */
    public static function breakdownForAgency(int $agencyId): array
    {
        // 대리점 행 → 전역 기본값 → 코드 기본값 순으로 떨어진다(2026-09-06 갑).
        // 예전엔 대리점 행이 없으면 곧장 코드에 박힌 1% 였고, 화면에서 바꿀 수가 없었다.
        $d = self::globalBreakdown();

        if ($agencyId > 0 && self::hasSplitColumns()) {
            $row = db_row(
                'SELECT hq_pct, distributor_pct, agency_pct FROM org_fee_config WHERE org_id = ? LIMIT 1',
                [$agencyId]
            );
            if ($row !== null) {
                $d = [
                    'agency'      => (float) $row['agency_pct'],
                    'distributor' => (float) $row['distributor_pct'],
                    'hq'          => (float) $row['hq_pct'],
                ];
            }
        }

        $d['total'] = round($d['agency'] + $d['distributor'] + $d['hq'], 2);

        return $d;
    }

    /** 플랫폼 수수료 금액 = base × 총요율%. */
    public static function feeAmount(int $base, int $agencyId): int
    {
        if ($base <= 0) {
            return 0;
        }

        return (int) round($base * self::breakdownForAgency($agencyId)['total'] / 100);
    }

    /** 대리점별 3분할 저장 (본사 전용). */
    public static function saveForAgency(int $agencyId, float $hq, float $distributor, float $agency, ?int $adminId = null): void
    {
        if ($agencyId < 1 || !self::tableExists()) {
            throw new RuntimeException('org_fee_config 테이블이 없습니다. php migrate.php 를 실행하세요.');
        }
        if (!self::hasSplitColumns()) {
            throw new RuntimeException('수수료 분할 컬럼이 없습니다. php migrate.php 를 실행하세요.');
        }
        foreach ([$hq, $distributor, $agency] as $p) {
            if ($p < 0 || $p > 100) {
                throw new InvalidArgumentException('요율은 0~100% 사이여야 합니다.');
            }
        }

        $by = ($adminId !== null && $adminId > 0) ? $adminId : null;
        if (db_row('SELECT org_id FROM org_fee_config WHERE org_id = ? LIMIT 1', [$agencyId]) !== null) {
            db_execute(
                'UPDATE org_fee_config
                    SET hq_pct = ?, distributor_pct = ?, agency_pct = ?, updated_by = ?, updated_at = NOW()
                  WHERE org_id = ?',
                [$hq, $distributor, $agency, $by, $agencyId]
            );
        } else {
            db_insert(
                'INSERT INTO org_fee_config (org_id, pg_service_fee_pct, hq_pct, distributor_pct, agency_pct, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$agencyId, $agency, $hq, $distributor, $agency, $by]
            );
        }
    }

    /**
     * 대리점별 수수료 설정 목록 — 플랫폼 수수료(3분할) + 정산수수료(건별·보증금)를 함께 반환.
     * 본사 「수수료 설정」 화면용.
     *
     * @return list<array<string,mixed>>
     */
    public static function listAgencyConfigs(): array
    {
        if (!db_table_exists('organizations')) {
            return [];
        }

        require_once __DIR__ . '/WithdrawalConfig.php';

        $split = self::hasSplitColumns();
        $rows  = db_rows(
            "SELECT o.id, o.name, o.code, o.parent_id, p.name AS parent_name
               FROM organizations o
               LEFT JOIN organizations p ON p.id = o.parent_id
              WHERE o.level = 'agency'
              ORDER BY p.name ASC, o.name ASC"
        );

        $out = [];
        foreach ($rows as $r) {
            $agencyId = (int) $r['id'];
            $fee      = $split
                ? self::breakdownForAgency($agencyId)
                : ['agency' => self::DEFAULT_PCT, 'distributor' => self::DEFAULT_PCT, 'hq' => self::DEFAULT_PCT, 'total' => self::DEFAULT_PCT * 3];
            $wc = WithdrawalConfig::get($agencyId);

            $out[] = [
                'id'          => $agencyId,
                'name'        => (string) $r['name'],
                'code'        => (string) ($r['code'] ?? ''),
                'parent_name' => (string) ($r['parent_name'] ?? '—'),
                'hq_pct'          => $fee['hq'],
                'distributor_pct' => $fee['distributor'],
                'agency_pct'      => $fee['agency'],
                'total_pct'       => $fee['total'],
                'reserve_amount'    => (int) $wc['reserve_amount'],
                'fee_day_threshold' => (int) $wc['fee_day_threshold'],
                'fee_per_tx_short'  => (int) $wc['fee_per_tx_short'],
                'fee_per_tx_long'   => (int) $wc['fee_per_tx_long'],
            ];
        }

        return $out;
    }
}
