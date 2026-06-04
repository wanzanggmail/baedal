<?php

declare(strict_types=1);

/**
 * 시스템 코드 마스터 (system_codes)
 */
final class SystemCode
{
    /** @var list<string> */
    public const CATEGORIES = [
        'bank',
        'vehicle',
        'rider_status',
        'settlement_status',
        'withdrawal_status',
        'platform',
        'deduction_kind',
    ];

    /** @return array<string, string> */
    public static function categoryLabels(): array
    {
        return [
            'bank'              => '은행 코드',
            'vehicle'           => '차량 유형',
            'rider_status'      => '라이더 상태',
            'settlement_status' => '정산 처리 상태',
            'withdrawal_status' => '출금 신청 상태',
            'platform'          => '배달 플랫폼',
            'deduction_kind'    => '차감 종류',
        ];
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public static function listGrouped(): array
    {
        $rows = db_rows(
            'SELECT id, category, code, label, sort_order, is_active
             FROM system_codes
             ORDER BY category ASC, sort_order ASC, id ASC'
        );

        $grouped = [];
        foreach (self::CATEGORIES as $cat) {
            $grouped[$cat] = [];
        }

        foreach ($rows as $row) {
            $cat = (string) ($row['category'] ?? '');
            if (!isset($grouped[$cat])) {
                $grouped[$cat] = [];
            }
            $grouped[$cat][] = self::mapRow($row);
        }

        return $grouped;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listByCategory(string $category, bool $activeOnly = false): array
    {
        self::validateCategory($category);
        $sql = 'SELECT id, category, code, label, sort_order, is_active
                FROM system_codes WHERE category = ?';
        $params = [$category];
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';

        return array_map([self::class, 'mapRow'], db_rows($sql, $params));
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        $row = db_row(
            'SELECT id, category, code, label, sort_order, is_active
             FROM system_codes WHERE id = ? LIMIT 1',
            [$id]
        );

        return $row !== null ? self::mapRow($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function save(array $data): array
    {
        $id       = (int) ($data['id'] ?? 0);
        $category = trim((string) ($data['category'] ?? ''));
        $code     = trim((string) ($data['code'] ?? ''));
        $label    = trim((string) ($data['label'] ?? ''));
        $sort     = (int) ($data['sort_order'] ?? $data['sort'] ?? 100);
        $active   = !empty($data['is_active']) || !empty($data['active']);

        self::validateCategory($category);
        self::validateCode($code);
        if ($label === '' || strlen($label) > 80) {
            throw new InvalidArgumentException('표시명을 확인하세요. (80자 이내)');
        }
        if ($sort < 0 || $sort > 32767) {
            throw new InvalidArgumentException('정렬 값을 확인하세요.');
        }

        if ($id > 0) {
            $existing = db_row(
                'SELECT id, category, code FROM system_codes WHERE id = ? LIMIT 1',
                [$id]
            );
            if ($existing === null) {
                throw new InvalidArgumentException('코드를 찾을 수 없습니다.');
            }
            if ((string) $existing['category'] !== $category) {
                throw new InvalidArgumentException('카테고리는 변경할 수 없습니다.');
            }
            if ((string) $existing['code'] !== $code) {
                throw new InvalidArgumentException('코드 값은 변경할 수 없습니다.');
            }

            db_execute(
                'UPDATE system_codes
                 SET label = ?, sort_order = ?, is_active = ?
                 WHERE id = ?',
                [$label, $sort, $active ? 1 : 0, $id]
            );

            $row = self::find($id);
        } else {
            if (db_row(
                'SELECT id FROM system_codes WHERE category = ? AND code = ? LIMIT 1',
                [$category, $code]
            ) !== null) {
                throw new InvalidArgumentException('같은 카테고리에 동일 코드가 이미 있습니다.');
            }

            $newId = db_insert(
                'INSERT INTO system_codes (category, code, label, sort_order, is_active)
                 VALUES (?, ?, ?, ?, ?)',
                [$category, $code, $label, $sort, $active ? 1 : 0]
            );
            $row = self::find($newId);
        }

        if ($row === null) {
            throw new RuntimeException('저장된 코드를 불러올 수 없습니다.');
        }

        return $row;
    }

    public static function delete(int $id): void
    {
        $row = db_row(
            'SELECT id, category, code FROM system_codes WHERE id = ? LIMIT 1',
            [$id]
        );
        if ($row === null) {
            throw new InvalidArgumentException('코드를 찾을 수 없습니다.');
        }

        $category = (string) $row['category'];
        $code     = (string) $row['code'];
        $usage    = self::usageCount($category, $code);
        if ($usage > 0) {
            throw new InvalidArgumentException("사용 중인 코드는 삭제할 수 없습니다. (참조 {$usage}건) — 사용 중지로 변경하세요.");
        }

        db_execute('DELETE FROM system_codes WHERE id = ?', [$id]);
    }

    public static function usageCount(string $category, string $code): int
    {
        if (!db_table_exists('system_codes')) {
            return 0;
        }

        return match ($category) {
            'bank' => self::countWhere('riders', 'bank_code = ?', [$code]),
            'vehicle' => self::countWhere('riders', 'vehicle_type = ?', [$code]),
            'rider_status' => self::countWhere('riders', 'status = ?', [$code]),
            'withdrawal_status' => db_table_exists('withdrawal_requests')
                ? self::countWhere('withdrawal_requests', 'status = ?', [$code])
                : 0,
            'settlement_status' => db_table_exists('settlement_uploads')
                ? self::countWhere('settlement_uploads', 'status = ?', [$code])
                : 0,
            default => 0,
        };
    }

    /** @param array<string, mixed> $row */
    private static function mapRow(array $row): array
    {
        return [
            'id'         => (int) ($row['id'] ?? 0),
            'category'   => (string) ($row['category'] ?? ''),
            'code'       => (string) ($row['code'] ?? ''),
            'label'      => (string) ($row['label'] ?? ''),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'sort'       => (int) ($row['sort_order'] ?? 0),
            'is_active'  => (int) ($row['is_active'] ?? 0) === 1,
            'active'     => (int) ($row['is_active'] ?? 0) === 1,
        ];
    }

    private static function validateCategory(string $category): void
    {
        if (!in_array($category, self::CATEGORIES, true)) {
            throw new InvalidArgumentException('올바르지 않은 코드 카테고리입니다.');
        }
    }

    private static function validateCode(string $code): void
    {
        if ($code === '' || strlen($code) > 40) {
            throw new InvalidArgumentException('코드를 확인하세요. (40자 이내)');
        }
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $code)) {
            throw new InvalidArgumentException('코드는 영문, 숫자, . _ - 만 사용할 수 있습니다.');
        }
    }

    /** @param list<mixed> $params */
    private static function countWhere(string $table, string $where, array $params): int
    {
        if (!db_table_exists($table)) {
            return 0;
        }

        return (int) (db_row("SELECT COUNT(*) AS c FROM {$table} WHERE {$where}", $params)['c'] ?? 0);
    }
}
