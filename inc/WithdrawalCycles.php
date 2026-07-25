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
 * ⚠️ 보증금 경계 정책(POLICY_*)은 **갑 확인 대기 중**(LOGIC.md §8-A #1).
 *    두 방식 모두 구현해 두었고 self::BOUNDARY_POLICY 상수 하나로 전환된다.
 */
final class WithdrawalCycles
{
    /** 사이클을 쪼개 보증금 선까지 정확히 맞춤(항상 최대한 출금 가능, 부분출금 건수는 안분) */
    public const POLICY_PARTIAL = 'partial';

    /** 사이클을 통째로만 가져감(수수료 건수 정확, 단 큰 사이클 하나면 출금이 막힐 수 있음) */
    public const POLICY_WHOLE = 'whole';

    /**
     * ⚠️⚠️ TODO(갑확인): 보증금 경계 사이클 처리 정책 — **미확정, 잠정값** ⚠️⚠️
     *
     * 출금은 오래된 사이클부터 가져가고 보증금(기본 50,000원)은 남겨야 하는데,
     * 사이클 하나가 그 경계를 넘칠 때 어떻게 할지가 아직 정해지지 않았다.
     *   (가) POLICY_WHOLE   — 통째로만. 수수료 건수 정확·단순. 단 사이클 1개가 보증금보다
     *                         크면 출금이 전면 차단됨(신규·소액 라이더).
     *   (나) POLICY_PARTIAL — 보증금 선까지 쪼갬. 항상 출금 가능. 단 부과 건수를 금액
     *                         비율로 안분해 원 단위 반올림 오차 발생.
     *
     * **두 방식 모두 구현돼 있어 이 상수 한 줄만 바꾸면 전환된다.**
     * 스키마(`settlement_rider_cycles.withdrawn_amount`)도 두 정책을 모두 수용하므로
     * 재마이그레이션이 필요 없다.
     *
     * 잠정 기본값을 PARTIAL 로 둔 이유: WHOLE 은 위 차단 문제로 기능적 퇴행이 되기 때문.
     *
     * 참고: LOGIC.md §8-A #1 · §7 #18. 확정되면 이 상수만 교체할 것.
     */
    public const BOUNDARY_POLICY = self::POLICY_PARTIAL;

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
     * @return array{picked:list<array{cycle_id:int, settlement_date:string, amount:int, order_count:int, partial:bool}>, taken:int, blocked_by_policy:bool}
     */
    public static function select(int $riderId, int $withdrawable, ?string $policy = null): array
    {
        $policy = $policy ?? self::BOUNDARY_POLICY;
        $picked = [];
        $taken  = 0;

        if ($withdrawable <= 0) {
            return ['picked' => [], 'taken' => 0, 'blocked_by_policy' => false];
        }

        $cycles          = self::unwithdrawn($riderId);
        $blockedByPolicy = false;

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
            if ($policy === self::POLICY_WHOLE) {
                // 통째로만 허용 → 이 사이클은 못 가져감. 뒤 사이클은 더 최근이라 더 크거나
                // 같은 경계에 걸릴 가능성이 높지만, 작은 사이클이 있으면 가져갈 수 있으므로 계속 진행.
                $blockedByPolicy = true;
                continue;
            }

            // PARTIAL — 남은 만큼만 쪼개서 소진
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

        return ['picked' => $picked, 'taken' => $taken, 'blocked_by_policy' => $blockedByPolicy && $taken < $withdrawable];
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
