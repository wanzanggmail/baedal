<?php

declare(strict_types=1);

/**
 * 라이더 차감 이월 원장 (2026-09-04)
 *
 * 그날 정산액이 정액 차감(시간제보험·엑셀 차감내역·미수금 일납 등)보다 적으면
 * 예전에는 `net = max(0, base - totalFee)` 로 **초과분이 그냥 증발**했다.
 * 실데이터에서 9건 135,034원이 이렇게 사라졌고, 장부에는 「걷었다」로 남아 있었다.
 *
 * 이 원장은 그 초과분을 **못 걷은 채로 기록**해두고, 다음 정산에서 여유가 생기는
 * 만큼 순서대로(FIFO) 다시 걷는다. 갑 지시: *"받아야 할 금액은 최대한 받아내고
 * 놓히는 법이 없도록"*.
 *
 * ⚠️ 미수금(대여금·리스·선지급)은 여기로 오지 않는다 — 부과 단계에서 걷을 수 있는
 *    일수만큼만 부과하고 나머지는 `rider_debts.due_updated_on` 이 안 밀리는 것으로
 *    이월된다(장부가 두 곳에 생기지 않게). 여기 쌓이는 건 정산서에서 온 정액 차감이다.
 */
final class RiderCarryForward
{
    private const TABLE = 'rider_carry_forward';

    public static function tableReady(): bool
    {
        return db_table_exists(self::TABLE);
    }

    /** 아직 못 걷은 총액. */
    public static function outstanding(int $riderId): int
    {
        if ($riderId < 1 || !self::tableReady()) {
            return 0;
        }

        return (int) (db_row(
            'SELECT COALESCE(SUM(remaining_amount), 0) AS s FROM ' . self::TABLE . '
              WHERE rider_id = ? AND remaining_amount > 0',
            [$riderId]
        )['s'] ?? 0);
    }

    /**
     * 못 걷은 행 목록 — 오래된 것부터(FIFO).
     *
     * @return list<array<string,mixed>>
     */
    public static function pending(int $riderId): array
    {
        if ($riderId < 1 || !self::tableReady()) {
            return [];
        }

        return db_rows(
            'SELECT * FROM ' . self::TABLE . '
              WHERE rider_id = ? AND remaining_amount > 0
              ORDER BY id ASC',
            [$riderId]
        );
    }

    /**
     * 이월분 등록 — 이번 정산에서 못 걷은 금액.
     *
     * @param string $feeCode 원래 차감 코드(excel_deduction 등) — 나중에 무엇이 밀렸는지 보기 위함
     */
    public static function add(int $riderId, int $amount, string $feeCode, string $label, ?int $cycleId = null): int
    {
        if ($riderId < 1 || $amount <= 0 || !self::tableReady()) {
            return 0;
        }

        return db_insert(
            'INSERT INTO ' . self::TABLE . '
                (rider_id, origin_cycle_id, fee_code, label, amount, remaining_amount, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())',
            [$riderId, $cycleId, mb_substr($feeCode, 0, 40), mb_substr($label, 0, 100), $amount, $amount]
        );
    }

    /**
     * 이월분 회수 — 여유분($limit) 한도로 오래된 것부터 차감한다.
     *
     * 실제로 걷은 금액을 돌려준다. `$cycleId` 는 어느 정산에서 걷혔는지 남기기 위함.
     */
    public static function consume(int $riderId, int $limit, ?int $cycleId = null): int
    {
        if ($riderId < 1 || $limit <= 0 || !self::tableReady()) {
            return 0;
        }

        $taken = 0;
        foreach (self::pending($riderId) as $row) {
            if ($taken >= $limit) {
                break;
            }
            $remain = (int) $row['remaining_amount'];
            $take   = min($remain, $limit - $taken);
            if ($take <= 0) {
                continue;
            }
            db_execute(
                'UPDATE ' . self::TABLE . '
                    SET remaining_amount = remaining_amount - ?,
                        collected_cycle_id = COALESCE(collected_cycle_id, ?),
                        closed_at = CASE WHEN remaining_amount - ? <= 0 THEN NOW() ELSE closed_at END,
                        updated_at = NOW()
                  WHERE id = ? AND remaining_amount >= ?',
                [$take, $cycleId, $take, (int) $row['id'], $take]
            );
            $taken += $take;
        }

        return $taken;
    }

    /**
     * 대리점(스코프)별 미회수 이월 합계 — 대시보드·감사용.
     *
     * @return array{total:int, riders:int}
     */
    public static function summaryForScope(): array
    {
        if (!self::tableReady()) {
            return ['total' => 0, 'riders' => 0];
        }
        [$scope, $params] = Org::agencyScopeClause('r.agency_id');
        $cond = $scope !== '' ? ' AND ' . $scope : '';
        $row  = db_row(
            'SELECT COALESCE(SUM(c.remaining_amount), 0) AS total, COUNT(DISTINCT c.rider_id) AS riders
               FROM ' . self::TABLE . ' c
               INNER JOIN riders r ON r.id = c.rider_id
              WHERE c.remaining_amount > 0' . $cond,
            $params
        );

        return ['total' => (int) ($row['total'] ?? 0), 'riders' => (int) ($row['riders'] ?? 0)];
    }
}
