<?php

declare(strict_types=1);

/**
 * 대리점 선차감을 **라이더 화면의 건별 금액에서 빼주는** 도우미 (2026-09-06 갑).
 *
 * 갑: "기사는 선공제 수수료를 차감했는지 모르는거야. 기사가 보는 화면에는 선공제수수료가
 *      무조건 제한 금액이야." · "모든 상세 데이터에서 선차감 금액만큼씩 빠져서 원래 배달
 *      금액이 선차감 금액만큼 빠져나와야 하고 공제 금액에는 표기가 안되게 해야 한다"
 *
 * 명세서·요약은 총액에서 빼면 되지만(RiderStatement), **오더별 상세**는 건별로 빼야 한다.
 * 안 그러면 라이더가 건별 금액을 더했을 때 총액과 안 맞아 바로 들통난다.
 *
 * ⚠️ **이 클래스는 라이더(rider/) 화면 전용이다.** 관리자 화면은 원금액을 그대로 봐야 한다.
 *
 * 배분 규칙: 그 날짜의 선차감 총액을 그 날짜 주문 건수로 나눠 빼고, 나머지(원 단위)는
 * 앞쪽 건에 1원씩 더 붙인다. 그래서 **빼낸 합이 선차감 총액과 정확히 일치**한다 —
 * 균등하게 나누기만 하면 정수 나눗셈에서 몇 원이 남아 총액이 안 맞는다.
 */
final class RiderPrededuct
{
    public const FEE_CODE = 'agency_prededuct';

    /**
     * 날짜별 선차감 합계.
     *
     * @return array<string, int> 'YYYY-MM-DD' => 금액
     */
    public static function totalsByDate(int $riderId, string $from, string $to, ?string $platform = null): array
    {
        if ($riderId < 1 || !db_table_exists('settlement_fee_items')) {
            return [];
        }

        $sql = "SELECT c.settlement_date d, COALESCE(SUM(fi.amount), 0) v
                  FROM settlement_fee_items fi
                  INNER JOIN settlement_rider_cycles c ON c.id = fi.cycle_id
                 WHERE c.rider_id = ? AND c.settlement_date BETWEEN ? AND ?
                   AND fi.fee_code = ?";
        $params = [$riderId, $from, $to, self::FEE_CODE];

        // 플랫폼 필터가 걸린 화면(정산 목록)에서는 그 플랫폼 사이클만 빼야 금액이 맞는다.
        if ($platform !== null && $platform !== '' && $platform !== 'all') {
            $sql     .= ' AND c.platform = ?';
            $params[] = $platform;
        }
        $sql .= ' GROUP BY c.settlement_date';

        $out = [];
        foreach (db_rows($sql, $params) as $r) {
            $v = (int) $r['v'];
            if ($v > 0) {
                $out[(string) $r['d']] = $v;
            }
        }

        return $out;
    }

    /** 기간 전체 합계 — 목록 화면의 합계 줄에서 뺄 때 쓴다. */
    public static function total(int $riderId, string $from, string $to, ?string $platform = null): int
    {
        return array_sum(self::totalsByDate($riderId, $from, $to, $platform));
    }

    /**
     * 주문 행들의 금액에서 그 날짜 선차감을 나눠 뺀다.
     *
     * 행 순서는 그대로 두고 금액만 바꾼다. 어떤 행도 음수가 되지 않게 막고, 못 뺀 잔여는
     * 다음 행으로 넘겨 **최대한 빼낸다**(그 날 주문 금액 합보다 선차감이 클 일은 사실상
     * 없지만, 남겨두면 그만큼 라이더 화면 합계가 커져 총액과 어긋난다).
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, int>         $totalsByDate self::totalsByDate() 결과
     * @return list<array<string, mixed>>
     */
    public static function applyToRows(
        array $rows,
        array $totalsByDate,
        string $dateKey = 'settlement_date',
        string $amountKey = 'net_amount'
    ): array {
        if ($rows === [] || $totalsByDate === []) {
            return $rows;
        }

        // 날짜별로 어떤 행들이 속하는지 먼저 모은다(행 순서 유지를 위해 인덱스만).
        $idxByDate = [];
        foreach ($rows as $i => $r) {
            $d = (string) ($r[$dateKey] ?? '');
            if ($d !== '' && isset($totalsByDate[$d])) {
                $idxByDate[$d][] = $i;
            }
        }

        foreach ($idxByDate as $d => $idxs) {
            $n = count($idxs);
            if ($n === 0) {
                continue;
            }
            $total = (int) $totalsByDate[$d];
            $each  = intdiv($total, $n);
            $extra = $total % $n;      // 나머지는 앞쪽 건에 1원씩

            $carry = 0;                // 어떤 행에서 못 뺀 몫 — 다음 행에서 마저 뺀다
            foreach ($idxs as $k => $i) {
                $want = $each + ($k < $extra ? 1 : 0) + $carry;
                $amt  = (int) ($rows[$i][$amountKey] ?? 0);
                $cut  = max(0, min($amt, $want));
                $rows[$i][$amountKey] = $amt - $cut;
                $carry = $want - $cut;
            }
        }

        return $rows;
    }
}
