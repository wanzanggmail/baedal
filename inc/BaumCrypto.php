<?php

declare(strict_types=1);

/**
 * 바움P&S 펌뱅킹 API 전송 암호화 — AES-256-CBC / PKCS5Padding / Base64.
 *
 * ⚠️ **`inc/Crypto.php` 와 목적도 방식도 다르다.** 헷갈리면 안 된다.
 *   - `Crypto`      : 우리 DB 에 값을 **저장**할 때 쓴다. AES-256-**GCM**, 키는 우리 `APP_ENC_KEY`.
 *   - `BaumCrypto`  : 바움과 **주고받을** 때 쓴다. AES-256-**CBC**, 키·IV 는 **바움이 발급**한다.
 *
 * 매뉴얼 v1.1.8 「API End-Point 암호화 정책」:
 *   - `/auth/access_token` 을 **제외한 모든 요청**은 JSON Body 전체를 암호화해 보낸다.
 *   - 응답 Body 도 암호화되어 온다.
 *   - 바움이 우리에게 보내는 「계좌이체 처리결과 통보」도 암호화돼 있고,
 *     **우리가 돌려주는 응답 Body 도 암호화해야** 정상 처리된다.
 *
 * PHP 의 `AES-256-CBC` 는 PKCS#7 패딩을 쓰는데, PKCS5Padding(Java)은 블록 16바이트에서
 * PKCS#7 과 동일하다 — 그래서 별도 처리 없이 그대로 호환된다.
 *
 * IV 가 고정값(발급받은 하나)인 점은 바움 규격이다. 일반적으로 CBC 는 매번 새 IV 를 쓰는 게
 * 맞지만, 여기서는 상대 서버가 그렇게 기대하므로 규격을 따른다.
 */
final class BaumCrypto
{
    private const CIPHER = 'aes-256-cbc';

    /** @var string 원시 키 32바이트 */
    private string $key;

    /** @var string 원시 IV 16바이트 */
    private string $iv;

    /**
     * @param string $keyB64 바움이 발급한 KEY (Base64)
     * @param string $ivB64  바움이 발급한 IV  (Base64)
     */
    public function __construct(string $keyB64, string $ivB64)
    {
        $key = base64_decode(trim($keyB64), true);
        $iv  = base64_decode(trim($ivB64), true);

        if (!is_string($key) || strlen($key) !== 32) {
            throw new InvalidArgumentException('바움 암호화 KEY 가 Base64 로 인코딩된 32바이트가 아닙니다.');
        }
        if (!is_string($iv) || strlen($iv) !== 16) {
            throw new InvalidArgumentException('바움 암호화 IV 가 Base64 로 인코딩된 16바이트가 아닙니다.');
        }

        $this->key = $key;
        $this->iv  = $iv;
    }

    /** 설정값이 형식상 쓸 수 있는지만 본다(실제 통신 성공 여부와는 별개). */
    public static function usable(string $keyB64, string $ivB64): bool
    {
        try {
            new self($keyB64, $ivB64);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** 평문 → Base64 암호문 */
    public function encrypt(string $plain): string
    {
        $out = openssl_encrypt($plain, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $this->iv);
        if ($out === false) {
            throw new RuntimeException('바움 요청 암호화에 실패했습니다.');
        }

        return base64_encode($out);
    }

    /** Base64 암호문 → 평문 */
    public function decrypt(string $b64): string
    {
        $raw = base64_decode(trim($b64), true);
        if (!is_string($raw) || $raw === '') {
            throw new RuntimeException('바움 응답이 Base64 형식이 아닙니다.');
        }
        $out = openssl_decrypt($raw, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $this->iv);
        if ($out === false) {
            throw new RuntimeException('바움 응답 복호화에 실패했습니다 — 키/IV 가 다르거나 값이 손상됐습니다.');
        }

        return $out;
    }

    /**
     * 배열 → JSON → 암호문.
     *
     * `JSON_UNESCAPED_UNICODE` 를 반드시 준다 — 예금주명·메모에 한글이 들어가는데
     * `\uXXXX` 로 이스케이프되면 바이트 수가 달라져 4096바이트 제한(metadata) 계산이 어긋난다.
     *
     * @param array<mixed>|list<mixed> $data
     */
    public function encryptJson(array $data): string
    {
        return $this->encrypt((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * 암호문 → JSON → 배열.
     *
     * @return array<mixed>
     */
    public function decryptJson(string $b64): array
    {
        $json = $this->decrypt($b64);
        $arr  = json_decode($json, true);
        if (!is_array($arr)) {
            throw new RuntimeException('바움 응답을 JSON 으로 해석할 수 없습니다: ' . mb_substr($json, 0, 200));
        }

        return $arr;
    }
}
