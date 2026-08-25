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
 * - 카드 청구액 = 부가세 제외 정산액(지원금 포함) + 플랫폼 수수료(PgFeeConfig).
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

        // 주문번호는 **결제 전에** 채번한다 — 위루트가 요청 시점에 요구하는데 pg_payments.id는
        // 결제가 성공해야 생기기 때문(순서 역전). 웹훅·대사·취소에서 우리 레코드를 찾는 키다.
        $ordNum = self::makeOrderNo($agencyId);

        $cards = AgencyCard::activeForAgency($agencyId);
        if ($cards === []) {
            $pgId = self::record($agencyId, $riderId, $uploadId, null, $netAmount, $fee, $total, 'failed', '', '등록된 카드가 없습니다.', 0, $adminId, $ordNum);

            return self::result(false, $pgId, $netAmount, $fee, $total, '', '등록된 카드가 없습니다.', 0, null);
        }

        $gateway  = PgGatewayFactory::make();
        $attempts = 0;
        $lastFail = '';
        $rider    = $riderId !== null && $riderId > 0
            ? db_row('SELECT name, phone FROM riders WHERE id = ? LIMIT 1', [$riderId])
            : null;

        foreach ($cards as $card) {
            $attempts++;
            // 카드를 바꿔 재시도할 때마다 주문번호도 새로 딴다 — 같은 ord_num으로 두 번 승인
            // 요청이 나가면 PG 쪽에서 중복 주문으로 막히거나 대사가 꼬인다.
            $tryOrdNum = $attempts === 1 ? $ordNum : self::makeOrderNo($agencyId);
            $res = $gateway->charge(new PgChargeRequest(
                billingKey: (string) $card['billing_key'],
                amount: $total,
                orderNo: $tryOrdNum,
                buyerName: (string) ($rider['name'] ?? ''),
                buyerPhone: preg_replace('/\D/', '', (string) ($rider['phone'] ?? '')) ?? '',
                itemName: '라이더 정산금 조달',
                installment: 0,
                meta: ['mock_limit' => (int) ($card['mock_limit'] ?? 0)],
            ));
            if ($res->success) {
                $cardId = (int) $card['id'];
                $pgId = db_transaction(static function () use ($agencyId, $riderId, $uploadId, $cardId, $netAmount, $fee, $total, $res, $attempts, $adminId, $tryOrdNum): int {
                    $id = self::record($agencyId, $riderId, $uploadId, $cardId, $netAmount, $fee, $total, 'success', $res->tid, '', $attempts, $adminId, $tryOrdNum);
                    // 조달된 자금(net)을 대리점 잔액에 충전. 카드에는 net+수수료가 청구되지만
                    // 지갑에 들어가는 건 **수수료를 제외한 순액**이다(2026-08-12 갑 확정 ②).
                    AgencyWallet::credit($agencyId, $netAmount, 'pg_fund', $id, 'PG 카드결제 충전', $adminId);
                    // ⚠️ 플랫폼 수수료는 **지갑에 적립하지 않는다**(2026-08-12 갑 확정 ③).
                    // 본사가 대리점·총판에 계산서를 발행해 시스템 밖에서 정산하므로, 시스템은
                    // 「플랫폼 수수료 내역」(settlement/platform-fee) 조회용 기록만 남긴다.
                    // 분배 금액은 self::record()가 pg_payments의 hq/distributor/agency_amount
                    // 스냅샷 컬럼에 이미 저장한다.

                    return $id;
                });

                return self::result(true, $pgId, $netAmount, $fee, $total, $res->tid, '', $attempts, $cardId);
            }
            $lastFail = $res->failReason;

            // ⚠️ 예전엔 **어떤 실패든 무조건** 다음 카드로 넘어갔다(isRetriable()은 정의만 되고
            //    아무도 안 썼다). 실 게이트웨이에선 두 가지가 위험하다.
            //      · TIMEOUT — 망상취소를 보냈어도 승인 여부가 불확실한데 다음 카드까지 긁으면
            //        이중 조달이 된다.
            //      · 가맹점 오류 — 카드를 바꿔도 같은 결과라 등록 카드 수만큼 PG만 두드린다.
            //    그래서 이런 실패는 폴백하지 않고 즉시 멈춘다.
            if ($res->isFatal()) {
                break;
            }
        }

        // 전 카드 실패
        $pgId = self::record($agencyId, $riderId, $uploadId, null, $netAmount, $fee, $total, 'failed', '', $lastFail, $attempts, $adminId, $ordNum);

        return self::result(false, $pgId, $netAmount, $fee, $total, '', $lastFail, $attempts, null);
    }

    /**
     * 승인 취소 — PG 취소 후 지갑을 되돌린다.
     *
     * 정산이 D+1 이라 **당일 취소는 PG 가 받아준다**(갑 확인). 정산이 넘어간 건은 PG 가 거절하고,
     * 그 사유를 그대로 올린다 — 우리가 임의로 성공 처리하면 카드에는 청구가 남은 채 지갑만 줄어든다.
     *
     * 순서가 중요하다: **PG 취소가 성공해야만** 지갑을 되돌린다. 반대로 하면 PG 거절 시
     * 지갑만 줄어든 상태가 된다.
     *
     * ⚠️ 지갑이 음수가 될 수 있다 — 조달한 돈을 이미 라이더에게 지급했으면 되돌릴 재원이 없다.
     *    그래도 차감한다. 카드에서는 실제로 돈이 빠졌으므로 **음수가 곧 "정산이 어긋났다"는 신호**이고,
     *    숨기면 나중에 원인을 못 찾는다(리스 수수료·주정산 출금과 같은 판단).
     *
     * @return array{ok:bool, message:string, balance_after?:int}
     */
    public static function cancel(int $paymentId, string $reason, ?int $adminId = null): array
    {
        require_once __DIR__ . '/PgGateway.php';
        require_once __DIR__ . '/AuditLog.php';

        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('취소 사유를 입력하세요.');
        }
        if (!db_table_exists('pg_payments')) {
            throw new RuntimeException('pg_payments 테이블이 없습니다. php migrate.php 를 실행하세요.');
        }

        $row = db_row('SELECT * FROM pg_payments WHERE id = ? LIMIT 1', [$paymentId]);
        if ($row === null) {
            throw new InvalidArgumentException('결제 건을 찾을 수 없습니다.');
        }
        $status = (string) $row['status'];
        if ($status === 'canceled') {
            return ['ok' => false, 'message' => '이미 취소된 결제입니다.'];
        }
        if ($status !== 'success') {
            return ['ok' => false, 'message' => '승인 성공 건만 취소할 수 있습니다. (현재: ' . $status . ')'];
        }
        $trxId = trim((string) $row['pg_tid']);
        if ($trxId === '') {
            return ['ok' => false, 'message' => 'PG 거래번호가 없어 취소를 요청할 수 없습니다.'];
        }

        $agencyId = (int) $row['agency_id'];
        $net      = (int) $row['net_amount'];
        $total    = (int) $row['total_charged'];

        // ① PG 취소 — 청구 총액(수수료 포함) 기준이다. 지갑에 들어간 건 net 이지만
        //    카드에 청구된 건 total 이므로 되돌릴 금액도 total 이다.
        $res = PgGatewayFactory::make()->cancel($trxId, $total);
        if (!$res->success) {
            AuditLog::record('PG_CANCEL_FAIL', 'pg_payments', sprintf(
                '#%d 취소 실패 · %s원 · %s',
                $paymentId,
                number_format($total),
                $res->failReason
            ));

            return ['ok' => false, 'message' => 'PG 취소 실패: ' . $res->failReason];
        }

        // ② 우리 기록 되돌리기 — 여기부터는 한 트랜잭션으로 묶는다.
        $balanceAfter = 0;
        db_transaction(static function () use ($paymentId, $agencyId, $net, $reason, $adminId, $res, &$balanceAfter): void {
            db_execute(
                'UPDATE pg_payments
                    SET status = ?, canceled_at = NOW(), cancel_reason = ?, canceled_by = ?, cancel_trx_id = ?
                  WHERE id = ? AND status = ?',
                ['canceled', mb_substr($reason, 0, 300), ($adminId !== null && $adminId > 0) ? $adminId : null,
                 mb_substr($res->cancelTrxId, 0, 80), $paymentId, 'success']
            );
            // 충전할 때 net 을 넣었으므로 되돌릴 때도 net 이다(수수료는 애초에 지갑에 안 들어갔다).
            AgencyWallet::debit($agencyId, $net, 'pg_fund_rev', $paymentId, 'PG 결제 취소 · ' . $reason, $adminId);
            $balanceAfter = (int) AgencyWallet::get($agencyId)['balance'];
        });

        AuditLog::record('PG_CANCEL', 'pg_payments', sprintf(
            '#%d 취소 · 청구 %s원 회수 · 지갑 −%s원 · 잔액 %s · 사유: %s',
            $paymentId,
            number_format($total),
            number_format($net),
            number_format($balanceAfter),
            $reason
        ));

        return [
            'ok'            => true,
            'message'       => sprintf('취소했습니다. 카드 %s원 취소 · 지갑 %s원 회수', number_format($total), number_format($net)),
            'balance_after' => $balanceAfter,
        ];
    }

    /**
     * PG 주문번호 채번 — 위루트 `ord_num` 최대 **30 byte** 제약을 지킨다.
     *
     * 형식: `PG{agency}-{YmdHis}-{rand4}`  (예: PG13-20260815093012-A1B2 = 24자)
     * 같은 초에 여러 건이 나갈 수 있어 난수를 붙이고, 그래도 겹치면 다시 뽑는다.
     */
    public static function makeOrderNo(int $agencyId): string
    {
        for ($i = 0; $i < 5; $i++) {
            $no = sprintf('PG%d-%s-%s', $agencyId, date('YmdHis'), strtoupper(bin2hex(random_bytes(2))));
            $no = substr($no, 0, 30);
            if (!db_table_exists('pg_payments')) {
                return $no;
            }
            $dup = db_row('SELECT id FROM pg_payments WHERE ord_num = ? LIMIT 1', [$no]);
            if ($dup === null) {
                return $no;
            }
        }

        // 여기까지 오면 난수가 5번 연속 겹친 것 — 사실상 없지만 조용히 중복을 쓰진 않는다.
        return substr(sprintf('PG%d-%s-%s', $agencyId, date('YmdHis'), strtoupper(bin2hex(random_bytes(5)))), 0, 30);
    }

    /**
     * 정산 반영된 업로드의 미충전 라이더들을 라이더별 건건히 결제(자금 조달).
     *
     * 정산 반영(`SettlementLedger::applyUpload`) 직후 호출된다 — 플랫폼 수수료는 이 결제에서
     * 발생하므로, 정산이 반영되는 시점에 곧바로 잡혀야 「플랫폼 수수료 내역」에 나타난다.
     *
     * 재실행해도 안전하다 — 이미 `success`로 결제된 (upload_id, rider_id)는 조회에서 빠지므로
     * 재반영 시 이중 청구가 되지 않는다.
     *
     * @return array{charged:int, funded:int, fee:int, failed:list<string>, skipped_reason:string}
     */
    public static function fundAppliedUpload(int $uploadId, int $agencyId, ?int $adminId = null): array
    {
        $charged = 0;
        $funded  = 0;
        $feeSum  = 0;
        $failed  = [];

        if (!self::tableExists()) {
            return ['charged' => 0, 'funded' => 0, 'fee' => 0, 'failed' => [], 'skipped_reason' => 'pg_payments 테이블이 없습니다. php migrate.php 를 실행하세요.'];
        }

        // 카드가 없으면 라이더 수만큼 "카드 없음" 실패 기록이 쌓이므로, 여기서 한 번에 막고
        // 사유만 돌려준다(관리자에겐 실패 25건보다 "카드를 등록하세요" 한 줄이 훨씬 유용하다).
        if (AgencyCard::activeForAgency($agencyId) === []) {
            return ['charged' => 0, 'funded' => 0, 'fee' => 0, 'failed' => [], 'skipped_reason' => '등록된 결제 카드가 없어 자금 조달을 건너뛰었습니다. 「결제수단 관리」에서 카드를 등록하세요.'];
        }

        $cycles = db_rows(
            'SELECT c.rider_id, c.gross_amount, c.support_amount, r.name
               FROM settlement_rider_cycles c
               INNER JOIN riders r ON r.id = c.rider_id
              WHERE c.upload_id = ?
                AND (c.gross_amount + c.support_amount) > 0
                AND r.agency_id = ?
                AND NOT EXISTS (
                    SELECT 1 FROM pg_payments p
                     WHERE p.upload_id = c.upload_id AND p.rider_id = c.rider_id AND p.status = \'success\'
                )',
            [$uploadId, $agencyId]
        );

        foreach ($cycles as $c) {
            $fund = (int) $c['gross_amount'] + (int) $c['support_amount'];
            try {
                $r = self::chargeForRider($agencyId, (int) $c['rider_id'], $fund, $uploadId, $adminId);
                $charged++;
                if ($r['success']) {
                    $funded += (int) $r['net'];
                    $feeSum += (int) $r['fee'];
                } else {
                    $failed[] = (string) $c['name'] . ': ' . $r['fail_reason'];
                }
            } catch (Throwable $e) {
                $failed[] = (string) $c['name'] . ': ' . $e->getMessage();
            }
        }

        return ['charged' => $charged, 'funded' => $funded, 'fee' => $feeSum, 'failed' => $failed, 'skipped_reason' => ''];
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

    /*
     * 🗑️ creditFeeSplit() 제거 — 2026-08-12 갑 확정 ③.
     *
     * 예전에는 결제 성공 시 플랫폼 수수료를 본사·총판·대리점 지갑에 `pg_fee_in`으로 적립했다.
     * 이제는 **지갑에 넣지 않는다** — 본사가 대리점·총판에 계산서를 발행해 시스템 밖에서
     * 정산하기 때문이다. 시스템의 역할은 "누가 얼마를 발생시켰는지"를 기간별로 보여주는 것뿐.
     *
     * 분배 금액 자체는 record()가 pg_payments의 hq_amount/distributor_amount/agency_amount
     * 스냅샷 컬럼에 계속 저장하므로 「플랫폼 수수료 내역」(settlement/platform-fee) 화면은
     * 그대로 동작한다. 분배 계산식이 필요하면 feeSplit()이 남아 있다.
     *
     * ⚠️ 리스 수수료 배분(RiderDebt::moveLeaseFees)은 이와 달리 **여전히 실제로 지갑을 옮긴다** —
     *    자금 출처가 다르기 때문이다(리스료는 이미 대리점 지갑에 있는 돈, 플랫폼 수수료는
     *    대리점이 카드로 더 낸 돈). 둘을 같은 규칙으로 착각하지 말 것.
     */

    private static function record(int $agencyId, ?int $riderId, ?int $uploadId, ?int $cardId, int $net, int $fee, int $total, string $status, string $tid, string $failReason, int $attempts, ?int $adminId, string $ordNum = ''): int
    {
        // 결제 시점 본사/총판/대리점 분배를 스냅샷으로 남긴다 — 나중에 org_fee_config
        // 요율이 바뀌어도 이 건의 실제 분배 내역은 그대로 보존된다.
        $sp         = self::feeSplit($fee, $agencyId);
        $bd         = $sp['pct'];
        $hqAmount   = (int) $sp['hq'];
        $distAmount = (int) $sp['distributor'];
        $agyAmount  = (int) $sp['agency'];

        $cols     = array_column(db_rows('SHOW COLUMNS FROM pg_payments'), 'Field');
        $hasSplit = in_array('hq_amount', $cols, true);
        $hasOrd   = in_array('ord_num', $cols, true);

        // 컬럼 유무에 따라 INSERT를 4가지로 나누지 않도록 동적으로 조립한다.
        $fields = ['agency_id', 'rider_id', 'upload_id', 'card_id', 'net_amount', 'service_fee',
            'total_charged', 'status', 'pg_tid', 'fail_reason', 'attempts', 'created_by'];
        $values = [
            $agencyId,
            ($riderId !== null && $riderId > 0) ? $riderId : null,
            ($uploadId !== null && $uploadId > 0) ? $uploadId : null,
            ($cardId !== null && $cardId > 0) ? $cardId : null,
            $net, $fee, $total, $status, $tid, mb_substr($failReason, 0, 300), max(1, $attempts),
            ($adminId !== null && $adminId > 0) ? $adminId : null,
        ];

        if ($hasOrd) {
            $fields[] = 'ord_num';
            $values[] = mb_substr($ordNum, 0, 30);
        }
        if ($hasSplit) {
            array_push($fields, 'hq_pct', 'distributor_pct', 'agency_pct', 'hq_amount', 'distributor_amount', 'agency_amount');
            array_push($values, $bd['hq'], $bd['distributor'], $bd['agency'], $hqAmount, $distAmount, $agyAmount);
        }

        return db_insert(
            'INSERT INTO pg_payments (' . implode(', ', $fields) . ')
             VALUES (' . implode(', ', array_fill(0, count($fields), '?')) . ')',
            $values
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
