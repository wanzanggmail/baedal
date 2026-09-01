<?php

declare(strict_types=1);

/**
 * 알림톡·문자 발송 설정(2026-09-02 갑) — 전역 단일 행(본사 설정).
 *
 * 발신번호·알림톡 채널·명세서 템플릿·명세서 링크 도메인/유효기간을 담는다. 실제 발송사
 * 자격증명(id/pw)은 여기 두지 않는다 — 연동 시 `Crypto` 암호화로 별도 저장(코드/깃 미포함).
 */
final class MessagingConfig
{
    /** @return array{sender_phone:string, alimtalk_channel:string, statement_template:string, public_base_url:string, link_ttl_days:int, alimtalk_fallback_sms:int} */
    public static function defaults(): array
    {
        return [
            'sender_phone'          => '',
            'alimtalk_channel'      => '',
            'statement_template'    => '',
            'public_base_url'       => '',
            'link_ttl_days'         => 90,
            'alimtalk_fallback_sms' => 1,
        ];
    }

    /** @var array<string,int|string>|null */
    private static ?array $cache = null;

    /** @return array{sender_phone:string, alimtalk_channel:string, statement_template:string, public_base_url:string, link_ttl_days:int} */
    public static function get(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $d = self::defaults();
        if (!db_table_exists('messaging_config')) {
            return $d;
        }
        $row = db_row('SELECT * FROM messaging_config ORDER BY id ASC LIMIT 1');
        if ($row === null) {
            return $d;
        }

        return self::$cache = [
            'sender_phone'          => trim((string) ($row['sender_phone'] ?? '')),
            'alimtalk_channel'      => trim((string) ($row['alimtalk_channel'] ?? '')),
            'statement_template'    => trim((string) ($row['statement_template'] ?? '')),
            'public_base_url'       => rtrim(trim((string) ($row['public_base_url'] ?? '')), '/'),
            'link_ttl_days'         => max(1, (int) ($row['link_ttl_days'] ?? $d['link_ttl_days'])),
            'alimtalk_fallback_sms' => array_key_exists('alimtalk_fallback_sms', $row) ? (int) !empty($row['alimtalk_fallback_sms']) : 1,
        ];
    }

    /**
     * 저장(전역 단일 행 upsert).
     *
     * @param array<string,mixed> $data
     */
    public static function save(array $data, ?int $adminId = null): void
    {
        if (!db_table_exists('messaging_config')) {
            throw new RuntimeException('messaging_config 테이블이 없습니다. php migrate.php 를 실행하세요.');
        }

        $phone   = self::normalizePhone((string) ($data['sender_phone'] ?? ''));
        $channel = mb_substr(trim((string) ($data['alimtalk_channel'] ?? '')), 0, 60);
        $tpl     = mb_substr(trim((string) ($data['statement_template'] ?? '')), 0, 60);
        $baseRaw = trim((string) ($data['public_base_url'] ?? ''));
        $base    = self::normalizeBaseUrl($baseRaw);
        if ($baseRaw !== '' && $base === '') {
            throw new InvalidArgumentException('링크 도메인은 http(s)://로 시작하는 주소여야 합니다.');
        }
        $ttl      = max(1, min(3650, (int) ($data['link_ttl_days'] ?? 90)));
        $fallback = !empty($data['alimtalk_fallback_sms']) ? 1 : 0;

        $hasFallbackCol = in_array('alimtalk_fallback_sms', array_column(db_rows('SHOW COLUMNS FROM messaging_config'), 'Field'), true);
        $fbCol = $hasFallbackCol ? ', alimtalk_fallback_sms=?' : '';
        $fbIns = $hasFallbackCol ? ', alimtalk_fallback_sms' : '';

        $existing = db_row('SELECT id FROM messaging_config ORDER BY id ASC LIMIT 1');
        if ($existing !== null) {
            $params = [$phone ?: null, $channel ?: null, $tpl ?: null, $base ?: null, $ttl];
            if ($hasFallbackCol) { $params[] = $fallback; }
            $params[] = ($adminId !== null && $adminId > 0) ? $adminId : null;
            $params[] = (int) $existing['id'];
            db_execute(
                "UPDATE messaging_config SET sender_phone=?, alimtalk_channel=?, statement_template=?,
                        public_base_url=?, link_ttl_days=?{$fbCol}, updated_by=?, updated_at=NOW() WHERE id=?",
                $params
            );
        } else {
            $params = [$phone ?: null, $channel ?: null, $tpl ?: null, $base ?: null, $ttl];
            if ($hasFallbackCol) { $params[] = $fallback; }
            $params[] = ($adminId !== null && $adminId > 0) ? $adminId : null;
            db_insert(
                "INSERT INTO messaging_config (sender_phone, alimtalk_channel, statement_template, public_base_url, link_ttl_days{$fbIns}, updated_by, updated_at, created_at)
                 VALUES (?, ?, ?, ?, ?" . ($hasFallbackCol ? ', ?' : '') . ", ?, NOW(), NOW())",
                $params
            );
        }
        self::$cache = null;
    }

    /** 명세서 링크 기본 도메인(설정값 → env → ''). */
    public static function publicBaseUrl(): string
    {
        $v = self::get()['public_base_url'];
        if ($v !== '') {
            return $v;
        }

        return rtrim(trim((string) (getenv('PUBLIC_BASE_URL') ?: '')), '/');
    }

    public static function linkTtlDays(): int
    {
        return self::get()['link_ttl_days'];
    }

    /** 알림톡 수신불가 시 SMS 대체발송 여부. */
    public static function alimtalkFallbackSms(): bool
    {
        return (int) self::get()['alimtalk_fallback_sms'] === 1;
    }

    private static function normalizePhone(string $s): string
    {
        $digits = preg_replace('/[^0-9]/', '', $s) ?? '';

        return mb_substr($digits, 0, 20);
    }

    private static function normalizeBaseUrl(string $s): string
    {
        $s = trim($s);
        if ($s === '') {
            return '';
        }
        if (!preg_match('#^https?://[^\s/]+#i', $s)) {
            return '';
        }

        return rtrim($s, '/');
    }
}
