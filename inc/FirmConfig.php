<?php

declare(strict_types=1);

require_once __DIR__ . '/Crypto.php';
require_once __DIR__ . '/BaumCrypto.php';

/**
 * 펌뱅킹(바움P&S) 연동 자격증명 — 매뉴얼 v1.1.8.
 *
 * PG(`PgConfig`)와 같은 이유로 **시스템 전역 1행**이다. 돈이 나가는 계좌는 본사 하나뿐이고
 * (2026-08-15 갑 확정), 대리점 지갑은 그 계좌를 조직별로 나눠 보는 내부 장부일 뿐이다.
 *
 * 🔒 비밀값(`secret_key`·`enc_key`·`enc_iv`·`access_token`)은 **DB에 암호화 저장**한다
 *    (`Crypto`, AES-256-GCM, 키는 `.env` 의 `APP_ENC_KEY`).
 *    이 값들이 새면 **남의 계좌로 돈을 보낼 수 있다** — PG 결제키보다 더 위험하다.
 *    `client_id` 는 식별자라 평문으로 둔다(PgConfig 의 `mid` 와 같은 취급).
 *
 * ⚠️ 암호화 키 두 가지를 헷갈리지 말 것:
 *    `enc_key`/`enc_iv` 는 **바움과 통신할 때 쓰는 키**(`BaumCrypto`)이고,
 *    그 값을 우리 DB에 넣을 때 다시 `Crypto` 로 감싼다. 이중 구조가 맞다.
 */
final class FirmConfig
{
    public const DRIVER_MOCK = 'mock';
    public const DRIVER_BAUM = 'baum';

    public const ENV_DEV  = 'dev';
    public const ENV_PROD = 'prod';

    /** 매뉴얼 「서버 구성」 */
    private const HOSTS = [
        self::ENV_DEV  => 'https://dev-firm-api.baumpns.com',
        self::ENV_PROD => 'https://firm-api.baumpns.com',
    ];

    /**
     * ⚠️ 매뉴얼 안에서 경로가 어긋나는 곳이 있다.
     *   - 예금주 조회: API 목록은 `/api/firm/depositor-name`, 상세 페이지는 `/api/firm/account-holder`
     *     → 상세 페이지의 curl 예시 기준인 `account-holder` 를 쓴다. 개발사 확인 대기(REF 참고).
     */
    public const EP_TOKEN          = '/auth/access_token';
    public const EP_ACCOUNT_HOLDER = '/api/firm/account-holder';
    public const EP_POCKETS        = '/api/firm/account-pocket';
    public const EP_POCKET         = '/api/firm/pocket/';            // + {포켓코드}
    public const EP_SUBMIT         = '/api/firm/transfer-submission';
    public const EP_TRANSFER_INFO  = '/api/firm/transfer-info/';     // + {transactionId}
    public const EP_CANCEL         = '/api/firm/transfer-cancel';
    public const EP_WEBHOOK        = '/api/firm/webhook';

    /** 한 번에 접수할 수 있는 이체 건수(매뉴얼 「계좌이체 접수」) */
    public const MAX_BATCH = 100;

    /** 암호화해서 저장하는 필드 */
    private const SECRET_FIELDS = [
        'secret_key', 'enc_key', 'enc_iv', 'access_token',
        'dev_secret_key', 'dev_enc_key', 'dev_enc_iv',
        'prod_secret_key', 'prod_enc_key', 'prod_enc_iv',
    ];

    /** 환경별로 따로 보관하는 자격증명 — 개발/운영은 값이 전부 다르다. */
    private const ENV_FIELDS = ['client_id', 'secret_key', 'enc_key', 'enc_iv', 'pocket_code'];

    public static function tableExists(): bool
    {
        return db_table_exists('firm_config');
    }

    /** @return array<string,mixed> */
    public static function get(): array
    {
        $defaults = [
            'driver' => self::DRIVER_MOCK, 'env' => self::ENV_DEV,
            'client_id' => '', 'secret_key' => '',
            'enc_key' => '', 'enc_iv' => '',
            'pocket_code' => '', 'noti_allow_ips' => '',
            'access_token' => '', 'token_expires_at' => null,
        ];
        if (!self::tableExists()) {
            return $defaults;
        }
        $row = db_row('SELECT * FROM firm_config WHERE id = 1 LIMIT 1');
        if ($row === null) {
            return $defaults;
        }

        // 복호화 실패를 삼키지 않는다 — 빈 값으로 넘기면 isReady() 가 false 가 되어
        // 게이트웨이가 조용히 mock 으로 떨어지고, **실제로는 안 나간 이체가 성공으로 기록된다**.
        foreach (self::SECRET_FIELDS as $f) {
            if (isset($row[$f]) && $row[$f] !== null) {
                $row[$f] = Crypto::decrypt((string) $row[$f]);
            }
        }

        $out = array_merge($defaults, $row);

        // 개발/운영은 Client ID·Secret·암호화 KEY/IV·포켓코드가 **전부 다르다**.
        // 현재 `env` 의 값을 최상위 키로 올려 준다 — 호출부는 예전처럼
        // `$cfg['client_id']` 만 보면 되고, 서버를 바꾸면 자격증명도 함께 바뀐다.
        $env = ((string) $out['env']) === self::ENV_PROD ? self::ENV_PROD : self::ENV_DEV;
        foreach (self::ENV_FIELDS as $fld) {
            $out[$fld] = (string) ($out[$env . '_' . $fld] ?? '');
        }
        $out['env'] = $env;

        return $out;
    }

    public static function host(?string $env = null): string
    {
        $env ??= (string) self::get()['env'];

        return self::HOSTS[$env] ?? self::HOSTS[self::ENV_DEV];
    }

    /** 실 연동을 켤 수 있는 상태인가 — 통신에 필요한 최소값이 다 있는지. */
    public static function isReady(): bool
    {
        $c = self::get();

        return (string) $c['driver'] === self::DRIVER_BAUM
            && trim((string) $c['client_id']) !== ''
            && trim((string) $c['secret_key']) !== ''
            && BaumCrypto::usable((string) $c['enc_key'], (string) $c['enc_iv']);
    }

    /** 설정된 키/IV 로 만든 암복호화기 (없거나 형식이 틀리면 예외) */
    public static function crypto(): BaumCrypto
    {
        $c = self::get();

        return new BaumCrypto((string) $c['enc_key'], (string) $c['enc_iv']);
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
            'driver'            => (string) $c['driver'],
            'env'               => (string) $c['env'],
            'host'              => self::host((string) $c['env']),
            'client_id'         => (string) $c['client_id'],
            'secret_key_masked' => $mask((string) $c['secret_key']),
            'enc_key_masked'    => $mask((string) $c['enc_key']),
            'enc_iv_masked'     => $mask((string) $c['enc_iv']),
            'pocket_code'       => (string) $c['pocket_code'],
            'noti_allow_ips'    => (string) $c['noti_allow_ips'],
            'has_secret_key'    => trim((string) $c['secret_key']) !== '',
            'has_enc'           => BaumCrypto::usable((string) $c['enc_key'], (string) $c['enc_iv']),
            'is_ready'          => self::isReady(),
            'token_expires_at'  => $c['token_expires_at'],
            // 양쪽 환경의 준비 상태 — 화면이 "지금 어느 쪽 값을 고치는 중인가" 를 알려야 한다.
            'envs'              => self::envReadiness($c),
        ];
    }

    /**
     * 환경별 자격증명이 채워졌는지.
     *
     * @param array<string,mixed> $c
     * @return array<string, array{filled:bool, client_id:string}>
     */
    private static function envReadiness(array $c): array
    {
        $out = [];
        foreach ([self::ENV_DEV, self::ENV_PROD] as $e) {
            $cid = trim((string) ($c[$e . '_client_id'] ?? ''));
            $sec = trim((string) ($c[$e . '_secret_key'] ?? ''));
            $out[$e] = [
                'filled'    => $cid !== '' && $sec !== ''
                    && BaumCrypto::usable((string) ($c[$e . '_enc_key'] ?? ''), (string) ($c[$e . '_enc_iv'] ?? '')),
                'client_id' => $cid,
            ];
        }

        return $out;
    }

    /**
     * 저장. 비밀값은 **빈 문자열로 오면 기존 값을 유지**한다 —
     * 화면이 마스킹된 값을 보여주므로 안 건드린 필드를 빈값으로 덮어쓰면 안 된다.
     *
     * @param array<string,mixed> $data
     */
    public static function save(array $data, ?int $adminId = null): void
    {
        if (!self::tableExists()) {
            throw new RuntimeException('firm_config 테이블이 없습니다. php migrate.php 를 실행하세요.');
        }

        $cur    = self::get();
        $driver = (string) ($data['driver'] ?? $cur['driver']);
        $env    = (string) ($data['env'] ?? $cur['env']);
        if (!in_array($driver, [self::DRIVER_MOCK, self::DRIVER_BAUM], true)) {
            throw new InvalidArgumentException('driver 값이 올바르지 않습니다.');
        }
        if (!in_array($env, [self::ENV_DEV, self::ENV_PROD], true)) {
            throw new InvalidArgumentException('서버 구분(env) 값이 올바르지 않습니다.');
        }

        // ⚠️ 기준은 **저장하려는 env** 다. 서버를 dev→prod 로 바꾸는 저장이라면
        //    비교·유지 대상도 prod 자격증명이어야 한다. `$cur` 은 지금 env 의 값이라
        //    그대로 쓰면 개발 자격증명이 운영 칸으로 넘어간다.
        $prev = $cur;
        if ($env !== (string) $cur['env']) {
            foreach (self::ENV_FIELDS as $fld) {
                $prev[$fld] = (string) ($cur[$env . '_' . $fld] ?? '');
            }
        }

        $keep = static function (string $key) use ($data, $prev): string {
            $v = trim((string) ($data[$key] ?? ''));

            return $v !== '' ? $v : (string) $prev[$key];
        };

        $clientId = trim((string) ($data['client_id'] ?? $prev['client_id']));
        $secret   = $keep('secret_key');
        $encKey   = $keep('enc_key');
        $encIv    = $keep('enc_iv');

        // 형식이 틀린 키를 저장해 두면 실제 이체 때 터진다 — 저장 시점에 막는다.
        if ($encKey !== '' || $encIv !== '') {
            new BaumCrypto($encKey, $encIv); // 형식 오류면 여기서 예외
        }

        // 실 연동으로 전환하려면 최소값이 있어야 한다 — 켜놓고 안 되는 상태를 만들지 않는다.
        if ($driver === self::DRIVER_BAUM) {
            if ($clientId === '' || $secret === '') {
                throw new InvalidArgumentException('실 연동을 켜려면 Client ID 와 Secret Key 가 필요합니다.');
            }
            if (!BaumCrypto::usable($encKey, $encIv)) {
                throw new InvalidArgumentException('실 연동을 켜려면 암호화 KEY(32바이트)와 IV(16바이트)가 필요합니다.');
            }
        }

        // 자격증명은 **그 환경의 칸에만** 쓴다 — 다른 환경 값은 건드리지 않는다.
        db_execute(
            "UPDATE firm_config
                SET driver = ?, env = ?,
                    `{$env}_client_id` = ?, `{$env}_secret_key` = ?,
                    `{$env}_enc_key` = ?, `{$env}_enc_iv` = ?, `{$env}_pocket_code` = ?,
                    noti_allow_ips = ?, updated_by = ?, updated_at = NOW()
              WHERE id = 1",
            [
                $driver,
                $env,
                $clientId,
                Crypto::encrypt($secret),
                Crypto::encrypt($encKey),
                Crypto::encrypt($encIv),
                trim((string) ($data['pocket_code'] ?? $prev['pocket_code'])),
                // 허용 IP는 비밀값이 아니라 **빈 값도 의미가 있다**(검사 끄기) → keep 하지 않는다.
                array_key_exists('noti_allow_ips', $data)
                    ? trim((string) $data['noti_allow_ips'])
                    : (string) $prev['noti_allow_ips'],
                ($adminId !== null && $adminId > 0) ? $adminId : null,
            ]
        );
    }

    /**
     * Access Token 캐시 저장.
     *
     * ⚠️ 매뉴얼의 `expires_in` 은 설명에 "단위: 초" 라고 적혀 있으나 예시값이 `86400000` 이다.
     *    초로 보면 1,000일이 되어 토큰을 영원히 갱신하지 않게 된다. 24시간을 밀리초로 적은
     *    것으로 보고 **10만 이상이면 밀리초로 간주**한다(개발사 확인 대기 — REF 참고).
     */
    public static function storeAccessToken(string $token, int $expiresIn): void
    {
        if (!self::tableExists()) {
            return;
        }
        $sec = $expiresIn >= 100000 ? (int) ($expiresIn / 1000) : $expiresIn;
        $sec = max(60, min(86400, $sec)); // 하루를 넘기지 않는다 — 길게 잡아 실패하는 것보다 낫다

        db_execute(
            'UPDATE firm_config
                SET access_token = ?, token_expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND)
              WHERE id = 1',
            [Crypto::encrypt($token), $sec]
        );
    }

    /** 아직 유효한 토큰이 있으면 반환, 없으면 빈 문자열(재발급 필요) */
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

    /** 토큰 캐시 비우기(설정 변경·재인증 시) */
    public static function clearAccessToken(): void
    {
        if (self::tableExists()) {
            db_execute("UPDATE firm_config SET access_token = '', token_expires_at = NULL WHERE id = 1");
        }
    }
}
