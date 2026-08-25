<?php

declare(strict_types=1);

/**
 * 라이더 원천징수·공제 내역 집계 — 라이더가 종합소득세 신고 때 쓰는 조회용.
 *
 * 왜 필요한가: 라이더는 3.3% 원천징수 대상이라 **매년 5월에 본인이 종합소득세를 신고**한다.
 * 그때 "작년에 얼마 받았고 세금이 얼마 빠졌나"를 알아야 하는데, 지금까지는 대리점에
 * 전화해서 엑셀을 뒤지게 하는 수밖에 없었다.
 *
 * ⚠️ **이 화면은 공식 원천징수영수증이 아니다.** 원천징수영수증은 지급자(대리점)가
 *    국세청에 신고한 내용으로 발급하는 것이고, 여기서는 우리 장부에 쌓인 값을 보여줄 뿐이다.
 *    화면에도 그렇게 안내한다 — 증빙으로 오해해 그대로 제출하면 곤란해진다.
 *
 * 지급액을 어떻게 구하느냐가 이 클래스의 핵심이다.
 *
 * 공제 계산의 원래 기준은 `gross_amount + support_amount` 다
 * (`SettlementLedger::composeFeesForDailyRow()`). 하지만 **그 합을 화면에 쓰면 안 된다** —
 * 정산 계산식이 여러 차례 개정됐고 기존 사이클은 소급 재계산하지 않아, 초기 구간에서는
 * 그 합이 `total_fee_amount`·`net_amount` 와 어긋난다.
 *
 * 세금 화면에서 "지급액 − 공제 = 실수령" 이 안 맞으면 그 자체로 문의가 된다. 그래서
 * `rider/views/settlement_fees.php` 가 쓰는 것과 **같은 규칙**을 따른다:
 *
 *     지급액 = net_amount + total_fee_amount   (역산)
 *
 * 실제로 지갑에 들어간 금액(net)과 실제로 뗀 금액(fee)은 둘 다 확정 사실이므로,
 * 이렇게 구한 지급액은 언제나 앞뒤가 맞는다.
 */
final class RiderTaxSummary
{
    /**
     * 공제 항목을 화면에 묶어 보여줄 이름.
     * `excel_deduction` 은 사고·오배달 등 건별 사유가 label 에 들어가 종류가 무한하다
     * → 코드 단위로 묶고 이름을 하나로 고정한다(상세는 정산 상세 화면에서 본다).
     */
    private const LABELS = [
        'hourly_ins'      => '시간제 보험',
        'agency_fee'      => '선정산수수료(대행)',
        'excel_deduction' => '차감내역(사고·오배달 등)',
    ];

    public static function ready(): bool
    {
        return db_table_exists('settlement_rider_cycles') && db_table_exists('settlement_fee_items');
    }

    /**
     * 조회 가능한 연도 — 정산 기록이 있는 해만 보여준다(빈 해를 고르게 하지 않는다).
     *
     * @return list<int>
     */
    public static function availableYears(int $riderId): array
    {
        if (!self::ready() || $riderId < 1) {
            return [];
        }
        $rows = db_rows(
            'SELECT DISTINCT YEAR(settlement_date) y
               FROM settlement_rider_cycles
              WHERE rider_id = ?
              ORDER BY y DESC',
            [$riderId]
        );
        $out = [];
        foreach ($rows as $r) {
            $y = (int) $r['y'];
            if ($y > 2000) {
                $out[] = $y;
            }
        }

        return $out;
    }

    /**
     * 기간 집계.
     *
     * @return array<string,mixed>
     */
    public static function forPeriod(int $riderId, string $from, string $to): array
    {
        $out = [
            'from' => $from, 'to' => $to,
            'cycles' => 0, 'orders' => 0,
            'settle_base' => 0, 'settle_fee' => 0, 'settle_net' => 0,
            'promo_gross' => 0, 'promo_net' => 0,
            'pay_total' => 0,
            'tax_withholding' => 0, 'tax_employment' => 0, 'tax_accident' => 0, 'tax_total' => 0,
            'other' => [], 'other_total' => 0,
            'net_total' => 0, 'months' => [],
        ];
        if (!self::ready() || $riderId < 1) {
            return $out;
        }

        // ── 정산 집계 ──
        $s = db_row(
            'SELECT COUNT(*) cycles,
                    COALESCE(SUM(order_count), 0)      orders,
                    COALESCE(SUM(total_fee_amount), 0) fee,
                    COALESCE(SUM(net_amount), 0)       net
               FROM settlement_rider_cycles
              WHERE rider_id = ? AND settlement_date BETWEEN ? AND ?',
            [$riderId, $from, $to]
        ) ?: [];

        $out['cycles']     = (int) ($s['cycles'] ?? 0);
        $out['orders']     = (int) ($s['orders'] ?? 0);
        $out['settle_fee'] = (int) ($s['fee'] ?? 0);
        $out['settle_net'] = (int) ($s['net'] ?? 0);

        // ⚠️ 지급액을 `gross_amount + support_amount` 로 구하지 않는다.
        //    정산 계산식이 여러 차례 개정됐고 **기존 사이클은 소급 재계산하지 않아서**,
        //    2026-02~03 구간은 그 합이 fee/net 과 어긋난다(295건 중 123건).
        //    세금 화면에서 "지급액 − 공제 = 실수령" 이 안 맞으면 그 자체로 문의가 된다.
        //    그래서 `settlement_fees.php` 와 같은 규칙을 쓴다 — **net + fee 로 역산**해
        //    항상 맞아떨어지게 한다.
        $out['settle_base'] = $out['settle_net'] + $out['settle_fee'];

        // ── 공제 항목별 ──
        $fees = db_rows(
            'SELECT fi.fee_code, SUM(fi.amount) amount, COUNT(*) cnt
               FROM settlement_fee_items fi
              INNER JOIN settlement_rider_cycles c ON c.id = fi.cycle_id
              WHERE c.rider_id = ? AND c.settlement_date BETWEEN ? AND ?
              GROUP BY fi.fee_code',
            [$riderId, $from, $to]
        );
        foreach ($fees as $f) {
            $code = (string) $f['fee_code'];
            $amt  = (int) $f['amount'];
            if ($code === 'withholding') {
                $out['tax_withholding'] = $amt;
            } elseif ($code === 'employment_ins') {
                $out['tax_employment'] = $amt;
            } elseif ($code === 'accident_ins') {
                $out['tax_accident'] = $amt;
            } else {
                $out['other'][]      = [
                    'code'   => $code,
                    'label'  => self::LABELS[$code] ?? $code,
                    'amount' => $amt,
                    'count'  => (int) $f['cnt'],
                ];
                $out['other_total'] += $amt;
            }
        }

        // ── 프로모션 ── 정산과 같은 요율로 공제된 별도 소득이다(Promotion.php).
        if (db_table_exists('promotion_entries')) {
            $p = db_row(
                "SELECT COALESCE(SUM(total_amount), 0)          gross,
                        COALESCE(SUM(withholding_amount), 0)    wh,
                        COALESCE(SUM(employment_ins_amount), 0) emp,
                        COALESCE(SUM(accident_ins_amount), 0)   acc,
                        COALESCE(SUM(net_amount), 0)            net
                   FROM promotion_entries
                  WHERE rider_id = ? AND status = 'paid' AND DATE(paid_at) BETWEEN ? AND ?",
                [$riderId, $from, $to]
            ) ?: [];
            $out['promo_gross']      = (int) ($p['gross'] ?? 0);
            $out['promo_net']        = (int) ($p['net'] ?? 0);
            $out['tax_withholding'] += (int) ($p['wh'] ?? 0);
            $out['tax_employment']  += (int) ($p['emp'] ?? 0);
            $out['tax_accident']    += (int) ($p['acc'] ?? 0);
        }

        $out['pay_total'] = $out['settle_base'] + $out['promo_gross'];
        $out['tax_total'] = $out['tax_withholding'] + $out['tax_employment'] + $out['tax_accident'];
        $out['net_total'] = $out['settle_net'] + $out['promo_net'];
        $out['months']    = self::months($riderId, $from, $to);

        return $out;
    }

    /**
     * 월별 내역 — 신고할 때 월 단위로 맞춰보는 경우가 많다.
     *
     * @return list<array{month:string,base:int,withholding:int,insurance:int,net:int}>
     */
    private static function months(int $riderId, string $from, string $to): array
    {
        // ⚠️ 공제는 사이클당 여러 행이라, 한 번의 LEFT JOIN 으로 묶으면 base·net 이
        //    행 수만큼 중복 합산된다(항목 수가 사이클마다 달라 나눗셈으로 못 되돌린다).
        //    그래서 사이클 합계와 공제 합계를 **따로 구해** 월 키로 합친다.
        $baseRows = db_rows(
            "SELECT DATE_FORMAT(settlement_date, '%Y-%m') ym,
                    SUM(net_amount + total_fee_amount) base,
                    SUM(net_amount) net
               FROM settlement_rider_cycles
              WHERE rider_id = ? AND settlement_date BETWEEN ? AND ?
              GROUP BY ym
              ORDER BY ym",
            [$riderId, $from, $to]
        );

        $feeRows = db_rows(
            "SELECT DATE_FORMAT(c.settlement_date, '%Y-%m') ym,
                    COALESCE(SUM(CASE WHEN fi.fee_code = 'withholding' THEN fi.amount END), 0) wh,
                    COALESCE(SUM(CASE WHEN fi.fee_code IN ('employment_ins', 'accident_ins') THEN fi.amount END), 0) ins
               FROM settlement_fee_items fi
              INNER JOIN settlement_rider_cycles c ON c.id = fi.cycle_id
              WHERE c.rider_id = ? AND c.settlement_date BETWEEN ? AND ?
              GROUP BY ym",
            [$riderId, $from, $to]
        );
        $feeByYm = [];
        foreach ($feeRows as $f) {
            $feeByYm[(string) $f['ym']] = ['wh' => (int) $f['wh'], 'ins' => (int) $f['ins']];
        }

        $out = [];
        foreach ($baseRows as $b) {
            $ym    = (string) $b['ym'];
            $out[] = [
                'month'       => $ym,
                'base'        => (int) $b['base'],
                'withholding' => $feeByYm[$ym]['wh'] ?? 0,
                'insurance'   => $feeByYm[$ym]['ins'] ?? 0,
                'net'         => (int) $b['net'],
            ];
        }

        return $out;
    }
}
