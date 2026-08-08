<?php

declare(strict_types=1);

require_once __DIR__ . '/WithdrawalConfig.php';

/**
 * 라이더 지갑 (누적 잔액·적립 일수 — 정산 반영은 추후 일원화)
 */
final class RiderWallet
{
    /** @return array{balance: int, accrued_days: int} */
    public static function get(int $riderId): array
    {
        self::ensure($riderId);
        $row = db_row(
            'SELECT balance, accrued_days FROM rider_wallets WHERE rider_id = ? LIMIT 1',
            [$riderId]
        );

        return [
            'balance'      => (int) ($row['balance'] ?? 0),
            'accrued_days' => (int) ($row['accrued_days'] ?? 0),
        ];
    }

    public static function ensure(int $riderId): void
    {
        if ($riderId < 1 || !db_table_exists('rider_wallets')) {
            return;
        }

        $exists = db_row('SELECT rider_id FROM rider_wallets WHERE rider_id = ? LIMIT 1', [$riderId]);
        if ($exists !== null) {
            return;
        }

        db_insert(
            'INSERT INTO rider_wallets (rider_id, balance, accrued_days) VALUES (?, 0, 0)',
            [$riderId]
        );
    }

    /**
     * 출금 가능 금액 미리보기 (전액 출금 정책)
     *
     * @return array<string, int|bool|string>
     */
    public static function previewWithdrawal(int $riderId, ?string $toDate = null): array
    {
        $wallet = self::get($riderId);
        // 멀티테넌시: 라이더 소속 대리점의 출금 정책 사용 (없으면 전역 기본)
        $agencyId = (int) (db_row('SELECT agency_id FROM riders WHERE id = ? LIMIT 1', [$riderId])['agency_id'] ?? 0);
        $orgId    = $agencyId > 0 ? $agencyId : null;
        $cfg      = WithdrawalConfig::get($orgId);
        $balance  = (int) $wallet['balance'];
        $accrued  = (int) $wallet['accrued_days'];
        $reserve  = (int) $cfg['reserve_amount'];

        // 보증금을 남기고 이번에 소진할 금액(= 실지급액 + 정산수수료)
        $afterReserve = max(0, $balance - $reserve);

        // §7 #18 — 미출금 사이클을 오래된 순으로 골라 주문 건별 요율로 수수료 산출.
        // 사이클 데이터가 없거나(구 데이터·마이그레이션 전) 선택이 비면 구 모델로 폴백한다.
        require_once __DIR__ . '/WithdrawalCycles.php';
        $picked      = [];
        $taken       = 0;
        $blocked     = false;
        $shortfall   = 0;
        $cycleBased  = false;

        if ($afterReserve > 0 && WithdrawalCycles::tableReady()) {
            $sel     = WithdrawalCycles::select($riderId, $afterReserve, null, $toDate);
            $picked  = $sel['picked'];
            $taken   = (int) $sel['taken'];
            $blocked = (bool) $sel['blocked_by_policy'];
            $shortfall = (int) ($sel['blocked_shortfall'] ?? 0);
            // ⚠️ 사이클 후보가 하나라도 있었으면 **고른 게 없어도** 사이클 모델로 간주한다.
            // 예전엔 `$picked !== []` 만 봤는데, 건 단위(WHOLE) 정책에서 "출금가능액이 다음
            // 사이클보다 작아 아무것도 못 고른" 정상 상황이 구 단일요율 폴백으로 새어 들어가
            // **사이클을 하나도 마킹하지 않은 채 잔액−보증금 전액이 지급**되는 문제가 있었다.
            $cycleBased = $picked !== [] || (bool) ($sel['had_candidates'] ?? false);
        }

        if ($cycleBased) {
            $feeCalc = WithdrawalCycles::feeFor($picked, $orgId);
            $fee     = (int) $feeCalc['total'];
            // 실제로 소진 가능한 금액이 잔액-보증금보다 적을 수 있다(사이클 부족·정책 차단)
            $consume = $taken;
        } else {
            // 폴백: 구 단일요율 모델
            $feeCalc = [
                'total' => WithdrawalConfig::feeForAccruedDays($accrued, $orgId),
                'short_orders' => 0, 'long_orders' => 0,
                'short_amount' => 0, 'long_amount' => 0,
                'rate_short' => (int) $cfg['fee_per_tx_short'],
                'rate_long'  => (int) $cfg['fee_per_tx_long'],
                'threshold'  => (int) $cfg['fee_day_threshold'],
            ];
            $fee     = (int) $feeCalc['total'];
            // 기간 지정 출금인데 해당 기간에 소진할 사이클이 없으면 0. (구 폴백 모델은 전액
            // 출금 전제라 기간 개념이 없으므로 그대로 쓰면 안 고른 금액까지 나가버린다.)
            $consume = ($toDate !== null && $toDate !== '') ? 0 : $afterReserve;
        }

        $payout = max(0, $consume - $fee);

        return [
            // ── 기존 키(라이더 화면·applyForRider 호환) ──
            'balance'           => $balance,
            'accrued_days'      => $accrued,
            'reserve_amount'    => $reserve,
            'fee_per_tx'        => $fee,          // 이제 age-bucket 합산 총액
            'fee_day_threshold' => (int) $cfg['fee_day_threshold'],
            'after_reserve'     => $afterReserve,
            'payout_amount'     => $payout,
            'can_apply'         => $payout > 0,
            // ── 신규: 수수료 구간 내역 + 사이클 선택 결과 ──
            'fee_cycle_based'   => $cycleBased,
            'fee_short_orders'  => (int) $feeCalc['short_orders'],
            'fee_long_orders'   => (int) $feeCalc['long_orders'],
            'fee_short_amount'  => (int) $feeCalc['short_amount'],
            'fee_long_amount'   => (int) $feeCalc['long_amount'],
            'fee_rate_short'    => (int) $feeCalc['rate_short'],
            'fee_rate_long'     => (int) $feeCalc['rate_long'],
            'consume_amount'    => $consume,      // 지갑에서 빠지는 총액(지급+수수료)
            'picked_cycles'     => $picked,
            'blocked_by_policy' => $blocked,
            // 건 단위 출금 정책상 이번엔 못 가져가는 경우, 얼마가 더 쌓이면 풀리는지
            'blocked_shortfall' => $shortfall,
        ];
    }

    /**
     * 출금 완료 후 지갑 정리 — 실제로 빠져나간 금액만큼 **차감**한다.
     *
     * 🐛 2026-07-24 수정: 이전 구현은 `balance = 보증금`으로 **대입**했다. 전액출금이라
     * 정상 경로에서는 결과가 같지만, **출금 신청(pending) ~ 완료 사이에 정산이 반영되면**
     * (`credit()`으로 잔액이 늘어남) 그 금액이 완료 시점에 덮어써져 **소멸**했다.
     *   예) 신청 시 159,000(보증금 50,000·수수료 3,640·지급 105,360)
     *       → 중간에 정산 +40,000 → 잔액 199,000
     *       → 완료 시 `balance = 50,000` 이 되며 40,000 증발
     * 차감 방식으로 바꾸면 199,000 − 109,000 = 90,000 으로 신규 입금분이 보존된다.
     *
     * @param int $deductedTotal 지갑에서 실제로 빠져나가는 총액 = 실지급액 + 정산수수료
     */
    public static function deductAfterWithdrawal(int $riderId, int $deductedTotal): void
    {
        if ($riderId < 1 || !db_table_exists('rider_wallets')) {
            return;
        }

        self::ensure($riderId);
        // GREATEST(0, ...): 완료 전 잔액이 수동조정 등으로 줄어든 경우에도 음수 방지
        db_execute(
            'UPDATE rider_wallets
                SET balance = GREATEST(0, balance - ?), accrued_days = 0, updated_at = NOW()
              WHERE rider_id = ?',
            [max(0, $deductedTotal), $riderId]
        );
    }

    /**
     * 정산 반영용 (추후 일원화 시 사용)
     */
    public static function credit(int $riderId, int $amount, bool $incrementAccruedDay = true): void
    {
        if ($riderId < 1 || $amount === 0 || !db_table_exists('rider_wallets')) {
            return;
        }

        self::ensure($riderId);
        if ($incrementAccruedDay) {
            db_execute(
                'UPDATE rider_wallets
                 SET balance = balance + ?, accrued_days = accrued_days + 1, updated_at = NOW()
                 WHERE rider_id = ?',
                [$amount, $riderId]
            );
        } else {
            db_execute(
                'UPDATE rider_wallets SET balance = balance + ?, updated_at = NOW() WHERE rider_id = ?',
                [$amount, $riderId]
            );
        }
    }
}
