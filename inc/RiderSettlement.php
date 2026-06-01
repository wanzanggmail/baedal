<?php

declare(strict_types=1);

/**
 * 라이더 앱 — 정산·출금 가능 잔액 조회
 */
final class RiderSettlement
{
    /**
     * 홈 카드·출금 신청용 요약
     *
     * @return array{
     *   month_start: string,
     *   month_end: string,
     *   month_label: string,
     *   month_total: int,
     *   month_line_count: int,
     *   month_status_label: string,
     *   month_status_class: string,
     *   withdrawable: int,
     *   total_settled: int,
     *   total_withdrawn: int,
     *   withdrawal_hold: bool,
     *   error: string|null
     * }
     */
    public static function homeSummary(int $riderId): array
    {
        $tz = new DateTimeZone('Asia/Seoul');
        $today = new DateTimeImmutable('now', $tz);
        $monthStart = $today->modify('first day of this month')->format('Y-m-d');
        $monthEnd = $today->format('Y-m-d');
        $monthLabel = $today->format('Y년 n월') . ' 정산 합계';

        $empty = [
            'month_start'          => $monthStart,
            'month_end'            => $monthEnd,
            'month_label'          => $monthLabel,
            'month_total'          => 0,
            'month_line_count'     => 0,
            'month_status_label'   => '내역 없음',
            'month_status_class'   => 'secondary',
            'withdrawable'         => 0,
            'total_settled'        => 0,
            'total_withdrawn'      => 0,
            'withdrawal_hold'      => false,
            'error'                => null,
        ];

        if ($riderId < 1) {
            $empty['error'] = '로그인이 필요합니다.';

            return $empty;
        }

        try {
            $holdRow = db_row('SELECT withdrawal_hold FROM riders WHERE id = ? LIMIT 1', [$riderId]);
            $hold = (int) ($holdRow['withdrawal_hold'] ?? 0) === 1;

            $monthRow = db_row(
                'SELECT COALESCE(SUM(payout_amount), 0) AS total, COUNT(*) AS line_count
                   FROM settlement_daily_riders
                  WHERE rider_id = ?
                    AND settlement_date >= ?
                    AND settlement_date <= ?',
                [$riderId, $monthStart, $monthEnd]
            );
            $monthTotal = (int) ($monthRow['total'] ?? 0);
            $monthLines = (int) ($monthRow['line_count'] ?? 0);

            $settledRow = db_row(
                'SELECT COALESCE(SUM(payout_amount), 0) AS total
                   FROM settlement_daily_riders
                  WHERE rider_id = ?',
                [$riderId]
            );
            $totalSettled = (int) ($settledRow['total'] ?? 0);

            $withdrawnRow = db_row(
                "SELECT COALESCE(SUM(amount), 0) AS total
                   FROM withdrawal_requests
                  WHERE rider_id = ?
                    AND status IN ('pending', 'downloaded', 'completed')",
                [$riderId]
            );
            $totalWithdrawn = (int) ($withdrawnRow['total'] ?? 0);

            $withdrawable = max(0, $totalSettled - $totalWithdrawn);
            if ($hold) {
                $withdrawable = 0;
            }

            [$monthStatusLabel, $monthStatusClass] = self::monthStatus($monthTotal, $monthLines);

            return [
                'month_start'          => $monthStart,
                'month_end'            => $monthEnd,
                'month_label'          => $monthLabel,
                'month_total'          => $monthTotal,
                'month_line_count'     => $monthLines,
                'month_status_label'   => $monthStatusLabel,
                'month_status_class'   => $monthStatusClass,
                'withdrawable'         => $withdrawable,
                'total_settled'        => $totalSettled,
                'total_withdrawn'      => $totalWithdrawn,
                'withdrawal_hold'      => $hold,
                'error'                => null,
            ];
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'settlement_daily_riders') || str_contains($msg, "doesn't exist")) {
                $empty['error'] = '정산 테이블이 없습니다. 관리자에게 migrate_settlement.php 실행을 요청하세요.';
            } elseif (str_contains($msg, 'withdrawal_requests')) {
                $empty['error'] = '출금 테이블이 없습니다. 관리자에게 migrate_daily_settlement.php 실행을 요청하세요.';
            } else {
                $empty['error'] = '정산 정보를 불러올 수 없습니다.';
            }

            return $empty;
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function monthStatus(int $monthTotal, int $lineCount): array
    {
        if ($lineCount === 0) {
            return ['내역 없음', 'secondary'];
        }
        if ($monthTotal > 0) {
            return ['반영 완료', 'success'];
        }

        return ['집계 중', 'warning'];
    }
}
