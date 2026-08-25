<?php

declare(strict_types=1);

require_once __DIR__ . '/Crypto.php';

/**
 * PG(루트업) 연동 자격증명 — REF_PG_WEROUTE.md §2.
 *
 * 갑 확정(2026-08-15) **"결제하는 상점은 하나"** 이므로 대리점별이 아니라 **시스템 전역 1행**이다.
 * (대리점 지갑은 이 단일 가맹점 계좌를 조직별로 나눠 보여주는 내부 장부일 뿐이다.)
 *
 * ⚠️ 인증이 **두 갈래**라는 점이 이 클래스의 존재 이유다.
 *   - 거래(결제) API : `Authorization: {pay_key}`      ← Bearer 아님, 키 원문
 *   - 대사(정산) API : `External-Api: Bearer {api_key}` + `Authorization: Bearer {access_token}`
 *     `access_token`은 `POST /api/v1/sign-in`으로 받고 **30시간** 유효 → 여기 캐시해 재사용한다.
 *
 * 🔒 비밀값(pay_key·sign_key·api_key·enc_key·enc_iv·login_pw·access_token)은 **DB에 암호화해서**
 *    저장한다(`Crypto`, AES-256-GCM). 대사 API 로그인에 원문이 필요해 단방향 해시는 쓸 수 없고,
 *    이 값들은 **그 자체로 결제를 실행할 수 있는 자격증명**이라 평문으로 두면 DB 덤프 한 번이
 *    곧 금전 피해가 된다. 키는 `.env` 의 `APP_ENC_KEY` — DB 밖에 있어야 의미가 있다.
 *    화면에는 여전히 마스킹만 내보낸다(`publicView()`).
 */
final class PgConfig
{
    public const DRIVER_MOCK    = 'mock';
    public const DRIVER_WEROUTE = 'weroute';

    /**
     * 암호화해서 저장하는 필드.
     *
     * `mid`·`tid`·`login_id` 는 넣지 않는다 — 가맹점 식별자일 뿐 그것만으로는 아무것도 못 하고,
     * 오히려 로그·문의에서 그대로 봐야 하는 값이다.
     */
    private const SECRET_FIELDS = [
        'pay_key', 'sign_key', 'api_key', 'enc_key', 'enc_iv', 'login_pw', 'access_token',
    ];

    /**
     * API 호스트.
     *
     * ⚠️ 2026-08-25 정정 — 처음 받은 문서(`api.weroutefincorp.com`)는 **우리 가맹점의 것이 아니었다.**
     * 그 호스트로는 어떤 요청도 `RV406 가맹점을 찾을 수 없습니다` 로 떨어졌고, 실제 문서는
     * `https://api.routeup.kr/docs/routeup.html`(루트업) 이다. 호스트만 바꾸니 같은 MID 로
     * 가맹점 조회가 통과했다. **경로·필드·인증 방식은 두 문서가 동일**하다.
     */
    public const HOST = 'https://api.routeup.kr';

    /**
     * ⚠️ 경로 함정 — 폼 전송형(인증·간편결제)만 `/api` 접두어가 없고, 대사는 버전도 다르다.
     * 하드코딩하지 말고 여기서 가져다 쓸 것.
     */
    public const EP_BILL_KEY     = '/api/v2/pay/bill-key';        // POST 생성 · DELETE 삭제
    public const EP_BILL_PAY     = '/api/v2/pay/bill-key/hand';   // POST 빌키 결제
    public const EP_CANCEL       = '/api/v2/pay/cancel';          // POST 결제취소
    public const EP_NET_CANCEL   = '/api/v2/pay/net-cancel';      // POST 망상취소(타임아웃 방어)
    public const EP_TRANSACTIONS = '/api/v2/transactions/';       // GET  + {ord_num}
    public const EP_SIGN_IN      = '/api/v1/sign-in';             // POST 대사 로그인

    public static function tableExists(): bool
    {
        return db_table_exists('pg_config');
    }

    /** @return array<string,mixed> */
    public static function get(): array
    {
        $defaults = [
            'driver' => self::DRIVER_MOCK, 'mid' => '', 'tid' => '',
            'pay_key' => '', 'sign_key' => '', 'api_key' => '',
            // 위루트가 가맹점별로 발급하는 AES 키/IV — 민감 필드 암호화용
            'enc_key' => '', 'enc_iv' => '',
            'noti_allow_ips' => '',
            'login_id' => '', 'login_pw' => '',
            'access_token' => '', 'token_expires_at' => null,
        ];
        if (!self::tableExists()) {
            return $defaults;
        }
        $row = db_row('SELECT * FROM pg_config WHERE id = 1 LIMIT 1');
        if ($row === null) {
            return $defaults;
        }

        // 이관 전 평문 행도 그대로 읽힌다(`Crypto::decrypt` 가 접두사 없는 값을 통과시킨다).
        // 복호화 실패는 **삼키지 않는다** — 빈 값으로 넘기면 isReady() 가 false 가 되고
        // 게이트웨이가 조용히 mock 으로 떨어져, 실제로는 안 긁힌 결제가 성공으로 기록된다.
        foreach (self::SECRET_FIELDS as $f) {
            if (isset($row[$f]) && $row[$f] !== null) {
                $row[$f] = Crypto::decrypt((string) $row[$f]);
            }
        }

        return array_merge($defaults, $row);
    }

    /**
     * 실 연동을 켤 수 있는 상태인가 — 거래 API에 필요한 최소값이 다 있는지.
     * (대사 API 값은 없어도 결제는 된다.)
     */
    public static function isReady(): bool
    {
        $c = self::get();

        return (string) $c['driver'] === self::DRIVER_WEROUTE
            && trim((string) $c['mid']) !== ''
            && trim((string) $c['pay_key']) !== '';
    }

    /** 웹훅 서명 검증이 가능한 상태인가 (sign_key 필요 — PG사 발급 대기 중) */
    public static function canVerifyWebhook(): bool
    {
        return trim((string) self::get()['sign_key']) !== '';
    }

    /**
     * 화면에 내보낼 수 있는 형태 — 비밀값은 앞 4자리만 남기고 가린다.
     *
     * @return array<string,mixed>
     */
    public static function publicView(): array
    {
        $c    = self::get();
        $mask = static function (string $v): string {
            $v = trim($v);
            if ($v === '') {
                return '';
            }

            return mb_substr($v, 0, 4) . str_repeat('•', max(4, min(12, mb_strlen($v) - 4)));
        };

        return [
            'driver'           => (string) $c['driver'],
            'mid'              => (string) $c['mid'],
            'tid'              => (string) $c['tid'],
            'pay_key_masked'   => $mask((string) $c['pay_key']),
            'sign_key_masked'  => $mask((string) $c['sign_key']),
            'api_key_masked'   => $mask((string) $c['api_key']),
            'enc_key_masked'   => $mask((string) $c['enc_key']),
            'enc_iv_masked'    => $mask((string) $c['enc_iv']),
            'login_id'         => (string) $c['login_id'],
            'login_pw_masked'  => $mask((string) $c['login_pw']),
            'has_pay_key'      => trim((string) $c['pay_key']) !== '',
            'has_sign_key'     => trim((string) $c['sign_key']) !== '',
            'has_api_key'      => trim((string) $c['api_key']) !== '',
            'has_enc_key'      => trim((string) $c['enc_key']) !== '' && trim((string) $c['enc_iv']) !== '',
            'is_ready'         => self::isReady(),
            'token_expires_at' => $c['token_expires_at'],
        ];
    }

    /**
     * 저장. 비밀값은 **빈 문자열로 오면 기존 값을 유지**한다 —
     * 화면이 마스킹된 값을 보여주므로, 사용자가 안 건드린 필드를 빈값으로 덮어쓰면 안 된다.
     *
     * @param array<string,mixed> $data
     */
    public static function save(array $data, ?int $adminId = null): void
    {
        if (!self::tableExists()) {
            throw new RuntimeException('pg_config 테이블이 없습니다. php migrate.php 를 실행하세요.');
        }

        $cur    = self::get();
        $driver = (string) ($data['driver'] ?? $cur['driver']);
        if (!in_array($driver, [self::DRIVER_MOCK, self::DRIVER_WEROUTE], true)) {
            throw new InvalidArgumentException('driver 값이 올바르지 않습니다.');
        }

        $keep = static function (string $key) use ($data, $cur): string {
            $v = trim((string) ($data[$key] ?? ''));

            return $v !== '' ? $v : (string) $cur[$key];
        };

        $mid    = trim((string) ($data['mid'] ?? $cur['mid']));
        $payKey = $keep('pay_key');

        // 실 연동으로 전환하려면 최소값이 있어야 한다 — 켜놓고 안 되는 상태를 만들지 않는다.
        if ($driver === self::DRIVER_WEROUTE && ($mid === '' || $payKey === '')) {
            throw new InvalidArgumentException('위루트 연동을 켜려면 가맹점 ID(MID)와 결제 KEY가 필요합니다.');
        }

        db_execute(
            'UPDATE pg_config
                SET driver = ?, mid = ?, tid = ?, pay_key = ?, sign_key = ?, api_key = ?,
                    enc_key = ?, enc_iv = ?, noti_allow_ips = ?,
                    login_id = ?, login_pw = ?, updated_by = ?, updated_at = NOW()
              WHERE id = 1',
            [
                $driver,
                $mid,
                trim((string) ($data['tid'] ?? $cur['tid'])),
                Crypto::encrypt($payKey),
                Crypto::encrypt($keep('sign_key')),
                Crypto::encrypt($keep('api_key')),
                Crypto::encrypt($keep('enc_key')),
                Crypto::encrypt($keep('enc_iv')),
                // 허용 IP는 비밀값이 아니라 **빈 값도 의미가 있다**(검사 끄기) → keep 하지 않는다.
                array_key_exists('noti_allow_ips', $data)
                    ? trim((string) $data['noti_allow_ips'])
                    : (string) $cur['noti_allow_ips'],
                trim((string) ($data['login_id'] ?? $cur['login_id'])),
                Crypto::encrypt($keep('login_pw')),
                ($adminId !== null && $adminId > 0) ? $adminId : null,
            ]
        );
    }

    /** 대사 API 토큰 캐시 저장(발급 후 30시간 유효) */
    public static function storeAccessToken(string $token, int $ttlHours = 30): void
    {
        if (!self::tableExists()) {
            return;
        }
        db_execute(
            'UPDATE pg_config SET access_token = ?, token_expires_at = DATE_ADD(NOW(), INTERVAL ? HOUR) WHERE id = 1',
            [Crypto::encrypt($token), max(1, $ttlHours)]
        );
    }

    /** 아직 유효한 토큰이 있으면 반환, 없으면 빈 문자열(재로그인 필요) */
    public static function validAccessToken(): string
    {
        $c = self::get();
        $t = trim((string) $c['access_token']);
        if ($t === '' || $c['token_expires_at'] === null) {
            return '';
        }

        // 만료 직전 호출이 실패하지 않도록 5분 여유를 둔다.
        return strtotime((string) $c['token_expires_at']) > (time() + 300) ? $t : '';
    }
}
