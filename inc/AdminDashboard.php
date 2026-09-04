<?php

declare(strict_types=1);

require_once __DIR__ . '/SettlementAmounts.php';

/**
 * 관리자 대시보드 집계 (DB)
 */
final class AdminDashboard
{
    /** 큰 금액 출금 하이라이트 임계값(원) */
    public const LARGE_WITHDRAWAL_THRESHOLD = 1_000_000;

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
            'debt_balance'     => 0,
            'debt_riders'      => 0,
            'debt_overdue'     => 0,
            'platform_rows'    => [],
            'platform_total'   => 0,
            'timeline'         => [],
            'recent_uploads'   => [],
            'risk_alerts'      => [],
            'large_withdrawals'=> [],
            'trend'            => ['labels' => [], 'payout' => [], 'orders' => [], 'bucket' => 'day'],
            'top_riders'       => [],
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
            $data['trend']      = self::dailyTrend($weekStart, $weekEnd);
            $data['top_riders'] = self::topRiders($weekStart, $weekEnd);
        } catch (Throwable $e) {
            // 차트는 부가 정보 — 실패해도 대시보드 전체를 막지 않는다
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

        // 라이더 미수금(대여금·리스·선지급) — 대리점이 회수해야 할 잔액.
        // 소속 대리점 스코프를 타므로 대리점 계정은 자기 라이더만, 본사는 전체가 잡힌다.
        // planned_end_on 이 지났는데 잔액이 남은 건은 "만기미납"으로 따로 센다.
        try {
            if (self::tableExists('rider_debts')) {
                [$dScope, $dParams] = Org::agencyScopeClause('r.agency_id');
                $dCond = $dScope !== '' ? ' AND ' . $dScope : '';
                $row = db_row(
                    "SELECT COALESCE(SUM(d.balance_amount), 0) AS amt,
                            COUNT(DISTINCT d.rider_id) AS riders,
                            COALESCE(SUM(d.planned_end_on IS NOT NULL
                                         AND d.planned_end_on < CURDATE()
                                         AND d.balance_amount > 0), 0) AS overdue
                       FROM rider_debts d
                       INNER JOIN riders r ON r.id = d.rider_id
                      WHERE d.status = 'active'" . $dCond,
                    $dParams
                );
                $data['debt_balance'] = (int) ($row['amt'] ?? 0);
                $data['debt_riders']  = (int) ($row['riders'] ?? 0);
                $data['debt_overdue'] = (int) ($row['overdue'] ?? 0);
            }
        } catch (Throwable $e) {
            $data['errors'][] = '미수금: ' . $e->getMessage();
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

        try {
            $data['risk_alerts']       = self::riskAlerts();
            $data['large_withdrawals'] = self::largeWithdrawals();
        } catch (Throwable $e) {
            // 리스크 위젯은 부가 정보라 실패해도 대시보드 전체를 막지 않음
        }

        return $data;
    }

    /**
     * 리스크 알림 — 본사(admin 레벨)에서만 노출. 최근 위험 관리자 행위(수동조정·역할변경 등).
     *
     * @return list<array{at:string, action:string, actor:string, detail:string, level:string}>
     */
    public static function riskAlerts(): array
    {
        if (admin_org_level() !== Org::LEVEL_ADMIN || !self::tableExists('audit_logs')) {
            return [];
        }

        $rows = db_rows(
            "SELECT al.action, al.target_table, al.target_id, al.before_value, al.after_value,
                    al.created_at, a.login_id AS actor_login
               FROM audit_logs al
               LEFT JOIN admins a ON a.id = al.actor_id AND al.actor_type = 'admin'
              WHERE al.action IN ('MANUAL_ADJUST', 'DELETE')
                 OR al.target_table IN ('agency_wallets', 'rider_wallets')
              ORDER BY al.created_at DESC
              LIMIT 10"
        );

        $out = [];
        foreach ($rows as $r) {
            $action = (string) ($r['action'] ?? '');
            $after  = json_decode((string) ($r['after_value'] ?? ''), true);
            $detail = is_array($after) && isset($after['reason'])
                ? (string) $after['reason']
                : (string) ($r['target_table'] ?? '');
            $out[] = [
                'at'     => date('m-d H:i', strtotime((string) $r['created_at'])),
                'action' => $action === 'MANUAL_ADJUST' ? '수동 조정' : ($action === 'DELETE' ? '삭제' : $action),
                'actor'  => (string) ($r['actor_login'] ?? 'system'),
                'detail' => $detail,
                'level'  => $action === 'MANUAL_ADJUST' ? 'danger' : 'warning',
            ];
        }

        return $out;
    }

    /**
     * 큰 금액 출금 하이라이트 — 임계값 이상 출금 신청(스코프 내, 최근 14일).
     *
     * @return list<array{name:string, amount:int, amount_label:string, status:string, at:string, kind:string}>
     */
    public static function largeWithdrawals(): array
    {
        if (!self::tableExists('withdrawal_requests')) {
            return [];
        }

        // rider_manual/auto_daily는 라이더 소속 대리점, agency_payout(rider 없음)은 wr.agency_id 기준
        [$scope, $scopeParams] = Org::agencyScopeClause('COALESCE(r.agency_id, wr.agency_id)');
        $cond = $scope !== '' ? ' AND ' . $scope : '';
        $params = array_merge([self::LARGE_WITHDRAWAL_THRESHOLD], $scopeParams);

        $rows = db_rows(
            "SELECT wr.amount, wr.status, wr.kind, wr.requested_at,
                    COALESCE(r.name, o.name) AS name
               FROM withdrawal_requests wr
               LEFT JOIN riders r ON r.id = wr.rider_id
               LEFT JOIN organizations o ON o.id = wr.agency_id
              WHERE wr.amount >= ?
                AND wr.requested_at >= DATE_SUB(NOW(), INTERVAL 14 DAY){$cond}
              ORDER BY wr.amount DESC, wr.requested_at DESC
              LIMIT 8",
            $params
        );

        $statusLabel = ['pending' => '대기', 'downloaded' => '처리중', 'completed' => '완료', 'rejected' => '반려', 'failed' => '실패'];
        $kindLabel   = ['rider_manual' => '라이더', 'auto_daily' => '일일정산', 'agency_payout' => '대리점인출'];

        return array_map(static function (array $r) use ($statusLabel, $kindLabel): array {
            return [
                'name'         => (string) ($r['name'] ?? ''),
                'amount'       => (int) $r['amount'],
                'amount_label' => number_format((int) $r['amount']) . '원',
                'status'       => $statusLabel[(string) $r['status']] ?? (string) $r['status'],
                'kind'         => $kindLabel[(string) $r['kind']] ?? (string) $r['kind'],
                'at'           => date('m-d H:i', strtotime((string) $r['requested_at'])),
            ];
        }, $rows);
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
        $exVat = SettlementAmounts::sqlExVatExpr('sdr');
        $row = db_row(
            "SELECT COALESCE(SUM({$exVat}), 0) AS payout,
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
     * 기간 내 일별 정산 추이 — 차트용(대리점 대시보드).
     * 데이터 없는 날도 0으로 채워 x축이 실제 날짜 간격을 반영하게 하고,
     * 62일을 넘으면 주 단위로 묶는다. 집계 소스는 KPI와 같은 부가세 제외 정산액.
     *
     * @return array{labels: list<string>, payout: list<int>, orders: list<int>, bucket: string}
     */
    private static function dailyTrend(string $from, string $to): array
    {
        $empty = ['labels' => [], 'payout' => [], 'orders' => [], 'bucket' => 'day'];
        if (!self::tableExists('settlement_daily_riders')) {
            return $empty;
        }

        [$scope, $scopeParams] = Org::agencyScopeClause('r.agency_id');
        $join = $scope !== '' ? 'INNER JOIN riders r ON r.id = sdr.rider_id' : '';
        $cond = $scope !== '' ? ' AND ' . $scope : '';
        $exVat = SettlementAmounts::sqlExVatExpr('sdr');
        $rows = db_rows(
            "SELECT sdr.settlement_date d,
                    COALESCE(SUM({$exVat}), 0) payout,
                    COALESCE(SUM(sdr.order_count), 0)   orders
               FROM settlement_daily_riders sdr {$join}
              WHERE sdr.settlement_date >= ? AND sdr.settlement_date <= ?{$cond}
              GROUP BY sdr.settlement_date",
            array_merge([$from, $to], $scopeParams)
        );

        $byDate = [];
        foreach ($rows as $r) {
            $byDate[(string) $r['d']] = ['payout' => (int) $r['payout'], 'orders' => (int) $r['orders']];
        }

        $days = (int) round((strtotime($to) - strtotime($from)) / 86400) + 1;
        if ($days < 1) {
            return $empty;
        }
        $weekly = $days > 62;

        $labels = [];
        $payout = [];
        $orders = [];
        $bPayout = 0;
        $bOrders = 0;
        $bStart  = null;

        for ($i = 0; $i < $days; $i++) {
            $date = date('Y-m-d', strtotime($from . ' +' . $i . ' days'));
            $v = $byDate[$date] ?? ['payout' => 0, 'orders' => 0];

            if (!$weekly) {
                $labels[] = date('n/j', strtotime($date));
                $payout[] = $v['payout'];
                $orders[] = $v['orders'];
                continue;
            }

            $bStart ??= $date;
            $bPayout += $v['payout'];
            $bOrders += $v['orders'];
            if ((($i + 1) % 7 === 0) || $i === $days - 1) {
                $labels[] = date('n/j', strtotime($bStart));
                $payout[] = $bPayout;
                $orders[] = $bOrders;
                $bPayout = 0;
                $bOrders = 0;
                $bStart  = null;
            }
        }

        return ['labels' => $labels, 'payout' => $payout, 'orders' => $orders, 'bucket' => $weekly ? 'week' : 'day'];
    }

    /**
     * 기간 내 정산액 상위 라이더 — 가로 막대 차트용.
     *
     * @return list<array{id: int, name: string, rider_code: string, payout: int, orders: int}>
     */
    private static function topRiders(string $from, string $to, int $limit = 8): array
    {
        if (!self::tableExists('settlement_daily_riders')) {
            return [];
        }
        [$scope, $scopeParams] = Org::agencyScopeClause('r.agency_id');
        $cond = $scope !== '' ? ' AND ' . $scope : '';
        $limit = max(1, min(20, $limit));
        $exVat = SettlementAmounts::sqlExVatExpr('sdr');

        $rows = db_rows(
            "SELECT r.id, r.name, r.rider_code,
                    COALESCE(SUM({$exVat}), 0) payout,
                    COALESCE(SUM(sdr.order_count), 0)   orders
               FROM settlement_daily_riders sdr
               INNER JOIN riders r ON r.id = sdr.rider_id
              WHERE sdr.settlement_date >= ? AND sdr.settlement_date <= ?{$cond}
              GROUP BY r.id, r.name, r.rider_code
             HAVING payout > 0
              ORDER BY payout DESC
              LIMIT {$limit}",
            array_merge([$from, $to], $scopeParams)
        );

        return array_map(static fn (array $r): array => [
            'id'         => (int) $r['id'],
            'name'       => (string) $r['name'],
            'rider_code' => (string) $r['rider_code'],
            'payout'     => (int) $r['payout'],
            'orders'     => (int) $r['orders'],
        ], $rows);
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
        $exVat = SettlementAmounts::sqlExVatExpr('sdr');
        $rows = db_rows(
            "SELECT sdr.platform, COALESCE(SUM({$exVat}), 0) AS amount
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

    public static function formatPeriodLabel(string $from, string $to): string
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
