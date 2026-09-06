<?php

declare(strict_types=1);

require_once __DIR__ . '/Org.php';

/**
 * 세무신고용 자료 — 대리점별·기간별 (2026-09-05 갑).
 *
 * 세무대리가 실제로 쓰던 「세무신고용_YYYY-MM-DD_YYYY-MM-DD.xlsx」 를 시스템에서 그대로
 * 뽑기 위한 집계. 갑이 보내준 실제 파일의 레이아웃을 기준으로 만들었다.
 *
 *   상단 요약   해당 지사 총 콜수 / 세무비용 단가 / 최종 세무 비용
 *               기사정산원금 합계 / 프로모션금액 합계 / 합산 기준금액 / 총 징수원천세
 *   라이더 표   이름 · 주민번호 · 기사정산원금 · 원금 원천세 3.3% · 프로모션금액
 *               · 프로모션 원천세 3.3% · 합산 기준금액 · 총 징수원천세
 *               · 세금신고유무 · 금액조정필요 · 조정금액 · 비고
 *
 * ⚠️ **PG 결제금액(110%) 열은 만들지 않는다** — 갑: "PG결제금액 항목은 무시해도 되".
 * ⚠️ **주민번호는 시스템에 없다.** riders 에 주민등록번호 컬럼이 없어 빈 칸으로 나간다
 *    (개인정보라 저장하려면 별도 결정·암호화가 필요하다).
 *
 * 포함 대상: 기간 안에 **원천세가 실제로 발생한** 라이더(정산분 또는 프로모션분).
 * 원천세 비대상 라이더를 넣으면 「기사정산원금 × 3.3% = 원금 원천세」가 깨져서 신고 자료로
 * 못 쓴다. 대신 세금신고 유무는 라이더별 체크박스(tax_report_enabled)로 따로 표시한다.
 */
require_once __DIR__ . '/SettlementLedger.php';   // 과세표준 SQL 조각(선차감 제외)

final class TaxReport
{
    /**
     * 조회 범위 — 세무대리는 **전 대리점**을 본다.
     *
     * 🐛 Org::agencyScopeClause() 를 그대로 쓰면 안 된다. Org::scopeAgencyIds() 는
     *    admin=null(전체) / distributor=하위 대리점 / **그 외=자기 조직 id** 라서,
     *    tax_agent 계정은 `r.agency_id IN (세무대리 org id)` 가 되어 아무 대리점도 안 잡힌다
     *    (2026-09-05 실제로 목록이 0곳으로 나왔다).
     *    세무대리는 대리점을 거느리는 조직이 아니라 전 대리점의 신고를 대행하는 조직이므로
     *    본사와 같은 「전체」로 취급한다. `Org::scopeAgencyIds()` 자체는 건드리지 않는다 —
     *    지갑 입출금처럼 세무대리가 **자기 것만** 봐야 하는 화면이 그 규칙에 기대고 있다.
     *
     * @return array{0:string, 1:list<mixed>}
     */
    private static function scopeClause(string $column): array
    {
        if (admin_org_level() === Org::LEVEL_TAX_AGENT) {
            return ['', []];
        }

        return Org::agencyScopeClause($column);
    }

    /** 세무비용 단가(원/콜) — 설정이 없으면 샘플 파일과 같은 15원. */
    public static function feePerCall(): int
    {
        if (!db_table_exists('deduction_global_config')) {
            return 15;
        }
        $cols = array_column(db_rows('SHOW COLUMNS FROM deduction_global_config'), 'Field');
        if (!in_array('tax_fee_per_call', $cols, true)) {
            return 15;
        }
        $row = db_row('SELECT tax_fee_per_call FROM deduction_global_config ORDER BY org_id IS NOT NULL, id LIMIT 1');

        return max(0, (int) ($row['tax_fee_per_call'] ?? 15));
    }

    /**
     * 기간 내 정산·프로모션이 있는 대리점 목록 + 요약.
     *
     * @return list<array{agency_id:int, agency_name:string, code:string, riders:int, calls:int,
     *                    base:int, base_wh:int, promo:int, promo_wh:int, total_base:int, total_wh:int}>
     */
    public static function agencies(string $from, string $to): array
    {
        $out = [];

        // ── 정산분 ──
        [$scope, $params] = self::scopeClause('r.agency_id');
        $cond = $scope !== '' ? ' AND ' . $scope : '';
        foreach (db_rows(
            "SELECT r.agency_id AS aid, o.name AS name, o.code AS code,
                    COALESCE(SUM(c.order_count), 0) AS calls,
                    COALESCE(SUM(c.gross_amount + c.support_amount), 0) AS base
               FROM settlement_rider_cycles c
               INNER JOIN riders r ON r.id = c.rider_id
               INNER JOIN organizations o ON o.id = r.agency_id
              WHERE c.settlement_date BETWEEN ? AND ?{$cond}
              GROUP BY r.agency_id, o.name, o.code",
            array_merge([$from, $to], $params)
        ) as $r) {
            $aid = (int) $r['aid'];
            $out[$aid] = [
                'agency_id'   => $aid,
                'agency_name' => (string) $r['name'],
                'code'        => (string) $r['code'],
                'riders'      => 0,   // 원천세 대상 인원만 아래에서 채운다
                'calls'       => (int) $r['calls'],
                'base'        => 0,   // 원천세 대상분만 아래에서 채운다
                'base_wh'     => 0,
                'promo'       => 0,
                'promo_wh'    => 0,
                'all_base'    => (int) $r['base'],
            ];
        }

        // ── 정산분 원천세 + 그 원천세가 붙은 사이클의 기준금액 ──
        foreach (db_rows(
            "SELECT r.agency_id AS aid,
                    COUNT(DISTINCT c.rider_id) AS riders,
                    COALESCE(SUM(fi.amount), 0) AS wh,
                    COALESCE(SUM(" . SettlementLedger::taxBaseSqlExpr('c') . "), 0) AS base
               FROM settlement_fee_items fi
               INNER JOIN settlement_rider_cycles c ON c.id = fi.cycle_id
               INNER JOIN riders r ON r.id = c.rider_id
              WHERE fi.fee_code = 'withholding'
                AND c.settlement_date BETWEEN ? AND ?{$cond}
              GROUP BY r.agency_id",
            array_merge([$from, $to], $params)
        ) as $r) {
            $aid = (int) $r['aid'];
            if (!isset($out[$aid])) {
                continue;
            }
            $out[$aid]['riders']  = (int) $r['riders'];
            $out[$aid]['base']    = (int) $r['base'];
            $out[$aid]['base_wh'] = (int) $r['wh'];
        }

        // ── 프로모션분 ──
        if (db_table_exists('promotion_entries') && db_table_exists('promotion_batches')) {
            [$pScope, $pParams] = self::scopeClause('b.agency_id');
            $pCond = $pScope !== '' ? ' AND ' . $pScope : '';
            foreach (db_rows(
                "SELECT b.agency_id AS aid, o.name AS name, o.code AS code,
                        COALESCE(SUM(pe.total_amount), 0) AS amt,
                        COALESCE(SUM(pe.withholding_amount), 0) AS wh
                   FROM promotion_entries pe
                   INNER JOIN promotion_batches b ON b.id = pe.batch_id
                   INNER JOIN organizations o ON o.id = b.agency_id
                  WHERE pe.status = 'paid' AND pe.withholding_amount > 0
                    AND b.pay_date BETWEEN ? AND ?{$pCond}
                  GROUP BY b.agency_id, o.name, o.code",
                array_merge([$from, $to], $pParams)
            ) as $r) {
                $aid = (int) $r['aid'];
                if (!isset($out[$aid])) {
                    $out[$aid] = [
                        'agency_id' => $aid, 'agency_name' => (string) $r['name'], 'code' => (string) $r['code'],
                        'riders' => 0, 'calls' => 0, 'base' => 0, 'base_wh' => 0,
                        'promo' => 0, 'promo_wh' => 0, 'all_base' => 0,
                    ];
                }
                $out[$aid]['promo']    = (int) $r['amt'];
                $out[$aid]['promo_wh'] = (int) $r['wh'];
            }
        }

        $list = [];
        foreach ($out as $r) {
            $r['total_base'] = $r['base'] + $r['promo'];
            $r['total_wh']   = $r['base_wh'] + $r['promo_wh'];
            if ($r['total_base'] === 0 && $r['total_wh'] === 0) {
                continue; // 신고할 게 없는 대리점은 목록에서 뺀다
            }
            $list[] = $r;
        }
        usort($list, static fn (array $a, array $b): int => $b['total_base'] <=> $a['total_base']);

        return $list;
    }

    /**
     * 한 대리점의 라이더별 신고 행.
     *
     * @return list<array{rider_id:int, name:string, rrn:string, base:int, base_wh:int,
     *                    promo:int, promo_wh:int, total_base:int, total_wh:int,
     *                    report:bool, adjust_note:string}>
     */
    public static function riders(int $agencyId, string $from, string $to): array
    {
        $rows = [];

        // 정산분 — 원천세가 붙은 사이클만
        foreach (db_rows(
            "SELECT c.rider_id AS rid,
                    COALESCE(SUM(" . SettlementLedger::taxBaseSqlExpr('c') . "), 0) AS base,
                    COALESCE(SUM(fi.amount), 0) AS wh
               FROM settlement_fee_items fi
               INNER JOIN settlement_rider_cycles c ON c.id = fi.cycle_id
               INNER JOIN riders r ON r.id = c.rider_id
              WHERE fi.fee_code = 'withholding'
                AND r.agency_id = ?
                AND c.settlement_date BETWEEN ? AND ?
              GROUP BY c.rider_id",
            [$agencyId, $from, $to]
        ) as $r) {
            $rows[(int) $r['rid']] = ['base' => (int) $r['base'], 'base_wh' => (int) $r['wh'], 'promo' => 0, 'promo_wh' => 0];
        }

        // 프로모션분
        if (db_table_exists('promotion_entries') && db_table_exists('promotion_batches')) {
            foreach (db_rows(
                "SELECT pe.rider_id AS rid,
                        COALESCE(SUM(pe.total_amount), 0) AS amt,
                        COALESCE(SUM(pe.withholding_amount), 0) AS wh
                   FROM promotion_entries pe
                   INNER JOIN promotion_batches b ON b.id = pe.batch_id
                  WHERE pe.status = 'paid' AND pe.withholding_amount > 0
                    AND pe.rider_id IS NOT NULL
                    AND b.agency_id = ?
                    AND b.pay_date BETWEEN ? AND ?
                  GROUP BY pe.rider_id",
                [$agencyId, $from, $to]
            ) as $r) {
                $rid = (int) $r['rid'];
                $rows[$rid] ??= ['base' => 0, 'base_wh' => 0, 'promo' => 0, 'promo_wh' => 0];
                $rows[$rid]['promo']    = (int) $r['amt'];
                $rows[$rid]['promo_wh'] = (int) $r['wh'];
            }
        }

        if ($rows === []) {
            return [];
        }

        $hasFlags = in_array('tax_report_enabled', array_column(db_rows('SHOW COLUMNS FROM riders'), 'Field'), true);
        $select   = $hasFlags ? ', tax_report_enabled, tax_adjust_note' : '';
        $ids      = array_keys($rows);
        $meta     = [];
        foreach (db_rows(
            'SELECT id, name' . $select . ' FROM riders WHERE id IN (' . db_in($ids) . ')',
            $ids
        ) as $r) {
            $meta[(int) $r['id']] = $r;
        }

        $out = [];
        foreach ($rows as $rid => $v) {
            $m = $meta[$rid] ?? null;
            $out[] = [
                'rider_id'    => $rid,
                'name'        => (string) ($m['name'] ?? '(삭제된 라이더)'),
                'rrn'         => '',   // 주민번호는 시스템에 저장하지 않는다
                'base'        => $v['base'],
                'base_wh'     => $v['base_wh'],
                'promo'       => $v['promo'],
                'promo_wh'    => $v['promo_wh'],
                'total_base'  => $v['base'] + $v['promo'],
                'total_wh'    => $v['base_wh'] + $v['promo_wh'],
                'report'      => $hasFlags ? ((int) ($m['tax_report_enabled'] ?? 1) === 1) : true,
                'adjust_note' => (string) ($m['tax_adjust_note'] ?? ''),
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $out;
    }

    /**
     * 한 대리점의 상단 요약 — 총 콜수는 **기간 내 전체 정산**을 센다(원천세 대상 여부와 무관).
     * 세무비용은 콜 기준으로 청구하므로 신고 대상만 세면 과소 청구가 된다.
     *
     * @return array{calls:int, fee_per_call:int, fee_total:int, base:int, promo:int,
     *               total_base:int, total_wh:int, riders:int}
     */
    public static function summary(int $agencyId, string $from, string $to): array
    {
        $calls = (int) (db_row(
            "SELECT COALESCE(SUM(c.order_count), 0) AS calls
               FROM settlement_rider_cycles c
               INNER JOIN riders r ON r.id = c.rider_id
              WHERE r.agency_id = ? AND c.settlement_date BETWEEN ? AND ?",
            [$agencyId, $from, $to]
        )['calls'] ?? 0);

        $base = 0;
        $promo = 0;
        $wh = 0;
        $riders = 0;
        foreach (self::riders($agencyId, $from, $to) as $r) {
            $base  += $r['base'];
            $promo += $r['promo'];
            $wh    += $r['total_wh'];
            $riders++;
        }

        $per = self::feePerCall();

        return [
            'calls'        => $calls,
            'fee_per_call' => $per,
            'fee_total'    => $calls * $per,
            'base'         => $base,
            'promo'        => $promo,
            'total_base'   => $base + $promo,
            'total_wh'     => $wh,
            'riders'       => $riders,
        ];
    }
}
