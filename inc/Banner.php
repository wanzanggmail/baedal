<?php

declare(strict_types=1);

/**
 * 광고 배너 CRUD (관리자·라이더 앱)
 */
final class Banner
{
    /**
     * 노출 위치는 **라이더 홈 배너 하나뿐**이다. 예전엔 home_top·home_middle 도 고를 수 있었지만
     * 그 두 자리는 어느 화면에도 그려지지 않아, 고르면 «등록은 됐는데 아무 데도 안 나오는» 광고가 됐다
     * (2026-09-19 갑 신고). 선택지를 지우고 기존 행은 마이그레이션으로 rider_app 으로 옮긴다.
     *
     * @var array<string, string>
     */
    public const SLOT_LABELS = [
        'rider_app' => '라이더 홈 배너',
    ];

    /** 라이더 홈 하단 롤링 캐러셀 슬롯 — 지금은 이것이 유일한 노출 위치다. */
    public const RIDER_HOME_CAROUSEL_SLOT = 'rider_app';

    public static function slots(): array
    {
        return array_keys(self::SLOT_LABELS);
    }

    public static function slotLabel(string $slot): string
    {
        return self::SLOT_LABELS[$slot] ?? $slot;
    }

    public static function imageSrc(string $imageUrl, bool $forRiderApp = false): string
    {
        $url = trim($imageUrl);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $rel = null;
        if (str_starts_with($url, '/assets/')) {
            $rel = ltrim(substr($url, strlen('/assets/')), '/');
        } elseif (str_starts_with($url, 'assets/')) {
            $rel = ltrim(substr($url, strlen('assets/')), '/');
        }

        if ($rel !== null && $rel !== '') {
            $full = ROOT_PATH . '/assets/' . $rel;
            if ($forRiderApp && is_file($full)) {
                return rtrim(RIDER_BASE, '/') . '/p/asset.php?f=' . rawurlencode($rel);
            }
            if (is_file($full)) {
                return web_request_origin() . web_asset($rel);
            }

            return web_request_origin() . web_asset($rel);
        }

        if (str_starts_with($url, '/uploads/')) {
            $rel = ltrim(substr($url, strlen('/uploads/')), '/');
            if ($rel !== '' && preg_match('#^[a-zA-Z0-9_./-]+$#', $rel)) {
                $full = ROOT_PATH . '/uploads/' . $rel;
                if ($forRiderApp && is_file($full)) {
                    return rtrim(RIDER_BASE, '/') . '/p/upload.php?f=' . rawurlencode($rel);
                }
                if (is_file($full)) {
                    return web_request_origin() . web_upload_url($url);
                }
            }

            return $forRiderApp ? '' : web_request_origin() . web_upload_url($url);
        }

        return $url;
    }

    public static function isAllowedImageRef(string $imageUrl): bool
    {
        $u = trim($imageUrl);
        if ($u === '') {
            return false;
        }
        if (preg_match('#^https?://#i', $u)) {
            return true;
        }

        return (bool) preg_match('#^/(uploads|assets)/#', $u);
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
        $row = db_row('SELECT id FROM content_banners WHERE public_id = ? LIMIT 1', [$s]);

        return $row ? (int) $row['id'] : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listAdmin(): array
    {
        // 멀티테넌시: 작성 조직 스코프
        [$scopeSql, $params] = Org::orgScopeClause('b.org_id');
        $whereSql = $scopeSql !== '' ? 'WHERE ' . $scopeSql : '';

        $rows = db_rows(
            'SELECT b.*, a.name AS created_by_name
             FROM content_banners b
             LEFT JOIN admins a ON a.id = b.created_by
             ' . $whereSql . '
             ORDER BY b.slot ASC, b.sort_order ASC, b.id DESC',
            $params
        );

        return array_map([self::class, 'mapAdminRow'], $rows);
    }

    /**
     * @param list<string> $slots
     * @return list<array<string, mixed>>
     */
    public static function listActiveForRider(array $slots, int $limit = 20, int $agencyId = 0): array
    {
        $slots = array_values(array_filter($slots, static fn (string $s): bool => in_array($s, self::slots(), true)));
        if ($slots === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($slots), '?'));
        $params = $slots;

        // 멀티테넌시: 라이더 대리점 + 상위(총판·본사) + 본사·개발사 broadcast + 전역(NULL)
        $orgCond = '';
        if ($agencyId > 0) {
            $orgIds = Org::broadcastOrgIds($agencyId);
            if ($orgIds === []) {
                $orgCond = ' AND org_id IS NULL';
            } else {
                $ph = implode(',', array_fill(0, count($orgIds), '?'));
                $orgCond = " AND (org_id IS NULL OR org_id IN ({$ph}))";
                $params  = array_merge($params, $orgIds);
            }
        }

        // 오늘 날짜는 DB 의 CURDATE() 가 아니라 PHP 에서 넘긴다 — 웹서버와 DB 의 시간대가
        // 다르면 관리자 화면 판정(date())과 어긋나 «관리자엔 노출 중, 라이더엔 없음» 이 된다.
        $today   = date('Y-m-d');
        $params  = array_merge($params, [$today, $today]);

        $sql = "SELECT id, public_id, title, subtitle, link_url, image_url, slot, sort_order
                FROM content_banners
                WHERE status = 'active'
                  AND slot IN ({$placeholders})
                  {$orgCond}
                  AND (start_at IS NULL OR start_at <= ?)
                  AND (end_at IS NULL OR end_at >= ?)
                ORDER BY sort_order ASC, id DESC
                LIMIT " . max(1, min(50, $limit));

        $rows = db_rows($sql, $params);

        return array_map([self::class, 'mapRiderRow'], $rows);
    }

    /**
     * 라이더 홈 하단 캐러셀용 (슬롯: rider_app 만)
     *
     * @return list<array<string, mixed>>
     */
    public static function listActiveForRiderHome(int $limit = 20, int $agencyId = 0): array
    {
        return self::listActiveForRider([self::RIDER_HOME_CAROUSEL_SLOT], $limit, $agencyId);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function save(array $input, ?int $adminId = null): array
    {
        $id = self::parseId($input['id'] ?? $input['banner_id'] ?? null);
        $title = trim((string) ($input['title'] ?? ''));
        $subtitle = trim((string) ($input['subtitle'] ?? ''));
        $linkUrl = trim((string) ($input['link_url'] ?? ''));
        $imageUrl = trim((string) ($input['image_url'] ?? ''));
        $slot = trim((string) ($input['slot'] ?? 'rider_app'));
        $sortOrder = (int) ($input['sort_order'] ?? 100);
        $status = trim((string) ($input['status'] ?? 'inactive'));
        $startAt = self::normalizeDate($input['start_at'] ?? '');
        $endAt = self::normalizeDate($input['end_at'] ?? '');

        if ($title === '') {
            throw new InvalidArgumentException('광고 제목을 입력하세요.');
        }
        if ($imageUrl === '') {
            throw new InvalidArgumentException('광고 이미지를 업로드하거나 URL을 입력하세요.');
        }
        if (!self::isAllowedImageRef($imageUrl)) {
            throw new InvalidArgumentException('이미지 경로는 /uploads/, /assets/ 또는 https:// 로 시작해야 합니다.');
        }
        if (!in_array($slot, self::slots(), true)) {
            $slot = 'rider_app';
        }
        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = 'inactive';
        }
        $sortOrder = max(0, min(9999, $sortOrder));

        if ($linkUrl !== '' && !preg_match('#^https?://#i', $linkUrl)) {
            throw new InvalidArgumentException('랜딩 URL은 http:// 또는 https:// 로 시작해야 합니다.');
        }
        if ($startAt !== null && $endAt !== null && $endAt < $startAt) {
            throw new InvalidArgumentException('종료일은 시작일보다 빠를 수 없습니다.');
        }

        if ($id) {
            $prev = db_row('SELECT image_url, org_id FROM content_banners WHERE id = ?', [$id]);
            if (!$prev) {
                throw new InvalidArgumentException('광고를 찾을 수 없습니다.');
            }
            if (!Org::canAccessOrg((int) ($prev['org_id'] ?? 0))) {
                throw new InvalidArgumentException('이 배너를 수정할 권한이 없습니다.');
            }
            db_execute(
                'UPDATE content_banners
                 SET title = ?, subtitle = ?, link_url = ?, image_url = ?, slot = ?,
                     sort_order = ?, status = ?, start_at = ?, end_at = ?
                 WHERE id = ?',
                [$title, $subtitle, $linkUrl, $imageUrl, $slot, $sortOrder, $status, $startAt, $endAt, $id]
            );
            $oldImg = (string) ($prev['image_url'] ?? '');
            if ($oldImg !== $imageUrl) {
                require_once INC_PATH . '/BannerUpload.php';
                BannerUpload::deleteStoredFile($oldImg);
            }

            return self::findAdminById($id) ?? [];
        }

        $publicId = self::generatePublicId();
        $orgId    = admin_org_id();
        $newId = db_insert(
            'INSERT INTO content_banners
                (public_id, title, subtitle, link_url, image_url, slot, sort_order, status, start_at, end_at, created_by, org_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $publicId,
                $title,
                $subtitle,
                $linkUrl,
                $imageUrl,
                $slot,
                $sortOrder,
                $status,
                $startAt,
                $endAt,
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
        $row = db_row('SELECT image_url, org_id FROM content_banners WHERE id = ?', [$id]);
        if (!$row) {
            throw new InvalidArgumentException('광고를 찾을 수 없습니다.');
        }
        if (!Org::canAccessOrg((int) ($row['org_id'] ?? 0))) {
            throw new InvalidArgumentException('이 배너를 삭제할 권한이 없습니다.');
        }
        db_execute('DELETE FROM content_banners WHERE id = ?', [$id]);
        require_once INC_PATH . '/BannerUpload.php';
        BannerUpload::deleteStoredFile((string) ($row['image_url'] ?? ''));
    }

    public static function findAdminById(int $id): ?array
    {
        $row = db_row(
            'SELECT b.*, a.name AS created_by_name
             FROM content_banners b
             LEFT JOIN admins a ON a.id = b.created_by
             WHERE b.id = ?
             LIMIT 1',
            [$id]
        );

        return $row ? self::mapAdminRow($row) : null;
    }

    private static function generatePublicId(): string
    {
        do {
            $pid = 'ad-' . date('Ymd') . '-' . str_pad((string) random_int(1, 999), 3, '0', STR_PAD_LEFT);
        } while (db_row('SELECT id FROM content_banners WHERE public_id = ?', [$pid]));

        return $pid;
    }

    private static function normalizeDate(mixed $raw): ?string
    {
        $s = trim((string) $raw);
        if ($s === '') {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            throw new InvalidArgumentException('날짜 형식은 YYYY-MM-DD 입니다.');
        }

        return $s;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function mapAdminRow(array $row): array
    {
        $st = (string) ($row['status'] ?? 'inactive');
        [$live, $liveReason, $scopeNote] = self::visibility($row);

        return [
            'live'         => $live,
            'live_reason'  => $liveReason,
            'scope_note'   => $scopeNote,
            'id'              => (int) $row['id'],
            'public_id'       => (string) $row['public_id'],
            'title'           => (string) $row['title'],
            'subtitle'        => (string) ($row['subtitle'] ?? ''),
            'link_url'        => (string) ($row['link_url'] ?? ''),
            'image_url'       => (string) ($row['image_url'] ?? ''),
            'image_src'       => self::imageSrc((string) ($row['image_url'] ?? '')),
            'slot'            => (string) $row['slot'],
            'slot_label'      => self::slotLabel((string) $row['slot']),
            'sort_order'      => (int) ($row['sort_order'] ?? 0),
            'status'          => $st,
            'status_label'    => $st === 'active' ? '집행 중' : '중지',
            'status_class'    => $st === 'active' ? 'success' : 'dark',
            'start_at'        => self::formatDate($row['start_at'] ?? null),
            'end_at'          => self::formatDate($row['end_at'] ?? null),
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
        $img = (string) ($row['image_url'] ?? '');

        $pid  = (string) ($row['public_id'] ?? '');
        $link = (string) ($row['link_url'] ?? '');
        // 랜딩으로 바로 보내지 않고 클릭 기록을 거친다(광고 정산·분석용, 2026-09-19 갑).
        $click = $link !== '' && $pid !== ''
            ? rtrim(RIDER_BASE, '/') . '/p/ad.php?b=' . rawurlencode($pid)
            : $link;

        return [
            'id'        => (int) $row['id'],
            'public_id' => $pid,
            'title'     => (string) ($row['title'] ?? ''),
            'subtitle'  => (string) ($row['subtitle'] ?? ''),
            'link_url'  => $click,
            'landing_url' => $link,
            'image_url' => $img,
            'image_src' => self::imageSrc($img, true),
            'slot'      => (string) ($row['slot'] ?? ''),
        ];
    }

    /**
     * 지금 라이더 홈에 나오는지, 아니면 왜 안 나오는지. 등록해 놓고 «안 보인다» 는 문의가
     * 대부분 상태·기간·위치 중 하나라서, 목록에서 바로 보이게 한다.
     *
     * @param array<string, mixed> $row
     * @return array{0:bool, 1:string, 2:string}
     */
    private static function visibility(array $row): array
    {
        $today = date('Y-m-d');
        $why   = [];
        if ((string) ($row['status'] ?? '') !== 'active') {
            $why[] = '송출 중지';
        }
        $start = self::formatDate($row['start_at'] ?? null);
        $end   = self::formatDate($row['end_at'] ?? null);
        if ($start !== '' && $start > $today) {
            $why[] = '시작일 전';
        }
        if ($end !== '' && $end < $today) {
            $why[] = '집행 종료';
        }
        if (!isset(self::SLOT_LABELS[(string) ($row['slot'] ?? '')])) {
            $why[] = '쓰지 않는 노출 위치';
        }
        if (self::imageFileMissing((string) ($row['image_url'] ?? ''))) {
            $why[] = '이미지 파일 없음';
        }

        // 작성 조직이 대리점·총판이면 그 아래 라이더만 본다(본사·개발사 글은 전 조직 broadcast).
        $scope = '';
        $orgId = (int) ($row['org_id'] ?? 0);
        if ($orgId > 0) {
            $org = Org::find($orgId);
            if ($org !== null && !in_array((string) $org['level'], ['admin', 'developer'], true)) {
                $scope = (string) $org['name'] . ' 소속 라이더에게만';
            }
        }

        return [$why === [], implode(' · ', $why), $scope];
    }

    /** 서버에 실제 파일이 있는지 — 다른 서버에서 올린 이미지는 여기 없다. 외부 URL 은 확인하지 않는다. */
    private static function imageFileMissing(string $imageUrl): bool
    {
        $u = trim($imageUrl);
        if ($u === '' || preg_match('#^https?://#i', $u)) {
            return $u === '';
        }
        if (!preg_match('#^/(uploads|assets)/[a-zA-Z0-9_./-]+$#', $u)) {
            return true;
        }

        return !is_file(ROOT_PATH . $u);
    }

    private static function formatDate(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '';
        }

        $ts = strtotime((string) $v);

        return $ts ? date('Y-m-d', $ts) : '';
    }

    private static function formatDt(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '';
        }
        $ts = strtotime((string) $v);

        return $ts ? date('Y-m-d H:i', $ts) : '';
    }
}
