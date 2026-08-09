<?php

declare(strict_types=1);

require_once __DIR__ . '/WithdrawalConfig.php';

/**
 * 출금 ↔ 정산 사이클 선택·마킹 (§7 #18 정산수수료 age-bucket 모델)
 *
 * 정산수수료는 주문 건별로 매겨지므로(임계일 이내 80원 / 지남 40원), "이번 출금이 어떤
 * 사이클에서 얼마를 가져가는지"를 알아야 정확한 수수료가 나온다. 이 클래스가 그 선택과
 * 기록(`withdrawal_request_cycles`, `settlement_rider_cycles.withdrawn_amount`)을 담당한다.
 *
 * 마킹 시점은 **출금 신청(pending)** — 신청 즉시 사이클을 점유해 이중 출금을 막고,
 * 반려(rejected) 시 되돌린다(release). 완료(completed)에는 이미 마킹돼 있어 할 일이 없다.
 *
 * 보증금 경계 정책은 **POLICY_ORDER**(배달 건 단위로 출금) — 아래 상수 주석 참고.
 */
final class WithdrawalCycles
{
    /** 사이클을 금액 기준으로 쪼개 보증금 선까지 정확히 맞춤(부분출금 건수는 금액비율로 안분) */
    public const POLICY_PARTIAL = 'partial';

    /** 사이클(하루치 정산)을 통째로만 가져감 — ⚠️ 실무 전제와 맞지 않아 사용하지 않는다(아래 참고) */
    public const POLICY_WHOLE = 'whole';

    /** 사이클을 **배달 건 단위**로 쪼개 가져감 — 현행 정책 */
    public const POLICY_ORDER = 'order';

    /**
     * 보증금 경계 처리 정책 — **POLICY_ORDER (배달 건 단위)**
     *
     * 출금은 오래된 사이클부터 가져가고 보증금(기본 50,000원)은 지갑에 남겨야 하는데,
     * 사이클 하나가 그 경계를 넘칠 때 어디서 자를지가 쟁점이었다.
     *
     * 갑 확정 규칙은 **"출금은 배달 1건 단위로 나간다"** 이다. 배달 1건 금액은 몇 천 원
     * 수준이라 보증금(5만원)을 넘을 일이 없으므로, 경계에서 잘려도 라이더가 손해를
     * 보거나 출금이 막히지 않는다. 수수료 모델도 이미 **주문 건별**(임계일 이내 80원 /
     * 지남 40원)이라 건 단위 절단과 정확히 맞물린다 — 가져간 건수만큼만 부과하면 된다.
     *
     * 🐛 2026-08-09 정정: 예전엔 이 "1건"을 **정산 1건(=하루치 사이클)** 으로 잘못 읽어
     *    POLICY_WHOLE(사이클 통째로만 출금)로 확정해 뒀었다. 실데이터에서는 하루치
     *    정산이 평균 12만원·최대 24만원이라(사이클의 80%가 보증금 초과) 이 해석 하에서는
     *    **"잔액은 24만원인데 출금 0원"** 같은 교착이 상시 발생했다(실제 발생). 올바른
     *    단위는 배달 건이며, 그 기준에서는 아래 계산대로 항상 보증금 직전까지 출금된다.
     *
     * 절단 방식: 사이클의 미출금 배달 건수 Mr, 미출금 금액 R 일 때 1건당 평균 R/Mr 로 보고
     * 출금가능액에 들어가는 만큼(K건)만 가져간다. 금액은 R×K/Mr(내림). 1건도 못 살 만큼
     * 출금가능액이 적으면 그때만 차단하고 `blocked_shortfall`(1건 값 − 가용액)로 안내한다.
     *
     * WHOLE/PARTIAL 구현도 남겨둔다 — 정책 재검토 시 상수 한 줄로 바꿀 수 있고,
     * 스키마(`withdrawn_amount`)는 세 정책을 모두 수용한다.
     *
     * 참고: LOGIC.md §7 #18.
     */
    public const BOUNDARY_POLICY = self::POLICY_ORDER;

    public static function tableReady(): bool
    {
        return db_table_exists('withdrawal_request_cycles')
            && db_table_exists('settlement_rider_cycles')
            && in_array('withdrawn_amount', array_column(db_rows('SHOW COLUMNS FROM settlement_rider_cycles'), 'Field'), true);
    }

    /**
     * 미출금(또는 일부만 출금된) 사이클 — 오래된 순.
     * 오래된 것부터 쓰는 이유: 대리점이 이미 플랫폼에서 정산받은 분이라 수수료가 싸다(40원 구간).
     *
     * @return list<array{id:int, settlement_date:string, order_count:int, net_amount:int, withdrawn_amount:int, remaining:int}>
     */
    public static function unwithdrawn(int $riderId): array
    {
        if ($riderId < 1 || !self::tableReady()) {
            return [];
        }

        $rows = db_rows(
            'SELECT id, settlement_date, order_count, net_amount, withdrawn_amount
               FROM settlement_rider_cycles
              WHERE rider_id = ? AND net_amount > withdrawn_amount
              ORDER BY settlement_date ASC, id ASC',
            [$riderId]
        );

        $out = [];
        foreach ($rows as $r) {
            $remaining = (int) $r['net_amount'] - (int) $r['withdrawn_amount'];
            if ($remaining <= 0) {
                continue;
            }
            $out[] = [
                'id'               => (int) $r['id'],
                'settlement_date'  => substr((string) $r['settlement_date'], 0, 10),
                'order_count'      => (int) $r['order_count'],
                'net_amount'       => (int) $r['net_amount'],
                'withdrawn_amount' => (int) $r['withdrawn_amount'],
                'remaining'        => $remaining,
            ];
        }

        return $out;
    }

    /**
     * 출금 가능액(= 잔액 − 보증금)만큼 오래된 사이클부터 고른다.
     *
     * 수수료는 고른 결과에서 계산되므로 순환 의존이 없다:
     *   지갑에서 빠지는 총액 = 실지급액 + 수수료 = withdrawable (고른 사이클 합)
     *
     * @param int $withdrawable 이번에 소진할 금액(원). 잔액 − 보증금.
     * @return array{picked:list<array{cycle_id:int, settlement_date:string, amount:int, order_count:int, partial:bool}>, taken:int, blocked_by_policy:bool, blocked_shortfall:int, had_candidates:bool}
     */
    public static function select(int $riderId, int $withdrawable, ?string $policy = null, ?string $toDate = null): array
    {
        $policy = $policy ?? self::BOUNDARY_POLICY;
        $picked = [];
        $taken  = 0;

        if ($withdrawable <= 0) {
            return [
                'picked' => [], 'taken' => 0, 'blocked_by_policy' => false,
                'blocked_shortfall' => 0, 'had_candidates' => false,
            ];
        }

        $cycles          = self::unwithdrawn($riderId);
        $blockedByPolicy = false;
        // WHOLE 정책에서 "통째로 못 가져가 건너뛴" 사이클들의 잔여액.
        // 라이더에게 "얼마가 더 쌓이면 출금되는지" 안내하는 데 쓴다.
        $skipped = [];

        // 기간 지정 출금(§7 #18-b) — 라이더가 달력에서 고른 날짜까지만 소진한다.
        // 사이클 소비는 항상 "가장 오래된 미출금분부터"여야 age-bucket 요율과 잔액 정합성이
        // 유지되므로, 임의 날짜 다중선택이 아니라 "선택일까지 누적"으로 자른다.
        if ($toDate !== null && $toDate !== '') {
            $cycles = array_values(array_filter(
                $cycles,
                static fn (array $c): bool => $c['settlement_date'] <= $toDate
            ));
        }

        foreach ($cycles as $c) {
            $left = $withdrawable - $taken;
            if ($left <= 0) {
                break;
            }

            if ($c['remaining'] <= $left) {
                // 통째로 소진
                $picked[] = [
                    'cycle_id'        => $c['id'],
                    'settlement_date' => $c['settlement_date'],
                    'amount'          => $c['remaining'],
                    'order_count'     => self::proratedOrders($c, $c['remaining']),
                    'partial'         => $c['withdrawn_amount'] > 0,
                ];
                $taken += $c['remaining'];
                continue;
            }

            // 여기서부터 보증금 경계 — 정책 분기
            //
            // ⚠️ 어느 정책이든 여기서 **멈춘다(break)**. 뒤에 더 작은 사이클이 있으면 그건
            // 가져갈 수도 있지만, 그러면 오래된 사이클을 건너뛰고 최신 사이클을 먼저 소진하게
            // 된다. 그건 두 가지를 깬다:
            //   ① 화면 문구 "출금은 오래된 정산분부터 순서대로 나갑니다"
            //   ② 달력의 "그 날짜까지 출금" 의미 — 중간에 구멍이 뚫려 버린다
            // age-bucket 요율도 오래된 분이 싸므로(40원) 순서를 지키는 쪽이 라이더에게 유리.
            if ($policy === self::POLICY_WHOLE) {
                // 통째로만 허용 → 이 사이클은 이번에 못 가져간다.
                $blockedByPolicy = true;
                $skipped[]       = $c['remaining'];
                break;
            }

            if ($policy === self::POLICY_ORDER) {
                // 배달 건 단위로만 절단 — 들어가는 건수(K)만큼만 가져간다.
                [$amount, $orders] = self::orderUnitSplit($c, $left);
                if ($orders < 1) {
                    // 배달 1건 값도 안 될 만큼 출금가능액이 적다 → 이번엔 출금 없음.
                    $blockedByPolicy = true;
                    $skipped[]       = self::remainingOrderUnit($c)['unit'];
                    break;
                }
                $picked[] = [
                    'cycle_id'        => $c['id'],
                    'settlement_date' => $c['settlement_date'],
                    'amount'          => $amount,
                    'order_count'     => $orders,
                    'partial'         => true,
                ];
                $taken += $amount;
                break;
            }

            // PARTIAL — 남은 만큼만 금액 기준으로 쪼개서 소진
            $picked[] = [
                'cycle_id'        => $c['id'],
                'settlement_date' => $c['settlement_date'],
                'amount'          => $left,
                'order_count'     => self::proratedOrders($c, $left),
                'partial'         => true,
            ];
            $taken += $left;
            break;
        }

        $blocked = $blockedByPolicy && $taken < $withdrawable;

        // 건너뛴 사이클 중 가장 작은 것을 가져가려면 지갑에 얼마가 더 있어야 하는지.
        // (가장 작은 것 기준 = 가장 빨리 풀리는 조건)
        $shortfall = 0;
        if ($blocked && $skipped !== []) {
            $shortfall = max(0, min($skipped) - ($withdrawable - $taken));
        }

        return [
            'picked'            => $picked,
            'taken'             => $taken,
            'blocked_by_policy' => $blocked,
            'blocked_shortfall' => $shortfall,
            // 이 라이더에게 (기간 필터 적용 후) 소진 대상 사이클이 하나라도 있었는지.
            // 호출자가 "정책상 못 가져감"과 "사이클 데이터 자체가 없음(구 데이터)"을
            // 구분해야 한다 — 전자는 출금 0원이어야 하고, 후자만 구 모델로 폴백한다.
            'had_candidates'    => $cycles !== [],
        ];
    }

    /**
     * 이 사이클에서 **아직 출금되지 않은 배달 건수**와 1건당 금액.
     *
     * 사이클은 하루치 합계(금액 R / 건수 Mr)만 들고 있고 주문별 개별 금액은 여기 없으므로,
     * 1건 값은 평균(R/Mr)으로 본다. 올림(ceil)하는 이유는 "1건을 가져갈 수 있는가" 판정을
     * 보수적으로 하기 위함 — 평균보다 비싼 주문이 섞여 있어도 초과 인출이 되지 않는다.
     *
     * @param array{order_count:int, net_amount:int, withdrawn_amount:int, remaining:int} $cycle
     * @return array{orders:int, unit:int}
     */
    private static function remainingOrderUnit(array $cycle): array
    {
        $net       = (int) $cycle['net_amount'];
        $orders    = (int) $cycle['order_count'];
        $remaining = (int) $cycle['remaining'];

        if ($orders <= 0 || $net <= 0 || $remaining <= 0) {
            return ['orders' => 0, 'unit' => 0];
        }

        $charged = (int) round($orders * ((int) $cycle['withdrawn_amount'] / $net));
        $left    = max(0, $orders - $charged);
        if ($left <= 0) {
            // 건수는 다 부과됐는데 금액이 남은 경계 케이스 — 남은 금액을 1건으로 본다.
            return ['orders' => 1, 'unit' => $remaining];
        }

        return ['orders' => $left, 'unit' => (int) ceil($remaining / $left)];
    }

    /**
     * 출금가능액 안에서 **배달 건 단위로** 얼마나 가져갈 수 있는지.
     *
     * @param array{order_count:int, net_amount:int, withdrawn_amount:int, remaining:int} $cycle
     * @return array{0:int, 1:int} [금액, 건수] — 1건도 못 가져가면 [0, 0]
     */
    private static function orderUnitSplit(array $cycle, int $available): array
    {
        $u = self::remainingOrderUnit($cycle);
        if ($u['orders'] < 1 || $u['unit'] < 1 || $available < 1) {
            return [0, 0];
        }

        $k = intdiv($available, $u['unit']);
        if ($k < 1) {
            return [0, 0];
        }
        if ($k >= $u['orders']) {
            // 전량 소진 가능(호출부에서 이미 걸러지지만 방어적으로)
            return [(int) $cycle['remaining'], $u['orders']];
        }

        // 금액은 건수 비율로 안분(내림) — 출금가능액을 절대 넘지 않게 한 번 더 클램프.
        $amount = (int) floor((int) $cycle['remaining'] * $k / $u['orders']);

        return [min($amount, $available), $k];
    }

    /**
     * 부분출금 시 수수료 부과 건수 안분 — 금액 비율로 계산하고 반올림(최소 1건).
     * 전액 소진이면 남은 건수 그대로.
     *
     * @param array{order_count:int, net_amount:int, withdrawn_amount:int, remaining:int} $cycle
     */
    private static function proratedOrders(array $cycle, int $amount): int
    {
        $net    = (int) $cycle['net_amount'];
        $orders = (int) $cycle['order_count'];
        if ($orders <= 0 || $net <= 0) {
            return 0;
        }
        if ($amount >= (int) $cycle['remaining']) {
            // 이 사이클의 잔여를 전부 소진 — 아직 부과되지 않은 건수 전부
            $alreadyCharged = (int) round($orders * ((int) $cycle['withdrawn_amount'] / $net));

            return max(0, $orders - $alreadyCharged);
        }

        return max(1, (int) round($orders * ($amount / $net)));
    }

    /**
     * 선택 결과의 정산수수료 — 주문 건별 age-bucket 합산.
     *
     * @param list<array{settlement_date:string, order_count:int}> $picked
     * @return array{total:int, short_orders:int, long_orders:int, short_amount:int, long_amount:int, rate_short:int, rate_long:int, threshold:int}
     */
    public static function feeFor(array $picked, ?int $orgId = null, ?string $asOf = null): array
    {
        return WithdrawalConfig::feeForCycles($picked, $orgId, $asOf);
    }

    /**
     * 선택 결과를 출금 신청에 연결하고 사이클을 점유(마킹)한다. 호출자가 트랜잭션을 연다.
     *
     * @param list<array{cycle_id:int, amount:int, order_count:int}> $picked
     */
    public static function attach(int $requestId, array $picked): void
    {
        if ($requestId < 1 || $picked === [] || !self::tableReady()) {
            return;
        }

        foreach ($picked as $p) {
            $cycleId = (int) $p['cycle_id'];
            $amount  = (int) $p['amount'];
            if ($cycleId < 1 || $amount <= 0) {
                continue;
            }
            db_insert(
                'INSERT INTO withdrawal_request_cycles (request_id, cycle_id, amount, order_count)
                 VALUES (?, ?, ?, ?)',
                [$requestId, $cycleId, $amount, (int) ($p['order_count'] ?? 0)]
            );
            // 사이클 점유 — net_amount를 넘지 않도록 clamp
            db_execute(
                'UPDATE settlement_rider_cycles
                    SET withdrawn_amount = LEAST(net_amount, withdrawn_amount + ?)
                  WHERE id = ?',
                [$amount, $cycleId]
            );
        }
    }

    /**
     * 출금 반려/취소 시 점유 해제 — 사이클 withdrawn_amount 복구 + 연결 삭제.
     *
     * @param list<int> $requestIds
     */
    public static function release(array $requestIds): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $requestIds), static fn (int $i): bool => $i > 0)));
        if ($ids === [] || !self::tableReady()) {
            return 0;
        }

        $ph   = implode(',', array_fill(0, count($ids), '?'));
        $rows = db_rows(
            "SELECT id, request_id, cycle_id, amount FROM withdrawal_request_cycles WHERE request_id IN ({$ph})",
            $ids
        );
        if ($rows === []) {
            return 0;
        }

        foreach ($rows as $r) {
            db_execute(
                'UPDATE settlement_rider_cycles
                    SET withdrawn_amount = GREATEST(0, withdrawn_amount - ?)
                  WHERE id = ?',
                [(int) $r['amount'], (int) $r['cycle_id']]
            );
        }
        db_execute("DELETE FROM withdrawal_request_cycles WHERE request_id IN ({$ph})", $ids);

        return count($rows);
    }

    /**
     * 특정 출금에 연결된 사이클 내역(상세 표시용).
     *
     * @return list<array<string,mixed>>
     */
    public static function forRequest(int $requestId): array
    {
        if ($requestId < 1 || !self::tableReady()) {
            return [];
        }

        return db_rows(
            'SELECT wrc.*, src.settlement_date, src.net_amount, src.order_count AS cycle_order_count
               FROM withdrawal_request_cycles wrc
               INNER JOIN settlement_rider_cycles src ON src.id = wrc.cycle_id
              WHERE wrc.request_id = ?
              ORDER BY src.settlement_date ASC, src.id ASC',
            [$requestId]
        );
    }
}
