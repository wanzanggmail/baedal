<?php

declare(strict_types=1);

require_once INC_PATH . '/WithdrawalConfig.php';
require_once INC_PATH . '/RiderWallet.php';

/**
 * 출금 신청 조회·상태 변경·라이더 신청
 */
final class Withdrawal
{
    /** @var array<string, array{0: string, 1: string}> */
    private const STATUS_LABELS = [
        'pending'    => ['대기', 'warning'],
        'downloaded' => ['다운로드 완료', 'primary'],
        'completed'  => ['처리 완료', 'success'],
        'rejected'   => ['반려', 'danger'],
    ];

    /** @var array<string, string> */
    private const KIND_LABELS = [
        'auto_daily'    => '자동일일',
        'rider_manual'  => '라이더신청',
    ];

    public static function publicId(int $dbId, string $kind): string
    {
        return ($kind === 'auto_daily' ? 'wd-auto-' : 'wd-') . $dbId;
    }

    public static function parseId(mixed $raw): ?int
    {
        if (is_int($raw)) {
            return $raw > 0 ? $raw : null;
        }
        $s = trim((string) $raw);
        if ($s === '') {
            return null;
        }
        if (ctype_digit($s)) {
            return (int) $s;
        }
        if (preg_match('/^wd(?:-auto)?-(\d+)$/i', $s, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public static function list(array $filters = []): array
    {
        $where  = ['1=1'];
        $params = [];

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && isset(self::STATUS_LABELS[$status])) {
            $where[]  = 'wr.status = ?';
            $params[] = $status;
        }

        $kind = trim((string) ($filters['kind'] ?? ''));
        if ($kind !== '' && isset(self::KIND_LABELS[$kind])) {
            $where[]  = 'wr.kind = ?';
            $params[] = $kind;
        }

        $from = trim((string) ($filters['from'] ?? ''));
        if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $where[]  = 'DATE(wr.requested_at) >= ?';
            $params[] = $from;
        }

        $to = trim((string) ($filters['to'] ?? ''));
        if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $where[]  = 'DATE(wr.requested_at) <= ?';
            $params[] = $to;
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like     = '%' . $q . '%';
            $where[]  = '(r.name LIKE ? OR r.rider_code LIKE ? OR wr.bank_account LIKE ? OR wr.account_holder LIKE ?)';
            $params   = array_merge($params, [$like, $like, $like, $like]);
        }

        // 멀티테넌시: 라이더 소속 대리점 스코프
        [$scopeSql, $scopeParams] = Org::agencyScopeClause('r.agency_id');
        if ($scopeSql !== '') {
            $where[]  = $scopeSql;
            $params   = array_merge($params, $scopeParams);
        }

        $limit = max(1, min(500, (int) ($filters['limit'] ?? 300)));
        $whereStr = implode(' AND ', $where);

        $rows = db_rows(
            "SELECT wr.*,
                    r.name AS rider_name,
                    r.rider_code,
                    sc.label AS bank_label
               FROM withdrawal_requests wr
               INNER JOIN riders r ON r.id = wr.rider_id
               LEFT JOIN system_codes sc
                      ON sc.category = 'bank' AND sc.code = wr.bank_code
              WHERE {$whereStr}
              ORDER BY wr.requested_at DESC, wr.id DESC
              LIMIT {$limit}",
            $params
        );

        return array_map([self::class, 'mapRow'], $rows);
    }

    /**
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public static function listByIds(array $ids, string $requiredStatus = 'pending'): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params       = $ids;

        $statusSql = '';
        if ($requiredStatus !== '' && isset(self::STATUS_LABELS[$requiredStatus])) {
            $statusSql = ' AND wr.status = ?';
            $params[]  = $requiredStatus;
        }

        $rows = db_rows(
            "SELECT wr.*,
                    r.name AS rider_name,
                    r.rider_code,
                    sc.label AS bank_label
               FROM withdrawal_requests wr
               INNER JOIN riders r ON r.id = wr.rider_id
               LEFT JOIN system_codes sc
                      ON sc.category = 'bank' AND sc.code = wr.bank_code
              WHERE wr.id IN ({$placeholders}){$statusSql}
              ORDER BY wr.id ASC",
            $params
        );

        return array_map([self::class, 'mapRow'], $rows);
    }

    /**
     * 멀티테넌시: 주어진 출금 id 중 현재 계정 스코프(라이더 소속 대리점) 내의 것만 반환.
     *
     * @param list<int> $ids
     * @return list<int>
     */
    public static function scopeFilterIds(array $ids): array
    {
        $ids = self::normalizeIds($ids);
        if ($ids === []) {
            return [];
        }
        [$scopeSql, $scopeParams] = Org::agencyScopeClause('r.agency_id');
        if ($scopeSql === '') {
            return $ids; // 본사: 전체 허용
        }
        $ph   = implode(',', array_fill(0, count($ids), '?'));
        $rows = db_rows(
            "SELECT wr.id FROM withdrawal_requests wr
               INNER JOIN riders r ON r.id = wr.rider_id
              WHERE wr.id IN ({$ph}) AND {$scopeSql}",
            array_merge($ids, $scopeParams)
        );

        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }

    /**
     * @return array{pending_count: int, pending_amount: int, downloaded_count: int}
     */
    public static function summary(): array
    {
        // 멀티테넌시: 라이더 소속 대리점 스코프
        [$scopeSql, $scopeParams] = Org::agencyScopeClause('r.agency_id');
        $join     = $scopeSql !== '' ? 'INNER JOIN riders r ON r.id = wr.rider_id' : '';
        $whereSql = $scopeSql !== '' ? 'WHERE ' . $scopeSql : '';

        $row = db_row(
            "SELECT
                SUM(CASE WHEN wr.status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN wr.status = 'pending' THEN wr.amount ELSE 0 END) AS pending_amount,
                SUM(CASE WHEN wr.status = 'downloaded' THEN 1 ELSE 0 END) AS downloaded_count
             FROM withdrawal_requests wr {$join} {$whereSql}",
            $scopeParams
        );

        return [
            'pending_count'    => (int) ($row['pending_count'] ?? 0),
            'pending_amount'   => (int) ($row['pending_amount'] ?? 0),
            'downloaded_count' => (int) ($row['downloaded_count'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $w
     * @return array<string, mixed>
     */
    public static function mapRow(array $w): array
    {
        $dbId   = (int) $w['id'];
        $kind   = (string) ($w['kind'] ?? 'rider_manual');
        $status = (string) ($w['status'] ?? 'pending');
        [$statusLabel, $statusClass] = self::STATUS_LABELS[$status] ?? [$status, 'secondary'];
        $kindLabel = self::KIND_LABELS[$kind] ?? $kind;

        $withholdSum = (int) ($w['withhold_tax'] ?? 0)
            + (int) ($w['withhold_refund'] ?? 0)
            + (int) ($w['withhold_other'] ?? 0)
            + (int) ($w['withhold_min_retain'] ?? 0)
            + (int) ($w['withhold_round_trim'] ?? 0);

        $accruedDays = (int) ($w['accrued_days'] ?? 0);
        $reserve     = (int) ($w['withhold_min_retain'] ?? 0);
        $fee         = (int) ($w['withhold_other'] ?? 0);

        $tip = '';
        if ($kind === 'auto_daily' && (int) ($w['gross_amount'] ?? 0) > 0) {
            $tip = sprintf(
                '정산일 %s · 총 %s원 · 보류 %s원',
                $w['settlement_date'] ?? '',
                number_format((int) $w['gross_amount']),
                number_format($withholdSum)
            );
        } elseif ($kind === 'rider_manual') {
            $tip = sprintf(
                '잔액 %s원 · 보증금 %s원 · 수수료 %s원(건당) · 적립 %d일',
                number_format((int) ($w['gross_amount'] ?? 0)),
                number_format($reserve),
                number_format($fee),
                $accruedDays
            );
        }

        return [
            'db_id'            => $dbId,
            'id'               => self::publicId($dbId, $kind),
            'rider_id'         => (string) ($w['rider_code'] ?: $w['rider_id']),
            'rider_name'       => (string) $w['rider_name'],
            'bank'             => (string) ($w['bank_label'] ?: '—'),
            'bank_code'        => (string) ($w['bank_code'] ?? ''),
            'account'          => (string) ($w['bank_account'] ?? ''),
            'holder'           => (string) ($w['account_holder'] ?? $w['rider_name']),
            'amount'           => (int) $w['amount'],
            'gross_amount'     => (int) ($w['gross_amount'] ?? 0),
            'reserve_amount'   => $reserve,
            'withdrawal_fee'   => $fee,
            'accrued_days'     => $accruedDays,
            'settlement_date'  => $w['settlement_date'] ? (string) $w['settlement_date'] : '',
            'kind'             => $kind,
            'kind_label'       => $kindLabel,
            'requested_at'     => substr((string) ($w['requested_at'] ?? $w['created_at']), 0, 19),
            'completed_at'     => $w['completed_at'] ? substr((string) $w['completed_at'], 0, 19) : '',
            'status'           => $status,
            'status_label'     => $statusLabel,
            'status_class'     => $statusClass,
            'note'             => (string) ($w['note'] ?? ''),
            'tip'              => $tip,
        ];
    }

    /**
     * @param list<int> $ids
     */
    public static function markDownloaded(array $ids, ?int $batchId = null): int
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params       = $ids;

        if ($batchId !== null && $batchId > 0) {
            return db_execute(
                "UPDATE withdrawal_requests
                    SET status = 'downloaded', download_batch_id = ?
                  WHERE id IN ({$placeholders}) AND status = 'pending'",
                array_merge([$batchId], $params)
            );
        }

        return db_execute(
            "UPDATE withdrawal_requests
                SET status = 'downloaded'
              WHERE id IN ({$placeholders}) AND status = 'pending'",
            $params
        );
    }

    /**
     * @param list<int> $ids
     */
    public static function markCompleted(array $ids): int
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = db_rows(
            "SELECT id, rider_id, kind, amount, withhold_other, withhold_min_retain, status
               FROM withdrawal_requests
              WHERE id IN ({$placeholders}) AND status = 'downloaded'",
            $ids
        );
        if ($rows === []) {
            return 0;
        }

        $completed = 0;
        db_transaction(static function () use ($rows, &$completed): void {
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $n  = db_execute(
                    "UPDATE withdrawal_requests
                        SET status = 'completed', completed_at = NOW()
                      WHERE id = ? AND status = 'downloaded'",
                    [$id]
                );
                if ($n < 1) {
                    continue;
                }
                $completed++;
                if ((string) ($row['kind'] ?? '') === 'rider_manual') {
                    // 지갑에서 실제로 빠지는 총액 = 실지급액 + 정산수수료(withhold_other).
                    // 보증금(withhold_min_retain)은 지급하지 않고 지갑에 남는 몫이라 차감 대상이 아니다.
                    RiderWallet::deductAfterWithdrawal(
                        (int) $row['rider_id'],
                        (int) ($row['amount'] ?? 0) + (int) ($row['withhold_other'] ?? 0)
                    );
                }
            }
        });

        return $completed;
    }

    /**
     * @param list<int> $ids
     */
    public static function markRejected(array $ids, string $reason = ''): int
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $reason       = mb_substr(trim($reason), 0, 300);

        return db_execute(
            "UPDATE withdrawal_requests
                SET status = 'rejected', rejected_reason = ?
              WHERE id IN ({$placeholders}) AND status IN ('pending', 'downloaded')",
            array_merge([$reason], $ids)
        );
    }

    /**
     * @param list<mixed> $rawIds
     * @return list<int>
     */
    public static function normalizeIds(array $rawIds): array
    {
        $out = [];
        foreach ($rawIds as $raw) {
            $id = self::parseId($raw);
            if ($id !== null) {
                $out[] = $id;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listForRider(int $riderId, int $limit = 50): array
    {
        if ($riderId < 1) {
            return [];
        }

        $limit = max(1, min(100, $limit));
        $rows  = db_rows(
            "SELECT wr.*,
                    r.name AS rider_name,
                    r.rider_code,
                    sc.label AS bank_label
               FROM withdrawal_requests wr
               INNER JOIN riders r ON r.id = wr.rider_id
               LEFT JOIN system_codes sc
                      ON sc.category = 'bank' AND sc.code = wr.bank_code
              WHERE wr.rider_id = ?
              ORDER BY wr.requested_at DESC, wr.id DESC
              LIMIT {$limit}",
            [$riderId]
        );

        return array_map([self::class, 'mapRow'], $rows);
    }

    public static function hasOpenRiderRequest(int $riderId): bool
    {
        if ($riderId < 1) {
            return true;
        }

        $row = db_row(
            "SELECT id FROM withdrawal_requests
              WHERE rider_id = ? AND kind = 'rider_manual'
                AND status IN ('pending', 'downloaded')
              LIMIT 1",
            [$riderId]
        );

        return $row !== null;
    }

    /**
     * 라이더 전액 출금 신청 (보증금·건당 수수료 차감)
     *
     * @return array<string, mixed>
     */
    public static function applyForRider(int $riderId): array
    {
        if ($riderId < 1) {
            throw new InvalidArgumentException('라이더 정보가 없습니다.');
        }

        if (!db_table_exists('withdrawal_requests')) {
            throw new RuntimeException('출금 테이블이 없습니다. php migrate.php 를 실행하세요.');
        }
        if (!db_table_exists('rider_wallets')) {
            throw new RuntimeException('지갑 테이블이 없습니다. php migrate.php 를 실행하세요.');
        }

        $rider = db_row(
            'SELECT id, name, status, withdrawal_hold, bank_code, bank_account, account_holder
             FROM riders WHERE id = ? LIMIT 1',
            [$riderId]
        );
        if ($rider === null) {
            throw new InvalidArgumentException('라이더를 찾을 수 없습니다.');
        }
        if ((string) ($rider['status'] ?? '') !== 'active') {
            throw new InvalidArgumentException('활동 중인 라이더만 출금 신청할 수 있습니다.');
        }
        if ((int) ($rider['withdrawal_hold'] ?? 0) === 1) {
            throw new InvalidArgumentException('출금 보류 상태입니다. 관리자에게 문의하세요.');
        }
        if (trim((string) ($rider['bank_code'] ?? '')) === '' || trim((string) ($rider['bank_account'] ?? '')) === '') {
            throw new InvalidArgumentException('출금 계좌를 먼저 등록해 주세요.');
        }
        if (self::hasOpenRiderRequest($riderId)) {
            throw new InvalidArgumentException('처리 중인 출금 신청이 있습니다. 완료 후 다시 신청해 주세요.');
        }

        $preview = RiderWallet::previewWithdrawal($riderId);
        if (!(bool) ($preview['can_apply'] ?? false)) {
            throw new InvalidArgumentException('출금 가능 금액이 없습니다. (보증금·수수료 차감 후 0원)');
        }

        $balance  = (int) $preview['balance'];
        $reserve  = (int) $preview['reserve_amount'];
        $fee      = (int) $preview['fee_per_tx'];
        $payout   = (int) $preview['payout_amount'];
        $accrued  = (int) $preview['accrued_days'];

        $hasAccruedCol = self::hasAccruedDaysColumn();
        $note = sprintf(
            '라이더 전액 출금 · 적립 %d일 · 보증금 %s원 · 수수료 %s원(건당)',
            $accrued,
            number_format($reserve),
            number_format($fee)
        );

        if ($hasAccruedCol) {
            $newId = db_insert(
                'INSERT INTO withdrawal_requests
                    (rider_id, kind, amount, gross_amount,
                     withhold_min_retain, withhold_other, accrued_days,
                     bank_code, bank_account, account_holder,
                     status, note, requested_at)
                 VALUES (?, \'rider_manual\', ?, ?,
                         ?, ?, ?,
                         ?, ?, ?,
                         \'pending\', ?, NOW())',
                [
                    $riderId,
                    $payout,
                    $balance,
                    $reserve,
                    $fee,
                    $accrued,
                    (string) $rider['bank_code'],
                    (string) $rider['bank_account'],
                    (string) ($rider['account_holder'] ?: $rider['name']),
                    $note,
                ]
            );
        } else {
            $newId = db_insert(
                'INSERT INTO withdrawal_requests
                    (rider_id, kind, amount, gross_amount,
                     withhold_min_retain, withhold_other,
                     bank_code, bank_account, account_holder,
                     status, note, requested_at)
                 VALUES (?, \'rider_manual\', ?, ?,
                         ?, ?,
                         ?, ?, ?,
                         \'pending\', ?, NOW())',
                [
                    $riderId,
                    $payout,
                    $balance,
                    $reserve,
                    $fee,
                    (string) $rider['bank_code'],
                    (string) $rider['bank_account'],
                    (string) ($rider['account_holder'] ?: $rider['name']),
                    $note,
                ]
            );
        }

        $row = db_row(
            'SELECT wr.*, r.name AS rider_name, r.rider_code, sc.label AS bank_label
             FROM withdrawal_requests wr
             INNER JOIN riders r ON r.id = wr.rider_id
             LEFT JOIN system_codes sc ON sc.category = \'bank\' AND sc.code = wr.bank_code
             WHERE wr.id = ? LIMIT 1',
            [$newId]
        );

        if ($row === null) {
            throw new RuntimeException('출금 신청 저장 후 조회에 실패했습니다.');
        }

        return self::mapRow($row);
    }

    private static function hasAccruedDaysColumn(): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        if (!db_table_exists('withdrawal_requests')) {
            $cache = false;

            return false;
        }
        $cols = array_column(db_rows('SHOW COLUMNS FROM withdrawal_requests'), 'Field');
        $cache = in_array('accrued_days', $cols, true);

        return $cache;
    }
}
