<?php

declare(strict_types=1);

require_once __DIR__ . '/AgencyWallet.php';
require_once __DIR__ . '/Org.php';

/**
 * 세무대리 — 대리점의 고용·산재 예수금을 확인하고, 월별로 세무대리 지갑으로 수집한다.
 *
 * 고용·산재 공제분은 정산 반영 시 대리점 지갑에 `insurance_reserve`(예수금)로 누적된다
 * (원천세 예수금과 같은 방식, `AgencyWallet::addInsuranceReserve`). 세무대리는 그 예수금을
 * 각 대리점 지갑에서 자기 지갑으로 가져와(대리점 balance 차감 + reserve 0) 신고·납입한다.
 */
final class TaxAgent
{
    public static function ready(): bool
    {
        return db_table_exists('organizations')
            && db_table_exists('agency_wallets')
            && db_table_exists('tax_insurance_collections');
    }

    /** 세무대리 지갑 잔액(수집돼 아직 납입 전인 예수금 보관액). */
    public static function walletBalance(): int
    {
        $taxId = Org::taxAgentOrgId();

        return $taxId > 0 ? (int) AgencyWallet::get($taxId)['balance'] : 0;
    }

    /**
     * 대리점별 고용·산재 예수금 현황.
     *  - reserve   : 지금 대리점 지갑에 남은(수집 대상) 예수금
     *  - accrued_* : 그동안 정산에서 걷힌 고용·산재 누계(참고용, settlement_fee_items 기준)
     *
     * @return list<array{agency_id:int, agency_name:string, code:string, reserve:int,
     *                     accrued_employment:int, accrued_accident:int, accrued_total:int}>
     */
    public static function agencySummary(): array
    {
        if (!db_table_exists('organizations')) {
            return [];
        }

        // 누계(참고) — fee_items 에서 대리점별 고용·산재 합
        $accrued = [];
        if (db_table_exists('settlement_fee_items') && db_table_exists('settlement_rider_cycles')) {
            foreach (db_rows(
                "SELECT r.agency_id AS aid,
                        COALESCE(SUM(CASE WHEN fi.fee_code='employment_ins' THEN fi.amount ELSE 0 END),0) AS emp,
                        COALESCE(SUM(CASE WHEN fi.fee_code='accident_ins'   THEN fi.amount ELSE 0 END),0) AS acc
                   FROM settlement_fee_items fi
                   JOIN settlement_rider_cycles c ON c.id = fi.cycle_id
                   JOIN riders r ON r.id = c.rider_id
                  WHERE fi.fee_code IN ('employment_ins','accident_ins')
                    AND r.agency_id IS NOT NULL
                  GROUP BY r.agency_id"
            ) as $r) {
                $accrued[(int) $r['aid']] = ['emp' => (int) $r['emp'], 'acc' => (int) $r['acc']];
            }
        }

        $rows = db_rows(
            "SELECT o.id, o.name, o.code, COALESCE(w.insurance_reserve,0) AS reserve
               FROM organizations o
               LEFT JOIN agency_wallets w ON w.agency_id = o.id
              WHERE o.level = 'agency' AND o.is_active = 1
              ORDER BY o.name ASC"
        );

        $out = [];
        foreach ($rows as $r) {
            $aid = (int) $r['id'];
            $a   = $accrued[$aid] ?? ['emp' => 0, 'acc' => 0];
            $out[] = [
                'agency_id'          => $aid,
                'agency_name'        => (string) $r['name'],
                'code'               => (string) $r['code'],
                'reserve'            => (int) $r['reserve'],
                'accrued_employment' => $a['emp'],
                'accrued_accident'   => $a['acc'],
                'accrued_total'      => $a['emp'] + $a['acc'],
            ];
        }

        return $out;
    }

    /** 현재 수집 대상(예수금>0) 대리점들의 예수금 합계. */
    public static function collectibleTotal(): int
    {
        $sum = 0;
        foreach (self::agencySummary() as $r) {
            $sum += max(0, (int) $r['reserve']);
        }

        return $sum;
    }

    /**
     * 예수금 수집 — 대리점 지갑에서 세무대리 지갑으로 이동.
     *
     * @param int|null $agencyId 특정 대리점만. null 이면 예수금이 있는 전체 대리점.
     * @param string   $period   수집 귀속월(YYYY-MM). 기록/집계용 라벨.
     * @return array{count:int, total:int, agencies:list<array{agency_id:int, name:string, amount:int}>}
     */
    public static function collect(?int $agencyId, string $period, ?int $adminId = null): array
    {
        if (!self::ready()) {
            throw new RuntimeException('세무대리 테이블이 없습니다. php migrate.php 를 실행하세요.');
        }
        $taxId = Org::taxAgentOrgId();
        if ($taxId < 1) {
            throw new RuntimeException('세무대리 조직이 없습니다.');
        }
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            $period = date('Y-m');
        }

        // 대상: 예수금 > 0 인 대리점
        $targets = [];
        foreach (self::agencySummary() as $r) {
            if ($agencyId !== null && (int) $r['agency_id'] !== $agencyId) {
                continue;
            }
            if ((int) $r['reserve'] > 0) {
                $targets[] = $r;
            }
        }
        if ($targets === []) {
            return ['count' => 0, 'total' => 0, 'agencies' => []];
        }

        $done  = [];
        $total = 0;
        db_transaction(static function () use ($targets, $taxId, $period, $adminId, &$done, &$total): void {
            foreach ($targets as $t) {
                $aid    = (int) $t['agency_id'];
                $amount = (int) $t['reserve'];
                if ($amount <= 0) {
                    continue;
                }
                $note = sprintf('%s 고용·산재 예수금 수집(%s)', (string) $t['agency_name'], $period);

                // 대리점 지갑에서 실제로 빼고(balance) 예수금 표시를 0으로 내린다.
                AgencyWallet::debit($aid, $amount, 'ins_collect_out', null, $note, $adminId);
                db_execute('UPDATE agency_wallets SET insurance_reserve = GREATEST(0, insurance_reserve - ?), updated_at = NOW() WHERE agency_id = ?', [$amount, $aid]);

                // 세무대리 지갑으로 수집.
                AgencyWallet::credit($taxId, $amount, 'ins_collect_in', $aid, $note, $adminId);

                db_insert(
                    'INSERT INTO tax_insurance_collections (tax_org_id, agency_id, period, amount, collected_by, collected_at)
                     VALUES (?, ?, ?, ?, ?, NOW())',
                    [$taxId, $aid, $period, $amount, ($adminId !== null && $adminId > 0) ? $adminId : null]
                );

                $done[]  = ['agency_id' => $aid, 'name' => (string) $t['agency_name'], 'amount' => $amount];
                $total  += $amount;
            }
        });

        return ['count' => count($done), 'total' => $total, 'agencies' => $done];
    }

    /**
     * 수집 이력.
     *
     * @return list<array<string,mixed>>
     */
    public static function history(int $limit = 200): array
    {
        if (!db_table_exists('tax_insurance_collections')) {
            return [];
        }

        return db_rows(
            "SELECT tc.id, tc.agency_id, o.name AS agency_name, tc.period, tc.amount,
                    DATE_FORMAT(tc.collected_at, '%Y-%m-%d %H:%i') AS collected_at
               FROM tax_insurance_collections tc
               LEFT JOIN organizations o ON o.id = tc.agency_id
              ORDER BY tc.id DESC
              LIMIT ?",
            [$limit]
        );
    }
}
