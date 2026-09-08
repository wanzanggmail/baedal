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
            // 개발사 몫(2026-09-05 갑) — 세무대리와 같은 구조, 개발사 지갑으로 보낸다.
            'dev_fee_short'  => 0,
            'dev_fee_long'   => 0,
            // 대리점 추가금(2026-09-08 갑) — 대리점이 정하는 자기 몫. 총판 추가금은 dist_fee_*.
            'agency_add_short' => 0,
            'agency_add_long'  => 0,
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

        // ── 2026-09-08 갑: 본사·세무대리·개발사 몫은 **전역 고정** ───────────────────
        // 대리점 행에도 이 컬럼들이 남아 있지만 더는 읽지 않는다(과거 값 보존용).
        // 대리점이 정하는 건 총판 추가금·대리점 추가금 둘뿐이고, **총액은 그 합**이다.
        //   총액 = 전역고정(본사+세무대리+개발사) + 총판 추가 + 대리점 추가
        // 예전처럼 총액을 따로 두면 배분 합과 어긋난다(실제로 21곳이 80원인데 합이 88원이었다).
        $gRow = ($orgId !== null && $orgId > 0)
            ? db_row('SELECT * FROM withdrawal_config WHERE org_id IS NULL ORDER BY id ASC LIMIT 1')
            : $row;
        $gRow ??= $row;

        $hqS  = max(0, (int) ($gRow['hq_fee_short'] ?? $d['hq_fee_short']));
        $hqL  = max(0, (int) ($gRow['hq_fee_long'] ?? $d['hq_fee_long']));
        $taxS = max(0, (int) ($gRow['tax_fee_short'] ?? $d['tax_fee_short']));
        $taxL = max(0, (int) ($gRow['tax_fee_long'] ?? $d['tax_fee_long']));
        $devS = max(0, (int) ($gRow['dev_fee_short'] ?? $d['dev_fee_short']));
        $devL = max(0, (int) ($gRow['dev_fee_long'] ?? $d['dev_fee_long']));

        $distS = max(0, (int) ($row['dist_fee_short'] ?? $d['dist_fee_short']));
        $distL = max(0, (int) ($row['dist_fee_long'] ?? $d['dist_fee_long']));
        $addS  = max(0, (int) ($row['agency_add_short'] ?? 0));
        $addL  = max(0, (int) ($row['agency_add_long'] ?? 0));

        return self::$cache[$key] = [
            'reserve_amount'    => max(0, (int) ($row['reserve_amount'] ?? $d['reserve_amount'])),
            'fee_day_threshold' => max(1, (int) ($row['fee_day_threshold'] ?? $d['fee_day_threshold'])),
            // 총액은 **파생값**이다 — DB 의 fee_per_tx_* 는 보지 않는다.
            'fee_per_tx_short'  => $hqS + $taxS + $devS + $distS + $addS,
            'fee_per_tx_long'   => $hqL + $taxL + $devL + $distL + $addL,
            'hq_fee_short'   => $hqS,
            'hq_fee_long'    => $hqL,
            'dist_fee_short' => $distS,
            'dist_fee_long'  => $distL,
            'tax_fee_short'  => $taxS,
            'tax_fee_long'   => $taxL,
            'dev_fee_short'  => $devS,
            'dev_fee_long'   => $devL,
            'agency_add_short' => $addS,
            'agency_add_long'  => $addL,
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
     * @return array{hq:int, distributor:int, tax:int, developer:int, agency:int, orders:int, short_orders:int, long_orders:int}
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
        $dev  = $shortOrders * (int) $cfg['dev_fee_short'] + $longOrders * (int) $cfg['dev_fee_long'];

        // 세무대리·개발사 **최우선** → 본사 → 총판 → 나머지 대리점. 총액을 넘지 않는다.
        // 둘 다 외부로 나가는 확정 비용이라, 잔액이 모자라 총액이 깎였을 때 뒤로 밀려
        // 0 이 되면 안 된다(2026-09-05 갑).
        $tax  = max(0, min($tax, $totalFee));
        $dev  = max(0, min($dev, $totalFee - $tax));
        $hq   = max(0, min($hq, $totalFee - $tax - $dev));
        $dist = max(0, min($dist, $totalFee - $tax - $dev - $hq));

        return [
            'hq'           => $hq,
            'distributor'  => $dist,
            'tax'          => $tax,
            'developer'    => $dev,
            'agency'       => $totalFee - $tax - $dev - $hq - $dist,
            'orders'       => $shortOrders + $longOrders,
            'short_orders' => $shortOrders,
            'long_orders'  => $longOrders,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, int>
     */
    /**
     * **추가금만** 저장한다 — 총판 추가금·대리점 추가금 두 쌍 (2026-09-08 갑).
     *
     * 갑: "수수료 설정화면에서 대리점, 총판의 정산수수료 추가금에 대한 것들을 설정하게 해줘"
     *
     * `save()` 를 쓰면 안 되는 이유: 그쪽은 보증금·경과일 기준·이체수수료까지 한꺼번에 쓰는데,
     * 키가 안 오면 **보증금이 0 으로 덮인다**(`$data['reserve_amount'] ?? 0`). 추가금만 있는
     * 화면에서 부르면 그 대리점 보증금이 조용히 사라진다. 그래서 네 컬럼만 만지는 길을 따로 둔다.
     *
     * 행이 없으면 만든다 — 이때 나머지 값은 **지금 그 대리점에 적용되던 값**을 복사해
     * 동작이 바뀌지 않게 한다.
     *
     * @param array<string, mixed> $data dist_fee_short/long · agency_add_short/long
     * @return array<string, int> 저장 후 설정
     */
    public static function saveAddons(array $data, ?int $orgId = null, ?int $adminId = null): array
    {
        if (!db_table_exists('withdrawal_config')) {
            throw new RuntimeException('withdrawal_config 테이블이 없습니다. php migrate.php 를 실행하세요.');
        }

        $cur  = self::get($orgId);
        $take = static function (string $k) use ($data, $cur): int {
            $v = array_key_exists($k, $data) ? (int) $data[$k] : (int) ($cur[$k] ?? 0);
            if ($v < 0) {
                throw new InvalidArgumentException('추가금은 0원 이상이어야 합니다.');
            }
            if ($v > 100000) {
                throw new InvalidArgumentException('추가금이 너무 큽니다(건당 100,000원 초과).');
            }

            return $v;
        };

        $distS = $take('dist_fee_short');
        $distL = $take('dist_fee_long');
        $addS  = $take('agency_add_short');
        $addL  = $take('agency_add_long');

        // 총액은 파생값 — 전역 고정분에 추가금을 더한다.
        $fixedS = (int) $cur['hq_fee_short'] + (int) $cur['tax_fee_short'] + (int) $cur['dev_fee_short'];
        $fixedL = (int) $cur['hq_fee_long'] + (int) $cur['tax_fee_long'] + (int) $cur['dev_fee_long'];

        $hasOrg = $orgId !== null && $orgId > 0;
        $exists = $hasOrg
            ? db_row('SELECT id FROM withdrawal_config WHERE org_id = ? LIMIT 1', [$orgId])
            : db_row('SELECT id FROM withdrawal_config WHERE org_id IS NULL ORDER BY id ASC LIMIT 1');

        if ($exists) {
            db_execute(
                'UPDATE withdrawal_config
                    SET dist_fee_short = ?, dist_fee_long = ?,
                        agency_add_short = ?, agency_add_long = ?,
                        fee_per_tx_short = ?, fee_per_tx_long = ?,
                        updated_by = ?, updated_at = NOW()
                  WHERE id = ?',
                [
                    $distS, $distL, $addS, $addL,
                    $fixedS + $distS + $addS,
                    $fixedL + $distL + $addL,
                    ($adminId !== null && $adminId > 0) ? $adminId : null,
                    (int) $exists['id'],
                ]
            );
        } else {
            db_insert(
                'INSERT INTO withdrawal_config
                    (org_id, reserve_amount, fee_day_threshold, fee_per_tx_short, fee_per_tx_long,
                     hq_fee_short, hq_fee_long, dist_fee_short, dist_fee_long,
                     tax_fee_short, tax_fee_long, dev_fee_short, dev_fee_long,
                     agency_add_short, agency_add_long,
                     transfer_fee, auto_transfer_on_request, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $hasOrg ? $orgId : null,
                    (int) $cur['reserve_amount'],
                    (int) $cur['fee_day_threshold'],
                    $fixedS + $distS + $addS,
                    $fixedL + $distL + $addL,
                    // 전역 고정분은 전역 행에서만 읽으므로 여기 값은 참고용으로 같이 복사해둔다.
                    (int) $cur['hq_fee_short'], (int) $cur['hq_fee_long'],
                    $distS, $distL,
                    (int) $cur['tax_fee_short'], (int) $cur['tax_fee_long'],
                    (int) $cur['dev_fee_short'], (int) $cur['dev_fee_long'],
                    $addS, $addL,
                    (int) $cur['transfer_fee'],
                    (int) $cur['auto_transfer_on_request'],
                    ($adminId !== null && $adminId > 0) ? $adminId : null,
                ]
            );
        }

        self::$cache = [];

        return self::get($orgId);
    }
    public static function save(array $data, ?int $orgId = null, ?int $adminId = null): array
    {
        if (!db_table_exists('withdrawal_config')) {
            throw new RuntimeException('withdrawal_config 테이블이 없습니다. php migrate.php 를 실행하세요.');
        }

        $cur = self::get($orgId);
        $hasOrg = $orgId !== null && $orgId > 0;

        // ── 2026-09-08 갑: 전역 고정 + 대리점 추가분 ────────────────────────────
        // 본사·세무대리·개발사 몫은 **전역 행에만** 저장한다. 대리점이 저장할 때 이 키가
        // 와도 무시한다 — 대리점이 본사 몫을 건드릴 수 있으면 「전역 고정」이 아니게 된다.
        // 대리점이 정하는 건 총판 추가금(dist_fee_*)·대리점 추가금(agency_add_*) 둘뿐이다.
        $keepFixed = static fn (string $k): int => (int) $cur[$k];
        $takeFixed = static function (string $k) use ($data, $cur, $hasOrg): int {
            if ($hasOrg || !array_key_exists($k, $data)) {
                return (int) $cur[$k];      // 대리점 저장이거나 키가 없으면 기존 값 유지
            }

            return max(0, (int) $data[$k]);
        };
        $takeAdd = static function (string $k) use ($data, $cur): int {
            return array_key_exists($k, $data) ? max(0, (int) $data[$k]) : (int) ($cur[$k] ?? 0);
        };

        $cfg = [
            'reserve_amount'    => max(0, (int) ($data['reserve_amount'] ?? 0)),
            'fee_day_threshold' => max(1, min(365, (int) ($data['fee_day_threshold'] ?? 7))),
            // 전역 고정 — 본사만 바꾼다
            'hq_fee_short'   => $takeFixed('hq_fee_short'),
            'hq_fee_long'    => $takeFixed('hq_fee_long'),
            'tax_fee_short'  => $takeFixed('tax_fee_short'),
            'tax_fee_long'   => $takeFixed('tax_fee_long'),
            'dev_fee_short'  => $takeFixed('dev_fee_short'),
            'dev_fee_long'   => $takeFixed('dev_fee_long'),
            // 추가분 — 대리점이 정한다(전역 행에도 저장 가능: 미설정 대리점의 기본값이 된다)
            'dist_fee_short'   => $takeAdd('dist_fee_short'),
            'dist_fee_long'    => $takeAdd('dist_fee_long'),
            'agency_add_short' => $takeAdd('agency_add_short'),
            'agency_add_long'  => $takeAdd('agency_add_long'),
            // 이체 수수료도 본사만 보내는 값 — 대리점 저장 시 키가 안 와서 기존 값 유지.
            'transfer_fee'   => array_key_exists('transfer_fee', $data) ? max(0, (int) $data['transfer_fee']) : (int) $cur['transfer_fee'],
            'auto_transfer_on_request' => array_key_exists('auto_transfer_on_request', $data)
                ? (int) (bool) $data['auto_transfer_on_request']
                : (int) $cur['auto_transfer_on_request'],
        ];

        // 총액은 **파생값**이다 — 합에서 만든다. 그래서 예전처럼 총액과 배분이 어긋날 수 없고,
        // 「최저 금액」 하한도 필요 없어졌다(대리점이 본사 몫을 깎을 방법 자체가 없다).
        $cfg['fee_per_tx_short'] = $cfg['hq_fee_short'] + $cfg['tax_fee_short'] + $cfg['dev_fee_short']
            + $cfg['dist_fee_short'] + $cfg['agency_add_short'];
        $cfg['fee_per_tx_long'] = $cfg['hq_fee_long'] + $cfg['tax_fee_long'] + $cfg['dev_fee_long']
            + $cfg['dist_fee_long'] + $cfg['agency_add_long'];

        $exists  = $hasOrg
            ? db_row('SELECT id FROM withdrawal_config WHERE org_id = ? LIMIT 1', [$orgId])
            : db_row('SELECT id FROM withdrawal_config WHERE org_id IS NULL ORDER BY id ASC LIMIT 1');

        if ($exists) {
            db_execute(
                'UPDATE withdrawal_config
                 SET reserve_amount = ?, fee_day_threshold = ?, fee_per_tx_short = ?, fee_per_tx_long = ?,
                     hq_fee_short = ?, hq_fee_long = ?, dist_fee_short = ?, dist_fee_long = ?,
                     tax_fee_short = ?, tax_fee_long = ?, dev_fee_short = ?, dev_fee_long = ?,
                     agency_add_short = ?, agency_add_long = ?,
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
                    $cfg['dev_fee_short'],
                    $cfg['dev_fee_long'],
                    $cfg['agency_add_short'],
                    $cfg['agency_add_long'],
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
                     tax_fee_short, tax_fee_long, dev_fee_short, dev_fee_long,
                     agency_add_short, agency_add_long,
                     transfer_fee, auto_transfer_on_request, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
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
                    $cfg['dev_fee_short'],
                    $cfg['dev_fee_long'],
                    $cfg['agency_add_short'],
                    $cfg['agency_add_long'],
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
