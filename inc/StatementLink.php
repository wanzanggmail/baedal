<?php

declare(strict_types=1);

require_once __DIR__ . '/MessagingConfig.php';

/**
 * 모바일 명세서 공개 링크(2026-09-01 갑).
 *
 * 라이더에게 파일 대신 **링크**로 정산 명세서를 전달한다(알림톡 링크). 토큰 하나가
 * (라이더 + 정산기간)에 매핑되며, 로그인 없이 `rider/p/statement.php?t=토큰`으로 열린다.
 * 토큰은 추측 불가(40 hex)하고 만료된다. 같은 라이더·기간의 유효 링크가 이미 있으면 재사용한다.
 */
final class StatementLink
{
    /** 기본 유효기간(일) — 설정(messaging_config)이 없을 때 폴백. */
    public const DEFAULT_TTL_DAYS = 90;

    public static function ready(): bool
    {
        return db_table_exists('statement_links');
    }

    /**
     * 링크 생성(또는 유효한 기존 링크 재사용).
     * $ttlDays 를 지정하지 않으면 설정(messaging_config.link_ttl_days)을 따른다.
     *
     * @return array{token:string, url:string}
     */
    public static function create(int $riderId, string $from, string $to, ?int $adminId = null, ?int $ttlDays = null): array
    {
        if ($ttlDays === null) {
            $ttlDays = class_exists('MessagingConfig') ? MessagingConfig::linkTtlDays() : self::DEFAULT_TTL_DAYS;
        }
        if (!self::ready()) {
            throw new RuntimeException('statement_links 테이블이 없습니다. php migrate.php 를 실행하세요.');
        }
        if ($riderId < 1) {
            throw new InvalidArgumentException('라이더가 올바르지 않습니다.');
        }

        // 같은 라이더·기간의 아직 유효한 링크가 있으면 재사용(중복 토큰 남발 방지).
        $existing = db_row(
            "SELECT token FROM statement_links
              WHERE rider_id = ? AND period_from = ? AND period_to = ?
                AND (expires_at IS NULL OR expires_at > NOW())
              ORDER BY id DESC LIMIT 1",
            [$riderId, $from, $to]
        );
        if ($existing !== null) {
            $token = (string) $existing['token'];

            return ['token' => $token, 'url' => self::url($token)];
        }

        $token   = bin2hex(random_bytes(20)); // 40 hex
        $expires = $ttlDays > 0 ? date('Y-m-d H:i:s', time() + $ttlDays * 86400) : null;

        db_insert(
            'INSERT INTO statement_links (token, rider_id, period_from, period_to, created_by, created_at, expires_at)
             VALUES (?, ?, ?, ?, ?, NOW(), ?)',
            [$token, $riderId, $from, $to, ($adminId !== null && $adminId > 0) ? $adminId : null, $expires]
        );

        return ['token' => $token, 'url' => self::url($token)];
    }

    /**
     * 토큰 조회 — 유효하고 만료되지 않았을 때만 행 반환(그 외 null).
     *
     * @return array<string,mixed>|null
     */
    public static function resolve(string $token): ?array
    {
        if (!self::ready() || !preg_match('/^[0-9a-f]{40}$/', $token)) {
            return null;
        }

        return db_row(
            "SELECT * FROM statement_links
              WHERE token = ? AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1",
            [$token]
        );
    }

    /** 조회 카운트 기록(공개 페이지 진입 시). */
    public static function markViewed(string $token): void
    {
        if (!self::ready()) {
            return;
        }
        try {
            db_execute(
                'UPDATE statement_links SET view_count = view_count + 1, last_viewed_at = NOW() WHERE token = ?',
                [$token]
            );
        } catch (Throwable) {
            // 카운트 실패는 조회를 막지 않는다.
        }
    }

    /**
     * 토큰 → 절대 URL(알림톡용). 설정(messaging_config.public_base_url) → env PUBLIC_BASE_URL →
     * 현재 요청 오리진(web_absolute_url) 순으로 도메인을 정한다.
     */
    public static function url(string $token): string
    {
        $path = rtrim(RIDER_BASE, '/') . '/p/statement.php?t=' . rawurlencode($token);

        $base = class_exists('MessagingConfig') ? MessagingConfig::publicBaseUrl() : rtrim(trim((string) (getenv('PUBLIC_BASE_URL') ?: '')), '/');
        if ($base !== '') {
            return $base . $path;
        }

        return web_absolute_url($path);
    }
}
