<?php

declare(strict_types=1);

require_once __DIR__ . '/Withdrawal.php';
require_once __DIR__ . '/RiderWallet.php';
require_once __DIR__ . '/WithdrawalCycles.php';
require_once __DIR__ . '/WithdrawalConfig.php';

/**
 * 일일정산(선정산) 라이더 자동 출금 — 정산 반영 직후 곧바로 이체까지 실행한다.
 *
 * 배경: 일일정산 라이더(`riders.is_daily_settlement = 1`)는 매일 정산분을 그날 바로 받는 게
 * 원칙이라, 관리자가 「정산 반영 · 수수료·지갑」을 누른 뒤 출금 화면에 또 들어가 승인해 주는
 * 절차가 불필요한 수작업이었다. 그래서 정산 반영 성공 직후 이 클래스가 자동으로
 * 신청(applyForRider) → 이체(executeTransfers)까지 이어서 처리한다. 주정산 라이더는
 * 대상이 아니며 기존대로 라이더가 직접 신청한다.
 *
 * 보증금은 별도 처리가 필요 없다 — `RiderWallet::previewWithdrawal()`이 이미
 * `잔액 − 보증금(withdrawal_config.reserve_amount, 기본 5만원)`만 출금 대상으로 잡는다.
 * 따라서 보증금이 안 찬 라이더는 자동으로 "출금 가능액 0원"이 되어 건너뛰어진다.
 *
 * ⚠️ 반드시 `SettlementLedger::applyUpload()`의 트랜잭션이 **커밋된 뒤** 호출할 것.
 *    지갑 잔액이 확정돼야 출금 가능액이 제대로 계산된다.
 * ⚠️ 여기서 바깥 트랜잭션을 열면 안 된다 — `executeTransfers()`가 실제 이체라서 건별로
 *    독립 커밋해야 하고(부분 실패 시 앞선 성공분을 되돌리면 실제 송금과 시스템 상태가
 *    어긋난다), 중첩 트랜잭션은 PDO가 지원하지도 않는다.
 */
final class DailyAutoWithdrawal
{
    /**
     * 업로드에 매칭된 일일정산 라이더 중 **아직 출금 안 된 정산분이 남은 사람**을 대상으로 실행.
     *
     * ⚠️ 대상을 "이번에 새로 반영된 라이더"로 좁히면 안 된다(2026-08-09 실사용 버그).
     * 계좌 미등록으로 건너뛴 라이더의 계좌를 채워 넣고 「정산 재반영」을 눌러도, 그 라이더는
     * 이미 사이클이 있어 반영 단계에서 skip → 대상 목록에서 빠져 **자동출금이 영영 재시도되지
     * 않았다**. 그래서 "새로 반영됐는지"가 아니라 "미출금 정산분이 남았는지"로 판단한다.
     *
     * 중복 출금 걱정은 없다 — 이미 받아간 사이클은 `unwithdrawn()`에서 빠지고,
     * 진행 중인 신청이 있으면 `applyForRider()`가 막는다.
     */
    public static function runForUpload(int $uploadId): array
    {
        if ($uploadId < 1 || !db_table_exists('settlement_daily_riders')) {
            return self::emptyResult();
        }

        $rows = db_rows(
            'SELECT DISTINCT dr.rider_id
               FROM settlement_daily_riders dr
               INNER JOIN riders r ON r.id = dr.rider_id
              WHERE dr.upload_id = ? AND dr.rider_id IS NOT NULL AND r.is_daily_settlement = 1',
            [$uploadId]
        );

        $ids = array_map(static fn (array $r): int => (int) $r['rider_id'], $rows);
        // 미출금 정산분이 없는 사람(이미 다 받은 사람)은 제외 — 불필요한 시도와 결과 잡음 방지.
        $ids = array_values(array_filter(
            $ids,
            static fn (int $id): bool => WithdrawalCycles::unwithdrawn($id) !== []
        ));

        return self::runForRiders($ids);
    }

    /**
     * @return array{targets:int, paid:int, paid_amount:int, failed:int, skipped:int, results:list<array<string,mixed>>}
     */
    private static function emptyResult(): array
    {
        return ['targets' => 0, 'paid' => 0, 'paid_amount' => 0, 'failed' => 0, 'skipped' => 0, 'results' => []];
    }

    /**
     * 건너뛴 사유를 관리자가 바로 조치할 수 있게 숫자를 붙여 다시 쓴다.
     * (특히 "보증금 미달"은 얼마가 더 쌓여야 하는지가 핵심 정보다.)
     */
    private static function explainSkip(int $riderId, string $raw): string
    {
        if (!str_contains($raw, '출금 가능 금액이 없습니다') && !str_contains($raw, '출금 가능한 정산 내역이 없습니다')) {
            return $raw;
        }

        $agencyId = (int) (db_row('SELECT agency_id FROM riders WHERE id = ? LIMIT 1', [$riderId])['agency_id'] ?? 0);
        $reserve  = (int) WithdrawalConfig::get($agencyId > 0 ? $agencyId : null)['reserve_amount'];
        $balance  = (int) RiderWallet::get($riderId)['balance'];

        if ($balance < $reserve) {
            return sprintf(
                '보증금 미달 — 잔액 %s원 / 보증금 %s원 (%s원 더 쌓이면 출금)',
                number_format($balance),
                number_format($reserve),
                number_format($reserve - $balance)
            );
        }

        // 보증금은 넘겼는데 건 단위(WHOLE) 정책상 다음 정산분을 통째로 못 가져가는 경우
        $preview   = RiderWallet::previewWithdrawal($riderId);
        $shortfall = (int) ($preview['blocked_shortfall'] ?? 0);
        if ($shortfall > 0) {
            return sprintf(
                '출금 가능액 부족 — 정산분을 건 단위로만 지급해 %s원 더 쌓여야 출금 (잔액 %s원 / 보증금 %s원)',
                number_format($shortfall),
                number_format($balance),
                number_format($reserve)
            );
        }

        return $raw;
    }

    /**
     * @param list<int> $riderIds
     * @return array{targets:int, paid:int, paid_amount:int, failed:int, skipped:int,
     *               results:list<array{rider_id:int, name:string, status:string, amount:int, message:string}>}
     */
    public static function runForRiders(array $riderIds): array
    {
        $out = self::emptyResult();

        $riderIds = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $riderIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($riderIds === [] || !db_table_exists('withdrawal_requests')) {
            return $out;
        }

        // 일일정산 대상자만 추린다.
        $placeholders = implode(',', array_fill(0, count($riderIds), '?'));
        $targets = db_rows(
            "SELECT id, name FROM riders
              WHERE id IN ({$placeholders}) AND is_daily_settlement = 1
              ORDER BY id ASC",
            $riderIds
        );
        $out['targets'] = count($targets);
        if ($targets === []) {
            return $out;
        }

        foreach ($targets as $t) {
            $riderId = (int) $t['id'];
            $name    = (string) ($t['name'] ?? '');

            // ── 1단계: 출금 신청 ──
            // 계좌 미등록·출금보류·보증금 미달 등은 **정상적인 건너뜀**이다. 정산 반영 자체는
            // 이미 성공했으므로 여기서 예외를 위로 던져 전체를 실패시키면 안 된다.
            try {
                $req = Withdrawal::applyForRider($riderId);
            } catch (Throwable $e) {
                $out['skipped']++;
                $out['results'][] = [
                    'rider_id' => $riderId, 'name' => $name, 'status' => 'skipped',
                    'amount' => 0, 'message' => self::explainSkip($riderId, $e->getMessage()),
                ];
                continue;
            }

            // mapRow()의 'id'는 공개용 문자열 ID(publicId)라 정수로 못 쓴다. DB PK는 'db_id'.
            $reqId  = (int) ($req['db_id'] ?? 0);
            $amount = (int) ($req['amount'] ?? 0);
            if ($reqId < 1) {
                $out['skipped']++;
                $out['results'][] = [
                    'rider_id' => $riderId, 'name' => $name, 'status' => 'skipped',
                    'amount' => $amount, 'message' => '출금 신청 ID를 확인할 수 없습니다.',
                ];
                continue;
            }

            // ── 2단계: 즉시 이체 ──
            $res  = Withdrawal::executeTransfers([$reqId]);
            $first = $res['results'][0] ?? null;

            if ((int) $res['completed'] > 0) {
                $out['paid']++;
                $out['paid_amount'] += $amount;
                $out['results'][] = [
                    'rider_id' => $riderId, 'name' => $name, 'status' => 'paid',
                    'amount' => $amount, 'message' => (string) ($first['message'] ?? ''),
                ];
                continue;
            }

            // 이체 실패 — 신청은 'failed'로 남아 출금 화면에서 재시도할 수 있다.
            $out['failed']++;
            $out['results'][] = [
                'rider_id' => $riderId, 'name' => $name, 'status' => 'failed',
                'amount' => $amount, 'message' => (string) ($first['message'] ?? '이체 실패'),
            ];
        }

        return $out;
    }
}
