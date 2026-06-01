<?php

declare(strict_types=1);

/**
 * 출금 신청 조회·상태 변경
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
     * @return array{pending_count: int, pending_amount: int, downloaded_count: int}
     */
    public static function summary(): array
    {
        $row = db_row(
            'SELECT
                SUM(CASE WHEN status = \'pending\' THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN status = \'pending\' THEN amount ELSE 0 END) AS pending_amount,
                SUM(CASE WHEN status = \'downloaded\' THEN 1 ELSE 0 END) AS downloaded_count
             FROM withdrawal_requests'
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

        $tip = '';
        if ($kind === 'auto_daily' && (int) ($w['gross_amount'] ?? 0) > 0) {
            $tip = sprintf(
                '정산일 %s · 총 %s원 · 보류 %s원',
                $w['settlement_date'] ?? '',
                number_format((int) $w['gross_amount']),
                number_format($withholdSum)
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

        return db_execute(
            "UPDATE withdrawal_requests
                SET status = 'completed', completed_at = NOW()
              WHERE id IN ({$placeholders}) AND status = 'downloaded'",
            $ids
        );
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
}
