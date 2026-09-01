<?php

declare(strict_types=1);

require_once __DIR__ . '/Org.php';

/**
 * 본사 통계 — 전사 관점의 다양한 집계. 본사(super)만 사용한다(호출부에서 권한 확인).
 *
 * 기간 스코프(from~to)는 정산·수수료수입·출금·플랫폼믹스·대리점순위에 적용하고,
 * 조직/라이더 구성·지갑·예수금은 현재 상태(전체)로 집계한다. 월 추이는 최근 12개월 고정.
 */
final class Statistics
{
    /** 핵심 KPI — 현재 상태 + 기간 정산/수입. */
    public static function overview(string $from, string $to): array
    {
        $riders = db_row("SELECT
            COUNT(*) AS total,
            SUM(status='active') AS active,
            SUM(is_daily_settlement=1) AS daily,
            SUM(withholding_tax_enabled=1) AS withholding
          FROM riders") ?? [];

        $orgs = db_row("SELECT
            SUM(level='distributor') AS distributors,
            SUM(level='agency') AS agencies
          FROM organizations WHERE is_active=1") ?? [];

        $settle = self::settlementTotals($from, $to);
        $hqIncome = 0;
        foreach (self::hqIncomeByType($from, $to) as $r) {
            $hqIncome += (int) $r['amount'];
        }

        return [
            'riders_total'   => (int) ($riders['total'] ?? 0),
            'riders_active'  => (int) ($riders['active'] ?? 0),
            'riders_daily'   => (int) ($riders['daily'] ?? 0),
            'riders_withholding' => (int) ($riders['withholding'] ?? 0),
            'distributors'   => (int) ($orgs['distributors'] ?? 0),
            'agencies'       => (int) ($orgs['agencies'] ?? 0),
            'settle_net'     => (int) $settle['net'],
            'settle_orders'  => (int) $settle['orders'],
            'hq_income'      => $hqIncome,
        ];
    }

    /** 기간 정산 합계(net·gross·주문수·건수). */
    public static function settlementTotals(string $from, string $to): array
    {
        if (!db_table_exists('settlement_rider_cycles')) {
            return ['net' => 0, 'gross' => 0, 'orders' => 0, 'cycles' => 0];
        }
        $r = db_row(
            "SELECT COALESCE(SUM(net_amount),0) AS net, COALESCE(SUM(gross_amount),0) AS gross,
                    COALESCE(SUM(order_count),0) AS orders, COUNT(*) AS cycles
               FROM settlement_rider_cycles WHERE settlement_date BETWEEN ? AND ?",
            [$from, $to]
        ) ?? [];

        return ['net' => (int) ($r['net'] ?? 0), 'gross' => (int) ($r['gross'] ?? 0), 'orders' => (int) ($r['orders'] ?? 0), 'cycles' => (int) ($r['cycles'] ?? 0)];
    }

    /** 최근 N개월 월별 추이(정산 net·주문수·출금 완료액). */
    public static function monthlyTrend(int $months = 12): array
    {
        $labels = [];
        $net = [];
        $orders = [];
        $withdraw = [];

        // 월 라벨 생성(오래된→최신)
        $keys = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $ym = date('Y-m', strtotime("first day of -{$i} month"));
            $keys[$ym] = count($labels);
            $labels[] = $ym;
            $net[] = 0;
            $orders[] = 0;
            $withdraw[] = 0;
        }
        $start = date('Y-m-01', strtotime("first day of -" . ($months - 1) . " month"));

        if (db_table_exists('settlement_rider_cycles')) {
            foreach (db_rows(
                "SELECT DATE_FORMAT(settlement_date,'%Y-%m') AS ym, SUM(net_amount) AS net, SUM(order_count) AS ord
                   FROM settlement_rider_cycles WHERE settlement_date >= ? GROUP BY ym",
                [$start]
            ) as $r) {
                if (isset($keys[$r['ym']])) {
                    $net[$keys[$r['ym']]] = (int) $r['net'];
                    $orders[$keys[$r['ym']]] = (int) $r['ord'];
                }
            }
        }
        if (db_table_exists('withdrawal_requests')) {
            foreach (db_rows(
                "SELECT DATE_FORMAT(COALESCE(completed_at, requested_at),'%Y-%m') AS ym, SUM(amount) AS amt
                   FROM withdrawal_requests WHERE status='completed' AND COALESCE(completed_at, requested_at) >= ? GROUP BY ym",
                [$start . ' 00:00:00']
            ) as $r) {
                if (isset($keys[$r['ym']])) {
                    $withdraw[$keys[$r['ym']]] = (int) $r['amt'];
                }
            }
        }

        return ['labels' => $labels, 'net' => $net, 'orders' => $orders, 'withdraw' => $withdraw];
    }

    /** 본사 수수료 수입 구성 — agency_wallet_ledger 의 본사 조직 credit 을 reason별로. */
    public static function hqIncomeByType(string $from, string $to): array
    {
        $hqId = Org::hqId();
        if ($hqId < 1 || !db_table_exists('agency_wallet_ledger')) {
            return [];
        }
        $labels = [
            'wd_fee_in'       => '정산수수료',
            'agency_fee_in'   => '대행수수료',
            'pg_fee_in'       => '플랫폼수수료',
            'transfer_fee_in' => '이체수수료',
            'lease_fee_in'    => '리스수수료',
        ];
        $rows = db_rows(
            "SELECT reason, COALESCE(SUM(amount),0) AS amt
               FROM agency_wallet_ledger
              WHERE agency_id = ? AND direction='credit'
                AND reason IN ('wd_fee_in','agency_fee_in','pg_fee_in','transfer_fee_in','lease_fee_in')
                AND DATE(created_at) BETWEEN ? AND ?
              GROUP BY reason",
            [$hqId, $from, $to]
        );
        $out = [];
        foreach ($rows as $r) {
            $amt = (int) $r['amt'];
            if ($amt <= 0) {
                continue;
            }
            $out[] = ['reason' => (string) $r['reason'], 'label' => $labels[(string) $r['reason']] ?? (string) $r['reason'], 'amount' => $amt];
        }
        usort($out, static fn ($a, $b): int => $b['amount'] <=> $a['amount']);

        return $out;
    }

    /** 플랫폼별 정산 분포(기간). */
    public static function platformMix(string $from, string $to): array
    {
        if (!db_table_exists('settlement_rider_cycles')) {
            return [];
        }
        $names = ['baemin' => '배달의민족', 'coupang' => '쿠팡이츠', 'other' => '기타'];
        $out = [];
        foreach (db_rows(
            "SELECT platform, COALESCE(SUM(net_amount),0) AS amt, COALESCE(SUM(order_count),0) AS ord
               FROM settlement_rider_cycles WHERE settlement_date BETWEEN ? AND ? GROUP BY platform",
            [$from, $to]
        ) as $r) {
            if ((int) $r['amt'] <= 0) {
                continue;
            }
            $p = (string) $r['platform'];
            $out[] = ['platform' => $p, 'label' => $names[$p] ?? $p, 'amount' => (int) $r['amt'], 'orders' => (int) $r['ord']];
        }
        usort($out, static fn ($a, $b): int => $b['amount'] <=> $a['amount']);

        return $out;
    }

    /** 대리점별 정산액 순위(기간). */
    public static function topAgenciesBySettlement(string $from, string $to, int $limit = 10): array
    {
        if (!db_table_exists('settlement_rider_cycles')) {
            return [];
        }

        return db_rows(
            "SELECT o.id, o.name, COALESCE(SUM(c.net_amount),0) AS net, COALESCE(SUM(c.order_count),0) AS ord
               FROM settlement_rider_cycles c
               JOIN riders r ON r.id = c.rider_id
               JOIN organizations o ON o.id = r.agency_id
              WHERE c.settlement_date BETWEEN ? AND ?
              GROUP BY o.id, o.name
              ORDER BY net DESC
              LIMIT {$limit}",
            [$from, $to]
        );
    }

    /** 대리점별 활성 라이더 수 순위. */
    public static function topAgenciesByRiders(int $limit = 10): array
    {
        if (!db_table_exists('riders')) {
            return [];
        }

        return db_rows(
            "SELECT o.id, o.name, COUNT(r.id) AS cnt
               FROM organizations o
               LEFT JOIN riders r ON r.agency_id = o.id AND r.status='active'
              WHERE o.level='agency' AND o.is_active=1
              GROUP BY o.id, o.name
              ORDER BY cnt DESC
              LIMIT {$limit}"
        );
    }

    /** 라이더 구성 — 선정산/주정산, 활성/기타. */
    public static function riderComposition(): array
    {
        $r = db_row("SELECT
            SUM(status='active') AS active,
            SUM(status<>'active') AS inactive,
            SUM(is_daily_settlement=1) AS daily,
            SUM(is_daily_settlement=0) AS weekly
          FROM riders") ?? [];

        return [
            'active'   => (int) ($r['active'] ?? 0),
            'inactive' => (int) ($r['inactive'] ?? 0),
            'daily'    => (int) ($r['daily'] ?? 0),
            'weekly'   => (int) ($r['weekly'] ?? 0),
        ];
    }

    /** 출금 — 유형별 금액 + 상태별 건수(기간). */
    public static function withdrawals(string $from, string $to): array
    {
        if (!db_table_exists('withdrawal_requests')) {
            return ['by_kind' => [], 'by_status' => []];
        }
        $kindLabel = ['rider_manual' => '주정산 출금', 'auto_daily' => '일일정산 지급', 'agency_payout' => '자체 인출'];
        $stLabel   = ['pending' => '대기', 'downloaded' => '다운로드', 'transferring' => '이체중', 'completed' => '완료', 'failed' => '실패', 'rejected' => '반려'];

        $byKind = [];
        foreach (db_rows(
            "SELECT kind, COALESCE(SUM(amount),0) AS amt, COUNT(*) AS cnt
               FROM withdrawal_requests WHERE DATE(requested_at) BETWEEN ? AND ? GROUP BY kind",
            [$from, $to]
        ) as $r) {
            $byKind[] = ['kind' => (string) $r['kind'], 'label' => $kindLabel[(string) $r['kind']] ?? (string) $r['kind'], 'amount' => (int) $r['amt'], 'count' => (int) $r['cnt']];
        }
        $byStatus = [];
        foreach (db_rows(
            "SELECT status, COUNT(*) AS cnt, COALESCE(SUM(amount),0) AS amt
               FROM withdrawal_requests WHERE DATE(requested_at) BETWEEN ? AND ? GROUP BY status",
            [$from, $to]
        ) as $r) {
            $byStatus[] = ['status' => (string) $r['status'], 'label' => $stLabel[(string) $r['status']] ?? (string) $r['status'], 'count' => (int) $r['cnt'], 'amount' => (int) $r['amt']];
        }

        return ['by_kind' => $byKind, 'by_status' => $byStatus];
    }

    /** 지갑·예수금 현재 합계. */
    public static function balances(): array
    {
        $rw = db_table_exists('rider_wallets')
            ? (int) (db_row("SELECT COALESCE(SUM(balance),0) AS b FROM rider_wallets")['b'] ?? 0)
            : 0;

        $agg = ['agency_balance' => 0, 'withholding' => 0, 'insurance' => 0];
        if (db_table_exists('agency_wallets')) {
            $cols = array_column(db_rows('SHOW COLUMNS FROM agency_wallets'), 'Field');
            $insSel = in_array('insurance_reserve', $cols, true) ? 'COALESCE(SUM(w.insurance_reserve),0)' : '0';
            $r = db_row(
                "SELECT COALESCE(SUM(w.balance),0) AS bal,
                        COALESCE(SUM(w.withholding_reserve),0) AS wh,
                        {$insSel} AS ins
                   FROM agency_wallets w
                   JOIN organizations o ON o.id = w.agency_id
                  WHERE o.level='agency'"
            ) ?? [];
            $agg = ['agency_balance' => (int) ($r['bal'] ?? 0), 'withholding' => (int) ($r['wh'] ?? 0), 'insurance' => (int) ($r['ins'] ?? 0)];
        }

        return [
            'rider_balance'   => $rw,
            'agency_balance'  => $agg['agency_balance'],
            'withholding_reserve' => $agg['withholding'],
            'insurance_reserve'   => $agg['insurance'],
        ];
    }
}
