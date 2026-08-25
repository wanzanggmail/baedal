<?php

declare(strict_types=1);

/**
 * 저장용 양방향 암호화 — AES-256-GCM.
 *
 * 왜 해시가 아니라 암호화인가: 여기 담기는 값(PG 결제키·빌키·계좌번호)은 **되읽어서 써야 한다**.
 * 비밀번호처럼 대조만 하면 되는 값은 bcrypt 로 두고(그대로 둔다), 이 클래스는 건드리지 않는다.
 *
 * 🔑 **키는 DB 밖에 둔다.** 같은 DB 에 키를 넣으면 암호화한 의미가 없다 — 덤프 한 번에 둘 다 나간다.
 *    키는 `.env` 의 `APP_ENC_KEY`(base64 32바이트)에서만 읽는다. `.env` 는 git 에 올라가지 않는다.
 *
 * 저장 형식 : `enc:v1:` + base64( iv(12) ‖ tag(16) ‖ ciphertext )
 *   - 접두사를 두는 이유는 **평문과 섞여 있어도 구분**하기 위해서다. 기존 행을 한 번에 다 바꾸지
 *     않아도 되고(점진적 이관), 이관 도중에 배포돼도 읽기가 깨지지 않는다.
 *   - `v1` 은 나중에 키를 교체(rotation)할 때 구분자로 쓴다.
 *
 * GCM 을 쓰는 이유: 변조 감지가 같이 된다. CBC 로 하면 누가 암호문을 바꿔치기해도 알 수 없다.
 */
final class Crypto
{
    private const PREFIX = 'enc:v1:';
    private const CIPHER = 'aes-256-gcm';
    private const IV_LEN = 12;   // GCM 권장 96비트
    private const TAG_LEN = 16;

    /** @var string|null|false 캐시 — false = 아직 안 읽음, null = 키 없음 */
    private static string|null|false $key = false;

    /**
     * 원시 키(32바이트). 없으면 null.
     *
     * 키를 매번 base64_decode 하지 않도록 한 번만 읽어 캐시한다.
     */
    private static function key(): ?string
    {
        if (self::$key !== false) {
            return self::$key;
        }
        $raw = trim((string) (getenv('APP_ENC_KEY') ?: ($_SERVER['APP_ENC_KEY'] ?? '')));
        if ($raw === '') {
            return self::$key = null;
        }
        $bin = base64_decode($raw, true);
        if (!is_string($bin) || strlen($bin) !== 32) {
            // 잘못된 키를 "없음"으로 넘기면 평문 저장으로 조용히 새어나간다. 시끄럽게 죽인다.
            throw new RuntimeException('APP_ENC_KEY 가 base64 로 인코딩된 32바이트가 아닙니다.');
        }

        return self::$key = $bin;
    }

    /** 암호화를 쓸 수 있는 상태인가 (설정 화면에서 안내용) */
    public static function available(): bool
    {
        return self::key() !== null;
    }

    /** 새 키 1개 생성 — `.env` 에 붙여 넣을 base64 문자열. */
    public static function generateKey(): string
    {
        return base64_encode(random_bytes(32));
    }

    /** 이미 암호화된 값인가 */
    public static function isEncrypted(?string $value): bool
    {
        return is_string($value) && str_starts_with($value, self::PREFIX);
    }

    /**
     * 암호화. 빈 문자열은 그대로 둔다 — "값 없음"을 암호문으로 만들면
     * `!== ''` 로 존재를 확인하는 기존 코드가 전부 어긋난다.
     *
     * @throws RuntimeException 키가 없을 때. **평문으로 대체 저장하지 않는다** —
     *                          그러면 설정 실수 하나로 비밀값이 조용히 평문이 된다.
     */
    public static function encrypt(?string $plain): string
    {
        $plain = (string) $plain;
        if ($plain === '') {
            return '';
        }
        if (self::isEncrypted($plain)) {
            return $plain; // 두 번 감싸지 않는다
        }
        $key = self::key();
        if ($key === null) {
            throw new RuntimeException(
                '암호화 키(APP_ENC_KEY)가 설정되지 않아 비밀값을 저장할 수 없습니다. '
                . '서버의 .env 에 APP_ENC_KEY 를 추가하세요. (php tools/gen_enc_key.php 로 생성)'
            );
        }

        $iv  = random_bytes(self::IV_LEN);
        $tag = '';
        $ct  = openssl_encrypt($plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LEN);
        if ($ct === false) {
            throw new RuntimeException('암호화에 실패했습니다.');
        }

        return self::PREFIX . base64_encode($iv . $tag . $ct);
    }

    /**
     * 복호화. **접두사가 없으면 평문으로 보고 그대로 돌려준다** — 이관 전 데이터가 그대로 읽힌다.
     *
     * 복호화에 실패하면(키 교체·변조) 빈 문자열이 아니라 예외를 던진다. 조용히 ''를 주면
     * "계좌 미등록"으로 오인돼 출금이 막히거나, 더 나쁘게는 빈 값으로 덮어써진다.
     */
    public static function decrypt(?string $stored): string
    {
        $stored = (string) $stored;
        if ($stored === '' || !self::isEncrypted($stored)) {
            return $stored;
        }
        $key = self::key();
        if ($key === null) {
            throw new RuntimeException('암호화 키(APP_ENC_KEY)가 없어 저장된 값을 읽을 수 없습니다.');
        }

        $blob = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if (!is_string($blob) || strlen($blob) <= self::IV_LEN + self::TAG_LEN) {
            throw new RuntimeException('암호문 형식이 올바르지 않습니다.');
        }
        $iv  = substr($blob, 0, self::IV_LEN);
        $tag = substr($blob, self::IV_LEN, self::TAG_LEN);
        $ct  = substr($blob, self::IV_LEN + self::TAG_LEN);

        $plain = openssl_decrypt($ct, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new RuntimeException('복호화에 실패했습니다 — 키가 다르거나 값이 변조되었습니다.');
        }

        return $plain;
    }

    /**
     * 복호화하되 실패해도 예외를 안 던지는 판(화면 표시용).
     * 실패한 값은 그대로 두면 암호문이 화면에 뜨므로 대체 문자열을 준다.
     */
    public static function decryptSafe(?string $stored, string $fallback = ''): string
    {
        try {
            return self::decrypt($stored);
        } catch (Throwable) {
            return $fallback;
        }
    }
}
