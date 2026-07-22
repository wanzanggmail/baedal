<?php

declare(strict_types=1);

require_once __DIR__ . '/AgencyWallet.php';
require_once __DIR__ . '/RiderWallet.php';

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
            "SELECT r.id, r.rider_code, r.name, r.agency_id, r.bank_code, r.bank_account,
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

            return [
                'rider_id'     => (int) $r['id'],
                'rider_code'   => (string) $r['rider_code'],
                'name'         => (string) $r['name'],
                'agency_id'    => $aid,
                'agency_name'  => (string) ($r['agency_name'] ?? ''),
                'balance'      => (int) $r['balance'],
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
        $amount = (int) (db_row('SELECT balance FROM rider_wallets WHERE rider_id = ? LIMIT 1', [$riderId])['balance'] ?? 0);
        if ($amount <= 0) {
            throw new InvalidArgumentException($rider['name'] . ': 지급할 잔액이 없습니다.');
        }

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
                    (rider_id, agency_id, kind, amount, gross_amount,
                     bank_code, bank_account, account_holder,
                     status, fail_reason, note, requested_at)
                 VALUES (?, ?, 'auto_daily', ?, ?, ?, ?, ?, 'failed', ?, ?, NOW())",
                [
                    $riderId, $agencyId, $amount, $amount,
                    (string) $rider['bank_code'], (string) $rider['bank_account'],
                    (string) ($rider['account_holder'] ?: $rider['name']),
                    mb_substr($res->failReason, 0, 300), '일일정산 지급 실패',
                ]
            );
            throw new RuntimeException($rider['name'] . ': 이체 실패 — ' . $res->failReason);
        }

        db_transaction(static function () use ($riderId, $agencyId, $amount, $rider, $adminId, $res): void {
            $reqId = db_insert(
                "INSERT INTO withdrawal_requests
                    (rider_id, agency_id, kind, amount, gross_amount,
                     bank_code, bank_account, account_holder,
                     status, note, requested_at, completed_at)
                 VALUES (?, ?, 'auto_daily', ?, ?, ?, ?, ?, 'completed', ?, NOW(), NOW())",
                [
                    $riderId,
                    $agencyId,
                    $amount,
                    $amount,
                    (string) $rider['bank_code'],
                    (string) $rider['bank_account'],
                    (string) ($rider['account_holder'] ?: $rider['name']),
                    '일일정산 지급(원클릭) · ' . $res->txId,
                ]
            );

            AgencyWallet::debit($agencyId, $amount, 'rider_payout', $reqId, (string) $rider['name'] . ' 일일정산 지급', $adminId);

            db_execute('UPDATE rider_wallets SET balance = 0, accrued_days = 0, updated_at = NOW() WHERE rider_id = ?', [$riderId]);
        });

        return ['rider_id' => $riderId, 'amount' => $amount, 'tx_id' => $res->txId];
    }

    /**
     * §7 #17 — 탈퇴/정지 라이더 잔여 잔액 0원 이체 종결.
     * 실제 송금 없이(0원 이체) 워크플로우를 종결하고 잔여 잔액을 정리한다.
     *
     * @return array{rider_id:int, written_off:int}
     */
    public static function zeroClose(int $riderId, ?int $adminId = null): array
    {
        if ($riderId < 1) {
            throw new InvalidArgumentException('라이더 정보가 없습니다.');
        }
        $rider = db_row(
            'SELECT id, name, status, agency_id, bank_code, bank_account, account_holder
               FROM riders WHERE id = ? LIMIT 1',
            [$riderId]
        );
        if ($rider === null) {
            throw new InvalidArgumentException('라이더를 찾을 수 없습니다.');
        }
        if (in_array((string) $rider['status'], ['active'], true)) {
            throw new InvalidArgumentException('활동 중인 라이더는 0원 종결 대상이 아닙니다.');
        }

        RiderWallet::ensure($riderId);
        $balance  = (int) (db_row('SELECT balance FROM rider_wallets WHERE rider_id = ? LIMIT 1', [$riderId])['balance'] ?? 0);
        $agencyId = (int) ($rider['agency_id'] ?? 0);

        // 0원 이체 처리(mock은 amount<=0을 성공 처리)
        require_once __DIR__ . '/Disbursement.php';
        $res = Disbursement::transfer($agencyId, (string) $rider['bank_code'], (string) $rider['bank_account'], 0);

        db_transaction(static function () use ($riderId, $agencyId, $balance, $rider, $res): void {
            db_insert(
                "INSERT INTO withdrawal_requests
                    (rider_id, agency_id, kind, amount, gross_amount,
                     bank_code, bank_account, account_holder,
                     status, note, requested_at, completed_at)
                 VALUES (?, ?, 'auto_daily', 0, ?, ?, ?, ?, 'completed', ?, NOW(), NOW())",
                [
                    $riderId,
                    $agencyId > 0 ? $agencyId : null,
                    $balance,
                    (string) $rider['bank_code'],
                    (string) $rider['bank_account'],
                    (string) ($rider['account_holder'] ?: $rider['name']),
                    '탈퇴/정지 0원 종결 · ' . $res->txId,
                ]
            );
            db_execute('UPDATE rider_wallets SET balance = 0, accrued_days = 0, updated_at = NOW() WHERE rider_id = ?', [$riderId]);
        });

        return ['rider_id' => $riderId, 'written_off' => $balance];
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
        $failed = [];
        foreach (array_values(array_unique(array_filter($riderIds, static fn ($i): bool => (int) $i > 0))) as $rid) {
            try {
                $r = self::payRider((int) $rid, $adminId);
                $paid++;
                $total += (int) $r['amount'];
            } catch (Throwable $e) {
                $failed[] = $e->getMessage();
            }
        }

        return ['paid' => $paid, 'total_amount' => $total, 'failed' => $failed];
    }
}
