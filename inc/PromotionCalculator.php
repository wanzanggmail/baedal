<?php

declare(strict_types=1);

require_once __DIR__ . '/Org.php';

/**
 * 프로모션 계산기 — 기간 + 건수 구간 룰로 라이더별 프로모션 금액을 산출한다.
 *
 * 「프로모션 지급」(`Promotion`)이 **금액을 받아서 지급**하는 모듈이라면, 여기는 그 **금액을
 * 만들어내는** 앞단이다. 지금까지는 관리자가 엑셀에 금액을 직접 적어 올렸는데, 실제 운영은
 * "이 기간에 몇 건 했으면 건당 얼마"라는 구간 룰로 정해지므로 그 계산을 자동화한다.
 *
 * ## 계산 방식 — 누진 (2026-08-16 갑 확정)
 * 소득세 구간처럼 **각 구간에 해당하는 건수만큼만** 그 구간 단가를 적용해 합산한다.
 *
 *   룰: 100~200건 건당 100원 / 201~300건 건당 200원
 *   라이더 250건 →  (200-100+1)=101건 × 100원 = 10,100원
 *                 + (250-201+1)= 50건 × 200원 = 10,000원
 *                 = 20,100원
 *
 * ⚠️ "도달 구간 단가를 전체 건에"(250×200=50,000) 방식이 아니다. 구간을 넘는 순간 금액이
 *    껑충 뛰지 않는다.
 * ⚠️ 구간 시작 건수 **미만이면 그 구간은 0건**이다. 위 예에서 99건인 라이더는 첫 구간
 *    시작(100)에 못 미쳐 프로모션이 0원이다.
 *
 * ## 건수 기준 — 정산 반영된 건수 (2026-08-16 갑 확정)
 * `settlement_rider_cycles.order_count` 합계를 쓴다. 정산 반영(지갑 적립)이 끝난 확정
 * 숫자라 나중에 다시 계산해도 같은 결과가 나온다. 업로드만 되고 아직 반영 전인 건은 빠진다.
 */
final class PromotionCalculator
{
    /** 구간 개수 상한 — 화면에서 무한정 늘리다 실수하는 걸 막는 안전장치. */
    public const MAX_TIERS = 20;

    /**
     * 화면에서 온 구간 입력을 검증하고 정규화한다.
     *
     * 겹치는 구간은 계산 결과를 조용히 왜곡시키므로(같은 건수가 두 번 계산됨) **거부**한다.
     * 정렬만 해서 넘기면 관리자는 자기가 뭘 잘못 넣었는지 모른 채 틀린 금액을 받게 된다.
     *
     * @param  list<array<string,mixed>> $raw
     * @return list<array{from:int, to:int, amount:int}>  from 오름차순
     */
    public static function normalizeTiers(array $raw): array
    {
        $tiers = [];
        foreach ($raw as $i => $t) {
            $from   = (int) ($t['from'] ?? 0);
            $to     = (int) ($t['to'] ?? 0);
            $amount = (int) ($t['amount'] ?? 0);

            // 전부 빈 줄은 화면에서 "추가만 하고 안 채운 행"이라 조용히 건너뛴다.
            if ($from === 0 && $to === 0 && $amount === 0) {
                continue;
            }
            if ($from < 1) {
                throw new InvalidArgumentException(sprintf('%d번째 구간: 시작 건수는 1 이상이어야 합니다.', $i + 1));
            }
            if ($to < $from) {
                throw new InvalidArgumentException(sprintf('%d번째 구간: 종료 건수(%d)가 시작 건수(%d)보다 작습니다.', $i + 1, $to, $from));
            }
            if ($amount < 0) {
                throw new InvalidArgumentException(sprintf('%d번째 구간: 건당 금액은 0 이상이어야 합니다.', $i + 1));
            }
            $tiers[] = ['from' => $from, 'to' => $to, 'amount' => $amount];
        }

        if ($tiers === []) {
            throw new InvalidArgumentException('구간을 1개 이상 입력하세요.');
        }
        if (count($tiers) > self::MAX_TIERS) {
            throw new InvalidArgumentException(sprintf('구간은 최대 %d개까지 만들 수 있습니다.', self::MAX_TIERS));
        }

        usort($tiers, static fn (array $a, array $b): int => $a['from'] <=> $b['from']);

        for ($i = 1, $n = count($tiers); $i < $n; $i++) {
            if ($tiers[$i]['from'] <= $tiers[$i - 1]['to']) {
                throw new InvalidArgumentException(sprintf(
                    '구간이 겹칩니다: %d~%d 과 %d~%d. 같은 건수가 두 번 계산되지 않도록 겹치지 않게 입력하세요.',
                    $tiers[$i - 1]['from'],
                    $tiers[$i - 1]['to'],
                    $tiers[$i]['from'],
                    $tiers[$i]['to']
                ));
            }
        }

        return $tiers;
    }

    /**
     * 건수 하나에 대해 누진 계산.
     *
     * @param  list<array{from:int, to:int, amount:int}> $tiers  normalizeTiers() 결과
     * @return array{total:int, breakdown:list<array{from:int,to:int,amount:int,orders:int,subtotal:int}>}
     */
    public static function amountFor(int $orderCount, array $tiers): array
    {
        $total     = 0;
        $breakdown = [];

        foreach ($tiers as $t) {
            if ($orderCount < $t['from']) {
                continue; // 구간 시작에 못 미침 → 이 구간은 0건
            }
            // 이 구간에서 인정되는 건수 = (구간 상한, 실제 건수) 중 작은 값까지
            $upTo   = min($orderCount, $t['to']);
            $orders = $upTo - $t['from'] + 1;
            if ($orders <= 0) {
                continue;
            }
            $subtotal = $orders * $t['amount'];
            $total   += $subtotal;
            $breakdown[] = $t + ['orders' => $orders, 'subtotal' => $subtotal];
        }

        return ['total' => $total, 'breakdown' => $breakdown];
    }

    /**
     * 기간 내 라이더별 정산 반영 건수 — 한 방에 집계한다(라이더 수만큼 쿼리를 돌지 않는다).
     *
     * @return list<array{rider_id:int, rider_code:string, name:string, order_count:int, net_amount:int, days:int}>
     */
    public static function riderOrderCounts(int $agencyId, string $from, string $to): array
    {
        if (!db_table_exists('settlement_rider_cycles')) {
            return [];
        }

        return db_rows(
            "SELECT r.id AS rider_id, r.rider_code, r.name,
                    COALESCE(SUM(c.order_count), 0) AS order_count,
                    COALESCE(SUM(c.net_amount), 0)  AS net_amount,
                    COUNT(DISTINCT c.settlement_date) AS days
               FROM settlement_rider_cycles c
               INNER JOIN riders r ON r.id = c.rider_id
              WHERE r.agency_id = ?
                AND c.settlement_date >= ?
                AND c.settlement_date <= ?
              GROUP BY r.id, r.rider_code, r.name
              ORDER BY order_count DESC, r.name ASC",
            [$agencyId, $from, $to]
        );
    }

    /**
     * 기간 + 룰 → 라이더별 프로모션 금액.
     *
     * 건수가 구간에 못 미쳐 0원인 라이더도 **결과에 남긴다**(`amount = 0`). 화면에서
     * "이 사람은 왜 안 나오지?"를 확인할 수 있어야 하고, 엑셀에도 0으로 내려가야
     * 지급 대상에서 빠졌다는 게 드러나기 때문이다.
     *
     * @param  list<array{from:int, to:int, amount:int}> $tiers
     * @return array{
     *   rows: list<array<string,mixed>>,
     *   summary: array{riders:int, paid_riders:int, orders:int, amount:int}
     * }
     */
    public static function calculate(int $agencyId, string $from, string $to, array $tiers): array
    {
        $rows    = [];
        $orders  = 0;
        $amount  = 0;
        $paid    = 0;

        foreach (self::riderOrderCounts($agencyId, $from, $to) as $r) {
            $cnt  = (int) $r['order_count'];
            $calc = self::amountFor($cnt, $tiers);

            $rows[] = [
                'rider_id'    => (int) $r['rider_id'],
                'rider_code'  => (string) $r['rider_code'],
                'name'        => (string) $r['name'],
                'order_count' => $cnt,
                'net_amount'  => (int) $r['net_amount'],
                'days'        => (int) $r['days'],
                'amount'      => $calc['total'],
                'breakdown'   => $calc['breakdown'],
            ];

            $orders += $cnt;
            $amount += $calc['total'];
            if ($calc['total'] > 0) {
                $paid++;
            }
        }

        return [
            'rows'    => $rows,
            'summary' => [
                'riders'      => count($rows),
                'paid_riders' => $paid,
                'orders'      => $orders,
                'amount'      => $amount,
            ],
        ];
    }

    /** 구간 룰을 사람이 읽는 한 줄로 (화면·엑셀 머리말용) */
    public static function describeTiers(array $tiers): string
    {
        return implode(' · ', array_map(
            static fn (array $t): string => sprintf(
                '%s~%s건 건당 %s원',
                number_format($t['from']),
                number_format($t['to']),
                number_format($t['amount'])
            ),
            $tiers
        ));
    }
}
