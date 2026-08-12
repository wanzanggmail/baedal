<?php

declare(strict_types=1);

/**
 * 출금 정책 설정 (보증금·건당 수수료)
 */
final class WithdrawalConfig
{
    /** @return array<string, int|float> */
    public static function defaults(): array
    {
        return [
            'reserve_amount'    => 50000,
            'fee_day_threshold' => 7,
            'fee_per_tx_short'  => 80,
            'fee_per_tx_long'   => 40,
            // 정산수수료 3분할(2026-08-12 갑 확정) — 본사는 배달 건당 정액, 나머지를 총판·대리점이 비율로.
            'hq_fee_per_order'          => 0,
            'fee_share_distributor_pct' => 0.0,
        ];
    }

    /**
     * 대리점(org)별 출금 정책. 해당 org 행 → 전역 기본(org_id NULL) → PHP 기본 순 폴백.
     *
     * @return array<string, int>
     */
    public static function get(?int $orgId = null): array
    {
        if (!db_table_exists('withdrawal_config')) {
            return self::defaults();
        }

        $row = null;
        if ($orgId !== null && $orgId > 0) {
            $row = db_row('SELECT * FROM withdrawal_config WHERE org_id = ? LIMIT 1', [$orgId]);
        }
        if ($row === null) {
            $row = db_row('SELECT * FROM withdrawal_config WHERE org_id IS NULL ORDER BY id ASC LIMIT 1');
        }
        if ($row === null) {
            return self::defaults();
        }

        $d = self::defaults();

        return [
            'reserve_amount'    => max(0, (int) ($row['reserve_amount'] ?? $d['reserve_amount'])),
            'fee_day_threshold' => max(1, (int) ($row['fee_day_threshold'] ?? $d['fee_day_threshold'])),
            'fee_per_tx_short'  => max(0, (int) ($row['fee_per_tx_short'] ?? $d['fee_per_tx_short'])),
            'fee_per_tx_long'   => max(0, (int) ($row['fee_per_tx_long'] ?? $d['fee_per_tx_long'])),
            'hq_fee_per_order'  => max(0, (int) ($row['hq_fee_per_order'] ?? $d['hq_fee_per_order'])),
            'fee_share_distributor_pct' => max(0.0, min(100.0, (float) ($row['fee_share_distributor_pct'] ?? $d['fee_share_distributor_pct']))),
        ];
    }

    /**
     * 정산수수료를 본사·총판·대리점 몫으로 나눈다 (2026-08-12 갑 확정).
     *
     * - **본사 몫 = 배달 건당 정액 × 건수.** 비율이 아니라 정액이며, 대리점이 라이더에게 받는
     *   건당 수수료(80/40원)보다 작아야 대리점에 남는 게 생긴다("대리점은 그 이상을 받아야 한다").
     * - 남은 금액을 총판 비율만큼 떼고, **나머지 전부가 대리점 몫**(끝수는 대리점으로 몰아
     *   세 몫의 합이 항상 총액과 정확히 일치하게 한다).
     * - 설정 실수로 본사 몫이 총액을 넘으면 총액까지만 가져간다 — 지갑이 음수로 새지 않도록.
     *
     * @return array{hq:int, distributor:int, agency:int, hq_per_order:int, orders:int}
     */
    public static function feeShare(int $totalFee, int $orderCount, ?int $orgId = null): array
    {
        $cfg     = self::get($orgId);
        $perOrder = (int) $cfg['hq_fee_per_order'];
        $totalFee = max(0, $totalFee);
        $orders   = max(0, $orderCount);

        $hq   = min($totalFee, $perOrder * $orders);
        $rest = $totalFee - $hq;
        $dist = (int) round($rest * ((float) $cfg['fee_share_distributor_pct']) / 100);
        $dist = max(0, min($rest, $dist));

        return [
            'hq'           => $hq,
            'distributor'  => $dist,
            'agency'       => $rest - $dist,
            'hq_per_order' => $perOrder,
            'orders'       => $orders,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, int>
     */
    public static function save(array $data, ?int $orgId = null, ?int $adminId = null): array
    {
        if (!db_table_exists('withdrawal_config')) {
            throw new RuntimeException('withdrawal_config 테이블이 없습니다. php migrate.php 를 실행하세요.');
        }

        $cur = self::get($orgId);
        $cfg = [
            'reserve_amount'    => max(0, (int) ($data['reserve_amount'] ?? 0)),
            'fee_day_threshold' => max(1, min(365, (int) ($data['fee_day_threshold'] ?? 7))),
            'fee_per_tx_short'  => max(0, (int) ($data['fee_per_tx_short'] ?? 0)),
            'fee_per_tx_long'   => max(0, (int) ($data['fee_per_tx_long'] ?? 0)),
            // 분배 설정은 본사만 보내는 값이라, 대리점이 저장할 땐 키가 안 온다 → 기존 값 유지.
            'hq_fee_per_order'  => array_key_exists('hq_fee_per_order', $data)
                ? max(0, (int) $data['hq_fee_per_order'])
                : (int) $cur['hq_fee_per_order'],
            'fee_share_distributor_pct' => array_key_exists('fee_share_distributor_pct', $data)
                ? max(0.0, min(100.0, (float) $data['fee_share_distributor_pct']))
                : (float) $cur['fee_share_distributor_pct'],
        ];

        // ⚠️ 본사 건당 몫이 건당 정산수수료보다 커도 **막지 않는다**(2026-08-12 갑 확정:
        // "대리점은 0이 되어도 되. 어차피 본사만 잘 받으면 되니까 대리점을 알아서 해야지").
        // 본사 몫이 걷은 총액을 넘으면 feeShare()가 총액까지만 가져가고 대리점 몫이 0이 된다
        // (음수로는 안 내려간다 — 그러면 대리점 지갑에서 없던 돈이 빠져나간다).

        $hasOrg  = $orgId !== null && $orgId > 0;
        $exists  = $hasOrg
            ? db_row('SELECT id FROM withdrawal_config WHERE org_id = ? LIMIT 1', [$orgId])
            : db_row('SELECT id FROM withdrawal_config WHERE org_id IS NULL ORDER BY id ASC LIMIT 1');

        if ($exists) {
            db_execute(
                'UPDATE withdrawal_config
                 SET reserve_amount = ?, fee_day_threshold = ?, fee_per_tx_short = ?, fee_per_tx_long = ?,
                     hq_fee_per_order = ?, fee_share_distributor_pct = ?,
                     updated_by = ?, updated_at = NOW()
                 WHERE id = ?',
                [
                    $cfg['reserve_amount'],
                    $cfg['fee_day_threshold'],
                    $cfg['fee_per_tx_short'],
                    $cfg['fee_per_tx_long'],
                    $cfg['hq_fee_per_order'],
                    $cfg['fee_share_distributor_pct'],
                    ($adminId !== null && $adminId > 0) ? $adminId : null,
                    (int) $exists['id'],
                ]
            );
        } else {
            db_insert(
                'INSERT INTO withdrawal_config
                    (org_id, reserve_amount, fee_day_threshold, fee_per_tx_short, fee_per_tx_long,
                     hq_fee_per_order, fee_share_distributor_pct, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $hasOrg ? $orgId : null,
                    $cfg['reserve_amount'],
                    $cfg['fee_day_threshold'],
                    $cfg['fee_per_tx_short'],
                    $cfg['fee_per_tx_long'],
                    $cfg['hq_fee_per_order'],
                    $cfg['fee_share_distributor_pct'],
                    ($adminId !== null && $adminId > 0) ? $adminId : null,
                ]
            );
        }

        return self::get($orgId);
    }

    public static function feeForAccruedDays(int $accruedDays, ?int $orgId = null): int
    {
        $cfg = self::get($orgId);

        return $accruedDays < $cfg['fee_day_threshold']
            ? $cfg['fee_per_tx_short']
            : $cfg['fee_per_tx_long'];
    }

    /**
     * §7 #18 정산수수료 — age-bucket 모델.
     *
     * 구 모델(feeForAccruedDays)은 "마지막 출금 이후 경과일" 하나로 출금 전체에 단일 요율을
     * 매겼으나, 실제 규칙은 **주문 건별로** 매겨진다(LOGIC.md §5.4):
     *   - 기준일수(기본 7일) 이내 주문 → 건당 fee_per_tx_short(80원)
     *   - 기준일수를 지난 주문   → 건당 fee_per_tx_long(40원)
     * 따라서 한 번의 출금 안에 80원 구간과 40원 구간이 섞여 합산된다.
     *
     * 같은 정산일의 주문은 경과일이 모두 같으므로, 주문 1건씩이 아니라
     * 사이클(라이더·일자)의 order_count 단위로 계산해도 결과가 동일하다.
     *
     * @param list<array{settlement_date:string, order_count:int|string}> $cycles
     * @param string|null $asOf 기준일(YYYY-MM-DD). 기본 오늘.
     * @return array{total:int, short_orders:int, long_orders:int, short_amount:int, long_amount:int, rate_short:int, rate_long:int, threshold:int}
     */
    public static function feeForCycles(array $cycles, ?int $orgId = null, ?string $asOf = null): array
    {
        $cfg       = self::get($orgId);
        $threshold = (int) $cfg['fee_day_threshold'];
        $rateShort = (int) $cfg['fee_per_tx_short'];
        $rateLong  = (int) $cfg['fee_per_tx_long'];

        $base = self::toDate($asOf ?? date('Y-m-d')) ?? new DateTimeImmutable('today');

        $shortOrders = 0;
        $longOrders  = 0;

        foreach ($cycles as $c) {
            $orders = (int) ($c['order_count'] ?? 0);
            if ($orders <= 0) {
                continue;
            }
            $settled = self::toDate((string) ($c['settlement_date'] ?? ''));
            if ($settled === null) {
                // 정산일을 알 수 없으면 보수적으로 비싼 구간(최근)으로 처리
                $shortOrders += $orders;
                continue;
            }
            // 경과일: 미래 정산일(음수)은 0일로 취급
            $elapsed = (int) $base->diff($settled)->days;
            if ($settled > $base) {
                $elapsed = 0;
            }
            if ($elapsed < $threshold) {
                $shortOrders += $orders;
            } else {
                $longOrders += $orders;
            }
        }

        $shortAmount = $shortOrders * $rateShort;
        $longAmount  = $longOrders * $rateLong;

        return [
            'total'        => $shortAmount + $longAmount,
            'short_orders' => $shortOrders,
            'long_orders'  => $longOrders,
            'short_amount' => $shortAmount,
            'long_amount'  => $longAmount,
            'rate_short'   => $rateShort,
            'rate_long'    => $rateLong,
            'threshold'    => $threshold,
        ];
    }

    private static function toDate(string $ymd): ?DateTimeImmutable
    {
        $ymd = substr(trim($ymd), 0, 10);
        if ($ymd === '') {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);

        return ($d && $d->format('Y-m-d') === $ymd) ? $d : null;
    }
}
