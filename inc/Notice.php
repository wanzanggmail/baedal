<?php

declare(strict_types=1);

/**
 * 공지사항 CRUD (관리자·라이더 앱)
 */
final class Notice
{
    /** @var array<string, array{0: string, 1: string}> */
    private const STATUS_LABELS = [
        'draft'     => ['초안', 'warning'],
        'published' => ['게시', 'success'],
        'hidden'    => ['숨김', 'dark'],
    ];

    /** @var list<string> */
    private const CATEGORIES = ['일반', '안내', '긴급'];

    public static function categories(): array
    {
        return self::CATEGORIES;
    }

    public static function statusLabel(string $status): array
    {
        return self::STATUS_LABELS[$status] ?? ['—', 'secondary'];
    }

    public static function categoryBadgeClass(string $category): string
    {
        return match ($category) {
            '긴급' => 'danger',
            '안내' => 'primary',
            default => 'secondary',
        };
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
        if (preg_match('/^nt-\d{8}-(\d+)$/i', $s, $m)) {
            $row = db_row('SELECT id FROM content_notices WHERE public_id = ? LIMIT 1', [$s]);

            return $row ? (int) $row['id'] : null;
        }
        $row = db_row('SELECT id FROM content_notices WHERE public_id = ? LIMIT 1', [$s]);

        return $row ? (int) $row['id'] : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listAdmin(): array
    {
        $rows = db_rows(
            'SELECT n.*, a.name AS created_by_name
             FROM content_notices n
             LEFT JOIN admins a ON a.id = n.created_by
             ORDER BY n.pinned DESC, COALESCE(n.published_at, n.updated_at) DESC, n.id DESC'
        );

        return array_map([self::class, 'mapAdminRow'], $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listPublishedForRider(int $limit = 50): array
    {
        $rows = db_rows(
            'SELECT id, public_id, title, body, category, pinned, status, published_at, updated_at
             FROM content_notices
             WHERE status = \'published\'
               AND (published_at IS NULL OR published_at <= NOW())
             ORDER BY pinned DESC, published_at DESC, id DESC
             LIMIT ' . max(1, min(100, $limit)),
            []
        );

        return array_map([self::class, 'mapRiderRow'], $rows);
    }

    public static function findForRider(int $id): ?array
    {
        $row = db_row(
            'SELECT id, public_id, title, body, category, pinned, status, published_at, updated_at
             FROM content_notices
             WHERE id = ? AND status = \'published\'
               AND (published_at IS NULL OR published_at <= NOW())
             LIMIT 1',
            [$id]
        );

        return $row ? self::mapRiderRow($row) : null;
    }

    /**
     * 로그인 직후 팝업 큐: 상단 고정 → 긴급 순 (최대 10건)
     *
     * @return list<array<string, mixed>>
     */
    public static function loginPopupQueue(): array
    {
        $rows = db_rows(
            'SELECT id, public_id, title, body, category, pinned, published_at
             FROM content_notices
             WHERE status = \'published\'
               AND (published_at IS NULL OR published_at <= NOW())
               AND (pinned = 1 OR category = \'긴급\')
             ORDER BY pinned DESC, published_at DESC, id DESC
             LIMIT 10'
        );

        return array_map([self::class, 'mapRiderRow'], $rows);
    }

    /** @deprecated loginPopupQueue() 사용 */
    public static function loginPopup(): ?array
    {
        $q = self::loginPopupQueue();

        return $q[0] ?? null;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function save(array $input, ?int $adminId = null): array
    {
        $id = self::parseId($input['id'] ?? $input['notice_id'] ?? null);
        $title = trim((string) ($input['title'] ?? ''));
        $body = (string) ($input['body'] ?? '');
        $category = trim((string) ($input['category'] ?? '일반'));
        $status = trim((string) ($input['status'] ?? 'draft'));
        $pinned = !empty($input['pinned']) ? 1 : 0;
        $pubAtRaw = trim((string) ($input['published_at'] ?? ''));

        if ($title === '') {
            throw new InvalidArgumentException('제목을 입력하세요.');
        }
        if (trim($body) === '') {
            throw new InvalidArgumentException('본문을 입력하세요.');
        }
        if (!in_array($category, self::CATEGORIES, true)) {
            $category = '일반';
        }
        if (!isset(self::STATUS_LABELS[$status])) {
            $status = 'draft';
        }

        $publishedAt = self::normalizeDateTime($pubAtRaw);
        if ($status === 'published' && $publishedAt === null) {
            $publishedAt = date('Y-m-d H:i:s');
        }
        if ($status !== 'published') {
            $publishedAt = $publishedAt ?? null;
        }

        if ($id) {
            $exists = db_row('SELECT id, public_id FROM content_notices WHERE id = ?', [$id]);
            if (!$exists) {
                throw new InvalidArgumentException('공지를 찾을 수 없습니다.');
            }
            db_execute(
                'UPDATE content_notices
                 SET title = ?, body = ?, category = ?, pinned = ?, status = ?, published_at = ?
                 WHERE id = ?',
                [$title, $body, $category, $pinned, $status, $publishedAt, $id]
            );

            return self::findAdminById($id) ?? [];
        }

        $publicId = self::generatePublicId();
        $newId = db_insert(
            'INSERT INTO content_notices
                (public_id, title, body, category, pinned, status, published_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $publicId,
                $title,
                $body,
                $category,
                $pinned,
                $status,
                $publishedAt,
                $adminId > 0 ? $adminId : null,
            ]
        );

        return self::findAdminById($newId) ?? [];
    }

    public static function delete(int $id): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('잘못된 ID입니다.');
        }
        $n = db_execute('DELETE FROM content_notices WHERE id = ?', [$id]);
        if ($n < 1) {
            throw new InvalidArgumentException('공지를 찾을 수 없습니다.');
        }
    }

    public static function findAdminById(int $id): ?array
    {
        $row = db_row(
            'SELECT n.*, a.name AS created_by_name
             FROM content_notices n
             LEFT JOIN admins a ON a.id = n.created_by
             WHERE n.id = ?
             LIMIT 1',
            [$id]
        );

        return $row ? self::mapAdminRow($row) : null;
    }

    private static function generatePublicId(): string
    {
        do {
            $pid = 'nt-' . date('Ymd') . '-' . str_pad((string) random_int(1, 999), 3, '0', STR_PAD_LEFT);
        } while (db_row('SELECT id FROM content_notices WHERE public_id = ?', [$pid]));

        return $pid;
    }

    private static function normalizeDateTime(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $raw . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $raw)) {
            return $raw . ':00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $raw)) {
            return $raw;
        }

        throw new InvalidArgumentException('게시일시 형식이 올바르지 않습니다. (YYYY-MM-DD HH:mm)');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function mapAdminRow(array $row): array
    {
        $st = (string) ($row['status'] ?? 'draft');
        [$stLabel, $stClass] = self::statusLabel($st);

        return [
            'id'              => (int) $row['id'],
            'public_id'       => (string) $row['public_id'],
            'title'           => (string) $row['title'],
            'body'            => (string) $row['body'],
            'category'        => (string) $row['category'],
            'pinned'          => (int) ($row['pinned'] ?? 0) === 1,
            'status'          => $st,
            'status_label'    => $stLabel,
            'status_class'    => $stClass,
            'published_at'    => self::formatDt($row['published_at'] ?? null),
            'updated_at'      => self::formatDt($row['updated_at'] ?? null),
            'created_by_name' => (string) ($row['created_by_name'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function mapRiderRow(array $row): array
    {
        $cat = (string) ($row['category'] ?? '일반');

        return [
            'id'            => (int) $row['id'],
            'public_id'     => (string) ($row['public_id'] ?? ''),
            'title'         => (string) ($row['title'] ?? ''),
            'body'          => (string) ($row['body'] ?? ''),
            'category'      => $cat,
            'category_class'=> self::categoryBadgeClass($cat),
            'pinned'        => (int) ($row['pinned'] ?? 0) === 1,
            'published_at'  => self::formatDt($row['published_at'] ?? null),
            'published_date'=> self::formatDateOnly($row['published_at'] ?? null),
        ];
    }

    private static function formatDt(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '';
        }
        $ts = strtotime((string) $v);

        return $ts ? date('Y-m-d H:i', $ts) : '';
    }

    private static function formatDateOnly(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '';
        }
        $ts = strtotime((string) $v);

        return $ts ? date('Y-m-d', $ts) : '';
    }
}
