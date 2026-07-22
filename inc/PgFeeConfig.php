<?php

declare(strict_types=1);

/**
 * 영업대행수수료 분배 요율 (org_fee_config) — LOGIC §5.4 · §7 #12.
 *
 * 대리점의 PG 카드결제 시 붙는 "영업대행수수료"를 본사·총판·대리점이 계약별로 나눠 갖는다.
 * 각 조직이 자기 몫 요율(%)을 가지며, 어떤 대리점의 결제에 적용되는 총 요율은
 *   총 영업대행수수료% = 대리점.pct + 상위총판.pct + 본사.pct
 * 기본값은 각 1.00%(임시 — 갑 확정 대기). 관리는 본사만.
 */
final class PgFeeConfig
{
    private const DEFAULT_PCT = 1.00;

    public static function tableExists(): bool
    {
        return db_table_exists('org_fee_config');
    }

    /** 특정 조직의 몫 요율(%) — 없으면 기본값. */
    public static function pctForOrg(int $orgId): float
    {
        if ($orgId < 1 || !self::tableExists()) {
            return self::DEFAULT_PCT;
        }
        $row = db_row('SELECT pg_service_fee_pct FROM org_fee_config WHERE org_id = ? LIMIT 1', [$orgId]);

        return $row !== null ? (float) $row['pg_service_fee_pct'] : self::DEFAULT_PCT;
    }

    /**
     * 어떤 대리점의 PG 결제에 적용되는 총 영업대행수수료 분해.
     *
     * @return array{agency:float, distributor:float, hq:float, total:float}
     */
    public static function breakdownForAgency(int $agencyId): array
    {
        $agency = db_row('SELECT id, parent_id, level FROM organizations WHERE id = ? LIMIT 1', [$agencyId]);
        $agencyPct = self::pctForOrg($agencyId);

        $distributorPct = 0.0;
        $distId = $agency !== null ? (int) ($agency['parent_id'] ?? 0) : 0;
        if ($distId > 0) {
            $distributorPct = self::pctForOrg($distId);
        }

        // 본사(admin 레벨 루트) 몫
        $hqPct = 0.0;
        $hq = db_row("SELECT id FROM organizations WHERE level = 'admin' ORDER BY id ASC LIMIT 1");
        if ($hq !== null) {
            $hqPct = self::pctForOrg((int) $hq['id']);
        }

        $total = round($agencyPct + $distributorPct + $hqPct, 2);

        return [
            'agency'      => $agencyPct,
            'distributor' => $distributorPct,
            'hq'          => $hqPct,
            'total'       => $total,
        ];
    }

    /** 영업대행수수료 금액 = base × 총요율%. */
    public static function feeAmount(int $base, int $agencyId): int
    {
        if ($base <= 0) {
            return 0;
        }
        $total = self::breakdownForAgency($agencyId)['total'];

        return (int) round($base * $total / 100);
    }

    public static function save(int $orgId, float $pct, ?int $adminId = null): void
    {
        if ($orgId < 1 || !self::tableExists()) {
            throw new RuntimeException('org_fee_config 테이블이 없습니다. php migrate.php 를 실행하세요.');
        }
        if ($pct < 0 || $pct > 100) {
            throw new InvalidArgumentException('요율은 0~100% 사이여야 합니다.');
        }
        $exists = db_row('SELECT org_id FROM org_fee_config WHERE org_id = ? LIMIT 1', [$orgId]);
        if ($exists !== null) {
            db_execute(
                'UPDATE org_fee_config SET pg_service_fee_pct = ?, updated_by = ?, updated_at = NOW() WHERE org_id = ?',
                [$pct, ($adminId !== null && $adminId > 0) ? $adminId : null, $orgId]
            );
        } else {
            db_insert(
                'INSERT INTO org_fee_config (org_id, pg_service_fee_pct, updated_by) VALUES (?, ?, ?)',
                [$orgId, $pct, ($adminId !== null && $adminId > 0) ? $adminId : null]
            );
        }
    }

    /**
     * 전 조직 요율 목록 (본사 관리 화면용).
     *
     * @return list<array<string,mixed>>
     */
    public static function listAll(): array
    {
        if (!self::tableExists() || !db_table_exists('organizations')) {
            return [];
        }

        return db_rows(
            "SELECT o.id, o.level, o.name, o.code, o.parent_id,
                    p.name AS parent_name,
                    COALESCE(f.pg_service_fee_pct, ?) AS pct
               FROM organizations o
               LEFT JOIN organizations p ON p.id = o.parent_id
               LEFT JOIN org_fee_config f ON f.org_id = o.id
              ORDER BY FIELD(o.level, 'admin', 'distributor', 'agency'), o.id ASC",
            [self::DEFAULT_PCT]
        );
    }
}
