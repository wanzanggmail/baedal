<?php

declare(strict_types=1);

require_once __DIR__ . '/AgencyWallet.php';
require_once __DIR__ . '/Org.php';

/**
 * 세무대리 — 대리점의 **원천세 예수금**을 **월(정산 귀속월) 단위**로 확인하고 수집한다(2026-09-04 갑 정정).
 *
 * 원천세 공제분은 정산 반영 시 대리점 지갑 `withholding_reserve`(예수금)로 누적되고,
 * 세무대리는 **특정 월에 걷힌 것만** 가져와 그 월로 신고·납입한다.
 *  - 월별 걷힌 금액 = `settlement_fee_items`(원천세) 중 사이클 정산일이 그 달인 것의 합.
 *  - 이미 수집한 금액 = `tax_withholding_collections`(대리점·월) 합.
 *  - 이번에 수집할 금액 = 걷힌 − 이미 수집(미수집분).
 * withholding_reserve 는 전체 미수집 합과 같게 유지되어(반영 시 +, 수집 시 −) 대리점 인출가능액에서 빠진다.
 *
 * ⚠️ 고용·산재는 세무대리 대상이 아니다 — 라이더 공제분을 **대리점이 보유**한다(예수금 아님).
 */
final class TaxAgent
{
    private const FEE_CODE = 'withholding';
    private const TABLE    = 'tax_withholding_collections';

    public static function ready(): bool
    {
        return db_table_exists('organizations')
            && db_table_exists('agency_wallets')
            && db_table_exists(self::TABLE)
            && db_table_exists('settlement_fee_items')
            && db_table_exists('settlement_rider_cycles');
    }

    /** 세무대리 지갑 잔액(수집돼 아직 납입 전인 예수금 보관액). */
    public static function walletBalance(): int
    {
        $taxId = Org::taxAgentOrgId();

        return $taxId > 0 ? (int) AgencyWallet::get($taxId)['balance'] : 0;
    }

    /**
     * 정산 귀속월별 걷힌 원천세 합(대리점 무관 전체). 최신월 우선.
     *
     * @return array<string,int>  'YYYY-MM' => 합
     */
    private static function accruedByMonth(?string $period = null): array
    {
        if (!db_table_exists('settlement_fee_items') || !db_table_exists('settlement_rider_cycles')) {
            return [];
        }
        $where  = "fi.fee_code = ? AND r.agency_id IS NOT NULL";
        $params = [self::FEE_CODE];
        if ($period !== null && preg_match('/^\d{4}-\d{2}$/', $period)) {
            $where   .= " AND DATE_FORMAT(c.settlement_date,'%Y-%m') = ?";
            $params[] = $period;
        }
        $out = [];
        foreach (db_rows(
            "SELECT DATE_FORMAT(c.settlement_date,'%Y-%m') AS ym, COALESCE(SUM(fi.amount),0) AS amt
               FROM settlement_fee_items fi
               JOIN settlement_rider_cycles c ON c.id = fi.cycle_id
               JOIN riders r ON r.id = c.rider_id
              WHERE {$where}
              GROUP BY ym
              ORDER BY ym DESC",
            $params
        ) as $r) {
            $out[(string) $r['ym']] = (int) $r['amt'];
        }

        return $out;
    }

    /** 이미 수집한 금액 (대리점·월). key 'agencyId|period' => amount */
    private static function collectedMap(?string $period = null): array
    {
        if (!db_table_exists(self::TABLE)) {
            return [];
        }
        $sql    = 'SELECT agency_id, period, COALESCE(SUM(amount),0) AS amt FROM ' . self::TABLE;
        $params = [];
        if ($period !== null && preg_match('/^\d{4}-\d{2}$/', $period)) {
            $sql     .= ' WHERE period = ?';
            $params[] = $period;
        }
        $sql .= ' GROUP BY agency_id, period';
        $out = [];
        foreach (db_rows($sql, $params) as $r) {
            $out[((int) $r['agency_id']) . '|' . (string) $r['period']] = (int) $r['amt'];
        }

        return $out;
    }

    /**
     * 수집할 수 있는(원천세 걷힌) 월 목록 + 월별 미수집 합. 최신월 우선.
     *
     * @return list<array{period:string, accrued:int, collected:int, uncollected:int}>
     */
    public static function months(): array
    {
        $accrued   = self::accruedByMonth();
        $collected = self::collectedMap();

        $collByMonth = [];
        foreach ($collected as $key => $amt) {
            $period = explode('|', $key)[1] ?? '';
            $collByMonth[$period] = ($collByMonth[$period] ?? 0) + $amt;
        }

        $out = [];
        foreach ($accrued as $ym => $amt) {
            $c = (int) ($collByMonth[$ym] ?? 0);
            $out[] = ['period' => $ym, 'accrued' => $amt, 'collected' => $c, 'uncollected' => max(0, $amt - $c)];
        }

        return $out;
    }

    /**
     * 특정 월의 대리점별 원천세 현황(걷힘·수집·미수집).
     *
     * @return list<array{agency_id:int, agency_name:string, code:string,
     *                     accrued:int, collected:int, uncollected:int}>
     */
    public static function agencySummary(string $period): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            $period = date('Y-m');
        }
        if (!db_table_exists('organizations')) {
            return [];
        }

        // 이 달의 대리점별 걷힌 원천세
        $accrued = [];
        foreach (db_rows(
            "SELECT r.agency_id AS aid, COALESCE(SUM(fi.amount),0) AS amt
               FROM settlement_fee_items fi
               JOIN settlement_rider_cycles c ON c.id = fi.cycle_id
               JOIN riders r ON r.id = c.rider_id
              WHERE fi.fee_code = ?
                AND r.agency_id IS NOT NULL
                AND DATE_FORMAT(c.settlement_date,'%Y-%m') = ?
              GROUP BY r.agency_id",
            [self::FEE_CODE, $period]
        ) as $r) {
            $accrued[(int) $r['aid']] = (int) $r['amt'];
        }

        $collected = self::collectedMap($period);

        $rows = db_rows("SELECT id, name, code FROM organizations WHERE level='agency' AND is_active=1 ORDER BY name ASC");
        $out  = [];
        foreach ($rows as $r) {
            $aid = (int) $r['id'];
            $acc = (int) ($accrued[$aid] ?? 0);
            if ($acc === 0) {
                continue; // 이 달 걷힌 게 없는 대리점은 표에서 생략
            }
            $col = (int) ($collected[$aid . '|' . $period] ?? 0);
            $out[] = [
                'agency_id'   => $aid,
                'agency_name' => (string) $r['name'],
                'code'        => (string) $r['code'],
                'accrued'     => $acc,
                'collected'   => $col,
                'uncollected' => max(0, $acc - $col),
            ];
        }

        return $out;
    }

    /** 특정 월의 미수집 합(전체 대리점). */
    public static function collectibleForPeriod(string $period): int
    {
        $sum = 0;
        foreach (self::agencySummary($period) as $r) {
            $sum += (int) $r['uncollected'];
        }

        return $sum;
    }

    /**
     * 특정 월의 원천세 예수금 수집 — 그 달 미수집분만 대리점 지갑에서 세무대리 지갑으로 이동.
     *
     * @param int|null $agencyId 특정 대리점만. null이면 그 달 미수집이 있는 전체 대리점.
     * @param string   $period   정산 귀속월(YYYY-MM).
     * @return array{count:int, total:int, period:string, agencies:list<array{agency_id:int, name:string, amount:int}>}
     */
    public static function collect(?int $agencyId, string $period, ?int $adminId = null): array
    {
        if (!self::ready()) {
            throw new RuntimeException('세무대리 테이블이 없습니다. php migrate.php 를 실행하세요.');
        }
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            throw new InvalidArgumentException('수집할 월(YYYY-MM)이 올바르지 않습니다.');
        }
        $taxId = Org::taxAgentOrgId();
        if ($taxId < 1) {
            throw new RuntimeException('세무대리 조직이 없습니다.');
        }

        $targets = [];
        foreach (self::agencySummary($period) as $r) {
            if ($agencyId !== null && (int) $r['agency_id'] !== $agencyId) {
                continue;
            }
            if ((int) $r['uncollected'] > 0) {
                $targets[] = $r;
            }
        }
        if ($targets === []) {
            return ['count' => 0, 'total' => 0, 'period' => $period, 'agencies' => []];
        }

        $done  = [];
        $total = 0;
        db_transaction(static function () use ($targets, $taxId, $period, $adminId, &$done, &$total): void {
            foreach ($targets as $t) {
                $aid    = (int) $t['agency_id'];
                $amount = (int) $t['uncollected'];
                if ($amount <= 0) {
                    continue;
                }
                $note = sprintf('%s %s월 원천세 예수금 수집', (string) $t['agency_name'], $period);

                AgencyWallet::debit($aid, $amount, 'wh_collect_out', null, $note, $adminId);
                db_execute('UPDATE agency_wallets SET withholding_reserve = GREATEST(0, withholding_reserve - ?), updated_at = NOW() WHERE agency_id = ?', [$amount, $aid]);
                AgencyWallet::credit($taxId, $amount, 'wh_collect_in', $aid, $note, $adminId);

                db_insert(
                    'INSERT INTO ' . self::TABLE . ' (tax_org_id, agency_id, period, amount, collected_by, collected_at)
                     VALUES (?, ?, ?, ?, ?, NOW())',
                    [$taxId, $aid, $period, $amount, ($adminId !== null && $adminId > 0) ? $adminId : null]
                );

                $done[] = ['agency_id' => $aid, 'name' => (string) $t['agency_name'], 'amount' => $amount];
                $total += $amount;
            }
        });

        return ['count' => count($done), 'total' => $total, 'period' => $period, 'agencies' => $done];
    }

    /**
     * 수집 이력.
     *
     * @return list<array<string,mixed>>
     */
    public static function history(int $limit = 100): array
    {
        if (!db_table_exists(self::TABLE)) {
            return [];
        }

        return db_rows(
            "SELECT tc.id, tc.agency_id, o.name AS agency_name, tc.period, tc.amount,
                    DATE_FORMAT(tc.collected_at, '%Y-%m-%d %H:%i') AS collected_at
               FROM " . self::TABLE . " tc
               LEFT JOIN organizations o ON o.id = tc.agency_id
              ORDER BY tc.id DESC
              LIMIT ?",
            [$limit]
        );
    }
}
