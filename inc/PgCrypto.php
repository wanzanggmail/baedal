<?php

declare(strict_types=1);

require_once __DIR__ . '/PgConfig.php';

/**
 * PG(위루트) 민감 필드 암호화 — 가맹점별로 발급받은 KEY/IV 사용.
 *
 * 카드번호·유효기간·인증번호처럼 그대로 실어 보내면 안 되는 값에 쓴다.
 *
 * ⚠️ **모드는 아직 문서로 확인하지 못했다.** 국내 PG는 대개 AES-256-CBC + PKCS7 + base64 이고
 *    발급된 키가 32바이트·IV가 16바이트라 그 조합에 정확히 맞아떨어져 기본값으로 뒀다.
 *    실제 승인 요청을 한 번 태워 보기 전까지는 **추정**이며, 위루트 규격이 다르면
 *    `CIPHER`와 `encrypt()`의 base64 여부만 바꾸면 된다.
 */
final class PgCrypto
{
    public const CIPHER = 'aes-256-cbc';

    /** @return array{key:string, iv:string} */
    private static function material(): array
    {
        $c = PgConfig::tableExists() ? PgConfig::get() : [];

        return [
            'key' => (string) ($c['enc_key'] ?? ''),
            'iv'  => (string) ($c['enc_iv'] ?? ''),
        ];
    }

    /** 키·IV가 모두 있고 길이가 맞는가 */
    public static function isReady(): bool
    {
        $m = self::material();

        return strlen($m['key']) === 32 && strlen($m['iv']) === 16;
    }

    /**
     * 왜 길이를 따지는가: openssl은 키가 짧으면 0으로 패딩하고 길면 잘라내며 **경고 없이**
     * 다른 암호문을 만든다. 그러면 PG쪽에서 복호화가 깨지는데 원인을 찾기 어렵다.
     * 여기서 먼저 막아 설정 실수를 드러낸다.
     */
    private static function assertReady(): array
    {
        $m = self::material();
        if ($m['key'] === '' || $m['iv'] === '') {
            throw new RuntimeException('PG 암호화 KEY/IV가 설정되지 않았습니다. 「PG 연동·결제통지」에서 등록하세요.');
        }
        if (strlen($m['key']) !== 32) {
            throw new RuntimeException(sprintf('암호화 KEY 길이가 %d바이트입니다 — AES-256은 32바이트여야 합니다.', strlen($m['key'])));
        }
        if (strlen($m['iv']) !== 16) {
            throw new RuntimeException(sprintf('IV 길이가 %d바이트입니다 — 16바이트여야 합니다.', strlen($m['iv'])));
        }

        return $m;
    }

    public static function encrypt(string $plain): string
    {
        $m   = self::assertReady();
        $out = openssl_encrypt($plain, self::CIPHER, $m['key'], OPENSSL_RAW_DATA, $m['iv']);
        if ($out === false) {
            throw new RuntimeException('암호화에 실패했습니다: ' . openssl_error_string());
        }

        return base64_encode($out);
    }

    public static function decrypt(string $encoded): string
    {
        $m   = self::assertReady();
        $bin = base64_decode($encoded, true);
        if ($bin === false) {
            throw new RuntimeException('base64 형식이 아닙니다.');
        }
        $out = openssl_decrypt($bin, self::CIPHER, $m['key'], OPENSSL_RAW_DATA, $m['iv']);
        if ($out === false) {
            throw new RuntimeException('복호화에 실패했습니다: ' . openssl_error_string());
        }

        return $out;
    }
}
