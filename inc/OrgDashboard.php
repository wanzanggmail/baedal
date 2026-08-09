<?php

declare(strict_types=1);

require_once __DIR__ . '/Org.php';
require_once __DIR__ . '/SettlementAmounts.php';

/**
 * 본사·총판 전용 대시보드 집계.
 *
 * 대리점 대시보드(AdminDashboard)가 "라이더 운영" 관점이라면, 이 화면은
 * **대리점을 관리하는 관점** — 대리점별 실적 비교 · 조직 현황 · 플랫폼 수수료 수익 ·
 * 손이 필요한 대리점(미반영 업로드·미매칭·지갑부족) 감지가 핵심이다.
 */
final class OrgDashboard
{
    /**
     * @param ?string $from 기간 시작(YYYY-MM-DD). 생략 시 이번 주 월요일
     * @param ?string $to   기간 끝(YYYY-MM-DD). 생략 시 오늘
     * @return array<string, mixed>
     */
    public static function load(?string $from = null, ?string $to = null): array
    {
        $weekStart = $from ?? date('Y-m-d', strtotime('monday this week'));
        $weekEnd   = $to ?? date('Y-m-d');
        // 이전 기간 비교 — 선택한 기간과 같은 일수만큼 직전 구간(고정 7일 아님)
        $periodDays = (int) round((strtotime($weekEnd) - strtotime($weekStart)) / 86400) + 1;
        $prevEnd    = date('Y-m-d', strtotime($weekStart . ' -1 day'));
        $prevStart  = date('Y-m-d', strtotime($prevEnd . ' -' . ($periodDays - 1) . ' days'));

        $isHq = admin_org_level() === Org::LEVEL_ADMIN;

        $data = [
            'is_hq'            => $isHq,
            'week_start'       => $weekStart,
            'week_end'         => $weekEnd,
            'period_label'     => AdminDashboard::formatPeriodLabel($weekStart, $weekEnd),
            'errors'           => [],
            'distributor_count'=> 0,
            'agency_count'     => 0,
            'agency_inactive'  => 0,
            'active_riders'    => 0,
            'week_payout'      => 0,
            'week_payout_delta'=> null,
            'week_orders'      => 0,
            'pending_count'    => 0,
            'pending_amount'   => 0,
            'wallet_total'     => 0,
            'fee_revenue'      => 0,
            'fee_revenue_label'=> $isHq ? '본사 몫' : '총판 몫',
            'agency_rows'      => [],
            'distributor_rows' => [],
            'attention'        => [],
            'risk_alerts'      => [],
            'large_withdrawals'=> [],
            'trend'            => ['labels' => [], 'net' => [], 'orders' => [], 'bucket' => 'day'],
            'platform_mix'     => [],
        ];

        try {
            $data['agency_rows'] = self::agencyRows($weekStart, $weekEnd);
        } catch (Throwable $e) {
            $data['errors'][] = '대리점 실적: ' . $e->getMessage();
        }

        foreach ($data['agency_rows'] as $r) {
            $data['agency_count']   += 1;
            $data['agency_inactive'] += $r['is_active'] ? 0 : 1;
            $data['active_riders']  += $r['riders'];
            $data['week_payout']    += $r['week_payout'];
            $data['week_orders']    += $r['week_orders'];
            $data['pending_count']  += $r['pending_count'];
            $data['pending_amount'] += $r['pending_amount'];
            $data['wallet_total']   += $r['wallet_balance'];
            $data['fee_revenue']    += $r['fee_share'];
        }

        try {
            $prev = self::payoutTotal($prevStart, $prevEnd);
            $data['week_payout_delta'] = self::percentDelta($data['week_payout'], $prev);
        } catch (Throwable $e) {
            // 비교값은 부가 정보
        }

        if ($isHq) {
            try {
                $data['distributor_count'] = (int) (db_row(
                    "SELECT COUNT(*) c FROM organizations WHERE level = 'distributor'"
                )['c'] ?? 0);
            } catch (Throwable $e) {
                // 무시
            }
            $data['distributor_rows'] = self::rollupByDistributor($data['agency_rows']);
        }

        $data['attention'] = self::attentionRows($data['agency_rows']);

        try {
            $data['trend']        = self::dailyTrend($weekStart, $weekEnd);
            $data['platform_mix'] = self::platformMix($weekStart, $weekEnd);
        } catch (Throwable $e) {
            // 차트는 부가 정보 — 실패해도 대시보드 전체를 막지 않는다
        }

        try {
            $data['risk_alerts']       = AdminDashboard::riskAlerts();
            $data['large_withdrawals'] = AdminDashboard::largeWithdrawals();
        } catch (Throwable $e) {
            // 부가 위젯
        }

        return $data;
    }

    /**
     * 스코프 내 대리점별 실적 한 줄씩.
     * 지표별로 GROUP BY 쿼리를 따로 돌려 PHP에서 합친다 — 한 쿼리로 조인하면
     * 라이더×정산×출금이 서로 곱해져(fan-out) 합계가 부풀려지기 때문.
     *
     * @return list<array<string, mixed>>
     */
    private static function agencyRows(string $from, string $to): array
    {
        if (!db_table_exists('organizations')) {
            return [];
        }

        [$where, $params] = Org::agencyScopeClause('o.id');
        $rows = db_rows(
            "SELECT o.id, o.name, o.code, o.is_active, o.parent_id, p.name AS parent_name
               FROM organizations o
               LEFT JOIN organizations p ON p.id = o.parent_id
              WHERE o.level = 'agency'" . ($where !== '' ? " AND {$where}" : '') . '
              ORDER BY p.name ASC, o.name ASC',
            $params
        );
        if ($rows === []) {
            return [];
        }

        $out = [];
        $ids = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $ids[] = $id;
            $out[$id] = [
                'id'             => $id,
                'name'           => (string) $r['name'],
                'code'           => (string) $r['code'],
                'parent_id'      => (int) ($r['parent_id'] ?? 0),
                'parent_name'    => $r['parent_name'] !== null ? (string) $r['parent_name'] : '—',
                'is_active'      => (int) ($r['is_active'] ?? 0) === 1,
                'riders'         => 0,
                'week_orders'    => 0,
                'week_payout'    => 0,
                'wallet_balance' => 0,
                'pending_count'  => 0,
                'pending_amount' => 0,
                'debt_balance'   => 0,
                'fee_share'      => 0,
                'fee_pct'        => 0.0,
                'last_upload'    => '',
                'unapplied'      => 0,
                'unmatched'      => 0,
            ];
        }

        $ph = implode(',', array_fill(0, count($ids), '?'));

        // 활성 라이더 수
        self::fill($out, "SELECT agency_id k, COUNT(*) v FROM riders
                           WHERE status = 'active' AND agency_id IN ({$ph}) GROUP BY agency_id", $ids, 'riders');

        // 이번 주 정산액 · 건수 (부가세 제외)
        if (db_table_exists('settlement_daily_riders')) {
            $exVat = SettlementAmounts::sqlExVatExpr('sdr');
            foreach (db_rows(
                "SELECT r.agency_id k,
                        COALESCE(SUM({$exVat}), 0) payout,
                        COALESCE(SUM(sdr.order_count), 0) orders
                   FROM settlement_daily_riders sdr
                   INNER JOIN riders r ON r.id = sdr.rider_id
                  WHERE sdr.settlement_date >= ? AND sdr.settlement_date <= ?
                    AND r.agency_id IN ({$ph})
                  GROUP BY r.agency_id",
                array_merge([$from, $to], $ids)
            ) as $r) {
                $k = (int) $r['k'];
                if (isset($out[$k])) {
                    $out[$k]['week_payout'] = (int) $r['payout'];
                    $out[$k]['week_orders'] = (int) $r['orders'];
                }
            }
        }

        // 대리점 지갑 잔액
        if (db_table_exists('agency_wallets')) {
            self::fill($out, "SELECT agency_id k, balance v FROM agency_wallets
                               WHERE agency_id IN ({$ph})", $ids, 'wallet_balance');
        }

        // 출금 대기 (라이더 소속 기준 + 대리점 자체 인출)
        if (db_table_exists('withdrawal_requests')) {
            foreach (db_rows(
                "SELECT COALESCE(r.agency_id, wr.agency_id) k,
                        COUNT(*) c, COALESCE(SUM(wr.amount), 0) amt
                   FROM withdrawal_requests wr
                   LEFT JOIN riders r ON r.id = wr.rider_id
                  WHERE wr.status = 'pending'
                    AND COALESCE(r.agency_id, wr.agency_id) IN ({$ph})
                  GROUP BY COALESCE(r.agency_id, wr.agency_id)",
                $ids
            ) as $r) {
                $k = (int) $r['k'];
                if (isset($out[$k])) {
                    $out[$k]['pending_count']  = (int) $r['c'];
                    $out[$k]['pending_amount'] = (int) $r['amt'];
                }
            }
        }

        // 미수금 잔액
        if (db_table_exists('rider_debts')) {
            self::fill($out, "SELECT r.agency_id k, COALESCE(SUM(d.balance_amount), 0) v
                                FROM rider_debts d
                                INNER JOIN riders r ON r.id = d.rider_id
                               WHERE d.status = 'active' AND r.agency_id IN ({$ph})
                               GROUP BY r.agency_id", $ids, 'debt_balance');
        }

        // 업로드 현황 — 최근 업로드일 · 미반영(파싱만 됨) · 미매칭 행
        if (db_table_exists('settlement_uploads')) {
            foreach (db_rows(
                "SELECT agency_id k,
                        MAX(settlement_date) last_date,
                        SUM(status = 'parsed') unapplied,
                        SUM(error_rows) unmatched
                   FROM settlement_uploads
                  WHERE kind = 'daily' AND agency_id IN ({$ph})
                  GROUP BY agency_id",
                $ids
            ) as $r) {
                $k = (int) $r['k'];
                if (isset($out[$k])) {
                    $out[$k]['last_upload'] = (string) ($r['last_date'] ?? '');
                    $out[$k]['unapplied']   = (int) $r['unapplied'];
                    $out[$k]['unmatched']   = (int) $r['unmatched'];
                }
            }
        }

        // 플랫폼 수수료 — 내 몫. 결제 시점에 스냅샷 저장된 hq_amount/distributor_amount를
        // 그대로 합산한다(요율이 나중에 바뀌어도 과거 결제건의 실제 분배액은 변하지 않는다).
        if (db_table_exists('pg_payments')) {
            require_once __DIR__ . '/PgFeeConfig.php';
            $isHq = admin_org_level() === Org::LEVEL_ADMIN;
            $shareCol = $isHq ? 'hq_amount' : 'distributor_amount';
            foreach (db_rows(
                "SELECT agency_id k, COALESCE(SUM({$shareCol}), 0) share
                   FROM pg_payments
                  WHERE status = 'success'
                    AND DATE(created_at) >= ? AND DATE(created_at) <= ?
                    AND agency_id IN ({$ph})
                  GROUP BY agency_id",
                array_merge([$from, $to], $ids)
            ) as $r) {
                $k = (int) $r['k'];
                if (isset($out[$k])) {
                    $out[$k]['fee_share'] = (int) $r['share'];
                }
            }
            // 적용 요율은 현재 설정값을 참고용으로 보여준다(모든 대리점에 대해)
            foreach ($out as $k => $row) {
                $bd = PgFeeConfig::breakdownForAgency($k);
                $out[$k]['fee_pct'] = $isHq ? $bd['hq'] : $bd['distributor'];
            }
        }

        $list = array_values($out);
        usort($list, static fn (array $a, array $b): int => $b['week_payout'] <=> $a['week_payout']);

        return $list;
    }

    /**
     * 기간 내 일별 정산 추이 — 차트용. 데이터 없는 날도 0으로 채워 x축이 실제 날짜 간격을
     * 반영하게 한다(데이터 있는 날만 이으면 2월과 6월이 붙어 보여 오해를 부른다).
     * 기간이 길면 포인트가 과해지므로 62일을 넘으면 주 단위로 묶는다.
     *
     * ⚠️ 집계 소스는 반드시 KPI·순위와 같은 부가세 제외 정산액(`SettlementAmounts::sqlExVatExpr`)을 쓴다.
     *    `settlement_rider_cycles.net_amount`(수수료·공제 후 지갑 적립액)로 그리면 바로 위
     *    "기간 정산 합계" 숫자와 차트가 어긋나 버그처럼 보인다(실측 차이 39만원).
     *
     * @return array{labels:list<string>, net:list<int>, orders:list<int>, bucket:string}
     */
    private static function dailyTrend(string $from, string $to): array
    {
        $empty = ['labels' => [], 'net' => [], 'orders' => [], 'bucket' => 'day'];
        if (!db_table_exists('settlement_daily_riders')) {
            return $empty;
        }

        [$scope, $params] = Org::agencyScopeClause('r.agency_id');
        $cond = $scope !== '' ? ' AND ' . $scope : '';
        $exVat = SettlementAmounts::sqlExVatExpr('sdr');
        $rows = db_rows(
            "SELECT sdr.settlement_date d,
                    COALESCE(SUM({$exVat}), 0) net,
                    COALESCE(SUM(sdr.order_count), 0)   orders
               FROM settlement_daily_riders sdr
               INNER JOIN riders r ON r.id = sdr.rider_id
              WHERE sdr.settlement_date >= ? AND sdr.settlement_date <= ?{$cond}
              GROUP BY sdr.settlement_date",
            array_merge([$from, $to], $params)
        );

        $byDate = [];
        foreach ($rows as $r) {
            $byDate[(string) $r['d']] = ['net' => (int) $r['net'], 'orders' => (int) $r['orders']];
        }

        $days = (int) round((strtotime($to) - strtotime($from)) / 86400) + 1;
        if ($days < 1) {
            return $empty;
        }
        $weekly = $days > 62;

        $labels = [];
        $net    = [];
        $orders = [];
        $bucketNet = 0;
        $bucketOrd = 0;
        $bucketStart = null;

        for ($i = 0; $i < $days; $i++) {
            $date = date('Y-m-d', strtotime($from . ' +' . $i . ' days'));
            $v = $byDate[$date] ?? ['net' => 0, 'orders' => 0];

            if (!$weekly) {
                $labels[] = date('n/j', strtotime($date));
                $net[]    = $v['net'];
                $orders[] = $v['orders'];
                continue;
            }

            $bucketStart ??= $date;
            $bucketNet += $v['net'];
            $bucketOrd += $v['orders'];
            // 7일마다 또는 마지막 날에 버킷을 닫는다
            if ((($i + 1) % 7 === 0) || $i === $days - 1) {
                $labels[] = date('n/j', strtotime($bucketStart));
                $net[]    = $bucketNet;
                $orders[] = $bucketOrd;
                $bucketNet = 0;
                $bucketOrd = 0;
                $bucketStart = null;
            }
        }

        return ['labels' => $labels, 'net' => $net, 'orders' => $orders, 'bucket' => $weekly ? 'week' : 'day'];
    }

    /**
     * 기간 내 플랫폼별 정산 비중 — 도넛 차트용.
     * dailyTrend()와 같은 이유로 KPI와 동일한 부가세 제외 정산액을 집계 소스로 쓴다.
     *
     * @return list<array{platform:string, label:string, net:int}>
     */
    private static function platformMix(string $from, string $to): array
    {
        if (!db_table_exists('settlement_daily_riders')) {
            return [];
        }
        $labels = ['baemin' => '배달의민족', 'coupang' => '쿠팡이츠', 'other' => '기타'];

        [$scope, $params] = Org::agencyScopeClause('r.agency_id');
        $cond = $scope !== '' ? ' AND ' . $scope : '';
        $exVat = SettlementAmounts::sqlExVatExpr('sdr');
        $rows = db_rows(
            "SELECT sdr.platform, COALESCE(SUM({$exVat}), 0) net
               FROM settlement_daily_riders sdr
               INNER JOIN riders r ON r.id = sdr.rider_id
              WHERE sdr.settlement_date >= ? AND sdr.settlement_date <= ?{$cond}
              GROUP BY sdr.platform
              ORDER BY net DESC",
            array_merge([$from, $to], $params)
        );

        return array_map(static fn (array $r): array => [
            'platform' => (string) $r['platform'],
            'label'    => $labels[(string) $r['platform']] ?? (string) $r['platform'],
            'net'      => (int) $r['net'],
        ], $rows);
    }

    /**
     * key/value 두 컬럼(k, v) 쿼리 결과를 대리점 행에 채운다.
     *
     * @param array<int, array<string, mixed>> $out
     * @param list<int>                        $ids
     */
    private static function fill(array &$out, string $sql, array $ids, string $field): void
    {
        foreach (db_rows($sql, $ids) as $r) {
            $k = (int) $r['k'];
            if (isset($out[$k])) {
                $out[$k][$field] = (int) $r['v'];
            }
        }
    }

    /**
     * 총판별 롤업 (본사 전용).
     *
     * @param  list<array<string, mixed>> $agencyRows
     * @return list<array<string, mixed>>
     */
    private static function rollupByDistributor(array $agencyRows): array
    {
        $byDist = [];
        foreach ($agencyRows as $r) {
            $key = (int) $r['parent_id'];
            if (!isset($byDist[$key])) {
                $byDist[$key] = [
                    'id'          => $key,
                    'name'        => (string) $r['parent_name'],
                    'agencies'    => 0,
                    'riders'      => 0,
                    'week_payout' => 0,
                    'week_orders' => 0,
                    'fee_share'   => 0,
                ];
            }
            $byDist[$key]['agencies']    += 1;
            $byDist[$key]['riders']      += $r['riders'];
            $byDist[$key]['week_payout'] += $r['week_payout'];
            $byDist[$key]['week_orders'] += $r['week_orders'];
            $byDist[$key]['fee_share']   += $r['fee_share'];
        }

        $list = array_values($byDist);
        usort($list, static fn (array $a, array $b): int => $b['week_payout'] <=> $a['week_payout']);

        return $list;
    }

    /**
     * 손이 필요한 대리점 — 미반영 업로드 / 미매칭 행 / 출금대기가 지갑잔액 초과.
     *
     * @param  list<array<string, mixed>> $agencyRows
     * @return list<array{name:string, id:int, issues:list<array{label:string, level:string}>}>
     */
    private static function attentionRows(array $agencyRows): array
    {
        $out = [];
        foreach ($agencyRows as $r) {
            $issues = [];
            if ($r['unapplied'] > 0) {
                $issues[] = ['label' => '미반영 업로드 ' . $r['unapplied'] . '건', 'level' => 'warning'];
            }
            if ($r['unmatched'] > 0) {
                $issues[] = ['label' => '미매칭 ' . number_format($r['unmatched']) . '행', 'level' => 'danger'];
            }
            if ($r['pending_amount'] > 0 && $r['pending_amount'] > $r['wallet_balance']) {
                $issues[] = ['label' => '출금대기가 지갑잔액 초과', 'level' => 'danger'];
            }
            if (!$r['is_active']) {
                $issues[] = ['label' => '중지된 대리점', 'level' => 'secondary'];
            }
            if ($issues !== []) {
                $out[] = ['name' => (string) $r['name'], 'id' => (int) $r['id'], 'issues' => $issues];
            }
        }

        return $out;
    }

    private static function payoutTotal(string $from, string $to): int
    {
        if (!db_table_exists('settlement_daily_riders')) {
            return 0;
        }
        [$scope, $params] = Org::agencyScopeClause('r.agency_id');
        $cond = $scope !== '' ? ' AND ' . $scope : '';
        $exVat = SettlementAmounts::sqlExVatExpr('sdr');
        $row = db_row(
            "SELECT COALESCE(SUM({$exVat}), 0) v
               FROM settlement_daily_riders sdr
               INNER JOIN riders r ON r.id = sdr.rider_id
              WHERE sdr.settlement_date >= ? AND sdr.settlement_date <= ?{$cond}",
            array_merge([$from, $to], $params)
        );

        return (int) ($row['v'] ?? 0);
    }

    private static function percentDelta(int $current, int $previous): ?float
    {
        if ($previous === 0) {
            return $current > 0 ? 100.0 : null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
