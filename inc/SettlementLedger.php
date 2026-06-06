<?php

declare(strict_types=1);

require_once __DIR__ . '/RiderWallet.php';
require_once __DIR__ . '/AgencyFeeConfig.php';

/**
 * 정산 완료·수수료 내역 (settlement_rider_cycles / settlement_fee_items)
 */
final class SettlementLedger
{
    /** @var array<string, string> */
    private const PLATFORM_LABELS = [
        'baemin'  => '배달의민족',
        'coupang' => '쿠팡이츠',
        'other'   => '기타',
    ];

    public static function tableExists(): bool
    {
        return db_table_exists('settlement_rider_cycles') && db_table_exists('settlement_fee_items');
    }

    /**
     * 업로드 건 정산 반영 — 매칭된 라이더별 수수료 산출·지갑 적립
     *
     * @return array{applied: int, skipped: int, errors: list<string>}
     */
    public static function applyUpload(int $uploadId, ?int $adminId = null): array
    {
        if (!self::tableExists()) {
            throw new RuntimeException('정산 수수료 테이블이 없습니다. php migrate.php 를 실행하세요.');
        }

        $upload = db_row(
            'SELECT id, platform, settlement_date, status FROM settlement_uploads WHERE id = ? LIMIT 1',
            [$uploadId]
        );
        if ($upload === null) {
            throw new InvalidArgumentException('업로드를 찾을 수 없습니다.');
        }

        $rows = db_rows(
            'SELECT * FROM settlement_daily_riders
             WHERE upload_id = ? AND rider_id IS NOT NULL
             ORDER BY id ASC',
            [$uploadId]
        );

        if ($rows === []) {
            throw new InvalidArgumentException('매칭된 라이더 정산 데이터가 없습니다.');
        }

        $cfg = self::globalDeductionConfig();
        $applied = 0;
        $skipped = 0;
        $errors  = [];

        db_transaction(static function () use ($rows, $upload, $uploadId, $cfg, $adminId, &$applied, &$skipped, &$errors): void {
            foreach ($rows as $row) {
                $riderId = (int) $row['rider_id'];
                if ($riderId < 1) {
                    $skipped++;
                    continue;
                }

                $platform = (string) ($row['platform'] ?? $upload['platform'] ?? 'baemin');
                $date     = (string) $row['settlement_date'];

                $exists = db_row(
                    'SELECT id FROM settlement_rider_cycles
                     WHERE rider_id = ? AND settlement_date = ? AND platform = ? LIMIT 1',
                    [$riderId, $date, $platform]
                );
                if ($exists !== null) {
                    $skipped++;
                    continue;
                }

                try {
                    self::createCycleFromDailyRow($row, $uploadId, $cfg, $adminId);
                    $applied++;
                } catch (Throwable $e) {
                    $errors[] = ($row['rider_name_raw'] ?? $riderId) . ': ' . $e->getMessage();
                }
            }

            if ($applied > 0) {
                db_execute(
                    'UPDATE settlement_uploads SET status = ?, updated_at = NOW() WHERE id = ?',
                    ['applied', $uploadId]
                );
            }
        });

        return ['applied' => $applied, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public static function listAdmin(array $filters = []): array
    {
        if (!self::tableExists()) {
            return [];
        }

        [$where, $params] = self::buildListWhere($filters, false);
        $limit = max(10, min(500, (int) ($filters['limit'] ?? 200)));

        $rows = db_rows(
            "SELECT c.*, r.name AS rider_name, r.rider_code,
                    u.original_filename
               FROM settlement_rider_cycles c
               INNER JOIN riders r ON r.id = c.rider_id
               LEFT JOIN settlement_uploads u ON u.id = c.upload_id
              WHERE {$where}
              ORDER BY c.settlement_date DESC, c.id DESC
              LIMIT {$limit}",
            $params
        );

        return array_map([self::class, 'mapCycleRow'], $rows);
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public static function listForRider(int $riderId, array $filters = []): array
    {
        if ($riderId < 1 || !self::tableExists()) {
            return [];
        }

        $filters['rider_id'] = $riderId;
        [$where, $params] = self::buildListWhere($filters, true);
        $limit = max(10, min(100, (int) ($filters['limit'] ?? 50)));

        $rows = db_rows(
            "SELECT c.*
               FROM settlement_rider_cycles c
              WHERE {$where}
              ORDER BY c.settlement_date DESC, c.id DESC
              LIMIT {$limit}",
            $params
        );

        return array_map([self::class, 'mapCycleRow'], $rows);
    }

    /** @return array<string, mixed>|null */
    public static function find(int $cycleId, ?int $riderId = null): ?array
    {
        if ($cycleId < 1 || !self::tableExists()) {
            return null;
        }

        $params = [$cycleId];
        $riderSql = '';
        if ($riderId !== null && $riderId > 0) {
            $riderSql = ' AND c.rider_id = ?';
            $params[] = $riderId;
        }

        $row = db_row(
            "SELECT c.*, r.name AS rider_name, r.rider_code,
                    u.original_filename
               FROM settlement_rider_cycles c
               INNER JOIN riders r ON r.id = c.rider_id
               LEFT JOIN settlement_uploads u ON u.id = c.upload_id
              WHERE c.id = ?{$riderSql}
              LIMIT 1",
            $params
        );

        if ($row === null) {
            return null;
        }

        $cycle = self::mapCycleRow($row);
        $cycle['fees'] = self::feeItems($cycleId);

        return $cycle;
    }

    /**
     * @param array<string, mixed> $dailyRow settlement_daily_riders row
     * @param array<string, float> $cfg
     */
    private static function createCycleFromDailyRow(array $dailyRow, int $uploadId, array $cfg, ?int $adminId): void
    {
        $riderId = (int) $dailyRow['rider_id'];
        $gross   = (int) ($dailyRow['gross_amount'] ?? 0);
        $payout  = (int) ($dailyRow['payout_amount'] ?? 0);
        $base    = $payout > 0 ? $payout : $gross;

        $fees = self::buildFeeItems($base, $riderId, (string) $dailyRow['settlement_date'], $cfg);
        $totalFee = array_sum(array_column($fees, 'amount'));
        $net      = max(0, $base - $totalFee);

        $cycleId = db_insert(
            'INSERT INTO settlement_rider_cycles
                (rider_id, upload_id, daily_rider_id, settlement_date, platform,
                 gross_amount, platform_payout, total_fee_amount, net_amount, order_count,
                 completed_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $riderId,
                $uploadId,
                (int) ($dailyRow['id'] ?? 0) ?: null,
                (string) $dailyRow['settlement_date'],
                (string) ($dailyRow['platform'] ?? 'baemin'),
                $gross,
                $payout,
                $totalFee,
                $net,
                (int) ($dailyRow['order_count'] ?? 0),
                ($adminId !== null && $adminId > 0) ? $adminId : null,
            ]
        );

        foreach ($fees as $i => $fee) {
            if ((int) $fee['amount'] <= 0) {
                continue;
            }
            db_insert(
                'INSERT INTO settlement_fee_items (cycle_id, fee_code, label, amount, sort_order)
                 VALUES (?, ?, ?, ?, ?)',
                [$cycleId, $fee['fee_code'], $fee['label'], $fee['amount'], ($i + 1) * 10]
            );
        }

        if ($net > 0) {
            RiderWallet::credit($riderId, $net, true);
        }
    }

    /**
     * @param array<string, float> $cfg
     * @return list<array{fee_code: string, label: string, amount: int}>
     */
    private static function buildFeeItems(int $base, int $riderId, string $settlementDate, array $cfg): array
    {
        $items = [];

        $wallet  = RiderWallet::get($riderId);
        $accrued = (int) $wallet['accrued_days'];
        $agency  = AgencyFeeConfig::feeForAccruedDays($accrued);
        if ($agency > 0) {
            $items[] = ['fee_code' => 'agency_fee', 'label' => '정산 수수료(대행)', 'amount' => $agency];
        }

        $tax = self::pctAmount($base, (float) ($cfg['withholding_tax_pct'] ?? 0));
        if ($tax > 0) {
            $items[] = ['fee_code' => 'withholding', 'label' => '원천세', 'amount' => $tax];
        }

        $ins = self::pctAmount($base, (float) ($cfg['employment_ins_pct'] ?? 0));
        if ($ins > 0) {
            $items[] = ['fee_code' => 'employment_ins', 'label' => '고용·산재보험', 'amount' => $ins];
        }

        if (db_table_exists('deduction_entries')) {
            $manual = db_rows(
                'SELECT kind, amount, note FROM deduction_entries
                 WHERE rider_id = ? AND applied_date = ? AND amount <> 0
                 ORDER BY id ASC',
                [$riderId, $settlementDate]
            );
            foreach ($manual as $m) {
                $amt = abs((int) ($m['amount'] ?? 0));
                if ($amt <= 0) {
                    continue;
                }
                $kind = (string) ($m['kind'] ?? 'manual');
                $items[] = [
                    'fee_code' => $kind,
                    'label'    => self::deductionKindLabel($kind, (string) ($m['note'] ?? '')),
                    'amount'   => $amt,
                ];
            }
        }

        return $items;
    }

    /** @return array<string, float> */
    private static function globalDeductionConfig(): array
    {
        if (!db_table_exists('deduction_global_config')) {
            return [
                'withholding_tax_pct' => 3.3,
                'employment_ins_pct'  => 9.12,
            ];
        }

        $row = db_row('SELECT withholding_tax_pct, employment_ins_pct FROM deduction_global_config LIMIT 1');

        return [
            'withholding_tax_pct' => (float) ($row['withholding_tax_pct'] ?? 3.3),
            'employment_ins_pct'  => (float) ($row['employment_ins_pct'] ?? 9.12),
        ];
    }

    private static function pctAmount(int $base, float $pct): int
    {
        if ($base <= 0 || $pct <= 0) {
            return 0;
        }

        return (int) round($base * $pct / 100);
    }

    private static function deductionKindLabel(string $kind, string $note): string
    {
        $labels = [
            'withholding'    => '원천세(수동)',
            'employment_ins' => '고용·산재(수동)',
            'agency_fee'     => '정산 수수료(수동)',
            'hourly_ins'     => '시간제 보험',
            'ins_refund'     => '보험료 환급',
            'rental'         => '대여금',
            'advance'        => '선지급',
            'manual'         => '수동 차감',
        ];
        $label = $labels[$kind] ?? $kind;
        if ($note !== '') {
            return $label . ' · ' . mb_substr($note, 0, 40);
        }

        return $label;
    }

    /** @return list<array<string, mixed>> */
    private static function feeItems(int $cycleId): array
    {
        $rows = db_rows(
            'SELECT fee_code, label, amount, sort_order
             FROM settlement_fee_items
             WHERE cycle_id = ?
             ORDER BY sort_order ASC, id ASC',
            [$cycleId]
        );

        return array_map(static fn (array $r): array => [
            'fee_code' => (string) $r['fee_code'],
            'label'    => (string) $r['label'],
            'amount'   => (int) $r['amount'],
        ], $rows);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private static function buildListWhere(array $filters, bool $riderScoped): array
    {
        $where  = ['1=1'];
        $params = [];

        if ($riderScoped && !empty($filters['rider_id'])) {
            $where[]  = 'c.rider_id = ?';
            $params[] = (int) $filters['rider_id'];
        }

        $from = trim((string) ($filters['from'] ?? ''));
        if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $where[]  = 'c.settlement_date >= ?';
            $params[] = $from;
        }

        $to = trim((string) ($filters['to'] ?? ''));
        if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $where[]  = 'c.settlement_date <= ?';
            $params[] = $to;
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '' && !$riderScoped) {
            $where[] = '(r.name LIKE ? OR r.rider_code LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
        }

        return [implode(' AND ', $where), $params];
    }

    /** @param array<string, mixed> $row */
    private static function mapCycleRow(array $row): array
    {
        $platform = (string) ($row['platform'] ?? 'baemin');
        $completed = $row['completed_at'] ?? null;

        return [
            'id'               => (int) $row['id'],
            'rider_id'         => (int) $row['rider_id'],
            'rider_name'       => (string) ($row['rider_name'] ?? ''),
            'rider_code'       => (string) ($row['rider_code'] ?? ''),
            'upload_id'        => isset($row['upload_id']) ? (int) $row['upload_id'] : 0,
            'upload_filename'  => (string) ($row['original_filename'] ?? ''),
            'settlement_date'  => (string) $row['settlement_date'],
            'platform'         => $platform,
            'platform_label'   => self::PLATFORM_LABELS[$platform] ?? $platform,
            'gross_amount'     => (int) $row['gross_amount'],
            'platform_payout'  => (int) $row['platform_payout'],
            'total_fee_amount' => (int) $row['total_fee_amount'],
            'net_amount'       => (int) $row['net_amount'],
            'order_count'      => (int) $row['order_count'],
            'completed_at'     => $completed ? substr((string) $completed, 0, 16) : '',
        ];
    }
}
