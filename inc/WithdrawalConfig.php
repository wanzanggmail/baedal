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
            // 정산수수료 배분(2026-08-31 갑 지시) — 본사·총판 몫을 「기준 미만/기준 이상」 두
            // 구간으로 나눠 각각 배달 건당 정액(원)으로 뗀다. 대리점 몫 = 대행수수료 − 본사 − 총판.
            'hq_fee_short'   => 0,
            'hq_fee_long'    => 0,
            'dist_fee_short' => 0,
            'dist_fee_long'  => 0,
            // 세무대리 몫(2026-09-05 갑) — 걷은 정산수수료에서 세무대리 지갑으로 보낸다.
            'tax_fee_short'  => 0,
            'tax_fee_long'   => 0,
            // 이체 수수료(2026-09-01 갑) — 펌뱅킹 이체 1건당 라이더에게 부과하는 정액. 실지급액에서
            // 빼서 **본사**로 귀속된다. 본사가 설정(대리점은 조회).
            'transfer_fee'   => 330,
            // 라이더가 신청하는 즉시 펌뱅킹으로 내보낼지. 기본은 끔 — 켜면 관리자가 검토할 틈이 없다.
            'auto_transfer_on_request'  => 0,
        ];
    }

    /**
     * 대리점(org)별 출금 정책. 해당 org 행 → 전역 기본(org_id NULL) → PHP 기본 순 폴백.
     *
     * @return array<string, int>
     */
    /**
     * ⚡ 요청 단위 캐시. 라이더 목록처럼 N명을 도는 화면에서 **같은 대리점 설정을 매번 다시
     * 읽던** 자리다(라이더 1명당 최대 2쿼리 × N). DB가 원격이라 쿼리 1건이 네트워크 왕복이라
     * 체감이 컸다. 설정을 바꾸는 `save()`가 해당 키를 지우므로 저장 직후 조회도 안전하다.
     *
     * @var array<string, array<string, int|float>>
     */
    private static array $cache = [];

    public static function get(?int $orgId = null): array
    {
        $key = ($orgId !== null && $orgId > 0) ? (string) $orgId : 'global';
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

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

        return self::$cache[$key] = [
            'reserve_amount'    => max(0, (int) ($row['reserve_amount'] ?? $d['reserve_amount'])),
            'fee_day_threshold' => max(1, (int) ($row['fee_day_threshold'] ?? $d['fee_day_threshold'])),
            'fee_per_tx_short'  => max(0, (int) ($row['fee_per_tx_short'] ?? $d['fee_per_tx_short'])),
            'fee_per_tx_long'   => max(0, (int) ($row['fee_per_tx_long'] ?? $d['fee_per_tx_long'])),
            'hq_fee_short'   => max(0, (int) ($row['hq_fee_short'] ?? $d['hq_fee_short'])),
            'hq_fee_long'    => max(0, (int) ($row['hq_fee_long'] ?? $d['hq_fee_long'])),
            'dist_fee_short' => max(0, (int) ($row['dist_fee_short'] ?? $d['dist_fee_short'])),
            'dist_fee_long'  => max(0, (int) ($row['dist_fee_long'] ?? $d['dist_fee_long'])),
            'tax_fee_short'  => max(0, (int) ($row['tax_fee_short'] ?? $d['tax_fee_short'])),
            'tax_fee_long'   => max(0, (int) ($row['tax_fee_long'] ?? $d['tax_fee_long'])),
            'transfer_fee'   => max(0, (int) ($row['transfer_fee'] ?? $d['transfer_fee'])),
            'auto_transfer_on_request'  => (int) !empty($row['auto_transfer_on_request']),
        ];
    }

    /**
     * 정산수수료를 본사·총판·대리점 몫으로 나눈다 (2026-08-31 갑 지시로 구간별 재작성).
     *
     * - 본사·총판 몫 모두 **배달 건당 정액**이며, 「기준 미만/기준 이상」 두 구간에 각각 다른
     *   단가를 매길 수 있다. 예: 기준 미만은 본사 30·총판 20, 기준 이상은 본사 15·총판 10.
     *     본사 몫 = short×hq_fee_short + long×hq_fee_long
     *     총판 몫 = short×dist_fee_short + long×dist_fee_long
     * - **세무대리 몫**도 같은 구조(2026-09-05 갑). 세무비용은 외부로 나가는 확정 비용이라
     *   **가장 먼저** 뗀다 — 잔액이 모자라 총액이 깎였을 때 뒤로 밀려 0 이 되면 안 된다.
     * - **대리점 몫 = 대행수수료 − 세무대리 − 본사 − 총판**(끝수까지 대리점으로 몰아 합 = 총액).
     * - 본사 우선: 실제 걷힌 총액($totalFee, 잔액에 막혀 낮아졌을 수 있음)을 넘지 않는다.
     *   설정 실수로 본사+총판이 총액을 넘어도 대리점 몫이 음수로 새지 않도록 총액에서 절단한다
     *   (갑 확정: "대리점은 0이 되어도 된다").
     *
     * @param int $shortOrders 기준 미만 배달 건수
     * @param int $longOrders  기준 이상 배달 건수
     * @param int $totalFee    실제 걷힌 정산수수료(대행수수료) 총액
     * @return array{hq:int, distributor:int, tax:int, agency:int, orders:int, short_orders:int, long_orders:int}
     */
    public static function feeShare(int $shortOrders, int $longOrders, int $totalFee, ?int $orgId = null): array
    {
        $cfg         = self::get($orgId);
        $totalFee    = max(0, $totalFee);
        $shortOrders = max(0, $shortOrders);
        $longOrders  = max(0, $longOrders);

        $hq   = $shortOrders * (int) $cfg['hq_fee_short'] + $longOrders * (int) $cfg['hq_fee_long'];
        $dist = $shortOrders * (int) $cfg['dist_fee_short'] + $longOrders * (int) $cfg['dist_fee_long'];
        $tax  = $shortOrders * (int) $cfg['tax_fee_short'] + $longOrders * (int) $cfg['tax_fee_long'];

        // 세무대리 **최우선** → 본사 → 총판 → 나머지 대리점. 총액을 넘지 않는다.
        // 세무비용은 외부에 실제로 나가는 확정 비용이라, 잔액이 모자라 총액이 깎였을 때
        // 뒤로 밀려 0 이 되면 안 된다(2026-09-05 갑: 배분에 세무대리를 넣는다).
        $tax  = max(0, min($tax, $totalFee));
        $hq   = max(0, min($hq, $totalFee - $tax));
        $dist = max(0, min($dist, $totalFee - $tax - $hq));

        return [
            'hq'           => $hq,
            'distributor'  => $dist,
            'tax'          => $tax,
            'agency'       => $totalFee - $tax - $hq - $dist,
            'orders'       => $shortOrders + $longOrders,
            'short_orders' => $shortOrders,
            'long_orders'  => $longOrders,
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
            // 분배 설정(본사·총판 몫, 최저 금액)은 본사만 보내는 값이라, 대리점이 저장할 땐 키가
            // 안 온다 → 각각 기존 값 유지.
            'hq_fee_short'   => array_key_exists('hq_fee_short', $data) ? max(0, (int) $data['hq_fee_short']) : (int) $cur['hq_fee_short'],
            'hq_fee_long'    => array_key_exists('hq_fee_long', $data) ? max(0, (int) $data['hq_fee_long']) : (int) $cur['hq_fee_long'],
            'dist_fee_short' => array_key_exists('dist_fee_short', $data) ? max(0, (int) $data['dist_fee_short']) : (int) $cur['dist_fee_short'],
            'dist_fee_long'  => array_key_exists('dist_fee_long', $data) ? max(0, (int) $data['dist_fee_long']) : (int) $cur['dist_fee_long'],
            'tax_fee_short'  => array_key_exists('tax_fee_short', $data) ? max(0, (int) $data['tax_fee_short']) : (int) $cur['tax_fee_short'],
            'tax_fee_long'   => array_key_exists('tax_fee_long', $data) ? max(0, (int) $data['tax_fee_long']) : (int) $cur['tax_fee_long'],
            // 이체 수수료도 본사만 보내는 값 — 대리점 저장 시 키가 안 와서 기존 값 유지.
            'transfer_fee'   => array_key_exists('transfer_fee', $data) ? max(0, (int) $data['transfer_fee']) : (int) $cur['transfer_fee'],
            'auto_transfer_on_request' => array_key_exists('auto_transfer_on_request', $data)
                ? (int) (bool) $data['auto_transfer_on_request']
                : (int) $cur['auto_transfer_on_request'],
        ];

        // 본사 몫(건당) 하한 검증 — 하한값은 **「대행수수료 설정」의 최저 금액**(AgencyFeeConfig)을
        // 그대로 쓴다(2026-08-31 갑: "대행수수료 최저 금액은 대행수수료 설정 부분에 되어 있어").
        // 별도 필드를 만들지 않고 구간별(미만/이상) 최저를 각각 본사 몫에 건다. 0이면 하한 없음.
        require_once __DIR__ . '/AgencyFeeConfig.php';
        $min = AgencyFeeConfig::minimums();
        $tooLow = [];
        if ($min['fee_per_tx_short'] > 0 && $cfg['hq_fee_short'] < $min['fee_per_tx_short']) {
            $tooLow[] = sprintf('기준 미만 본사 몫 %d원(최저 %d원)', $cfg['hq_fee_short'], $min['fee_per_tx_short']);
        }
        if ($min['fee_per_tx_long'] > 0 && $cfg['hq_fee_long'] < $min['fee_per_tx_long']) {
            $tooLow[] = sprintf('기준 이상 본사 몫 %d원(최저 %d원)', $cfg['hq_fee_long'], $min['fee_per_tx_long']);
        }
        if ($tooLow !== []) {
            throw new InvalidArgumentException(
                '본사 몫(건당)은 대행수수료 최저 금액보다 낮을 수 없습니다 — ' . implode(' · ', $tooLow)
            );
        }

        // ⚠️ 본사+총판 몫이 걷은 건당 수수료(80/40원)를 넘어도 저장은 막지 않는다(갑 확정:
        // "대리점은 0이 되어도 된다"). 넘으면 feeShare()가 총액까지만 떼고 대리점 몫을 0으로 막는다
        // — 음수로 내려가면 대리점 지갑에서 없던 돈이 빠져나가므로.

        $hasOrg  = $orgId !== null && $orgId > 0;
        $exists  = $hasOrg
            ? db_row('SELECT id FROM withdrawal_config WHERE org_id = ? LIMIT 1', [$orgId])
            : db_row('SELECT id FROM withdrawal_config WHERE org_id IS NULL ORDER BY id ASC LIMIT 1');

        if ($exists) {
            db_execute(
                'UPDATE withdrawal_config
                 SET reserve_amount = ?, fee_day_threshold = ?, fee_per_tx_short = ?, fee_per_tx_long = ?,
                     hq_fee_short = ?, hq_fee_long = ?, dist_fee_short = ?, dist_fee_long = ?,
                     tax_fee_short = ?, tax_fee_long = ?,
                     transfer_fee = ?, auto_transfer_on_request = ?,
                     updated_by = ?, updated_at = NOW()
                 WHERE id = ?',
                [
                    $cfg['reserve_amount'],
                    $cfg['fee_day_threshold'],
                    $cfg['fee_per_tx_short'],
                    $cfg['fee_per_tx_long'],
                    $cfg['hq_fee_short'],
                    $cfg['hq_fee_long'],
                    $cfg['dist_fee_short'],
                    $cfg['dist_fee_long'],
                    $cfg['tax_fee_short'],
                    $cfg['tax_fee_long'],
                    $cfg['transfer_fee'],
                    $cfg['auto_transfer_on_request'],
                    ($adminId !== null && $adminId > 0) ? $adminId : null,
                    (int) $exists['id'],
                ]
            );
        } else {
            db_insert(
                'INSERT INTO withdrawal_config
                    (org_id, reserve_amount, fee_day_threshold, fee_per_tx_short, fee_per_tx_long,
                     hq_fee_short, hq_fee_long, dist_fee_short, dist_fee_long,
                     tax_fee_short, tax_fee_long,
                     transfer_fee, auto_transfer_on_request, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $hasOrg ? $orgId : null,
                    $cfg['reserve_amount'],
                    $cfg['fee_day_threshold'],
                    $cfg['fee_per_tx_short'],
                    $cfg['fee_per_tx_long'],
                    $cfg['hq_fee_short'],
                    $cfg['hq_fee_long'],
                    $cfg['dist_fee_short'],
                    $cfg['dist_fee_long'],
                    $cfg['tax_fee_short'],
                    $cfg['tax_fee_long'],
                    $cfg['transfer_fee'],
                    $cfg['auto_transfer_on_request'],
                    ($adminId !== null && $adminId > 0) ? $adminId : null,
                ]
            );
        }

        // 방금 쓴 값을 다시 읽어야 하므로 캐시를 버린다(안 그러면 저장 전 값이 돌아온다).
        self::$cache = [];

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
