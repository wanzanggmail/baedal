<?php

declare(strict_types=1);

/**
 * 관리자 대시보드 집계 (DB)
 */
final class AdminDashboard
{
    /**
     * @return array<string, mixed>
     */
    public static function load(): array
    {
        $weekStart = date('Y-m-d', strtotime('monday this week'));
        $weekEnd   = date('Y-m-d');
        $prevStart = date('Y-m-d', strtotime($weekStart . ' -7 days'));
        $prevEnd   = date('Y-m-d', strtotime($weekEnd . ' -7 days'));
        $monthStart = date('Y-m-01');

        $data = [
            'period_label'     => self::formatPeriodLabel($weekStart, $weekEnd),
            'week_start'       => $weekStart,
            'week_end'         => $weekEnd,
            'errors'           => [],
            'active_riders'    => 0,
            'riders_new_week'  => 0,
            'week_payout'      => 0,
            'week_orders'      => 0,
            'week_payout_delta'=> null,
            'week_orders_delta'=> null,
            'pending_withdrawals' => 0,
            'pending_withdraw_amount' => 0,
            'active_banners'   => 0,
            'published_notices'=> 0,
            'month_deductions' => 0,
            'month_deduction_delta' => null,
            'platform_rows'    => [],
            'platform_total'   => 0,
            'timeline'         => [],
            'recent_uploads'   => [],
        ];

        try {
            // 멀티테넌시: 소속 대리점 스코프
            [$rScope, $rScopeParams] = Org::agencyScopeClause('agency_id');
            $rScopeSql = $rScope !== '' ? ' AND ' . $rScope : '';
            $data['active_riders'] = (int) (db_row(
                "SELECT COUNT(*) AS c FROM riders WHERE status = 'active'" . $rScopeSql,
                $rScopeParams
            )['c'] ?? 0);
            $data['riders_new_week'] = (int) (db_row(
                "SELECT COUNT(*) AS c FROM riders
                 WHERE status = 'active' AND created_at >= ?" . $rScopeSql,
                array_merge([$weekStart . ' 00:00:00'], $rScopeParams)
            )['c'] ?? 0);
        } catch (Throwable $e) {
            $data['errors'][] = '라이더: ' . $e->getMessage();
        }

        try {
            $cur = self::settlementTotals($weekStart, $weekEnd);
            $prev = self::settlementTotals($prevStart, $prevEnd);
            $data['week_payout'] = $cur['payout'];
            $data['week_orders'] = $cur['orders'];
            $data['week_payout_delta'] = self::percentDelta($cur['payout'], $prev['payout']);
            $data['week_orders_delta'] = self::percentDelta($cur['orders'], $prev['orders']);
            $data['platform_rows'] = self::platformBreakdown($weekStart, $weekEnd);
            $data['platform_total'] = array_sum(array_column($data['platform_rows'], 'amount'));
        } catch (Throwable $e) {
            $data['errors'][] = '정산: ' . $e->getMessage();
        }

        try {
            require_once INC_PATH . '/Withdrawal.php';
            $ws = Withdrawal::summary();
            $data['pending_withdrawals'] = $ws['pending_count'];
            $data['pending_withdraw_amount'] = $ws['pending_amount'];
        } catch (Throwable $e) {
            $data['errors'][] = '출금: ' . $e->getMessage();
        }

        try {
            require_once INC_PATH . '/Banner.php';
            require_once INC_PATH . '/Notice.php';
            $data['active_banners'] = count(Banner::listActiveForRider([Banner::RIDER_HOME_CAROUSEL_SLOT], 50));
            $data['published_notices'] = count(Notice::listPublishedForRider(500));
        } catch (Throwable $e) {
            $data['errors'][] = '콘텐츠: ' . $e->getMessage();
        }

        try {
            $curDed = self::monthlyDeductions($monthStart, $weekEnd);
            $prevMonthStart = date('Y-m-01', strtotime($monthStart . ' -1 month'));
            $prevMonthEnd = date('Y-m-t', strtotime($prevMonthStart));
            $prevDed = self::monthlyDeductions($prevMonthStart, $prevMonthEnd);
            $data['month_deductions'] = $curDed;
            $data['month_deduction_delta'] = self::percentDelta($curDed, $prevDed);
        } catch (Throwable $e) {
            // 테이블 없으면 0 유지
        }

        try {
            $data['timeline'] = self::buildTimeline();
            [$uScope, $uScopeParams] = Org::agencyScopeClause('u.agency_id');
            $uCond = $uScope !== '' ? ' AND ' . $uScope : '';
            $data['recent_uploads'] = db_rows(
                "SELECT u.id, u.settlement_date, u.platform, u.original_filename,
                        u.total_rows, u.ok_rows, u.error_rows, u.status, u.created_at
                   FROM settlement_uploads u
                  WHERE u.kind = 'daily'{$uCond}
                  ORDER BY u.created_at DESC
                  LIMIT 8",
                $uScopeParams
            );
        } catch (Throwable $e) {
            $data['errors'][] = '업로드 이력: ' . $e->getMessage();
        }

        return $data;
    }

    public static function formatWon(int $amount): string
    {
        $sign = $amount < 0 ? '-' : '';
        $n = abs($amount);
        if ($n >= 100_000_000) {
            $v = $n / 100_000_000;
            $s = abs($v - round($v)) < 0.05 ? (string) (int) round($v) : number_format($v, 1, '.', '');

            return $sign . '₩ ' . $s . '억';
        }
        if ($n >= 10_000) {
            return $sign . '₩ ' . number_format((int) round($n / 10_000)) . '만';
        }

        return $sign . '₩ ' . number_format($n);
    }

    public static function formatCount(int $n): string
    {
        return number_format($n);
    }

    /**
     * @return array{payout: int, orders: int}
     */
    private static function settlementTotals(string $from, string $to): array
    {
        if (!self::tableExists('settlement_daily_riders')) {
            return ['payout' => 0, 'orders' => 0];
        }
        [$scope, $scopeParams] = Org::agencyScopeClause('r.agency_id');
        $join = $scope !== '' ? 'INNER JOIN riders r ON r.id = sdr.rider_id' : '';
        $cond = $scope !== '' ? ' AND ' . $scope : '';
        $row = db_row(
            "SELECT COALESCE(SUM(sdr.payout_amount), 0) AS payout,
                    COALESCE(SUM(sdr.order_count), 0) AS orders
               FROM settlement_daily_riders sdr {$join}
              WHERE sdr.settlement_date >= ? AND sdr.settlement_date <= ?{$cond}",
            array_merge([$from, $to], $scopeParams)
        );

        return [
            'payout' => (int) ($row['payout'] ?? 0),
            'orders' => (int) ($row['orders'] ?? 0),
        ];
    }

    /**
     * @return list<array{platform: string, label: string, amount: int, pct: float}>
     */
    private static function platformBreakdown(string $from, string $to): array
    {
        if (!self::tableExists('settlement_daily_riders')) {
            return [];
        }
        $labels = [
            'baemin'  => '배달의민족',
            'coupang' => '쿠팡이츠',
            'other'   => '기타',
        ];
        [$scope, $scopeParams] = Org::agencyScopeClause('r.agency_id');
        $join = $scope !== '' ? 'INNER JOIN riders r ON r.id = sdr.rider_id' : '';
        $cond = $scope !== '' ? ' AND ' . $scope : '';
        $rows = db_rows(
            "SELECT sdr.platform, COALESCE(SUM(sdr.payout_amount), 0) AS amount
               FROM settlement_daily_riders sdr {$join}
              WHERE sdr.settlement_date >= ? AND sdr.settlement_date <= ?{$cond}
              GROUP BY sdr.platform
              ORDER BY amount DESC",
            array_merge([$from, $to], $scopeParams)
        );
        $total = 0;
        foreach ($rows as $r) {
            $total += (int) $r['amount'];
        }
        $out = [];
        foreach ($rows as $r) {
            $amt = (int) $r['amount'];
            $plat = (string) $r['platform'];
            $out[] = [
                'platform' => $plat,
                'label'    => $labels[$plat] ?? $plat,
                'amount'   => $amt,
                'pct'      => $total > 0 ? round($amt / $total * 100, 1) : 0.0,
            ];
        }

        return $out;
    }

    private static function monthlyDeductions(string $from, string $to): int
    {
        if (!self::tableExists('settlement_weekly_deductions')) {
            return 0;
        }
        [$scope, $scopeParams] = Org::agencyScopeClause('u.agency_id');
        $join = $scope !== '' ? 'INNER JOIN settlement_uploads u ON u.id = swd.upload_id' : '';
        $cond = $scope !== '' ? ' AND ' . $scope : '';
        $row = db_row(
            "SELECT COALESCE(SUM(ABS(swd.amount)), 0) AS total
               FROM settlement_weekly_deductions swd {$join}
              WHERE swd.week_start >= ? AND swd.week_start <= ?{$cond}",
            array_merge([$from, $to], $scopeParams)
        );

        return (int) ($row['total'] ?? 0);
    }

    /**
     * @return list<array{time: string, time_label: string, icon: string, icon_class: string, text: string}>
     */
    private static function buildTimeline(): array
    {
        $events = [];

        if (self::tableExists('settlement_uploads')) {
            [$uScope, $uScopeParams] = Org::agencyScopeClause('u.agency_id');
            $uCond = $uScope !== '' ? ' AND ' . $uScope : '';
            $uploads = db_rows(
                "SELECT u.original_filename, u.platform, u.total_rows, u.ok_rows, u.error_rows,
                        u.status, u.created_at
                   FROM settlement_uploads u
                  WHERE u.kind = 'daily'
                    AND u.created_at >= DATE_SUB(NOW(), INTERVAL 3 DAY){$uCond}
                  ORDER BY u.created_at DESC
                  LIMIT 6",
                $uScopeParams
            );
            $plat = ['baemin' => '배민', 'coupang' => '쿠팡', 'other' => '기타'];
            foreach ($uploads as $u) {
                $p = $plat[(string) ($u['platform'] ?? '')] ?? (string) ($u['platform'] ?? '');
                $err = (int) ($u['error_rows'] ?? 0);
                $icon = $err > 0 ? 'ki-information-2' : 'ki-file-added';
                $iconClass = $err > 0 ? 'danger' : 'success';
                $text = $p . ' 일일 정산 업로드 · ' . (string) ($u['original_filename'] ?? '');
                $text .= ' (행 ' . number_format((int) ($u['total_rows'] ?? 0)) . ')';
                if ($err > 0) {
                    $text .= ' · 미매칭 ' . $err . '건';
                }
                $events[] = [
                    'ts'         => strtotime((string) $u['created_at']),
                    'time_label' => date('H:i', strtotime((string) $u['created_at'])),
                    'icon'       => $icon,
                    'icon_class' => $iconClass,
                    'text'       => $text,
                ];
            }
        }

        if (self::tableExists('withdrawal_requests')) {
            [$wScope, $wScopeParams] = Org::agencyScopeClause('r.agency_id');
            $wCond = $wScope !== '' ? ' AND ' . $wScope : '';
            $wds = db_rows(
                "SELECT wr.requested_at, wr.amount, r.name AS rider_name
                   FROM withdrawal_requests wr
                   INNER JOIN riders r ON r.id = wr.rider_id
                  WHERE wr.requested_at >= DATE_SUB(NOW(), INTERVAL 3 DAY){$wCond}
                  ORDER BY wr.requested_at DESC
                  LIMIT 5",
                $wScopeParams
            );
            foreach ($wds as $w) {
                $events[] = [
                    'ts'         => strtotime((string) $w['requested_at']),
                    'time_label' => date('H:i', strtotime((string) $w['requested_at'])),
                    'icon'       => 'ki-wallet',
                    'icon_class' => 'warning',
                    'text'       => '출금 신청 · ' . (string) ($w['rider_name'] ?? '') . ' ' . self::formatWon((int) ($w['amount'] ?? 0)),
                ];
            }
        }

        usort($events, static fn (array $a, array $b): int => ($b['ts'] ?? 0) <=> ($a['ts'] ?? 0));
        $events = array_slice($events, 0, 8);
        foreach ($events as &$e) {
            unset($e['ts']);
        }

        return $events;
    }

    private static function formatPeriodLabel(string $from, string $to): string
    {
        $fy = (int) date('Y', strtotime($from));
        $ty = (int) date('Y', strtotime($to));
        $fm = (int) date('n', strtotime($from));
        $tm = (int) date('n', strtotime($to));
        $fd = (int) date('j', strtotime($from));
        $td = (int) date('j', strtotime($to));
        if ($from === $to) {
            return "{$fy}년 {$fm}월 {$fd}일";
        }

        return "{$fy}년 {$fm}월 {$fd}일 ~ {$ty}년 {$tm}월 {$td}일";
    }

    private static function percentDelta(int $current, int $previous): ?float
    {
        if ($previous === 0) {
            return $current > 0 ? 100.0 : null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    private static function tableExists(string $table): bool
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }
        try {
            $cache[$table] = db_table_exists($table);
        } catch (Throwable) {
            $cache[$table] = false;
        }

        return $cache[$table];
    }
}
