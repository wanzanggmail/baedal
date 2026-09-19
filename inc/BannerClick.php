<?php

declare(strict_types=1);

/**
 * 광고 배너 클릭 로그 (2026-09-19 갑) — "나중에 광고 정산이나 분석등에 쓸꺼야".
 *
 * 라이더가 배너를 누르면 랜딩 URL 로 바로 보내지 않고 `rider/p/ad.php` 를 거치게 해서
 * 여기에 한 줄 남긴다. 광고주에게 청구할 근거가 되는 자료라 **광고가 지워져도 남아야 하므로**
 * 외래키를 걸지 않고 클릭 당시의 광고명을 함께 박아 둔다.
 */
final class BannerClick
{
    public static function tableExists(): bool
    {
        return db_table_exists('banner_clicks');
    }

    /**
     * 클릭 한 건 기록. 기록 실패가 랜딩 이동을 막으면 안 되므로 예외를 삼킨다.
     *
     * @param array<string, mixed> $banner content_banners 행
     */
    public static function record(array $banner, ?int $riderId, ?int $agencyId): void
    {
        if (!self::tableExists()) {
            return;
        }
        try {
            db_insert(
                'INSERT INTO banner_clicks
                    (banner_id, banner_title, rider_id, agency_id, clicked_at, click_date, ip, user_agent)
                 VALUES (?, ?, ?, ?, NOW(), CURDATE(), ?, ?)',
                [
                    (int) $banner['id'],
                    mb_substr((string) ($banner['title'] ?? ''), 0, 120),
                    $riderId > 0 ? $riderId : null,
                    $agencyId > 0 ? $agencyId : null,
                    mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                    mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                ]
            );
        } catch (Throwable $e) {
            error_log('[BannerClick] 기록 실패: ' . $e->getMessage());
        }
    }

    /**
     * 기간 조건 — 화면·집계가 같은 조건을 쓰도록 한 곳에서 만든다.
     *
     * @param array{from?:string, to?:string, banner_id?:int} $f
     * @return array{0:string, 1:list<mixed>}
     */
    private static function where(array $f): array
    {
        $sql    = ['1=1'];
        $params = [];
        if (!empty($f['from'])) {
            $sql[]    = 'c.click_date >= ?';
            $params[] = $f['from'];
        }
        if (!empty($f['to'])) {
            $sql[]    = 'c.click_date <= ?';
            $params[] = $f['to'];
        }
        if (!empty($f['banner_id'])) {
            $sql[]    = 'c.banner_id = ?';
            $params[] = (int) $f['banner_id'];
        }

        return [implode(' AND ', $sql), $params];
    }

    /**
     * 광고별 집계 — 정산 청구서의 바탕이 되는 숫자.
     *
     * @param array{from?:string, to?:string, banner_id?:int} $f
     * @return list<array<string, mixed>>
     */
    public static function summary(array $f): array
    {
        if (!self::tableExists()) {
            return [];
        }
        [$w, $p] = self::where($f);

        return db_rows(
            "SELECT c.banner_id,
                    COALESCE(b.title, c.banner_title) AS title,
                    b.id IS NULL AS deleted,
                    COUNT(*) AS clicks,
                    COUNT(DISTINCT c.rider_id) AS riders,
                    COUNT(DISTINCT c.click_date) AS days,
                    MAX(c.clicked_at) AS last_click
             FROM banner_clicks c
             LEFT JOIN content_banners b ON b.id = c.banner_id
             WHERE {$w}
             GROUP BY c.banner_id, COALESCE(b.title, c.banner_title), b.id IS NULL
             ORDER BY clicks DESC",
            $p
        );
    }

    /**
     * 일자별 클릭 수 (선택한 광고 기준, 없으면 전체 합).
     *
     * @param array{from?:string, to?:string, banner_id?:int} $f
     * @return list<array{click_date:string, clicks:int}>
     */
    public static function daily(array $f): array
    {
        if (!self::tableExists()) {
            return [];
        }
        [$w, $p] = self::where($f);

        return db_rows(
            "SELECT c.click_date, COUNT(*) AS clicks
             FROM banner_clicks c
             WHERE {$w}
             GROUP BY c.click_date
             ORDER BY c.click_date DESC
             LIMIT 60",
            $p
        );
    }

    /**
     * 클릭 상세 — 누가 언제 눌렀는지.
     *
     * @param array{from?:string, to?:string, banner_id?:int} $f
     * @return list<array<string, mixed>>
     */
    public static function recent(array $f, int $limit = 50, int $offset = 0): array
    {
        if (!self::tableExists()) {
            return [];
        }
        [$w, $p] = self::where($f);
        $limit   = max(1, min(200, $limit));
        $offset  = max(0, $offset);

        return db_rows(
            "SELECT c.*,
                    COALESCE(b.title, c.banner_title) AS title,
                    r.name AS rider_name, r.rider_code,
                    o.name AS agency_name
             FROM banner_clicks c
             LEFT JOIN content_banners b ON b.id = c.banner_id
             LEFT JOIN riders r ON r.id = c.rider_id
             LEFT JOIN organizations o ON o.id = c.agency_id
             WHERE {$w}
             ORDER BY c.id DESC
             LIMIT {$limit} OFFSET {$offset}",
            $p
        );
    }

    /** @param array{from?:string, to?:string, banner_id?:int} $f */
    public static function count(array $f): int
    {
        if (!self::tableExists()) {
            return 0;
        }
        [$w, $p] = self::where($f);

        return (int) (db_row("SELECT COUNT(*) c FROM banner_clicks c WHERE {$w}", $p)['c'] ?? 0);
    }
}
