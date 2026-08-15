<?php

declare(strict_types=1);

/**
 * 대리점 등록 카드 (agency_cards) — PG 결제 다건 카드/우선순위 (LOGIC §5.4 · §7 #8).
 *
 * 대리점은 카드를 여러 개 등록할 수 있고, 결제는 우선순위(priority) 낮은 순으로 시도한다.
 * 한도초과 등 실패 시 다음 카드로 자동 대체결제(→ PgPayment).
 * 빌링키는 실 PG 연동 전까지 모의 값을 저장할 수 있다.
 */
final class AgencyCard
{
    public static function tableExists(): bool
    {
        return db_table_exists('agency_cards');
    }

    /**
     * 결제 시도 순서(우선순위 → id) 활성 카드 목록.
     *
     * @return list<array<string,mixed>>
     */
    public static function activeForAgency(int $agencyId): array
    {
        if ($agencyId < 1 || !self::tableExists()) {
            return [];
        }

        return db_rows(
            'SELECT * FROM agency_cards WHERE agency_id = ? AND is_active = 1 ORDER BY priority ASC, id ASC',
            [$agencyId]
        );
    }

    /** @return list<array<string,mixed>> */
    public static function listForAgency(int $agencyId): array
    {
        if ($agencyId < 1 || !self::tableExists()) {
            return [];
        }

        return array_map([self::class, 'mapRow'], db_rows(
            'SELECT * FROM agency_cards WHERE agency_id = ? ORDER BY priority ASC, id ASC',
            [$agencyId]
        ));
    }

    /**
     * 카드 등록 = **PG 빌키 발급**.
     *
     * 🔒 카드번호·유효기간·비밀번호·생년월일은 **PG로 전달만 하고 저장하지 않는다**
     *    (PCI-DSS 범위 회피). 우리 DB에 남는 건 PG가 발급한 `bill_key`와 표시용
     *    끝 4자리·카드사명뿐이다.
     *
     * ⚠️ 2026-08-15 이전에는 이 메서드가 `MOCK-BK-...`를 **스스로 만들어** 저장했다.
     *    실 연동에서는 반대로 **PG가 준 키를 받아 저장**해야 하므로 게이트웨이를 거친다.
     *    Mock 드라이버도 같은 경로를 타므로 개발 흐름은 그대로다.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function create(int $agencyId, array $data): array
    {
        if ($agencyId < 1 || !self::tableExists()) {
            throw new RuntimeException('카드 테이블이 없습니다. php migrate.php 를 실행하세요.');
        }
        require_once __DIR__ . '/PgGateway.php';
        require_once __DIR__ . '/PgPayment.php';

        $alias     = trim((string) ($data['alias'] ?? ''));
        // 화면에서는 입력받지 않는다. 빌키를 직접 넣는 경로에서만 값이 올 수 있다.
        $brand     = trim((string) ($data['brand'] ?? ''));
        $priority  = (int) ($data['priority'] ?? 100);
        $mockLimit = (int) ($data['mock_limit'] ?? 0);

        if ($alias === '') {
            throw new InvalidArgumentException('카드 별칭을 입력하세요.');
        }

        // 카드 정보(저장하지 않음 — 아래 요청에만 쓰고 버린다)
        $cardNum = preg_replace('/\D/', '', (string) ($data['card_num'] ?? '')) ?? '';
        $expiry  = preg_replace('/\D/', '', (string) ($data['yymm'] ?? '')) ?? '';
        $authNum = preg_replace('/\D/', '', (string) ($data['auth_num'] ?? '')) ?? '';
        $cardPw  = preg_replace('/\D/', '', (string) ($data['card_pw'] ?? '')) ?? '';

        $billingKey = trim((string) ($data['billing_key'] ?? ''));
        $billCode   = '';
        $issuerCode = '';
        $last4      = substr(preg_replace('/\D/', '', (string) ($data['last4'] ?? '')) ?? '', -4);

        if ($billingKey === '') {
            // 이미 발급받은 빌키를 직접 넣는 경우가 아니면 카드 정보로 발급받는다.
            if ($cardNum === '' || $expiry === '') {
                throw new InvalidArgumentException('카드번호와 유효기간(YYMM)을 입력하세요.');
            }
            if (strlen($expiry) !== 4) {
                throw new InvalidArgumentException('유효기간은 YYMM 4자리로 입력하세요. (예: 2509)');
            }

            $gateway = PgGatewayFactory::make();
            $res     = $gateway->issueBillingKey(new PgBillingKeyRequest(
                cardNumber: $cardNum,
                expiry: $expiry,
                authNum: $authNum,
                cardPw: $cardPw,
                orderNo: PgPayment::makeOrderNo($agencyId),
                buyerName: trim((string) ($data['buyer_name'] ?? '')),
                buyerPhone: preg_replace('/\D/', '', (string) ($data['buyer_phone'] ?? '')) ?? '',
            ));

            if (!$res->success) {
                throw new InvalidArgumentException('카드 등록 실패: ' . ($res->failReason !== '' ? $res->failReason : '알 수 없는 오류'));
            }

            $billingKey = $res->billKey;
            $billCode   = $res->billCode;
            $issuerCode = $res->issuerCode;
            // 카드사는 입력받지 않는다(2026-08-15) — 사람이 "국민"/"KB"/"국민카드"를 제각각 적어
            // 표기가 흔들리므로 PG 응답을 그대로 쓴다. 이름이 안 오면 발급사 코드로 라벨을 찾는다.
            if ($brand === '') {
                $brand = $res->issuer;
            }
            if ($brand === '' && $issuerCode !== '') {
                require_once __DIR__ . '/SystemCode.php';
                $brand = SystemCode::cardIssuerLabel($issuerCode);
            }
            if ($last4 === '') {
                $last4 = substr($cardNum, -4);
            }
        }

        $cols   = array_column(db_rows('SHOW COLUMNS FROM agency_cards'), 'Field');
        $fields = ['agency_id', 'alias', 'billing_key', 'brand', 'last4', 'priority', 'is_active', 'mock_limit'];
        $values = [$agencyId, $alias, $billingKey, $brand, $last4, max(0, min(9999, $priority)), 1, max(0, $mockLimit)];

        if (in_array('bill_code', $cols, true)) {
            $fields[] = 'bill_code';
            $values[] = $billCode;
        }
        if (in_array('issuer_code', $cols, true)) {
            $fields[] = 'issuer_code';
            $values[] = $issuerCode;
        }

        $id = db_insert(
            'INSERT INTO agency_cards (' . implode(', ', $fields) . ')
             VALUES (' . implode(', ', array_fill(0, count($fields), '?')) . ')',
            $values
        );

        return self::mapRow(db_row('SELECT * FROM agency_cards WHERE id = ? LIMIT 1', [$id]) ?? []);
    }

    public static function setActive(int $agencyId, int $id, bool $active): void
    {
        self::assertOwned($agencyId, $id);
        db_execute('UPDATE agency_cards SET is_active = ? WHERE id = ?', [$active ? 1 : 0, $id]);
    }

    public static function setPriority(int $agencyId, int $id, int $priority): void
    {
        self::assertOwned($agencyId, $id);
        db_execute('UPDATE agency_cards SET priority = ? WHERE id = ?', [max(0, min(9999, $priority)), $id]);
    }

    /**
     * 카드 해지 — **PG에 먼저 빌키 삭제를 요청하고** 성공해야 우리 DB에서 지운다.
     * 순서를 바꾸면 PG 쪽엔 살아있는 빌키가 우리 시스템에서만 사라진다(유령 빌키).
     */
    public static function delete(int $agencyId, int $id): void
    {
        self::assertOwned($agencyId, $id);
        require_once __DIR__ . '/PgGateway.php';
        require_once __DIR__ . '/PgPayment.php';

        $row        = db_row('SELECT billing_key FROM agency_cards WHERE id = ? LIMIT 1', [$id]);
        $billingKey = (string) ($row['billing_key'] ?? '');

        if ($billingKey !== '') {
            $res = PgGatewayFactory::make()->deleteBillingKey($billingKey, PgPayment::makeOrderNo($agencyId));
            if (!$res->success) {
                throw new RuntimeException('PG 빌키 해지에 실패해 카드를 삭제하지 않았습니다: ' . $res->failReason);
            }
        }

        db_execute('DELETE FROM agency_cards WHERE id = ?', [$id]);
    }

    private static function assertOwned(int $agencyId, int $id): void
    {
        $row = db_row('SELECT agency_id FROM agency_cards WHERE id = ? LIMIT 1', [$id]);
        if ($row === null || (int) $row['agency_id'] !== $agencyId) {
            throw new InvalidArgumentException('내 대리점 카드가 아닙니다.');
        }
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private static function mapRow(array $r): array
    {
        return [
            'id'         => (int) ($r['id'] ?? 0),
            'alias'      => (string) ($r['alias'] ?? ''),
            'brand'      => (string) ($r['brand'] ?? ''),
            'last4'      => (string) ($r['last4'] ?? ''),
            'priority'   => (int) ($r['priority'] ?? 100),
            'active'     => (int) ($r['is_active'] ?? 0) === 1,
            'mock_limit' => (int) ($r['mock_limit'] ?? 0),
            'bill_code'   => (string) ($r['bill_code'] ?? ''),
            'issuer_code' => (string) ($r['issuer_code'] ?? ''),
            'billing_masked' => substr((string) ($r['billing_key'] ?? ''), 0, 10) . '…',
        ];
    }
}
