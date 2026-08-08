<?php

declare(strict_types=1);

require_once __DIR__ . '/PgGateway.php';
require_once __DIR__ . '/AgencyCard.php';
require_once __DIR__ . '/AgencyWallet.php';
require_once __DIR__ . '/PgFeeConfig.php';

/**
 * PG 카드결제 실행 (LOGIC §5.4 · §7 #8).
 *
 * 정산 반영 후, 대리점이 라이더에게 지급할 자금을 카드로 조달(FUND)한다.
 * - 결제 단위: 라이더별 건건히(총액 일괄 아님).
 * - 카드 청구액 = 라이더 net + 플랫폼 수수료(PgFeeConfig).
 * - 우선순위 1번 카드부터 시도 → 한도초과 등 실패 시 다음 카드로 자동 재시도.
 * - 성공: agency_wallets.balance 에 net 충전 + pg_payments(success) 기록.
 * - 전 카드 실패: pg_payments(failed) 기록(알림/재시도는 상위에서).
 */
final class PgPayment
{
    public static function tableExists(): bool
    {
        return db_table_exists('pg_payments');
    }

    /**
     * 라이더 1명분 PG 결제(자금 조달).
     *
     * @return array{success:bool, pg_id:int, net:int, fee:int, total:int, tid:string, fail_reason:string, attempts:int, card_id:?int}
     */
    public static function chargeForRider(int $agencyId, ?int $riderId, int $netAmount, ?int $uploadId = null, ?int $adminId = null): array
    {
        if ($agencyId < 1 || $netAmount <= 0) {
            throw new InvalidArgumentException('결제 대상/금액이 올바르지 않습니다.');
        }
        if (!db_table_exists('pg_payments')) {
            throw new RuntimeException('pg_payments 테이블이 없습니다. php migrate.php 를 실행하세요.');
        }

        $fee   = PgFeeConfig::feeAmount($netAmount, $agencyId);
        $total = $netAmount + $fee;

        $cards = AgencyCard::activeForAgency($agencyId);
        if ($cards === []) {
            $pgId = self::record($agencyId, $riderId, $uploadId, null, $netAmount, $fee, $total, 'failed', '', '등록된 카드가 없습니다.', 0, $adminId);

            return self::result(false, $pgId, $netAmount, $fee, $total, '', '등록된 카드가 없습니다.', 0, null);
        }

        $gateway  = PgGatewayFactory::make();
        $attempts = 0;
        $lastFail = '';

        foreach ($cards as $card) {
            $attempts++;
            $res = $gateway->charge((string) $card['billing_key'], $total, ['mock_limit' => (int) ($card['mock_limit'] ?? 0)]);
            if ($res->success) {
                $cardId = (int) $card['id'];
                $pgId = db_transaction(static function () use ($agencyId, $riderId, $uploadId, $cardId, $netAmount, $fee, $total, $res, $attempts, $adminId): int {
                    $id = self::record($agencyId, $riderId, $uploadId, $cardId, $netAmount, $fee, $total, 'success', $res->tid, '', $attempts, $adminId);
                    // 조달된 자금(net)을 대리점 잔액에 충전
                    AgencyWallet::credit($agencyId, $netAmount, 'pg_fund', $id, 'PG 카드결제 충전', $adminId);
                    // 영업대행수수료(카드 청구액 − net)를 본사·총판·대리점 지갑에 배분 적립
                    self::creditFeeSplit($agencyId, $fee, $id, $adminId);

                    return $id;
                });

                return self::result(true, $pgId, $netAmount, $fee, $total, $res->tid, '', $attempts, $cardId);
            }
            $lastFail = $res->failReason;
            // 재시도 불가(비한도성) 실패면 다음 카드로 넘어가되 사유 기록 유지
        }

        // 전 카드 실패
        $pgId = self::record($agencyId, $riderId, $uploadId, null, $netAmount, $fee, $total, 'failed', '', $lastFail, $attempts, $adminId);

        return self::result(false, $pgId, $netAmount, $fee, $total, '', $lastFail, $attempts, null);
    }

    /**
     * 정산 반영된 업로드의 미충전 라이더들을 라이더별 건건히 결제(자금 조달).
     *
     * @return array{charged:int, funded:int, failed:list<string>}
     */
    public static function fundAppliedUpload(int $uploadId, int $agencyId, ?int $adminId = null): array
    {
        $charged = 0;
        $funded  = 0;
        $failed  = [];

        $cycles = db_rows(
            'SELECT c.rider_id, c.net_amount, r.name
               FROM settlement_rider_cycles c
               INNER JOIN riders r ON r.id = c.rider_id
              WHERE c.upload_id = ? AND c.net_amount > 0
                AND r.agency_id = ?
                AND NOT EXISTS (
                    SELECT 1 FROM pg_payments p
                     WHERE p.upload_id = c.upload_id AND p.rider_id = c.rider_id AND p.status = \'success\'
                )',
            [$uploadId, $agencyId]
        );

        foreach ($cycles as $c) {
            try {
                $r = self::chargeForRider($agencyId, (int) $c['rider_id'], (int) $c['net_amount'], $uploadId, $adminId);
                $charged++;
                if ($r['success']) {
                    $funded += (int) $r['net'];
                } else {
                    $failed[] = (string) $c['name'] . ': ' . $r['fail_reason'];
                }
            } catch (Throwable $e) {
                $failed[] = (string) $c['name'] . ': ' . $e->getMessage();
            }
        }

        return ['charged' => $charged, 'funded' => $funded, 'failed' => $failed];
    }

    /**
     * 조회 범위(Org 스코프) 내 플랫폼 수수료 내역 — 「플랫폼 수수료 내역」 화면용.
     *
     * @param array{from?:string, to?:string, agency_id?:int, status?:string, limit?:int} $filters
     * @return list<array<string,mixed>>
     */
    public static function listScoped(array $filters = []): array
    {
        if (!db_table_exists('pg_payments')) {
            return [];
        }
        [$sql, $params] = self::buildScopedWhere($filters);
        $limit = max(1, min(500, (int) ($filters['limit'] ?? 200)));

        return db_rows(
            "SELECT p.*, r.name AS rider_name, o.name AS agency_name, o.code AS agency_code, c.alias AS card_alias
               FROM pg_payments p
               LEFT JOIN riders r ON r.id = p.rider_id
               LEFT JOIN organizations o ON o.id = p.agency_id
               LEFT JOIN agency_cards c ON c.id = p.card_id
              {$sql}
              ORDER BY p.id DESC
              LIMIT {$limit}",
            $params
        );
    }

    /**
     * 조회 범위 내 합계 — 필터 전체 대상(표시 상한과 무관).
     *
     * @param array{from?:string, to?:string, agency_id?:int, status?:string} $filters
     * @return array{count:int, success_count:int, net:int, fee:int, hq:int, distributor:int, agency:int}
     */
    public static function sumScoped(array $filters = []): array
    {
        $zero = ['count' => 0, 'success_count' => 0, 'net' => 0, 'fee' => 0, 'hq' => 0, 'distributor' => 0, 'agency' => 0];
        if (!db_table_exists('pg_payments')) {
            return $zero;
        }
        [$sql, $params] = self::buildScopedWhere($filters);
        $row = db_row(
            "SELECT COUNT(*) cnt,
                    SUM(status = 'success') ok_cnt,
                    COALESCE(SUM(CASE WHEN status = 'success' THEN net_amount ELSE 0 END), 0) net,
                    COALESCE(SUM(CASE WHEN status = 'success' THEN service_fee ELSE 0 END), 0) fee,
                    COALESCE(SUM(CASE WHEN status = 'success' THEN hq_amount ELSE 0 END), 0) hq,
                    COALESCE(SUM(CASE WHEN status = 'success' THEN distributor_amount ELSE 0 END), 0) dist,
                    COALESCE(SUM(CASE WHEN status = 'success' THEN agency_amount ELSE 0 END), 0) agy
               FROM pg_payments p
              {$sql}",
            $params
        );
        if ($row === null) {
            return $zero;
        }

        return [
            'count'         => (int) $row['cnt'],
            'success_count' => (int) $row['ok_cnt'],
            'net'           => (int) $row['net'],
            'fee'           => (int) $row['fee'],
            'hq'            => (int) $row['hq'],
            'distributor'   => (int) $row['dist'],
            'agency'        => (int) $row['agy'],
        ];
    }

    /**
     * @param array{from?:string, to?:string, agency_id?:int, status?:string} $filters
     * @return array{0:string, 1:list<mixed>}
     */
    private static function buildScopedWhere(array $filters): array
    {
        require_once __DIR__ . '/Org.php';
        [$scope, $params] = Org::agencyScopeClause('p.agency_id');
        $conds = $scope !== '' ? [$scope] : [];

        $from = trim((string) ($filters['from'] ?? ''));
        if ($from !== '') {
            $conds[] = 'p.created_at >= ?';
            $params[] = $from . ' 00:00:00';
        }
        $to = trim((string) ($filters['to'] ?? ''));
        if ($to !== '') {
            $conds[] = 'p.created_at <= ?';
            $params[] = $to . ' 23:59:59';
        }
        $agencyId = (int) ($filters['agency_id'] ?? 0);
        if ($agencyId > 0) {
            $conds[] = 'p.agency_id = ?';
            $params[] = $agencyId;
        }
        $status = trim((string) ($filters['status'] ?? ''));
        if (in_array($status, ['success', 'failed'], true)) {
            $conds[] = 'p.status = ?';
            $params[] = $status;
        }

        $sql = $conds !== [] ? 'WHERE ' . implode(' AND ', $conds) : '';

        return [$sql, $params];
    }

    /** @return list<array<string,mixed>> */
    public static function listForAgency(int $agencyId, int $limit = 100): array
    {
        if (!db_table_exists('pg_payments')) {
            return [];
        }
        $limit = max(1, min(500, $limit));

        return db_rows(
            'SELECT p.*, r.name AS rider_name, c.alias AS card_alias
               FROM pg_payments p
               LEFT JOIN riders r ON r.id = p.rider_id
               LEFT JOIN agency_cards c ON c.id = p.card_id
              WHERE p.agency_id = ?
              ORDER BY p.id DESC LIMIT ' . $limit,
            [$agencyId]
        );
    }

    /**
     * 영업대행수수료 3분할 금액 — 요율(%)을 금액으로 환산한다.
     * 반올림 잔차는 대리점 몫이 흡수해 **세 금액의 합이 정확히 fee와 같다**(자금 증발/증가 없음).
     *
     * @return array{hq:int, distributor:int, agency:int, pct:array{hq:float, distributor:float, agency:float, total:float}}
     */
    public static function feeSplit(int $fee, int $agencyId): array
    {
        $bd   = PgFeeConfig::breakdownForAgency($agencyId);
        $hq   = $bd['total'] > 0 ? (int) round($fee * $bd['hq'] / $bd['total']) : 0;
        $dist = $bd['total'] > 0 ? (int) round($fee * $bd['distributor'] / $bd['total']) : 0;

        return [
            'hq'          => $hq,
            'distributor' => $dist,
            'agency'      => $fee - $hq - $dist,
            'pct'         => $bd,
        ];
    }

    /**
     * 영업대행수수료를 본사·총판·대리점 지갑에 각각 적립한다(결제 성공 시).
     *
     * ⚠️ 리스 수수료 배분과 **자금 출처가 다르다** — 리스료는 라이더 정산에서 걷어 이미 대리점
     * 지갑에 남아 있는 돈이라 "대리점 → 상위" 이동이었지만, 영업대행수수료는 **대리점이 카드로
     * 결제한 금액 중 net을 넘는 부분**이라 어느 지갑에도 없던 돈이다. 따라서 어디서도 빼지 않고
     * 세 조직에 각각 credit 한다(합계 = service_fee).
     *
     * 총판이 없는(본사 직속) 대리점이면 총판 몫은 갈 곳이 없으므로 **본사로 합친다** — 돈이
     * 증발하지 않게 하되 어디로 갔는지 원장 메모에 남긴다.
     */
    private static function creditFeeSplit(int $agencyId, int $fee, int $pgId, ?int $adminId): void
    {
        if ($fee <= 0) {
            return;
        }
        require_once __DIR__ . '/Org.php';

        $sp    = self::feeSplit($fee, $agencyId);
        $chain = Org::chainForAgency($agencyId);

        $hq   = (int) $sp['hq'];
        $dist = (int) $sp['distributor'];
        $agy  = (int) $sp['agency'];
        $note = 'PG 영업대행수수료 배분';

        // 받을 조직이 없는 몫은 본사로 흡수(본사는 항상 존재)
        $foldedNote = '';
        if ($dist > 0 && $chain['distributor'] < 1) {
            $hq  += $dist;
            $dist = 0;
            $foldedNote = ' · 총판 없음(본사 직속)이라 총판 몫을 본사에 합산';
        }

        if ($hq > 0 && $chain['hq'] > 0) {
            AgencyWallet::credit($chain['hq'], $hq, 'pg_fee_in', $pgId, $note . ' · 본사 몫' . $foldedNote, $adminId);
        }
        if ($dist > 0 && $chain['distributor'] > 0) {
            AgencyWallet::credit($chain['distributor'], $dist, 'pg_fee_in', $pgId, $note . ' · 총판 몫', $adminId);
        }
        if ($agy > 0 && $chain['agency'] > 0) {
            AgencyWallet::credit($chain['agency'], $agy, 'pg_fee_in', $pgId, $note . ' · 대리점 몫', $adminId);
        }
    }

    private static function record(int $agencyId, ?int $riderId, ?int $uploadId, ?int $cardId, int $net, int $fee, int $total, string $status, string $tid, string $failReason, int $attempts, ?int $adminId): int
    {
        // 결제 시점 본사/총판/대리점 분배를 스냅샷으로 남긴다 — 나중에 org_fee_config
        // 요율이 바뀌어도 이 건의 실제 분배 내역은 그대로 보존된다.
        $sp         = self::feeSplit($fee, $agencyId);
        $bd         = $sp['pct'];
        $hqAmount   = (int) $sp['hq'];
        $distAmount = (int) $sp['distributor'];
        $agyAmount  = (int) $sp['agency'];

        $cols = array_column(db_rows('SHOW COLUMNS FROM pg_payments'), 'Field');
        $hasSplit = in_array('hq_amount', $cols, true);

        if ($hasSplit) {
            return db_insert(
                'INSERT INTO pg_payments
                    (agency_id, rider_id, upload_id, card_id, net_amount, service_fee, total_charged, status, pg_tid, fail_reason, attempts, created_by,
                     hq_pct, distributor_pct, agency_pct, hq_amount, distributor_amount, agency_amount)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $agencyId,
                    ($riderId !== null && $riderId > 0) ? $riderId : null,
                    ($uploadId !== null && $uploadId > 0) ? $uploadId : null,
                    ($cardId !== null && $cardId > 0) ? $cardId : null,
                    $net, $fee, $total, $status, $tid, mb_substr($failReason, 0, 300), max(1, $attempts),
                    ($adminId !== null && $adminId > 0) ? $adminId : null,
                    $bd['hq'], $bd['distributor'], $bd['agency'], $hqAmount, $distAmount, $agyAmount,
                ]
            );
        }

        return db_insert(
            'INSERT INTO pg_payments
                (agency_id, rider_id, upload_id, card_id, net_amount, service_fee, total_charged, status, pg_tid, fail_reason, attempts, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $agencyId,
                ($riderId !== null && $riderId > 0) ? $riderId : null,
                ($uploadId !== null && $uploadId > 0) ? $uploadId : null,
                ($cardId !== null && $cardId > 0) ? $cardId : null,
                $net, $fee, $total, $status, $tid, mb_substr($failReason, 0, 300), max(1, $attempts),
                ($adminId !== null && $adminId > 0) ? $adminId : null,
            ]
        );
    }

    /** @return array{success:bool, pg_id:int, net:int, fee:int, total:int, tid:string, fail_reason:string, attempts:int, card_id:?int} */
    private static function result(bool $ok, int $pgId, int $net, int $fee, int $total, string $tid, string $failReason, int $attempts, ?int $cardId): array
    {
        return [
            'success'     => $ok,
            'pg_id'       => $pgId,
            'net'         => $net,
            'fee'         => $fee,
            'total'       => $total,
            'tid'         => $tid,
            'fail_reason' => $failReason,
            'attempts'    => $attempts,
            'card_id'     => $cardId,
        ];
    }
}
