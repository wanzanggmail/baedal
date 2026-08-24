<?php

declare(strict_types=1);

require_once __DIR__ . '/PgConfig.php';

/**
 * PG 카드결제 게이트웨이 추상화 (LOGIC §5.4 · §7 #8 · REF_PG_WEROUTE.md).
 *
 * 2026-08-15: 실 PG사(위루트) 스펙에 맞춰 인터페이스를 확장했다.
 *   - 기존 `charge(string $billingKey, int $amount, array $meta)` 는 위루트가 요구하는
 *     `ord_num`/`buyer_name`/`buyer_phone`/`item_name`/`installment` 를 담을 수 없었다.
 *     `meta` 배열에 밀어넣으면 계약이 무의미해지므로 **요청 DTO**로 바꿨다.
 *   - 카드 등록(빌키 생성)·해지도 게이트웨이의 책임이다. 기존에는 `AgencyCard`가
 *     `MOCK-BK-...` 를 **스스로 만들어** 저장했는데, 실 연동에서는 반대로
 *     **PG가 발급한 `bill_key`를 받아 저장**해야 한다.
 *
 * 🔒 카드번호·비밀번호는 **우리 DB에 저장하지 않는다.** 빌키 발급 요청에만 실어 보내고
 *    응답의 `bill_key`만 보관한다(PCI-DSS 범위 회피).
 */
interface PgGateway
{
    /** 빌링키로 결제 승인 */
    public function charge(PgChargeRequest $req): PgChargeResult;

    /** 카드 등록 — 빌키 발급 */
    public function issueBillingKey(PgBillingKeyRequest $req): PgBillingKeyResult;

    /** 카드 해지 — 빌키 삭제 */
    public function deleteBillingKey(string $billingKey, string $orderNo): PgSimpleResult;

    /** 화면·로그 표기용 이름 */
    public function label(): string;
}

/**
 * 결제 요청 — 위루트 `POST /api/v2/pay/bill-key/hand` 필드에 대응.
 *
 * `orderNo`(ord_num)는 **결제 전에 채번**해야 한다. 위루트가 요청 시점에 요구하는데
 * 우리 `pg_payments.id`는 결제가 성공해야 생기기 때문이다(`PgPayment::makeOrderNo()`).
 */
final class PgChargeRequest
{
    /** @param array<string,mixed> $meta 드라이버 부가정보(mock_limit 등) */
    public function __construct(
        public readonly string $billingKey,
        public readonly int $amount,
        public readonly string $orderNo,
        public readonly string $buyerName = '',
        public readonly string $buyerPhone = '',
        public readonly string $itemName = '정산금 충전',
        public readonly int $installment = 0,
        public readonly array $meta = []
    ) {
    }
}

/**
 * 빌키 발급 요청 — 위루트 `POST /api/v2/pay/bill-key` 필드에 대응.
 *
 * ⚠️ `cardNumber`·`cardPw`는 절대 저장하지 말 것. 이 객체는 요청 직후 버려진다.
 */
final class PgBillingKeyRequest
{
    public function __construct(
        public readonly string $cardNumber,
        /** 유효기간 YYMM (예: 2509) */
        public readonly string $expiry,
        /** 카드 소유자 생년월일 6자리 또는 사업자번호 */
        public readonly string $authNum,
        /** 카드비밀번호 앞 2자리 */
        public readonly string $cardPw,
        public readonly string $orderNo,
        public readonly string $buyerName = '',
        public readonly string $buyerPhone = ''
    ) {
    }
}

/** PG 결제 결과 */
final class PgChargeResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $tid = '',
        public readonly string $failCode = '',
        public readonly string $failReason = '',
        /** 위루트 응답 부가정보(리포트·대사용) */
        public readonly string $apprNum = '',
        public readonly string $issuer = '',
        public readonly string $issuerCode = '',
        public readonly string $cardNum = ''
    ) {
    }

    public static function ok(
        string $tid,
        string $apprNum = '',
        string $issuer = '',
        string $issuerCode = '',
        string $cardNum = ''
    ): self {
        return new self(true, $tid, '', '', $apprNum, $issuer, $issuerCode, $cardNum);
    }

    public static function fail(string $code, string $reason): self
    {
        return new self(false, '', $code, $reason);
    }

    /** 한도초과 등 "다음 카드로 재시도" 대상 실패인지. */
    public function isRetriable(): bool
    {
        return in_array($this->failCode, ['LIMIT_EXCEEDED', 'CARD_DECLINED', 'INSUFFICIENT'], true);
    }
}

/** 빌키 발급 결과 */
final class PgBillingKeyResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $billKey = '',
        public readonly string $billCode = '',
        public readonly string $issuer = '',
        public readonly string $issuerCode = '',
        public readonly string $tid = '',
        public readonly string $failCode = '',
        public readonly string $failReason = ''
    ) {
    }

    public static function ok(string $billKey, string $billCode = '', string $issuer = '', string $issuerCode = '', string $tid = ''): self
    {
        return new self(true, $billKey, $billCode, $issuer, $issuerCode, $tid);
    }

    public static function fail(string $code, string $reason): self
    {
        return new self(false, '', '', '', '', '', $code, $reason);
    }
}

/** 삭제 등 단순 성공/실패 */
final class PgSimpleResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $failReason = ''
    ) {
    }

    public static function ok(): self
    {
        return new self(true);
    }

    public static function fail(string $reason): self
    {
        return new self(false, $reason);
    }
}

/**
 * 개발/데모용 모의 PG 게이트웨이.
 * - meta['mock_limit'] > 0 이고 amount 가 그 값을 초과하면 LIMIT_EXCEEDED 실패(대체결제 테스트용)
 * - billingKey 가 'FAIL-'로 시작하면 무조건 실패
 * - 그 외에는 성공(모의 tid 발급)
 */
final class MockPgGateway implements PgGateway
{
    public function charge(PgChargeRequest $req): PgChargeResult
    {
        if (str_starts_with($req->billingKey, 'FAIL-')) {
            return PgChargeResult::fail('CARD_DECLINED', '모의: 카드 승인 거절');
        }
        $limit = (int) ($req->meta['mock_limit'] ?? 0);
        if ($limit > 0 && $req->amount > $limit) {
            return PgChargeResult::fail('LIMIT_EXCEEDED', sprintf(
                '모의: 한도초과(%s > %s)',
                number_format($req->amount),
                number_format($limit)
            ));
        }

        return PgChargeResult::ok(
            'MOCK-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(3)), 0, 6),
            (string) random_int(10000000, 99999999),
            '모의카드',
            '99'
        );
    }

    public function issueBillingKey(PgBillingKeyRequest $req): PgBillingKeyResult
    {
        if (str_starts_with($req->cardNumber, '0000')) {
            return PgBillingKeyResult::fail('INVALID_CARD', '모의: 카드 정보 오류');
        }

        return PgBillingKeyResult::ok(
            'MOCK-BK-' . strtoupper(bin2hex(random_bytes(6))),
            'MOCK-BC-' . strtoupper(bin2hex(random_bytes(3))),
            '모의카드',
            '99',
            'MOCK-' . date('YmdHis')
        );
    }

    public function deleteBillingKey(string $billingKey, string $orderNo): PgSimpleResult
    {
        return PgSimpleResult::ok();
    }

    public function label(): string
    {
        return '모의(Mock)';
    }
}

/**
 * 게이트웨이 팩토리 — `pg_config.driver` 기준으로 분기한다.
 *
 * 실 드라이버(`RealPgGateway`)는 2026-08-23 구현했다. 다만 **설정이 갖춰졌을 때만** 탄다 —
 * driver=weroute 이고 mid·pay_key 가 있어야 한다. 하나라도 비면 Mock 으로 폴백해서,
 * 설정을 덜 넣은 채 실 결제가 나가는 일이 없게 한다.
 */
final class PgGatewayFactory
{
    public static function make(): PgGateway
    {
        if (self::realAvailable()) {
            require_once __DIR__ . '/RealPgGateway.php';

            return new RealPgGateway(PgConfig::get());
        }

        return new MockPgGateway();
    }

    /** 실 드라이버로 나갈 조건 — driver 선택 + 최소 자격증명 */
    public static function realAvailable(): bool
    {
        $cfg = PgConfig::get();
        if ((string) $cfg['driver'] !== PgConfig::DRIVER_WEROUTE) {
            return false;
        }

        return trim((string) $cfg['mid']) !== '' && trim((string) $cfg['pay_key']) !== '';
    }

    /** 화면에 "모의 모드" 배지를 띄울지 판단 */
    public static function isMock(): bool
    {
        return !self::realAvailable();
    }
}
