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
 *   본사 몫  = 배달 건당 정액(hq_fee_per_order) × 출금에 포함된 배달 건수
 *   총판 몫  = (총수수료 − 본사몫) × fee_share_distributor_pct
 *   대리점 몫 = 나머지 전부
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
     * @param int|null $orderCount 배달 건수를 직접 넘길 때 사용(일일정산 원클릭 지급처럼
     *                             `withdrawal_request_cycles` 점유 기록을 만들지 않는 경로용).
     *                             null이면 점유 기록에서 합산한다.
     * @return array{hq:int, distributor:int, agency:int, orders:int, moved:int}
     */
    public static function distribute(int $requestId, int $riderId, int $totalFee, ?int $adminId = null, ?int $orderCount = null): array
    {
        $none = ['hq' => 0, 'distributor' => 0, 'agency' => 0, 'orders' => 0, 'moved' => 0];

        if ($requestId < 1 || $totalFee <= 0 || !AgencyWallet::tableExists()) {
            return $none;
        }

        $agencyId = self::agencyOf($riderId);
        if ($agencyId < 1) {
            return $none;
        }

        $orders = $orderCount !== null ? max(0, $orderCount) : self::orderCount($requestId);
        $share  = WithdrawalConfig::feeShare($totalFee, $orders, $agencyId);

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
     * 이 출금이 소진한 정산 사이클의 배달 건수 합계.
     * 사이클 점유 기록이 없는(구 모델) 출금이면 0 → 본사 몫도 0이 되어 전액 대리점에 남는다.
     */
    private static function orderCount(int $requestId): int
    {
        if (!db_table_exists('withdrawal_request_cycles')) {
            return 0;
        }

        return (int) (db_row(
            'SELECT COALESCE(SUM(order_count), 0) AS c FROM withdrawal_request_cycles WHERE request_id = ?',
            [$requestId]
        )['c'] ?? 0);
    }

    private static function agencyOf(int $riderId): int
    {
        if ($riderId < 1) {
            return 0;
        }

        return (int) (db_row('SELECT agency_id FROM riders WHERE id = ? LIMIT 1', [$riderId])['agency_id'] ?? 0);
    }
}
