<?php

declare(strict_types=1);

/**
 * 자동 일일정산 — 일일정산 대상 라이더만, 세금·환불·보증금 보류 후 출금액 산출
 */
final class DailySettlement
{
    /** @return array<string, mixed> */
    public static function defaultParams(): array
    {
        return [
            'tax_pct'       => 3.3,
            'refund_pct'    => 1.0,
            'refund_fixed'  => 30000,
            'min_retain'    => 50000,
            'round_unit'    => 1000,
            'skip_dup'      => true,
            'skip_manual'   => false,
            'platform'      => 'baemin',
        ];
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public static function normalizeParams(array $raw): array
    {
        $d = self::defaultParams();

        return [
            'tax_pct'      => max(0.0, min(100.0, (float) ($raw['tax_pct'] ?? $d['tax_pct']))),
            'refund_pct'   => max(0.0, min(100.0, (float) ($raw['refund_pct'] ?? $d['refund_pct']))),
            'refund_fixed' => max(0, (int) ($raw['refund_fixed'] ?? $d['refund_fixed'])),
            'min_retain'   => max(0, (int) ($raw['min_retain'] ?? $d['min_retain'])),
            'round_unit'   => max(1, (int) ($raw['round_unit'] ?? $d['round_unit'])),
            'skip_dup'     => !empty($raw['skip_dup']),
            'skip_manual'  => !empty($raw['skip_manual']),
            'platform'     => in_array($raw['platform'] ?? 'baemin', ['baemin', 'coupang', 'other'], true)
                ? (string) $raw['platform']
                : 'baemin',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function fetchSettlementSources(string $settlementDate, string $platform = 'baemin'): array
    {
        $rows = db_rows(
            'SELECT r.id AS rider_id,
                    r.rider_code,
                    r.name AS rider_name,
                    r.is_daily_settlement,
                    r.withdrawal_hold,
                    r.bank_code,
                    r.bank_account,
                    r.account_holder,
                    sc.label AS bank_label,
                    COALESCE(SUM(dr.payout_amount), 0) AS gross_amount,
                    COALESCE(SUM(dr.order_count), 0) AS order_count,
                    COUNT(dr.id) AS line_count
               FROM settlement_daily_riders dr
               INNER JOIN riders r ON r.id = dr.rider_id
               LEFT JOIN system_codes sc
                      ON sc.category = \'bank\' AND sc.code = r.bank_code
              WHERE dr.settlement_date = ?
                AND dr.platform = ?
              GROUP BY r.id
              ORDER BY r.name ASC',
            [$settlementDate, $platform]
        );

        $deductMap = [];
        foreach (db_rows(
            'SELECT rider_id, SUM(ABS(amount)) AS total
               FROM settlement_weekly_deductions
              WHERE week_start = ? AND rider_id IS NOT NULL
              GROUP BY rider_id',
            [$settlementDate]
        ) as $d) {
            $deductMap[(int) $d['rider_id']] = (int) $d['total'];
        }

        $out = [];
        foreach ($rows as $row) {
            $rid = (int) $row['rider_id'];
            $row['other_withhold'] = $deductMap[$rid] ?? 0;
            $row['is_daily_settlement'] = (int) ($row['is_daily_settlement'] ?? 0) === 1;
            $row['withdrawal_hold'] = (int) ($row['withdrawal_hold'] ?? 0) === 1;
            $row['gross_amount'] = (int) $row['gross_amount'];
            $row['other_withhold'] = (int) $row['other_withhold'];
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, int>
     */
    public static function calcLine(int $gross, int $otherWithhold, array $params): array
    {
        $tax = (int) floor($gross * $params['tax_pct'] / 100);
        $refund = $params['refund_fixed'] + (int) floor($gross * $params['refund_pct'] / 100);
        $deposit = $params['min_retain'];
        $sub = $tax + $refund + $deposit + $otherWithhold;
        $rawNet = $gross - $sub;
        $roundTrim = 0;
        $net = $rawNet;
        $unit = (int) $params['round_unit'];

        if ($unit > 1 && $net > 0) {
            $floored = (int) (floor($net / $unit) * $unit);
            $roundTrim = $net - $floored;
            $net = $floored;
        }

        return [
            'gross'        => $gross,
            'tax'          => $tax,
            'refund'       => $refund,
            'deposit'      => $deposit,
            'other'        => $otherWithhold,
            'round_trim'   => $roundTrim,
            'net'          => max(0, $net),
            'withheld_sum' => $tax + $refund + $deposit + $otherWithhold + $roundTrim,
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{params: array, rows: list<array>, skipped: list<array>, summary: array}
     */
    public static function preview(string $settlementDate, array $params): array
    {
        $params = self::normalizeParams($params);
        $sources = self::fetchSettlementSources($settlementDate, $params['platform']);

        $rows = [];
        $skipped = [];

        foreach ($sources as $src) {
            if (!$src['is_daily_settlement']) {
                $skipped[] = [
                    'rider_id' => $src['rider_id'],
                    'rider_name' => $src['rider_name'],
                    'reason' => '주간 정산 대상(일일정산 미등록)',
                ];
                continue;
            }

            if ($src['withdrawal_hold']) {
                $skipped[] = [
                    'rider_id' => $src['rider_id'],
                    'rider_name' => $src['rider_name'],
                    'reason' => '출금 보류',
                ];
                continue;
            }

            $rid = (int) $src['rider_id'];
            if ($params['skip_dup'] && self::hasAutoDailyWithdrawal($rid, $settlementDate)) {
                $skipped[] = [
                    'rider_id' => $rid,
                    'rider_name' => $src['rider_name'],
                    'reason' => '이미 자동 일일정산 출금 생성됨',
                ];
                continue;
            }

            if ($params['skip_manual'] && self::hasManualPending($rid, $settlementDate)) {
                $skipped[] = [
                    'rider_id' => $rid,
                    'rider_name' => $src['rider_name'],
                    'reason' => '당일 수동 출금 대기 존재',
                ];
                continue;
            }

            $line = self::calcLine((int) $src['gross_amount'], (int) $src['other_withhold'], $params);

            $rows[] = [
                'rider_id'      => $rid,
                'rider_code'    => $src['rider_code'],
                'rider_name'    => $src['rider_name'],
                'bank_label'    => $src['bank_label'] ?? '',
                'bank_code'     => $src['bank_code'] ?? '',
                'bank_account'  => $src['bank_account'] ?? '',
                'account_holder'=> $src['account_holder'] ?? $src['rider_name'],
                'gross_amount'  => (int) $src['gross_amount'],
                'order_count'   => (int) $src['order_count'],
                'other_withhold'=> (int) $src['other_withhold'],
                'line'          => $line,
                'note'          => (int) $src['line_count'] > 1 ? '정산 행 ' . $src['line_count'] . '건 합산' : '',
            ];
        }

        $totalGross = 0;
        $totalNet = 0;
        foreach ($rows as $r) {
            $totalGross += $r['line']['gross'];
            $totalNet += $r['line']['net'];
        }

        return [
            'params'  => $params,
            'rows'    => $rows,
            'skipped' => $skipped,
            'summary' => [
                'source_count'   => count($sources),
                'preview_count'  => count($rows),
                'skipped_count'  => count($skipped),
                'total_gross'    => $totalGross,
                'total_withdraw' => $totalNet,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{created: int, skipped: list<array>, rows: list<array>}
     */
    public static function commit(string $settlementDate, array $params, int $adminId): array
    {
        $preview = self::preview($settlementDate, $params);
        $created = 0;
        $committed = [];

        db_transaction(static function () use ($preview, $settlementDate, $adminId, &$created, &$committed): void {
            foreach ($preview['rows'] as $row) {
                $line = $row['line'];
                if ($line['net'] <= 0) {
                    continue;
                }

                $rid = (int) $row['rider_id'];
                if (self::hasAutoDailyWithdrawal($rid, $settlementDate)) {
                    continue;
                }

                $note = sprintf(
                    '자동일일정산 %s (세금·환불·보증금 보류)',
                    $settlementDate
                );

                $newId = db_insert(
                    'INSERT INTO withdrawal_requests
                        (rider_id, kind, amount, gross_amount,
                         withhold_tax, withhold_refund, withhold_other, withhold_min_retain, withhold_round_trim,
                         settlement_date, bank_code, bank_account, account_holder,
                         status, note, created_by, requested_at)
                     VALUES (?, \'auto_daily\', ?, ?,
                             ?, ?, ?, ?, ?,
                             ?, ?, ?, ?,
                             \'pending\', ?, ?, NOW())',
                    [
                        $rid,
                        $line['net'],
                        $line['gross'],
                        $line['tax'],
                        $line['refund'],
                        $line['other'],
                        $line['deposit'],
                        $line['round_trim'],
                        $settlementDate,
                        (string) ($row['bank_code'] ?? ''),
                        (string) ($row['bank_account'] ?? ''),
                        (string) ($row['account_holder'] ?? $row['rider_name']),
                        $note,
                        $adminId > 0 ? $adminId : null,
                    ]
                );

                $created++;
                $committed[] = [
                    'request_code' => 'wd-auto-' . $newId,
                    'rider_id'     => $rid,
                    'rider_name'   => $row['rider_name'],
                    'amount'       => $line['net'],
                ];
            }
        });

        return [
            'created'  => $created,
            'skipped'  => $preview['skipped'],
            'rows'     => $committed,
            'summary'  => $preview['summary'],
        ];
    }

    public static function hasAutoDailyWithdrawal(int $riderId, string $settlementDate): bool
    {
        $row = db_row(
            'SELECT id FROM withdrawal_requests
              WHERE rider_id = ? AND settlement_date = ? AND kind = \'auto_daily\'
                AND status IN (\'pending\', \'downloaded\', \'completed\')
              LIMIT 1',
            [$riderId, $settlementDate]
        );

        return $row !== null;
    }

    public static function hasManualPending(int $riderId, string $settlementDate): bool
    {
        $row = db_row(
            'SELECT id FROM withdrawal_requests
              WHERE rider_id = ? AND settlement_date = ? AND kind = \'rider_manual\'
                AND status = \'pending\'
              LIMIT 1',
            [$riderId, $settlementDate]
        );

        return $row !== null;
    }

    /** @return list<string> */
    public static function availableDates(string $platform = 'baemin'): array
    {
        $rows = db_rows(
            'SELECT DISTINCT settlement_date
               FROM settlement_uploads
              WHERE kind = \'daily\' AND platform = ?
              ORDER BY settlement_date DESC
              LIMIT 60',
            [$platform]
        );

        return array_map(static fn (array $r): string => (string) $r['settlement_date'], $rows);
    }
}
