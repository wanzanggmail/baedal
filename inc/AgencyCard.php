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
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function create(int $agencyId, array $data): array
    {
        if ($agencyId < 1 || !self::tableExists()) {
            throw new RuntimeException('카드 테이블이 없습니다. php migrate.php 를 실행하세요.');
        }
        $alias      = trim((string) ($data['alias'] ?? ''));
        $billingKey = trim((string) ($data['billing_key'] ?? ''));
        $brand      = trim((string) ($data['brand'] ?? ''));
        $last4      = preg_replace('/\D/', '', (string) ($data['last4'] ?? '')) ?? '';
        $priority   = (int) ($data['priority'] ?? 100);
        $mockLimit  = (int) ($data['mock_limit'] ?? 0);

        if ($alias === '') {
            throw new InvalidArgumentException('카드 별칭을 입력하세요.');
        }
        if ($billingKey === '') {
            // 실 PG 연동 전: 별칭 기반 모의 빌링키 자동 발급
            $billingKey = 'MOCK-BK-' . strtoupper(bin2hex(random_bytes(6)));
        }
        $last4 = substr($last4, -4);

        $id = db_insert(
            'INSERT INTO agency_cards (agency_id, alias, billing_key, brand, last4, priority, is_active, mock_limit)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?)',
            [$agencyId, $alias, $billingKey, $brand, $last4, max(0, min(9999, $priority)), max(0, $mockLimit)]
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

    public static function delete(int $agencyId, int $id): void
    {
        self::assertOwned($agencyId, $id);
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
            'billing_masked' => substr((string) ($r['billing_key'] ?? ''), 0, 10) . '…',
        ];
    }
}
