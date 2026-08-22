<?php

declare(strict_types=1);

require_once __DIR__ . '/RiderWallet.php';
require_once __DIR__ . '/AgencyFeeConfig.php';
require_once __DIR__ . '/AgencyWallet.php';
require_once __DIR__ . '/SettlementAmounts.php';

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
            'SELECT id, kind, platform, agency_id, team_name, region_name, settlement_date, status
               FROM settlement_uploads WHERE id = ? LIMIT 1',
            [$uploadId]
        );
        if ($upload === null) {
            throw new InvalidArgumentException('업로드를 찾을 수 없습니다.');
        }
        // 주간 정산서는 이 경로로 반영하지 않는다(일자별 사이클을 만드는 로직이라 대상이 아니다).
        // 막지 않아도 `settlement_daily_riders`가 비어 있어 실패하지만, 그때 나오는
        // "매칭된 라이더 정산 데이터가 없습니다"는 원인을 오해하게 만든다.
        if ((string) ($upload['kind'] ?? 'daily') === 'weekly') {
            throw new InvalidArgumentException('주간 정산서는 정산 반영 대상이 아닙니다. (프로모션·시간제보험만 별도로 다룹니다)');
        }

        // 멀티테넌시: 업로드 소유 대리점 설정으로 수수료 산출
        $agencyId = (int) ($upload['agency_id'] ?? 0);
        $orgId    = $agencyId > 0 ? $agencyId : null;

        // 같은 날 여러 팀지역 정산을 각각 별도 사이클로 쌓기 위한 키(정규화된 값으로 저장)
        $teamRegion = trim(
            normalize_hangul_nfc((string) ($upload['team_name'] ?? '')) . ' '
            . normalize_hangul_nfc((string) ($upload['region_name'] ?? ''))
        );

        $rows = db_rows(
            'SELECT * FROM settlement_daily_riders
             WHERE upload_id = ? AND rider_id IS NOT NULL
             ORDER BY id ASC',
            [$uploadId]
        );

        if ($rows === []) {
            throw new InvalidArgumentException('매칭된 라이더 정산 데이터가 없습니다.');
        }

        $cfg = self::globalDeductionConfig($orgId);
        $applied = 0;
        $skipped = 0;
        $errors  = [];

        // ⚠️ 리스/렌탈 자동 일수계산은 **반드시 사이클 생성보다 먼저** 실행해야 한다.
        // 계약기간∩정산기간 일수만큼 `deduction_entries`(applied_date = 정산기간 종료일)를 만드는데,
        // 그 행을 소비하는 쪽이 아래 createCycleFromDailyRow → buildFeeItems(applied_date = settlement_date
        // 로 조회)이기 때문이다.
        //
        // 🐛 2026-08-08 수정: 예전엔 이 호출이 트랜잭션 **뒤**에 있어서, 리스 차감 행이 만들어질 때는
        //    이미 그 날짜의 사이클이 확정된 뒤였다. 결과적으로 **원장(rider_debts)에는 "받았다"고
        //    기록되는데 실제 정산에서는 한 푼도 안 걷히는** 상태였다(재반영해도 사이클 중복 체크로
        //    스킵되어 영영 회수 불가, 우연히 같은 날짜의 다른 팀지역 정산이 들어올 때만 뒤늦게 걷힘).
        //    순서를 앞으로 옮겨 정상적으로 해당 사이클에서 차감되게 했다.
        //
        // 트랜잭션 밖인 것은 유지 — 개별 리스 데이터 이상이 정산 반영 전체를 막지 않게 하기 위함이며,
        // 재실행 시 이중 차감은 rider_debt_entries UNIQUE(debt_id, applied_date)가 막는다.
        self::applyActiveLeasesForUpload($rows);

        db_transaction(static function () use ($rows, $upload, $uploadId, $cfg, $adminId, $orgId, $teamRegion, &$applied, &$skipped, &$errors): void {
            foreach ($rows as $row) {
                $riderId = (int) $row['rider_id'];
                if ($riderId < 1) {
                    $skipped++;
                    continue;
                }

                $platform = (string) ($row['platform'] ?? $upload['platform'] ?? 'baemin');
                $date     = (string) $row['settlement_date'];

                // 중복 판정에 팀지역 포함 — 같은 날이라도 팀지역이 다르면 별개 정산이다.
                $exists = db_row(
                    'SELECT id FROM settlement_rider_cycles
                     WHERE rider_id = ? AND settlement_date = ? AND platform = ? AND team_region = ? LIMIT 1',
                    [$riderId, $date, $platform, $teamRegion]
                );
                if ($exists !== null) {
                    $skipped++;
                    continue;
                }

                try {
                    self::createCycleFromDailyRow($row, $uploadId, $cfg, $adminId, $orgId, $teamRegion);
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
     * 업로드에 매칭된 라이더들의 정산기간(min~max settlement_date)을 구해
     * 각 라이더의 활성 리스에 자동 일수계산을 1회씩 적용한다(§7 격차, RiderDebt::applyLeaseForPeriod).
     *
     * @param list<array<string, mixed>> $rows settlement_daily_riders 행 목록(applyUpload에서 조회한 것)
     */
    private static function applyActiveLeasesForUpload(array $rows): void
    {
        if (!class_exists('RiderDebt')) {
            require_once __DIR__ . '/RiderDebt.php';
        }
        if (!RiderDebt::tableReady()) {
            return;
        }

        $dates = array_column($rows, 'settlement_date');
        if ($dates === []) {
            return;
        }
        $periodStart = min($dates);
        $periodEnd   = max($dates);

        $riderIds = array_unique(array_filter(array_map(
            static fn ($r) => (int) ($r['rider_id'] ?? 0),
            $rows
        )));

        foreach ($riderIds as $riderId) {
            $leases = array_filter(
                RiderDebt::forRider($riderId, true),
                static fn (array $d): bool => (string) $d['kind'] === 'lease'
            );
            foreach ($leases as $debt) {
                try {
                    RiderDebt::applyLeaseForPeriod((int) $debt['id'], (string) $periodStart, (string) $periodEnd);
                } catch (Throwable) {
                    // 리스 자동계산 실패가 정산 반영 자체를 막지 않는다(개별 미수금 데이터 이상 등).
                    continue;
                }
            }
        }
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
        $limit  = max(10, min(500, (int) ($filters['limit'] ?? 200)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $rows = db_rows(
            "SELECT c.*, r.name AS rider_name, r.rider_code,
                    u.original_filename
               FROM settlement_rider_cycles c
               INNER JOIN riders r ON r.id = c.rider_id
               LEFT JOIN settlement_uploads u ON u.id = c.upload_id
              WHERE {$where}
              ORDER BY c.settlement_date DESC, c.id DESC
              LIMIT {$limit} OFFSET {$offset}",
            $params
        );

        return array_map([self::class, 'mapCycleRow'], $rows);
    }

    /**
     * listAdmin()과 동일한 필터로 전체 건수만 센다(페이징용).
     *
     * @param array<string, mixed> $filters
     */
    public static function countAdmin(array $filters = []): int
    {
        if (!self::tableExists()) {
            return 0;
        }

        [$where, $params] = self::buildListWhere($filters, false);

        $row = db_row(
            "SELECT COUNT(*) AS cnt
               FROM settlement_rider_cycles c
               INNER JOIN riders r ON r.id = c.rider_id
              WHERE {$where}",
            $params
        );

        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * listAdmin()과 동일한 필터로 기간 합계를 낸다.
     *
     * ⚠️ 화면이 표시 상한(예: 500행)에 걸려 일부만 보여주더라도, 합계는 **필터 조건 전체**를
     *    대상으로 집계해야 맞다. 그래서 목록과 별도 쿼리로 구한다.
     *
     * @param array<string, mixed> $filters
     * @return array{count:int, orders:int, gross:int, support:int, payout:int, fee:int, net:int}
     */
    public static function sumAdmin(array $filters = []): array
    {
        $empty = ['count' => 0, 'orders' => 0, 'gross' => 0, 'support' => 0, 'payout' => 0, 'fee' => 0, 'net' => 0];
        if (!self::tableExists()) {
            return $empty;
        }

        [$where, $params] = self::buildListWhere($filters, false);

        $row = db_row(
            "SELECT COUNT(*) AS cnt,
                    COALESCE(SUM(c.order_count), 0)      AS orders,
                    COALESCE(SUM(c.gross_amount), 0)     AS gross,
                    COALESCE(SUM(c.support_amount), 0)   AS support,
                    COALESCE(SUM(c.platform_payout), 0)  AS payout,
                    COALESCE(SUM(c.total_fee_amount), 0) AS fee,
                    COALESCE(SUM(c.net_amount), 0)       AS net
               FROM settlement_rider_cycles c
               INNER JOIN riders r ON r.id = c.rider_id
              WHERE {$where}",
            $params
        );

        if ($row === null) {
            return $empty;
        }

        return [
            'count'   => (int) $row['cnt'],
            'orders'  => (int) $row['orders'],
            'gross'   => (int) $row['gross'],
            'support' => (int) $row['support'],
            'payout'  => (int) $row['payout'],
            'fee'     => (int) $row['fee'],
            'net'     => (int) $row['net'],
        ];
    }

    /**
     * listAdmin()과 동일한 필터로 수수료·차감 항목(fee_code)별 합계를 낸다.
     *
     * `label`은 행마다 다를 수 있어(예: "대여금 · 계약명") 대표 라벨은 fee_code 기준
     * 표준 명칭을 우선 쓰고, 없으면 실제 저장된 라벨 하나를 쓴다.
     *
     * @param array<string, mixed> $filters
     * @return list<array{fee_code:string, label:string, count:int, amount:int, is_debt:bool}>
     */
    public static function feeBreakdownAdmin(array $filters = []): array
    {
        if (!self::tableExists()) {
            return [];
        }

        [$where, $params] = self::buildListWhere($filters, false);

        $rows = db_rows(
            "SELECT fi.fee_code, MIN(fi.label) AS label, COUNT(*) AS cnt,
                    COALESCE(SUM(fi.amount), 0) AS amount
               FROM settlement_fee_items fi
               INNER JOIN settlement_rider_cycles c ON c.id = fi.cycle_id
               INNER JOIN riders r ON r.id = c.rider_id
              WHERE {$where}
              GROUP BY fi.fee_code
              ORDER BY amount DESC",
            $params
        );

        // 미수금(대여금·리스·선지급)은 수수료가 아니라 원금 상환 차감 — 화면에서 구분 표기용
        $debtCodes = ['loan', 'lease', 'advance', 'rental'];
        $canonical = [
            'agency_fee'      => '선정산수수료(대행)',
            'withholding'     => '원천세',
            'employment_ins'  => '고용보험',
            'accident_ins'    => '산재보험',
            'hourly_ins'      => '시간제 보험',
            'vat'             => '부가세',
            'excel_deduction' => '차감내역',
            'ins_refund'      => '보험료 환급',
            'loan'            => '대여금',
            'lease'           => '리스/렌탈',
            'advance'         => '선지급',
            'rental'          => '대여금',
            'manual'          => '수동 차감',
        ];

        return array_map(static function (array $r) use ($debtCodes, $canonical): array {
            $code = (string) $r['fee_code'];

            return [
                'fee_code' => $code,
                'label'    => $canonical[$code] ?? (string) $r['label'],
                'count'    => (int) $r['cnt'],
                'amount'   => (int) $r['amount'],
                'is_debt'  => in_array($code, $debtCodes, true),
            ];
        }, $rows);
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

    /**
     * listForRider()와 동일한 필터로 라이더 1명의 기간 합계를 낸다(라이더 앱 기간 조회용).
     *
     * @param array<string, mixed> $filters
     * @return array{count:int, orders:int, gross:int, support:int, payout:int, fee:int, net:int}
     */
    public static function sumForRider(int $riderId, array $filters = []): array
    {
        $empty = ['count' => 0, 'orders' => 0, 'gross' => 0, 'support' => 0, 'payout' => 0, 'fee' => 0, 'net' => 0];
        if ($riderId < 1 || !self::tableExists()) {
            return $empty;
        }

        $filters['rider_id'] = $riderId;
        [$where, $params] = self::buildListWhere($filters, true);

        $row = db_row(
            "SELECT COUNT(*) AS cnt,
                    COALESCE(SUM(c.order_count), 0)      AS orders,
                    COALESCE(SUM(c.gross_amount), 0)     AS gross,
                    COALESCE(SUM(c.support_amount), 0)   AS support,
                    COALESCE(SUM(c.platform_payout), 0)  AS payout,
                    COALESCE(SUM(c.total_fee_amount), 0) AS fee,
                    COALESCE(SUM(c.net_amount), 0)       AS net
               FROM settlement_rider_cycles c
              WHERE {$where}",
            $params
        );

        if ($row === null) {
            return $empty;
        }

        return [
            'count'   => (int) $row['cnt'],
            'orders'  => (int) $row['orders'],
            'gross'   => (int) $row['gross'],
            'support' => (int) $row['support'],
            'payout'  => (int) $row['payout'],
            'fee'     => (int) $row['fee'],
            'net'     => (int) $row['net'],
        ];
    }

    /**
     * 라이더 화면에 표시할 공제 요율(원천세·고용보험·산재보험). `globalDeductionConfig()`의
     * 공개 래퍼 — 대리점(org) 설정이 있으면 그 값, 없으면 전역 기본값.
     *
     * @return array{withholding_tax_pct:float, employment_ins_pct:float, industrial_accident_ins_pct:float}
     */
    public static function deductionRates(?int $orgId = null): array
    {
        return self::globalDeductionConfig($orgId);
    }

    /**
     * listForRider()와 동일한 필터로 라이더 1명의 항목별(fee_code) 합계를 낸다.
     *
     * @param array<string, mixed> $filters
     * @return list<array{fee_code:string, label:string, count:int, amount:int, is_debt:bool}>
     */
    public static function feeBreakdownForRider(int $riderId, array $filters = []): array
    {
        if ($riderId < 1 || !self::tableExists()) {
            return [];
        }

        $filters['rider_id'] = $riderId;
        [$where, $params] = self::buildListWhere($filters, true);

        $rows = db_rows(
            "SELECT fi.fee_code, MIN(fi.label) AS label, COUNT(*) AS cnt,
                    COALESCE(SUM(fi.amount), 0) AS amount
               FROM settlement_fee_items fi
               INNER JOIN settlement_rider_cycles c ON c.id = fi.cycle_id
              WHERE {$where}
              GROUP BY fi.fee_code
              ORDER BY amount DESC",
            $params
        );

        $debtCodes = ['loan', 'lease', 'advance', 'rental'];
        $canonical = [
            'agency_fee'      => '선정산수수료(대행)',
            'withholding'     => '원천세',
            'employment_ins'  => '고용보험',
            'accident_ins'    => '산재보험',
            'hourly_ins'      => '시간제 보험',
            'vat'             => '부가세',
            'excel_deduction' => '차감내역',
            'ins_refund'      => '보험료 환급',
            'loan'            => '대여금',
            'lease'           => '리스/렌탈',
            'advance'         => '선지급',
            'rental'          => '대여금',
            'manual'          => '수동 차감',
        ];

        return array_map(static function (array $r) use ($debtCodes, $canonical): array {
            $code = (string) $r['fee_code'];

            return [
                'fee_code' => $code,
                'label'    => $canonical[$code] ?? (string) $r['label'],
                'count'    => (int) $r['cnt'],
                'amount'   => (int) $r['amount'],
                'is_debt'  => in_array($code, $debtCodes, true),
            ];
        }, $rows);
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
        } else {
            // 멀티테넌시: 관리자 조회 시 소속 대리점 스코프
            [$scopeSql, $scopeParams] = Org::agencyScopeClause('r.agency_id');
            if ($scopeSql !== '') {
                $riderSql .= ' AND ' . $scopeSql;
                $params    = array_merge($params, $scopeParams);
            }
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
     * 정산 반영·미리보기 공통 산식.
     *
     * base = 부가세 제외 정산액 + 지원금. 보수액·부가세는 쓰지 않는다.
     * 공제 = 시간제보험 + 엑셀 차감내역 + 선정산수수료/원천세(해당 시) + 고용 0.8% + 산재 0.88%
     *      + 수동 deduction_entries(엑셀 차감내역에서 등록한 건은 제외 — 이중차감 방지).
     *
     * @param array<string, mixed> $dailyRow
     * @param array<string, float> $cfg
     * @return array{base: int, fees: list<array{fee_code: string, label: string, amount: int}>}
     */
    public static function composeFeesForDailyRow(array $dailyRow, array $cfg, ?int $orgId = null): array
    {
        $support = (int) ($dailyRow['support_amount'] ?? 0);
        $base    = SettlementAmounts::exVat($dailyRow) + $support;
        $riderId = (int) ($dailyRow['rider_id'] ?? 0);
        $uploadId = (int) ($dailyRow['upload_id'] ?? 0);

        $fees = [];

        $hourlyIns = (int) ($dailyRow['hourly_insurance'] ?? 0);
        if ($hourlyIns > 0) {
            $fees[] = ['fee_code' => 'hourly_ins', 'label' => '시간제 보험', 'amount' => $hourlyIns];
        }

        foreach (SettlementAmounts::excelDeductions($uploadId, $riderId, (string) ($dailyRow['rider_name_raw'] ?? '')) as $ded) {
            $fees[] = [
                'fee_code' => $ded['fee_code'],
                'label'    => $ded['label'],
                'amount'   => $ded['amount'],
            ];
        }

        if ($riderId > 0) {
            $fees = array_merge(
                $fees,
                self::buildFeeItems($base, $riderId, (string) ($dailyRow['settlement_date'] ?? ''), $cfg, $orgId)
            );
        } else {
            $emp = self::pctAmount($base, (float) ($cfg['employment_ins_pct'] ?? 0));
            if ($emp > 0) {
                $fees[] = ['fee_code' => 'employment_ins', 'label' => '고용보험', 'amount' => $emp];
            }
            $acc = self::pctAmount($base, (float) ($cfg['industrial_accident_ins_pct'] ?? 0));
            if ($acc > 0) {
                $fees[] = ['fee_code' => 'accident_ins', 'label' => '산재보험', 'amount' => $acc];
            }
        }

        return ['base' => $base, 'fees' => $fees];
    }

    /**
     * 업로드 상세 모달용 — DB에 쓰지 않고 같은 산식으로 예상 실지급을 보여준다.
     *
     * @param array<string, mixed> $dailyRow
     * @return array{base: int, earn: int, fees: list<array{fee_code: string, label: string, amount: int}>, total_fee: int, net: int}
     */
    public static function previewFromDailyRow(array $dailyRow, ?int $orgId = null): array
    {
        $cfg      = self::globalDeductionConfig($orgId);
        $composed = self::composeFeesForDailyRow($dailyRow, $cfg, $orgId);
        $totalFee = 0;
        foreach ($composed['fees'] as $f) {
            $totalFee += (int) $f['amount'];
        }

        return [
            'base'      => $composed['base'],
            'earn'      => SettlementAmounts::exVat($dailyRow),
            'fees'      => $composed['fees'],
            'total_fee' => $totalFee,
            'net'       => max(0, $composed['base'] - $totalFee),
        ];
    }

    /**
     * @param array<string, mixed> $dailyRow settlement_daily_riders row
     * @param array<string, float> $cfg
     */
    private static function createCycleFromDailyRow(array $dailyRow, int $uploadId, array $cfg, ?int $adminId, ?int $orgId = null, string $teamRegion = ''): void
    {
        $riderId = (int) $dailyRow['rider_id'];
        $gross   = SettlementAmounts::exVat($dailyRow);
        $payout  = (int) ($dailyRow['payout_amount'] ?? 0);
        $support = (int) ($dailyRow['support_amount'] ?? 0);

        $composed = self::composeFeesForDailyRow($dailyRow, $cfg, $orgId);
        $base     = $composed['base'];
        $fees     = $composed['fees'];

        $totalFee = 0;
        foreach ($fees as $f) {
            $totalFee += (int) $f['amount'];
        }
        $net = max(0, $base - $totalFee);

        $cycleId = db_insert(
            'INSERT INTO settlement_rider_cycles
                (rider_id, upload_id, daily_rider_id, settlement_date, platform, team_region,
                 gross_amount, support_amount, platform_payout, total_fee_amount, net_amount, order_count,
                 completed_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $riderId,
                $uploadId,
                (int) ($dailyRow['id'] ?? 0) ?: null,
                (string) $dailyRow['settlement_date'],
                (string) ($dailyRow['platform'] ?? 'baemin'),
                $teamRegion,
                $gross,
                $support,
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

        // #15 원천세 예수금 누적 — 원천세 대상 라이더 공제분을 대리점 지갑 reserve에 적립.
        if ($orgId !== null && $orgId > 0) {
            $withheld = 0;
            foreach ($fees as $f) {
                if (($f['fee_code'] ?? '') === 'withholding') {
                    $withheld += (int) $f['amount'];
                }
            }
            if ($withheld > 0) {
                AgencyWallet::addWithholdingReserve($orgId, $withheld);
            }
        }
    }

    /**
     * @param array<string, float> $cfg
     * @return list<array{fee_code: string, label: string, amount: int}>
     */
    private static function buildFeeItems(int $base, int $riderId, string $settlementDate, array $cfg, ?int $orgId = null): array
    {
        $items = [];

        // 라이더 정산 유형·원천세 대상 여부
        $rider         = db_row('SELECT is_daily_settlement, withholding_tax_enabled FROM riders WHERE id = ? LIMIT 1', [$riderId]);
        $isDaily       = (int) ($rider['is_daily_settlement'] ?? 0) === 1;
        $withholdRider = (int) ($rider['withholding_tax_enabled'] ?? 0) === 1;

        // #7 선정산수수료(대행수수료) — 선정산(일일지급, is_daily_settlement=1) 라이더만 반영 시점에 부과.
        //
        // 주정산 라이더는 **여기서도, 출금 시점에도 대행수수료를 내지 않는다**(2026-08-08 갑 확정:
        // "출금 신청 시 건당 수수료만 적용하면 된다"). 예전엔 "주정산은 출금 시점에 부과하도록
        // 이연"으로 적혀 있었으나 그 계획 자체가 취소됐다 — 주정산 라이더가 부담하는 건
        // 출금 시 건당 정산수수료(WithdrawalCycles/WithdrawalConfig::feeForCycles)뿐이다.
        if ($isDaily) {
            $wallet  = RiderWallet::get($riderId);
            $accrued = (int) $wallet['accrued_days'];
            $agency  = AgencyFeeConfig::feeForAccruedDays($accrued, $orgId);
            if ($agency > 0) {
                $items[] = ['fee_code' => 'agency_fee', 'label' => '선정산수수료(대행)', 'amount' => $agency];
            }
        }

        // #15 원천세 — 대상 라이더만(대리점이 상세화면에서 설정). 세율 고정(3.3%). 예수금은 createCycleFromDailyRow에서 누적.
        if ($withholdRider) {
            $tax = self::pctAmount($base, (float) ($cfg['withholding_tax_pct'] ?? 0));
            if ($tax > 0) {
                $items[] = ['fee_code' => 'withholding', 'label' => '원천세', 'amount' => $tax];
            }
        }

        // #4 고용보험·산재보험 분리 — 쿠팡이 대납 처리하므로 예수금 아님, 단순 공제(라이더 몫에서 차감).
        $emp = self::pctAmount($base, (float) ($cfg['employment_ins_pct'] ?? 0));
        if ($emp > 0) {
            $items[] = ['fee_code' => 'employment_ins', 'label' => '고용보험', 'amount' => $emp];
        }
        $acc = self::pctAmount($base, (float) ($cfg['industrial_accident_ins_pct'] ?? 0));
        if ($acc > 0) {
            $items[] = ['fee_code' => 'accident_ins', 'label' => '산재보험', 'amount' => $acc];
        }

        if (db_table_exists('deduction_entries')) {
            // 엑셀 차감내역에서 등록된 deduction_entries는 이 업로드 반영 때
            // SettlementAmounts::excelDeductions 로 이미 빠지므로 여기서 제외한다.
            $excludeExcel = db_table_exists('settlement_weekly_deductions')
                ? ' AND NOT EXISTS (
                        SELECT 1 FROM settlement_weekly_deductions swd
                         WHERE swd.registered_entry_id = deduction_entries.id
                    )'
                : '';
            $manual = db_rows(
                'SELECT kind, amount, note FROM deduction_entries
                  WHERE rider_id = ? AND applied_date = ? AND amount <> 0' . $excludeExcel . '
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
    private static function globalDeductionConfig(?int $orgId = null): array
    {
        if (!db_table_exists('deduction_global_config')) {
            return [
                'withholding_tax_pct'         => 3.3,
                'employment_ins_pct'          => 0.80,
                'industrial_accident_ins_pct' => 0.88,
            ];
        }

        // 대리점(org) 행 → 전역 기본(org_id NULL) 순 폴백
        $row = null;
        if ($orgId !== null && $orgId > 0) {
            $row = db_row('SELECT withholding_tax_pct, employment_ins_pct, industrial_accident_ins_pct FROM deduction_global_config WHERE org_id = ? LIMIT 1', [$orgId]);
        }
        if ($row === null) {
            $row = db_row('SELECT withholding_tax_pct, employment_ins_pct, industrial_accident_ins_pct FROM deduction_global_config WHERE org_id IS NULL ORDER BY id ASC LIMIT 1');
        }

        return [
            'withholding_tax_pct'         => (float) ($row['withholding_tax_pct'] ?? 3.3),
            'employment_ins_pct'          => (float) ($row['employment_ins_pct'] ?? 0.80),
            'industrial_accident_ins_pct' => (float) ($row['industrial_accident_ins_pct'] ?? 0.88),
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
            'employment_ins' => '고용보험(수동)',
            'accident_ins'   => '산재보험(수동)',
            'agency_fee'      => '선정산수수료(수동)',
            'hourly_ins'      => '시간제 보험',
            'vat'             => '부가세',
            'excel_deduction' => '차감내역',
            'ins_refund'      => '보험료 환급',
            'rental'         => '대여금',
            'loan'           => '대여금',
            'lease'          => '리스/렌탈',
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

        // 멀티테넌시: 관리자 조회는 소속 대리점 스코프 (라이더 앱은 rider_id로 이미 제한)
        if (!$riderScoped) {
            [$scopeSql, $scopeParams] = Org::agencyScopeClause('r.agency_id');
            if ($scopeSql !== '') {
                $where[] = $scopeSql;
                $params  = array_merge($params, $scopeParams);
            }
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
            'team_region'      => (string) ($row['team_region'] ?? ''),
            'gross_amount'     => (int) $row['gross_amount'],
            'support_amount'   => (int) ($row['support_amount'] ?? 0),
            'platform_payout'  => (int) $row['platform_payout'],
            'total_fee_amount' => (int) $row['total_fee_amount'],
            'net_amount'       => (int) $row['net_amount'],
            'order_count'      => (int) $row['order_count'],
            'completed_at'     => $completed ? substr((string) $completed, 0, 16) : '',
        ];
    }
}
