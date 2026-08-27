<?php

declare(strict_types=1);

require_once __DIR__ . '/PgConfig.php';
require_once __DIR__ . '/PgGateway.php';
require_once __DIR__ . '/FirmConfig.php';
require_once __DIR__ . '/FirmBankingGateway.php';

/**
 * 외부 연동 모드(모의 ↔ 실연동) 통합 조회·전환.
 *
 * PG(결제)와 펌뱅킹(이체)은 설정 화면이 따로 있지만, **"지금 진짜 돈이 움직이는가"** 는
 * 한눈에 봐야 하는 정보다. 두 화면을 오가며 확인하면 한쪽만 켜 둔 걸 놓치기 쉽다.
 *
 * ⚠️ 두 연동은 **돈의 방향이 반대**라 위험의 성격이 다르다.
 *   - PG      : 대리점 카드에서 **돈이 들어온다**(지갑 충전). 잘못 켜면 과금이 발생한다.
 *   - 펌뱅킹  : 라이더·대리점 계좌로 **돈이 나간다**. 잘못 켜면 회수가 매우 어렵다.
 *   그래서 펌뱅킹 쪽 경고를 더 강하게 둔다.
 *
 * "선택한 드라이버"와 "실제로 실연동으로 도는지"는 다르다 — 자격증명이 빠지면 드라이버를
 * 실연동으로 골라도 **조용히 모의로 떨어진다**. 화면은 그 차이를 드러내야 한다.
 */
final class IntegrationMode
{
    public const CH_PG   = 'pg';
    public const CH_FIRM = 'firm';

    /** @return list<string> */
    public static function channels(): array
    {
        return [self::CH_PG, self::CH_FIRM];
    }

    /**
     * 두 연동의 현재 상태.
     *
     * @return array<string, array<string,mixed>>
     */
    public static function status(): array
    {
        return [
            self::CH_PG   => self::pgStatus(),
            self::CH_FIRM => self::firmStatus(),
        ];
    }

    /** @return array<string,mixed> */
    private static function pgStatus(): array
    {
        $ready  = PgConfig::tableExists();
        $cfg    = $ready ? PgConfig::get() : [];
        $driver = (string) ($cfg['driver'] ?? PgConfig::DRIVER_MOCK);
        $live   = $ready && PgGatewayFactory::realAvailable();

        // 실연동으로 바꿀 수 있는가 — 자격증명이 다 있어야 한다.
        $canGoLive = $ready
            && trim((string) ($cfg['mid'] ?? '')) !== ''
            && trim((string) ($cfg['pay_key'] ?? '')) !== '';

        return [
            'key'         => self::CH_PG,
            'label'       => 'PG 결제',
            'provider'    => '루트업',
            'direction'   => '들어옴',
            'live'        => $live,
            'driver'      => $driver,
            'live_driver' => PgConfig::DRIVER_WEROUTE,
            'can_go_live' => $canGoLive,
            'table_ready' => $ready,
            'settings'    => 'system/pg-integration',
            'affects'     => ['대리점 카드결제(지갑 충전)', '카드 등록·해지', '결제 취소'],
            'missing'     => $canGoLive ? [] : ['가맹점 ID(MID)', '결제 KEY'],
            'risk'        => '실연동에서는 등록된 카드로 **실제 결제**가 발생합니다.',
        ];
    }

    /** @return array<string,mixed> */
    private static function firmStatus(): array
    {
        $ready  = FirmConfig::tableExists();
        $cfg    = $ready ? FirmConfig::get() : [];
        $driver = (string) ($cfg['driver'] ?? FirmConfig::DRIVER_MOCK);
        $live   = $ready && FirmConfig::isReady();

        require_once __DIR__ . '/BaumCrypto.php';
        $canGoLive = $ready
            && trim((string) ($cfg['client_id'] ?? '')) !== ''
            && trim((string) ($cfg['secret_key'] ?? '')) !== ''
            && BaumCrypto::usable((string) ($cfg['enc_key'] ?? ''), (string) ($cfg['enc_iv'] ?? ''));

        $missing = [];
        if ($ready) {
            if (trim((string) ($cfg['client_id'] ?? '')) === '') {
                $missing[] = 'Client ID';
            }
            if (trim((string) ($cfg['secret_key'] ?? '')) === '') {
                $missing[] = 'Secret Key';
            }
            if (!BaumCrypto::usable((string) ($cfg['enc_key'] ?? ''), (string) ($cfg['enc_iv'] ?? ''))) {
                $missing[] = '암호화 KEY/IV';
            }
        } else {
            $missing[] = 'firm_config 테이블';
        }

        return [
            'key'         => self::CH_FIRM,
            'label'       => '펌뱅킹 이체',
            'provider'    => '바움P&S',
            'direction'   => '나감',
            'live'        => $live,
            'driver'      => $driver,
            'live_driver' => FirmConfig::DRIVER_BAUM,
            'can_go_live' => $canGoLive,
            'table_ready' => $ready,
            'settings'    => 'system/firm-integration',
            'affects'     => ['라이더 출금 이체', '일일지급(선정산)', '대리점 정산금 인출', '예금주 조회'],
            'missing'     => $missing,
            'risk'        => '실연동에서는 라이더·대리점 계좌로 **실제 송금**이 나갑니다. 잘못 나간 돈은 회수가 매우 어렵습니다.',
            'env'         => (string) ($cfg['env'] ?? FirmConfig::ENV_DEV),
        ];
    }

    /**
     * 모드 전환.
     *
     * 다른 설정은 건드리지 않고 **드라이버만** 바꾼다. 각 Config 의 `save()` 가
     * `$keep()` 으로 나머지 값을 그대로 유지하고, 실연동 전환에 필요한 값이 없으면
     * 스스로 거절한다 — 검증을 여기서 다시 짜지 않는 이유다.
     *
     * @return array{ok:bool, message:string, live:bool}
     */
    public static function switchTo(string $channel, bool $live, ?int $adminId = null): array
    {
        if ($channel === self::CH_PG) {
            if (!PgConfig::tableExists()) {
                throw new RuntimeException('pg_config 테이블이 없습니다. php migrate.php 를 실행하세요.');
            }
            PgConfig::save(['driver' => $live ? PgConfig::DRIVER_WEROUTE : PgConfig::DRIVER_MOCK], $adminId);

            $now = PgGatewayFactory::realAvailable();

            return [
                'ok'      => true,
                'live'    => $now,
                'message' => $now ? 'PG 를 실연동으로 전환했습니다.' : 'PG 를 모의로 전환했습니다.',
            ];
        }

        if ($channel === self::CH_FIRM) {
            if (!FirmConfig::tableExists()) {
                throw new RuntimeException('firm_config 테이블이 없습니다. php migrate.php 를 실행하세요.');
            }
            FirmConfig::save(['driver' => $live ? FirmConfig::DRIVER_BAUM : FirmConfig::DRIVER_MOCK], $adminId);
            // 드라이버가 바뀌면 캐시된 토큰은 의미가 없다.
            FirmConfig::clearAccessToken();

            $now = FirmConfig::isReady();

            return [
                'ok'      => true,
                'live'    => $now,
                'message' => $now ? '펌뱅킹을 실연동으로 전환했습니다.' : '펌뱅킹을 모의로 전환했습니다.',
            ];
        }

        throw new InvalidArgumentException('알 수 없는 연동 구분입니다.');
    }

    /**
     * 둘 다 모의로 — 사고가 났을 때 가장 먼저 누를 버튼.
     *
     * 실패해도 나머지 하나는 끄고 본다. "하나가 안 꺼져서 둘 다 켜져 있는" 상황이
     * 제일 나쁘다.
     *
     * @return array{ok:bool, message:string, errors:list<string>}
     */
    public static function allToMock(?int $adminId = null): array
    {
        $errors = [];
        foreach (self::channels() as $ch) {
            try {
                self::switchTo($ch, false, $adminId);
            } catch (Throwable $e) {
                $errors[] = $ch . ': ' . $e->getMessage();
            }
        }

        return [
            'ok'      => $errors === [],
            'message' => $errors === [] ? '모든 연동을 모의로 전환했습니다.' : '일부 전환에 실패했습니다.',
            'errors'  => $errors,
        ];
    }

    /** 하나라도 실연동이면 true — 헤더 배지 등에 쓴다. */
    public static function anyLive(): bool
    {
        foreach (self::status() as $s) {
            if ($s['live']) {
                return true;
            }
        }

        return false;
    }
}
