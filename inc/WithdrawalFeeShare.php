<?php

declare(strict_types=1);

require_once __DIR__ . '/AgencyWallet.php';
require_once __DIR__ . '/WithdrawalConfig.php';
require_once __DIR__ . '/Org.php';

/**
 * 정산수수료 3분할 배분 (2026-08-12 갑 확정) — LOGIC §5.4.
 *
 * 라이더가 출금할 때 지갑에서 `실지급액 + 정산수수료`가 빠진다. 실지급액만 라이더 계좌로
 * 나가므로 **정산수수료만큼은 대리점 지갑에 남는다.** 그 남은 돈을 본사·총판·대리점이 나눈다.
 *
 *   본사 몫  = short×hq_fee_short + long×hq_fee_long  (구간별 배달 건당 정액)
 *   총판 몫  = short×dist_fee_short + long×dist_fee_long
 *   대리점 몫 = 대행수수료 − 본사 − 총판 (나머지 전부)
 *
 * 구간(기준 미만/이상)은 주문의 정산일 경과일로 나뉜다. 사이클 점유 기록이 있는 경로
 * (라이더 주정산)는 `withdrawal_request_cycles`↔`settlement_rider_cycles`로 재구성하고,
 * 없는 경로(일일정산 원클릭)는 호출부가 구간 건수를 직접 넘긴다.
 *
 * ⚠️ **대리점 몫은 이동하지 않는다** — 이미 대리점 지갑에 남아 있는 돈이라 옮길 필요가 없다.
 *    본사·총판 몫만 대리점 지갑에서 빼서 각 조직 지갑으로 옮긴다.
 *    (`RiderDebt::moveLeaseFees()`와 같은 패턴. 반대로 PG 플랫폼 수수료는 2026-08-12부터
 *     지갑을 아예 건드리지 않고 조회용 기록만 남기므로 셋을 혼동하지 말 것.)
 *
 * 일일정산(auto_daily)·주정산(rider_manual) **둘 다** 대상이다(갑: "일일정산이 아니더라도 해야해").
 */
final class WithdrawalFeeShare
{
    /**
     * 출금 1건의 정산수수료를 배분한다. **호출부의 트랜잭션 안에서 실행할 것.**
     *
     * 멱등성: 같은 요청으로 두 번 부르면 두 번 배분된다. 호출부가 "상태가 completed로 바뀐
     * 그 순간"에만 부르도록 되어 있어(UPDATE ... WHERE status IN (...) 의 영향행수 확인 뒤)
     * 중복 실행이 발생하지 않는다.
     *
     * @param int|null $shortOrders 기준 미만 배달 건수를 직접 넘길 때 사용(일일정산 원클릭 지급처럼
     *                              `withdrawal_request_cycles` 점유 기록을 만들지 않는 경로용).
     *                              null이면 점유 기록에서 구간별로 재구성한다.
     * @param int|null $longOrders  기준 이상 배달 건수(위와 함께 넘긴다).
     * @return array{hq:int, distributor:int, agency:int, orders:int, moved:int}
     */
    public static function distribute(int $requestId, int $riderId, int $totalFee, ?int $adminId = null, ?int $shortOrders = null, ?int $longOrders = null): array
    {
        $none = ['hq' => 0, 'distributor' => 0, 'agency' => 0, 'orders' => 0, 'moved' => 0];

        if ($requestId < 1 || $totalFee <= 0 || !AgencyWallet::tableExists()) {
            return $none;
        }

        $agencyId = self::agencyOf($riderId);
        if ($agencyId < 1) {
            return $none;
        }

        // 구간 건수를 직접 안 넘겼으면 사이클 점유 기록에서 재구성한다(정산일 경과일로 버킷팅).
        if ($shortOrders === null || $longOrders === null) {
            [$shortOrders, $longOrders] = self::bucketOrders($requestId, $agencyId);
        }
        $shortOrders = max(0, (int) $shortOrders);
        $longOrders  = max(0, (int) $longOrders);
        $orders      = $shortOrders + $longOrders;
        $share       = WithdrawalConfig::feeShare($shortOrders, $longOrders, $totalFee, $agencyId);

        $hq   = (int) $share['hq'];
        $dist = (int) $share['distributor'];
        $chain = Org::chainForAgency($agencyId);

        // 총판이 없는(본사 직속) 대리점이면 총판 몫은 갈 곳이 없다 → 본사로 합친다.
        // 그냥 두면 대리점 지갑에서 빠진 돈이 어디에도 안 들어가 증발한다.
        $foldNote = '';
        if ($dist > 0 && $chain['distributor'] < 1) {
            $hq  += $dist;
            $dist = 0;
            $foldNote = ' · 총판 없음(본사 직속)이라 총판 몫을 본사에 합산';
        }

        $moveOut = $hq + $dist;
        if ($moveOut <= 0) {
            // 전액 대리점 몫이면 이동할 게 없다(이미 대리점 지갑에 있음).
            return ['hq' => 0, 'distributor' => 0, 'agency' => (int) $share['agency'], 'orders' => $orders, 'moved' => 0];
        }

        $note = sprintf('출금#%d 정산수수료 배분(배달 %d건)', $requestId, $orders);

        AgencyWallet::debit($agencyId, $moveOut, 'wd_fee_up', $requestId, $note . $foldNote, $adminId);
        if ($hq > 0 && $chain['hq'] > 0) {
            AgencyWallet::credit($chain['hq'], $hq, 'wd_fee_in', $requestId, $note . ' · 본사 몫' . $foldNote, $adminId);
        }
        if ($dist > 0 && $chain['distributor'] > 0) {
            AgencyWallet::credit($chain['distributor'], $dist, 'wd_fee_in', $requestId, $note . ' · 총판 몫', $adminId);
        }

        return [
            'hq'          => $hq,
            'distributor' => $dist,
            'agency'      => (int) $share['agency'],
            'orders'      => $orders,
            'moved'       => $moveOut,
        ];
    }

    /**
     * 이 출금이 소진한 정산 사이클을 「기준 미만/기준 이상」 배달 건수로 재구성한다.
     *
     * 구간 판정 기준일(asOf)은 **신청 시각(requested_at)** — 그때 라이더에게 부과한 수수료의
     * 구간 분리와 일치시켜, 나중에 완료 처리하며 재계산해도 같은 구간으로 나뉘게 한다.
     * 사이클 점유 기록이 없는(구 모델) 출금이면 [0, 0] → 본사·총판 몫 0, 전액 대리점.
     *
     * @return array{0:int, 1:int} [기준 미만 건수, 기준 이상 건수]
     */
    private static function bucketOrders(int $requestId, int $agencyId): array
    {
        if (!db_table_exists('withdrawal_request_cycles') || !db_table_exists('settlement_rider_cycles')) {
            return [0, 0];
        }

        $rows = db_rows(
            'SELECT src.settlement_date, wrc.order_count
               FROM withdrawal_request_cycles wrc
               INNER JOIN settlement_rider_cycles src ON src.id = wrc.cycle_id
              WHERE wrc.request_id = ?',
            [$requestId]
        );
        if ($rows === []) {
            return [0, 0];
        }

        $asOf = db_row('SELECT DATE(requested_at) AS d FROM withdrawal_requests WHERE id = ? LIMIT 1', [$requestId]);
        $asOf = is_array($asOf) ? (string) ($asOf['d'] ?? '') : '';

        $calc = WithdrawalConfig::feeForCycles(
            array_map(static fn (array $r): array => [
                'settlement_date' => (string) $r['settlement_date'],
                'order_count'     => (int) $r['order_count'],
            ], $rows),
            $agencyId,
            $asOf !== '' ? $asOf : null
        );

        return [(int) $calc['short_orders'], (int) $calc['long_orders']];
    }

    private static function agencyOf(int $riderId): int
    {
        if ($riderId < 1) {
            return 0;
        }

        return (int) (db_row('SELECT agency_id FROM riders WHERE id = ? LIMIT 1', [$riderId])['agency_id'] ?? 0);
    }
}
