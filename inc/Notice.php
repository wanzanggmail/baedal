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
        // 멀티테넌시: 작성 조직 스코프 (자기 + 하위)
        [$scopeSql, $params] = Org::orgScopeClause('n.org_id');
        $whereSql = $scopeSql !== '' ? 'WHERE ' . $scopeSql : '';

        $rows = db_rows(
            'SELECT n.*, a.name AS created_by_name
             FROM content_notices n
             LEFT JOIN admins a ON a.id = n.created_by
             ' . $whereSql . '
             ORDER BY n.pinned DESC, COALESCE(n.published_at, n.updated_at) DESC, n.id DESC',
            $params
        );

        return array_map([self::class, 'mapAdminRow'], $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listPublishedForRider(int $limit = 50, int $agencyId = 0): array
    {
        $where  = ["status = 'published'", '(published_at IS NULL OR published_at <= NOW())', '(ends_at IS NULL OR ends_at >= NOW())'];
        $params = [];
        [$orgSql, $orgParams] = self::riderOrgVisibility($agencyId);
        if ($orgSql !== '') {
            $where[] = $orgSql;
            $params  = array_merge($params, $orgParams);
        }

        $rows = db_rows(
            'SELECT id, public_id, title, body, category, pinned, status, published_at, updated_at
             FROM content_notices
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY pinned DESC, published_at DESC, id DESC
             LIMIT ' . max(1, min(100, $limit)),
            $params
        );

        return array_map([self::class, 'mapRiderRow'], $rows);
    }

    public static function findForRider(int $id, int $agencyId = 0): ?array
    {
        $where  = ['id = ?', "status = 'published'", '(published_at IS NULL OR published_at <= NOW())', '(ends_at IS NULL OR ends_at >= NOW())'];
        $params = [$id];
        [$orgSql, $orgParams] = self::riderOrgVisibility($agencyId);
        if ($orgSql !== '') {
            $where[] = $orgSql;
            $params  = array_merge($params, $orgParams);
        }

        $row = db_row(
            'SELECT id, public_id, title, body, category, pinned, status, published_at, updated_at
             FROM content_notices
             WHERE ' . implode(' AND ', $where) . '
             LIMIT 1',
            $params
        );

        return $row ? self::mapRiderRow($row) : null;
    }

    /**
     * 라이더(대리점 소속)가 볼 수 있는 공지 org 범위: 자기 대리점 + 상위(총판·본사) + 전역(NULL).
     * agencyId 0 이면 제한 없음(레거시·전역).
     *
     * @return array{0:string,1:list<int>}
     */
    private static function riderOrgVisibility(int $agencyId): array
    {
        if ($agencyId < 1) {
            return ['', []];
        }
        $orgIds = Org::ancestorOrgIds($agencyId);
        if ($orgIds === []) {
            return ['org_id IS NULL', []];
        }
        $ph = implode(',', array_fill(0, count($orgIds), '?'));

        return ["(org_id IS NULL OR org_id IN ({$ph}))", array_values($orgIds)];
    }

    /**
     * 로그인 직후 팝업 큐: 상단 고정 → 긴급 순 (최대 10건)
     *
     * @return list<array<string, mixed>>
     */
    public static function loginPopupQueue(int $agencyId = 0): array
    {
        $where  = ["status = 'published'", '(published_at IS NULL OR published_at <= NOW())', '(ends_at IS NULL OR ends_at >= NOW())', "(pinned = 1 OR category = '긴급')"];
        $params = [];
        [$orgSql, $orgParams] = self::riderOrgVisibility($agencyId);
        if ($orgSql !== '') {
            $where[] = $orgSql;
            $params  = array_merge($params, $orgParams);
        }

        $rows = db_rows(
            'SELECT id, public_id, title, body, category, pinned, published_at
             FROM content_notices
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY pinned DESC, published_at DESC, id DESC
             LIMIT 10',
            $params
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
        $endsAtRaw = trim((string) ($input['ends_at'] ?? ''));

        if ($title === '') {
            throw new InvalidArgumentException('제목을 입력하세요.');
        }
        if (trim(strip_tags($body)) === '') {
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

        // 노출 종료일 — 시작일(published_at)보다 앞설 수 없다. 시작일이 없으면(즉시 게시)
        // 오늘 날짜 기준으로 비교한다.
        $endsAt = self::normalizeDateTime($endsAtRaw, true);
        if ($endsAt !== null) {
            $startCompare = $publishedAt ?? date('Y-m-d H:i:s');
            if ($endsAt < $startCompare) {
                throw new InvalidArgumentException('노출 종료일은 시작일보다 앞설 수 없습니다.');
            }
        }

        if ($id) {
            $exists = db_row('SELECT id, public_id, org_id FROM content_notices WHERE id = ?', [$id]);
            if (!$exists) {
                throw new InvalidArgumentException('공지를 찾을 수 없습니다.');
            }
            if (!Org::canAccessOrg((int) ($exists['org_id'] ?? 0))) {
                throw new InvalidArgumentException('이 공지를 수정할 권한이 없습니다.');
            }
            db_execute(
                'UPDATE content_notices
                 SET title = ?, body = ?, category = ?, pinned = ?, status = ?, published_at = ?, ends_at = ?
                 WHERE id = ?',
                [$title, $body, $category, $pinned, $status, $publishedAt, $endsAt, $id]
            );

            return self::findAdminById($id) ?? [];
        }

        $publicId = self::generatePublicId();
        $orgId    = admin_org_id();
        $newId = db_insert(
            'INSERT INTO content_notices
                (public_id, title, body, category, pinned, status, published_at, ends_at, created_by, org_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $publicId,
                $title,
                $body,
                $category,
                $pinned,
                $status,
                $publishedAt,
                $endsAt,
                $adminId > 0 ? $adminId : null,
                $orgId > 0 ? $orgId : null,
            ]
        );

        return self::findAdminById($newId) ?? [];
    }

    public static function delete(int $id): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('잘못된 ID입니다.');
        }
        $exists = db_row('SELECT org_id FROM content_notices WHERE id = ? LIMIT 1', [$id]);
        if (!$exists) {
            throw new InvalidArgumentException('공지를 찾을 수 없습니다.');
        }
        if (!Org::canAccessOrg((int) ($exists['org_id'] ?? 0))) {
            throw new InvalidArgumentException('이 공지를 삭제할 권한이 없습니다.');
        }
        db_execute('DELETE FROM content_notices WHERE id = ?', [$id]);
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

    /**
     * @param bool $endOfDay 날짜만 온 경우 00:00:00 대신 23:59:59로 채운다(종료일 — 그 날까지는 노출).
     */
    private static function normalizeDateTime(string $raw, bool $endOfDay = false): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $raw . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $raw)) {
            return $raw . ':00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $raw)) {
            return $raw;
        }

        throw new InvalidArgumentException(($endOfDay ? '노출 종료일' : '게시일시') . ' 형식이 올바르지 않습니다. (YYYY-MM-DD)');
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
            'published_date'  => self::formatDateOnly($row['published_at'] ?? null),
            'ends_at'         => self::formatDt($row['ends_at'] ?? null),
            'ends_date'       => self::formatDateOnly($row['ends_at'] ?? null),
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
