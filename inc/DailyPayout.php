<?php

declare(strict_types=1);

require_once __DIR__ . '/AgencyWallet.php';
require_once __DIR__ . '/RiderWallet.php';
require_once __DIR__ . '/WithdrawalConfig.php';
require_once __DIR__ . '/WithdrawalCycles.php';
require_once __DIR__ . '/WithdrawalFeeShare.php';

/**
 * 일일정산(선정산) 지급 — LOGIC §5.4 2단계.
 *
 * 선정산(riders.is_daily_settlement=1) 라이더는 개별 출금신청을 하지 않는다.
 * 관리자(대리점)가 "일일정산 지급 리스트"에서 그날 확정된 대상자를 확인하고 원클릭으로 지급한다
 * (완전 자동 아님 — 사람이 확인 후 실행). 지급 = 대리점 잔액(PG로 충전)에서 라이더 계좌로 이체.
 *
 * 지급 처리(원클릭):
 *   1) 라이더 지갑 잔액 = 지급액
 *   2) 대리점 잔액 ≥ 지급액 확인(PG 충전 선행 필요)
 *   3) withdrawal_requests(kind=auto_daily, completed) 기록 + 대리점 잔액 차감(원장) + 라이더 지갑 0
 *
 * 실제 오픈뱅킹 이체 연동은 Phase F. 현재는 원클릭=실행(completed)으로 기록한다.
 */
final class DailyPayout
{
    /**
     * 지급 대상 목록 — 선정산 라이더 중 지갑 잔액>0, 스코프 내.
     *
     * @return array{rows:list<array<string,mixed>>, agency_wallets:array<int,int>}
     */
    public static function listPayable(?int $agencyId = null): array
    {
        if (!db_table_exists('rider_wallets')) {
            return ['rows' => [], 'agency_wallets' => []];
        }

        $where  = ['r.is_daily_settlement = 1', "r.status = 'active'", 'w.balance > 0'];
        $params = [];

        if ($agencyId !== null && $agencyId > 0) {
            $where[]  = 'r.agency_id = ?';
            $params[] = $agencyId;
        } else {
            [$scopeSql, $scopeParams] = Org::agencyScopeClause('r.agency_id');
            if ($scopeSql !== '') {
                $where[] = $scopeSql;
                $params  = array_merge($params, $scopeParams);
            }
        }

        $whereStr = implode(' AND ', $where);
        $rows = db_rows(
            "SELECT r.id, r.phone, r.name, r.agency_id, r.bank_code, r.bank_account,
                    w.balance, w.accrued_days,
                    o.name AS agency_name,
                    sc.label AS bank_label
               FROM rider_wallets w
               INNER JOIN riders r ON r.id = w.rider_id
               LEFT JOIN organizations o ON o.id = r.agency_id
               LEFT JOIN system_codes sc ON sc.category = 'bank' AND sc.code = r.bank_code
              WHERE {$whereStr}
              ORDER BY r.agency_id ASC, r.name ASC",
            $params
        );

        $agencyIds = [];
        $out = array_map(static function (array $r) use (&$agencyIds): array {
            $aid = (int) $r['agency_id'];
            $agencyIds[$aid] = true;
            $hasBank = trim((string) $r['bank_code']) !== '' && trim((string) $r['bank_account']) !== '';

            // 정산수수료를 뗀 실지급액을 목록에서도 보여준다 — 지급 버튼을 누르기 전에
            // 관리자가 실제 이체될 금액을 알 수 있어야 한다(2026-08-12 일일정산 수수료 부과).
            $balance    = (int) $r['balance'];
            $feeCalc    = WithdrawalConfig::feeForCycles(WithdrawalCycles::unwithdrawn((int) $r['id']), $aid);
            $orderCount = (int) $feeCalc['short_orders'] + (int) $feeCalc['long_orders'];
            $fee        = min($balance, max(0, (int) $feeCalc['total']));

            return [
                'rider_id'     => (int) $r['id'],
                'phone'        => (string) $r['phone'],
                'name'         => (string) $r['name'],
                'agency_id'    => $aid,
                'agency_name'  => (string) ($r['agency_name'] ?? ''),
                'balance'      => $balance,
                'fee'          => $fee,
                'order_count'  => $orderCount,
                'payout'       => $balance - $fee,
                'accrued_days' => (int) $r['accrued_days'],
                'bank_label'   => (string) ($r['bank_label'] ?? ''),
                'has_bank'     => $hasBank,
            ];
        }, $rows);

        $wallets = [];
        foreach (array_keys($agencyIds) as $aid) {
            $wallets[$aid] = AgencyWallet::get($aid)['balance'];
        }

        return ['rows' => $out, 'agency_wallets' => $wallets];
    }

    /**
     * 라이더 1명 지급(원클릭). 트랜잭션.
     *
     * @return array{rider_id:int, amount:int}
     */
    public static function payRider(int $riderId, ?int $adminId = null): array
    {
        if ($riderId < 1) {
            throw new InvalidArgumentException('라이더 정보가 없습니다.');
        }
        if (!db_table_exists('withdrawal_requests') || !db_table_exists('rider_wallets') || !AgencyWallet::tableExists()) {
            throw new RuntimeException('지갑/출금 테이블이 없습니다. php migrate.php 를 실행하세요.');
        }

        $rider = db_row(
            'SELECT id, name, status, is_daily_settlement, agency_id, bank_code, bank_account, account_holder
               FROM riders WHERE id = ? LIMIT 1',
            [$riderId]
        );
        if ($rider === null) {
            throw new InvalidArgumentException('라이더를 찾을 수 없습니다.');
        }
        if ((int) ($rider['is_daily_settlement'] ?? 0) !== 1) {
            throw new InvalidArgumentException('선정산(일일지급) 대상 라이더가 아닙니다.');
        }
        $agencyId = (int) ($rider['agency_id'] ?? 0);
        if ($agencyId < 1) {
            throw new InvalidArgumentException('라이더 소속 대리점이 없습니다.');
        }
        if (trim((string) $rider['bank_code']) === '' || trim((string) $rider['bank_account']) === '') {
            throw new InvalidArgumentException($rider['name'] . ': 출금 계좌가 없습니다.');
        }

        RiderWallet::ensure($riderId);
        $balance = (int) (db_row('SELECT balance FROM rider_wallets WHERE rider_id = ? LIMIT 1', [$riderId])['balance'] ?? 0);
        if ($balance <= 0) {
            throw new InvalidArgumentException($rider['name'] . ': 지급할 잔액이 없습니다.');
        }

        // 정산수수료 — 일일정산에도 부과한다(2026-08-12 갑 확정: "일일정산도 정산수수료를 부과해야해").
        // 주정산과 같은 age-bucket 규칙(기준일 이내 건당 80원 / 지난 건 40원)을 그대로 쓴다.
        require_once __DIR__ . '/WithdrawalCycles.php';
        require_once __DIR__ . '/WithdrawalFeeShare.php';
        $cycles     = WithdrawalCycles::unwithdrawn($riderId);
        $feeCalc    = WithdrawalConfig::feeForCycles($cycles, $agencyId);
        $orderCount = (int) $feeCalc['short_orders'] + (int) $feeCalc['long_orders'];
        // 잔액보다 수수료가 클 수는 없다(그러면 지급액이 음수) — 잔액까지만 뗀다.
        $fee    = min($balance, max(0, (int) $feeCalc['total']));
        $amount = $balance - $fee;
        if ($amount <= 0) {
            throw new InvalidArgumentException(sprintf(
                '%s: 정산수수료(%s원)를 빼면 지급액이 남지 않습니다. (잔액 %s원)',
                $rider['name'],
                number_format($fee),
                number_format($balance)
            ));
        }

        // 대리점 지갑은 실제 이체분(수수료 제외)만큼만 빠진다. 수수료는 대리점 지갑에 남아
        // 아래에서 본사·총판 몫만 상위로 이동한다.
        $agencyBalance = AgencyWallet::get($agencyId)['balance'];
        if ($agencyBalance < $amount) {
            throw new InvalidArgumentException(sprintf(
                '%s: 대리점 잔액 부족(잔액 %s < 지급 %s). PG 충전이 필요합니다.',
                $rider['name'],
                number_format($agencyBalance),
                number_format($amount)
            ));
        }

        // 오픈뱅킹 이체(현재 mock). 성공 시에만 잔액 이동·완료 처리.
        require_once __DIR__ . '/Disbursement.php';
        $res = Disbursement::transfer($agencyId, (string) $rider['bank_code'], (string) $rider['bank_account'], $amount);

        if (!$res->success) {
            // 실패 이력 기록(잔액 이동 없음) — 다른 라이더 지급은 계속 진행
            db_insert(
                "INSERT INTO withdrawal_requests
                    (rider_id, agency_id, kind, amount, gross_amount, withhold_other,
                     bank_code, bank_account, account_holder,
                     status, fail_reason, note, requested_at)
                 VALUES (?, ?, 'auto_daily', ?, ?, ?, ?, ?, ?, 'failed', ?, ?, NOW())",
                [
                    $riderId, $agencyId, $amount, $balance, $fee,
                    (string) $rider['bank_code'], (string) $rider['bank_account'],
                    (string) ($rider['account_holder'] ?: $rider['name']),
                    mb_substr($res->failReason, 0, 300), '일일정산 지급 실패',
                ]
            );
            throw new RuntimeException($rider['name'] . ': 이체 실패 — ' . $res->failReason);
        }

        db_transaction(static function () use ($riderId, $agencyId, $amount, $balance, $fee, $orderCount, $rider, $adminId, $res): void {
            $reqId = db_insert(
                "INSERT INTO withdrawal_requests
                    (rider_id, agency_id, kind, amount, gross_amount, withhold_other,
                     bank_code, bank_account, account_holder,
                     status, note, requested_at, completed_at)
                 VALUES (?, ?, 'auto_daily', ?, ?, ?, ?, ?, ?, 'completed', ?, NOW(), NOW())",
                [
                    $riderId,
                    $agencyId,
                    $amount,
                    $balance,
                    $fee,
                    (string) $rider['bank_code'],
                    (string) $rider['bank_account'],
                    (string) ($rider['account_holder'] ?: $rider['name']),
                    sprintf('일일정산 지급(원클릭) · 정산수수료 %s원(배달 %d건) · %s', number_format($fee), $orderCount, $res->txId),
                ]
            );

            AgencyWallet::debit($agencyId, $amount, 'rider_payout', $reqId, (string) $rider['name'] . ' 일일정산 지급', $adminId);

            // 수수료를 본사·총판·대리점 몫으로 배분(대리점 몫은 이미 지갑에 있어 이동 없음).
            // 사이클 점유 기록을 만들지 않는 경로라 배달 건수를 직접 넘긴다.
            WithdrawalFeeShare::distribute($reqId, $riderId, $fee, $adminId, $orderCount);

            db_execute('UPDATE rider_wallets SET balance = 0, accrued_days = 0, updated_at = NOW() WHERE rider_id = ?', [$riderId]);
        });

        return ['rider_id' => $riderId, 'amount' => $amount, 'fee' => $fee, 'tx_id' => $res->txId];
    }

    /**
     * §7 #17 — 탈퇴/정지 라이더 잔여 정리(종결).
     *
     * 🔧 2026-07-24 정책 변경: 기존에는 잔여 잔액(보증금 포함)을 **0원 이체로 상각**해
     * 라이더에게 지급하지 않고 소멸시켰다. 보증금은 "만약을 위해 남겨두는 예치금"이므로
     * 정상 종결 시 **라이더에게 실제로 지급**하도록 변경(사용자 확정).
     *
     * 자금 흐름은 payRider와 동일: 대리점 잔액 확인 → 이체 → 성공 시 대리점 잔액 차감 + 라이더 지갑 0.
     * 종결 지급에는 정산수수료를 부과하지 않는다(예치금 반환 성격).
     * 잔액이 0이면 이체 없이 종결 기록만 남긴다(구 동작과 동일).
     *
     * @return array{rider_id:int, paid:int, tx_id:string}
     */
    public static function closeOut(int $riderId, ?int $adminId = null): array
    {
        if ($riderId < 1) {
            throw new InvalidArgumentException('라이더 정보가 없습니다.');
        }
        if (!db_table_exists('withdrawal_requests') || !db_table_exists('rider_wallets') || !AgencyWallet::tableExists()) {
            throw new RuntimeException('지갑/출금 테이블이 없습니다. php migrate.php 를 실행하세요.');
        }

        $rider = db_row(
            'SELECT id, name, status, agency_id, bank_code, bank_account, account_holder
               FROM riders WHERE id = ? LIMIT 1',
            [$riderId]
        );
        if ($rider === null) {
            throw new InvalidArgumentException('라이더를 찾을 수 없습니다.');
        }
        if ((string) $rider['status'] === 'active') {
            throw new InvalidArgumentException('활동 중인 라이더는 종결 대상이 아닙니다.');
        }

        RiderWallet::ensure($riderId);
        $balance  = (int) (db_row('SELECT balance FROM rider_wallets WHERE rider_id = ? LIMIT 1', [$riderId])['balance'] ?? 0);
        $agencyId = (int) ($rider['agency_id'] ?? 0);
        $hasAcct  = trim((string) $rider['bank_code']) !== '' && trim((string) $rider['bank_account']) !== '';

        // 지급할 잔액이 있으면 계좌·대리점 잔액이 반드시 있어야 한다(돈을 말없이 없애지 않음)
        if ($balance > 0) {
            if (!$hasAcct) {
                throw new InvalidArgumentException(
                    $rider['name'] . ': 잔여 ' . number_format($balance) . '원을 지급해야 하는데 출금 계좌가 없습니다. 계좌 등록 후 다시 종결하세요.'
                );
            }
            if ($agencyId < 1) {
                throw new InvalidArgumentException('라이더 소속 대리점이 없어 지급할 수 없습니다.');
            }
            $agencyBalance = AgencyWallet::get($agencyId)['balance'];
            if ($agencyBalance < $balance) {
                throw new InvalidArgumentException(sprintf(
                    '%s: 대리점 잔액 부족(잔액 %s < 지급 %s). PG 충전이 필요합니다.',
                    $rider['name'],
                    number_format($agencyBalance),
                    number_format($balance)
                ));
            }
        }

        require_once __DIR__ . '/Disbursement.php';
        $res = Disbursement::transfer($agencyId, (string) $rider['bank_code'], (string) $rider['bank_account'], $balance);

        if (!$res->success) {
            db_insert(
                "INSERT INTO withdrawal_requests
                    (rider_id, agency_id, kind, amount, gross_amount,
                     bank_code, bank_account, account_holder,
                     status, fail_reason, note, requested_at)
                 VALUES (?, ?, 'auto_daily', ?, ?, ?, ?, ?, 'failed', ?, ?, NOW())",
                [
                    $riderId, $agencyId > 0 ? $agencyId : null, $balance, $balance,
                    (string) $rider['bank_code'], (string) $rider['bank_account'],
                    (string) ($rider['account_holder'] ?: $rider['name']),
                    mb_substr($res->failReason, 0, 300), '탈퇴/정지 잔여 지급 실패',
                ]
            );
            throw new RuntimeException($rider['name'] . ': 이체 실패 — ' . $res->failReason);
        }

        db_transaction(static function () use ($riderId, $agencyId, $balance, $rider, $res, $adminId): void {
            $reqId = db_insert(
                "INSERT INTO withdrawal_requests
                    (rider_id, agency_id, kind, amount, gross_amount,
                     bank_code, bank_account, account_holder,
                     status, note, requested_at, completed_at)
                 VALUES (?, ?, 'auto_daily', ?, ?, ?, ?, ?, 'completed', ?, NOW(), NOW())",
                [
                    $riderId,
                    $agencyId > 0 ? $agencyId : null,
                    $balance,
                    $balance,
                    (string) $rider['bank_code'],
                    (string) $rider['bank_account'],
                    (string) ($rider['account_holder'] ?: $rider['name']),
                    ($balance > 0 ? '탈퇴/정지 잔여 지급 종결 · ' : '탈퇴/정지 종결(잔액 0) · ') . $res->txId,
                ]
            );

            if ($balance > 0 && $agencyId > 0) {
                AgencyWallet::debit($agencyId, $balance, 'rider_payout', $reqId, (string) $rider['name'] . ' 탈퇴 잔여 지급', $adminId);
            }

            db_execute('UPDATE rider_wallets SET balance = 0, accrued_days = 0, updated_at = NOW() WHERE rider_id = ?', [$riderId]);
        });

        return ['rider_id' => $riderId, 'paid' => $balance, 'tx_id' => $res->txId];
    }

    /** @deprecated 2026-07-24 — closeOut()으로 대체(상각 → 실지급). 호출 호환용 별칭. */
    public static function zeroClose(int $riderId, ?int $adminId = null): array
    {
        return self::closeOut($riderId, $adminId);
    }

    /**
     * 여러 라이더 일괄 지급. 개별 실패는 건너뛰고 결과를 모은다(부분 성공 허용).
     *
     * @param list<int> $riderIds
     * @return array{paid:int, total_amount:int, failed:list<string>}
     */
    public static function payBatch(array $riderIds, ?int $adminId = null): array
    {
        $paid = 0;
        $total = 0;
        $feeTotal = 0;
        $failed = [];
        foreach (array_values(array_unique(array_filter($riderIds, static fn ($i): bool => (int) $i > 0))) as $rid) {
            try {
                $r = self::payRider((int) $rid, $adminId);
                $paid++;
                $total += (int) $r['amount'];
                $feeTotal += (int) ($r['fee'] ?? 0);
            } catch (Throwable $e) {
                $failed[] = $e->getMessage();
            }
        }

        return ['paid' => $paid, 'total_amount' => $total, 'fee_amount' => $feeTotal, 'failed' => $failed];
    }
}
