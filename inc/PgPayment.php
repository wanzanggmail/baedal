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

    private static function record(int $agencyId, ?int $riderId, ?int $uploadId, ?int $cardId, int $net, int $fee, int $total, string $status, string $tid, string $failReason, int $attempts, ?int $adminId): int
    {
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
