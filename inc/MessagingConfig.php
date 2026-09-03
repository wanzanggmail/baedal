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
            // 발송 1건당 대리점 지갑 → 본사로 옮길 요금(2026-09-03 갑).
            'price_alimtalk'        => 10,
            'price_sms'             => 10,
            'price_lms'             => 50,
            // SMS 한 건 최대 바이트(EUC-KR 기준). 넘으면 LMS 로 보낸다.
            'sms_max_bytes'         => 90,
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
            'price_alimtalk'        => max(0, (int) ($row['price_alimtalk'] ?? $d['price_alimtalk'])),
            'price_sms'             => max(0, (int) ($row['price_sms'] ?? $d['price_sms'])),
            'price_lms'             => max(0, (int) ($row['price_lms'] ?? $d['price_lms'])),
            'sms_max_bytes'         => max(1, (int) ($row['sms_max_bytes'] ?? $d['sms_max_bytes'])),
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

        $cols = array_column(db_rows('SHOW COLUMNS FROM messaging_config'), 'Field');

        // 컬럼이 있을 때만 SET 에 넣는다(마이그레이션 이전 호환).
        $d       = self::defaults();
        $optional = [];
        if (in_array('alimtalk_fallback_sms', $cols, true)) {
            $optional['alimtalk_fallback_sms'] = $fallback;
        }
        foreach (['price_alimtalk', 'price_sms', 'price_lms'] as $k) {
            if (in_array($k, $cols, true)) {
                $optional[$k] = max(0, (int) ($data[$k] ?? $d[$k]));
            }
        }
        if (in_array('sms_max_bytes', $cols, true)) {
            $optional['sms_max_bytes'] = max(1, min(2000, (int) ($data['sms_max_bytes'] ?? $d['sms_max_bytes'])));
        }

        $optSet  = '';
        $optNames = '';
        $optPh   = '';
        foreach (array_keys($optional) as $k) {
            $optSet   .= ", {$k}=?";
            $optNames .= ", {$k}";
            $optPh    .= ', ?';
        }

        $base5 = [$phone ?: null, $channel ?: null, $tpl ?: null, $base ?: null, $ttl];
        $optV  = array_values($optional);

        $existing = db_row('SELECT id FROM messaging_config ORDER BY id ASC LIMIT 1');
        if ($existing !== null) {
            $params = array_merge($base5, $optV, [
                ($adminId !== null && $adminId > 0) ? $adminId : null,
                (int) $existing['id'],
            ]);
            db_execute(
                "UPDATE messaging_config SET sender_phone=?, alimtalk_channel=?, statement_template=?,
                        public_base_url=?, link_ttl_days=?{$optSet}, updated_by=?, updated_at=NOW() WHERE id=?",
                $params
            );
        } else {
            $params = array_merge($base5, $optV, [($adminId !== null && $adminId > 0) ? $adminId : null]);
            db_insert(
                "INSERT INTO messaging_config (sender_phone, alimtalk_channel, statement_template, public_base_url, link_ttl_days{$optNames}, updated_by, updated_at, created_at)
                 VALUES (?, ?, ?, ?, ?{$optPh}, ?, NOW(), NOW())",
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

    /**
     * 문자 본문의 바이트 길이 — **EUC-KR 기준**(한글 2바이트, 영숫자 1바이트).
     * 국내 SMS 규격이 이 기준이라 UTF-8 바이트로 재면 안 된다.
     */
    public static function smsByteLength(string $content): int
    {
        $euc = @mb_convert_encoding($content, 'EUC-KR', 'UTF-8');

        return is_string($euc) && $euc !== '' ? strlen($euc) : strlen($content);
    }

    /**
     * 문자 채널 판정 — 90바이트(설정값) 이하면 SMS, 넘으면 LMS.
     *
     * @return 'sms'|'lms'
     */
    public static function smsChannelFor(string $content): string
    {
        return self::smsByteLength($content) > (int) self::get()['sms_max_bytes'] ? 'lms' : 'sms';
    }

    /** 채널별 1건 단가(원). */
    public static function priceFor(string $channel): int
    {
        $c = self::get();

        return match ($channel) {
            'alimtalk' => (int) $c['price_alimtalk'],
            'lms'      => (int) $c['price_lms'],
            default    => (int) $c['price_sms'],
        };
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
