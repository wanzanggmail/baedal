<?php

declare(strict_types=1);

require_once __DIR__ . '/Org.php';

/**
 * 조직 지갑 (agency_wallets) — 대리점뿐 아니라 본사·총판도 같은 테이블을 쓴다.
 *
 * 자금 흐름(LOGIC §5.4·§5.5):
 *   PG 카드결제(FUND) → balance 충전 → 오픈뱅킹 이체(DISBURSE)로 라이더 지급 → balance 차감
 *   플랫폼 수수료·리스 수수료 몫은 본사/총판/대리점 지갑에 credit
 *   원천세 대상 라이더 공제분은 withholding_reserve로 누적(대리점이 신고·납부할 예수금)
 *   대리점 자체 인출가능액 = balance − 라이더 정산금(rider_wallets 합계) − withholding_reserve
 */
final class AgencyWallet
{
    /** @var array<string, string> */
    public const REASON_LABELS = [
        'pg_fund'          => 'PG 정산 조달',
        'pg_fund_rev'      => 'PG 결제 취소(조달 회수)',
        'pg_fee_in'         => '플랫폼 수수료 수입',
        'rider_payout'      => '라이더 지급',
        'agency_payout'     => '자체 인출',
        'manual_adjust'     => '수동 조정',
        'lease_fee_up'      => '리스 수수료 상위 이체',
        'lease_fee_up_rev'  => '리스 수수료 상위 이체 취소',
        'lease_fee_in'      => '리스 수수료 수입',
        'lease_fee_in_rev'  => '리스 수수료 수입 취소',
        'wd_fee_up'         => '정산수수료 상위 이체',
        'wd_fee_in'         => '정산수수료 수입',
        'transfer_fee_up'   => '이체 수수료 상위 이체',
        'transfer_fee_in'   => '이체 수수료 수입(본사)',
        'agency_fee_up'     => '대행수수료 상위 이체',
        'agency_fee_in'     => '대행수수료 수입(본사)',
        'ins_collect_out'   => '고용·산재 예수금 납부(세무대리)',
        'ins_collect_in'    => '고용·산재 예수금 수집',
        'ins_collect_rev'   => '고용·산재 예수금 환원',
        'wh_collect_out'    => '원천세 예수금 납부(세무대리)',
        'wh_collect_in'     => '원천세 예수금 수집',
        'msg_fee_up'        => '메시지 발송 요금',
        'msg_fee_in'        => '메시지 발송 요금 수입(본사)',
    ];

    public static function tableExists(): bool
    {
        return db_table_exists('agency_wallets');
    }

    public static function ensure(int $agencyId): void
    {
        if ($agencyId < 1 || !self::tableExists()) {
            return;
        }
        $exists = db_row('SELECT agency_id FROM agency_wallets WHERE agency_id = ? LIMIT 1', [$agencyId]);
        if ($exists === null) {
            db_insert(
                'INSERT INTO agency_wallets (agency_id, balance, withholding_reserve) VALUES (?, 0, 0)',
                [$agencyId]
            );
        }
    }

    /** @return array{balance:int, withholding_reserve:int, insurance_reserve:int} */
    public static function get(int $agencyId): array
    {
        self::ensure($agencyId);
        // insurance_reserve 컬럼이 아직 없을 수 있어(마이그레이션 전) 방어적으로 조회한다.
        $row = db_row('SELECT * FROM agency_wallets WHERE agency_id = ? LIMIT 1', [$agencyId]);

        return [
            'balance'             => (int) ($row['balance'] ?? 0),
            'withholding_reserve' => (int) ($row['withholding_reserve'] ?? 0),
            'insurance_reserve'   => (int) ($row['insurance_reserve'] ?? 0),
        ];
    }

    /**
     * 라이더 정산금 — 이 대리점 소속 라이더들에게 아직 지급해야 할 지갑 잔액 합계.
     */
    public static function riderDebt(int $agencyId): int
    {
        if ($agencyId < 1 || !db_table_exists('rider_wallets') || !db_table_exists('riders')) {
            return 0;
        }
        $row = db_row(
            'SELECT COALESCE(SUM(w.balance), 0) AS debt
               FROM rider_wallets w
               INNER JOIN riders r ON r.id = w.rider_id
              WHERE r.agency_id = ?',
            [$agencyId]
        );

        return (int) ($row['debt'] ?? 0);
    }

    /**
     * 대리점 자체 인출가능액 = balance − 라이더 정산금 − 원천세 예수금 (0 하한).
     *
     * ⚠️ 고용·산재(insurance_reserve)는 **빼지 않는다** — 대리점이 보유하는 돈이라 인출가능하다
     *    (2026-09-04 갑 정정). insurance_reserve 는 항상 0으로 유지되지만 호환을 위해 반환은 유지.
     *
     * @return array{balance:int, rider_debt:int, withholding_reserve:int, insurance_reserve:int, withdrawable:int}
     */
    public static function withdrawable(int $agencyId): array
    {
        $w        = self::get($agencyId);
        $debt     = self::riderDebt($agencyId);
        $reserve  = (int) $w['withholding_reserve'];
        $avail    = max(0, (int) $w['balance'] - $debt - $reserve);

        return [
            'balance'             => (int) $w['balance'],
            'rider_debt'          => $debt,
            'withholding_reserve' => $reserve,
            'insurance_reserve'   => (int) $w['insurance_reserve'],
            'withdrawable'        => $avail,
        ];
    }

    /**
     * 원천세 예수금 누적 (정산 반영 시 원천세 대상 라이더분).
     * balance 이동이 아니라 별도 예수금 accumulator라 원장(ledger)은 남기지 않음.
     */
    public static function addWithholdingReserve(int $agencyId, int $amount): void
    {
        if ($agencyId < 1 || $amount === 0 || !self::tableExists()) {
            return;
        }
        self::ensure($agencyId);
        db_execute(
            'UPDATE agency_wallets SET withholding_reserve = withholding_reserve + ?, updated_at = NOW() WHERE agency_id = ?',
            [$amount, $agencyId]
        );
    }

    /**
     * @deprecated 2026-09-04 — 고용·산재는 예수금이 아니라 대리점 보유금으로 정정됨(갑). 더 이상 호출하지 않는다.
     *             컬럼·메서드는 하위호환·마이그레이션 참조용으로만 남긴다.
     */
    public static function addInsuranceReserve(int $agencyId, int $amount): void
    {
        // no-op — 고용·산재는 대리점이 보유(예수금 아님). 잔재 호출을 무해화한다.
    }

    /**
     * 잔액 충전(credit) — PG 카드결제 성공 시 등. 원장 기록.
     */
    public static function credit(int $agencyId, int $amount, string $reason, ?int $refId = null, string $note = '', ?int $adminId = null): void
    {
        self::move($agencyId, 'credit', $amount, $reason, $refId, $note, $adminId);
    }

    /**
     * 잔액 차감(debit) — 라이더 지급·대리점 자체 인출 등. 원장 기록.
     */
    public static function debit(int $agencyId, int $amount, string $reason, ?int $refId = null, string $note = '', ?int $adminId = null): void
    {
        self::move($agencyId, 'debit', $amount, $reason, $refId, $note, $adminId);
    }

    /**
     * 잔액을 특정 값으로 직접 설정(수동 조정, 본사 전용). 차액을 원장에 기록.
     */
    public static function setBalance(int $agencyId, int $newBalance, string $note, ?int $adminId = null): void
    {
        if ($agencyId < 1 || !self::tableExists()) {
            return;
        }
        $cur   = self::get($agencyId)['balance'];
        $delta = $newBalance - $cur;
        if ($delta === 0) {
            return;
        }
        self::move($agencyId, $delta > 0 ? 'credit' : 'debit', abs($delta), 'manual_adjust', null, $note, $adminId);
    }

    private static function move(int $agencyId, string $direction, int $amount, string $reason, ?int $refId, string $note, ?int $adminId): void
    {
        if ($agencyId < 1 || $amount <= 0 || !self::tableExists()) {
            return;
        }
        self::ensure($agencyId);

        if ($direction === 'debit') {
            db_execute('UPDATE agency_wallets SET balance = balance - ?, updated_at = NOW() WHERE agency_id = ?', [$amount, $agencyId]);
        } else {
            db_execute('UPDATE agency_wallets SET balance = balance + ?, updated_at = NOW() WHERE agency_id = ?', [$amount, $agencyId]);
        }

        $balanceAfter = self::get($agencyId)['balance'];

        if (db_table_exists('agency_wallet_ledger')) {
            db_insert(
                'INSERT INTO agency_wallet_ledger
                    (agency_id, direction, reason, amount, balance_after, ref_id, note, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $agencyId,
                    $direction,
                    mb_substr($reason, 0, 40),
                    $amount,
                    $balanceAfter,
                    ($refId !== null && $refId > 0) ? $refId : null,
                    mb_substr($note, 0, 300),
                    ($adminId !== null && $adminId > 0) ? $adminId : null,
                ]
            );
        }
    }

    /**
     * 잔액 변동 이력.
     *
     * @return list<array<string,mixed>>
     */
    public static function ledger(int $agencyId, int $limit = 100): array
    {
        if ($agencyId < 1 || !db_table_exists('agency_wallet_ledger')) {
            return [];
        }
        $limit = max(1, min(500, $limit));

        return db_rows(
            'SELECT id, direction, reason, amount, balance_after, ref_id, note, created_at
               FROM agency_wallet_ledger
              WHERE agency_id = ?
              ORDER BY id DESC
              LIMIT ' . $limit,
            [$agencyId]
        );
    }

    public static function reasonLabel(string $reason): string
    {
        return self::REASON_LABELS[$reason] ?? ($reason !== '' ? $reason : '기타');
    }

    /**
     * 스코프 내 전체 조직(필터 드롭다운).
     *
     * 지갑 행(agency_wallets)은 돈이 처음 오갈 때 ensure()로 늦게 만들어진다. 그래서
     * INNER JOIN 하면 아직 거래가 없는 조직이 목록에서 통째로 빠져, 고르려던 총판이
     * 안 보일 때 "잔액 0원"인지 "누락"인지 구분할 수 없었다. LEFT JOIN 으로 전 조직을
     * 내보내고 지갑이 없으면 0원으로 표시한다.
     *
     * @return list<array{id:int,name:string,level:string,level_label:string,balance:int}>
     */
    public static function orgFilterOptions(): array
    {
        if (!self::tableExists()) {
            return [];
        }
        [$scopeSql, $scopeParams] = Org::orgScopeClause('o.id');
        $where = $scopeSql !== '' ? 'WHERE ' . $scopeSql : '';
        $rows = db_rows(
            "SELECT o.id, o.name, o.level, COALESCE(w.balance, 0) AS balance
               FROM organizations o
               LEFT JOIN agency_wallets w ON w.agency_id = o.id
              {$where}
              ORDER BY FIELD(o.level, 'admin', 'distributor', 'agency'), o.name ASC",
            $scopeParams
        );

        return array_map(static fn (array $r): array => [
            'id'           => (int) $r['id'],
            'name'         => (string) $r['name'],
            'level'        => (string) $r['level'],
            'level_label'  => Org::levelLabel((string) $r['level']),
            'balance'      => (int) $r['balance'],
        ], $rows);
    }

    /**
     * 스코프·필터 적용 원장 목록.
     *
     * @param array{from?:string,to?:string,org_id?:int,direction?:string,reason?:string,limit?:int} $filters
     * @return list<array<string,mixed>>
     */
    public static function listLedgerScoped(array $filters = []): array
    {
        if (!db_table_exists('agency_wallet_ledger')) {
            return [];
        }
        $limit = max(1, min(1000, (int) ($filters['limit'] ?? 500)));
        [$where, $params] = self::buildLedgerWhere($filters);

        $rows = db_rows(
            "SELECT l.id, l.agency_id, l.direction, l.reason, l.amount, l.balance_after,
                    l.ref_id, l.note, l.created_at, l.created_by,
                    o.name AS org_name, o.level AS org_level,
                    a.name AS actor_name
               FROM agency_wallet_ledger l
               INNER JOIN organizations o ON o.id = l.agency_id
               LEFT JOIN admins a ON a.id = l.created_by
              WHERE {$where}
              ORDER BY l.id DESC
              LIMIT {$limit}",
            $params
        );

        return array_map([self::class, 'mapLedgerRow'], $rows);
    }

    /**
     * @param array{from?:string,to?:string,org_id?:int,direction?:string,reason?:string} $filters
     * @return array{count:int, credit:int, debit:int}
     */
    public static function sumLedgerScoped(array $filters = []): array
    {
        $empty = ['count' => 0, 'credit' => 0, 'debit' => 0];
        if (!db_table_exists('agency_wallet_ledger')) {
            return $empty;
        }
        [$where, $params] = self::buildLedgerWhere($filters);
        $row = db_row(
            "SELECT COUNT(*) AS cnt,
                    COALESCE(SUM(CASE WHEN l.direction = 'credit' THEN l.amount ELSE 0 END), 0) AS credit,
                    COALESCE(SUM(CASE WHEN l.direction = 'debit' THEN l.amount ELSE 0 END), 0) AS debit
               FROM agency_wallet_ledger l
              WHERE {$where}",
            $params
        );

        return [
            'count'  => (int) ($row['cnt'] ?? 0),
            'credit' => (int) ($row['credit'] ?? 0),
            'debit'  => (int) ($row['debit'] ?? 0),
        ];
    }

    /**
     * @param array{from?:string,to?:string,org_id?:int,direction?:string,reason?:string} $filters
     * @return array{0:string,1:list<mixed>}
     */
    private static function buildLedgerWhere(array $filters): array
    {
        $where  = ['1=1'];
        $params = [];

        [$scopeSql, $scopeParams] = Org::orgScopeClause('l.agency_id');
        if ($scopeSql !== '') {
            $where[] = $scopeSql;
            $params  = array_merge($params, $scopeParams);
        }

        $orgId = (int) ($filters['org_id'] ?? 0);
        if ($orgId > 0) {
            if (!Org::canAccessOrg($orgId)) {
                $where[] = '1=0';
            } else {
                $where[]  = 'l.agency_id = ?';
                $params[] = $orgId;
            }
        }

        $from = trim((string) ($filters['from'] ?? ''));
        if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $where[]  = 'l.created_at >= ?';
            $params[] = $from . ' 00:00:00';
        }
        $to = trim((string) ($filters['to'] ?? ''));
        if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $where[]  = 'l.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
            $params[] = $to;
        }

        $dir = (string) ($filters['direction'] ?? '');
        if ($dir === 'credit' || $dir === 'debit') {
            $where[]  = 'l.direction = ?';
            $params[] = $dir;
        }

        $reason = trim((string) ($filters['reason'] ?? ''));
        if ($reason !== '' && isset(self::REASON_LABELS[$reason])) {
            $where[]  = 'l.reason = ?';
            $params[] = $reason;
        }

        return [implode(' AND ', $where), $params];
    }

    /** @param array<string, mixed> $row */
    private static function mapLedgerRow(array $row): array
    {
        $dir = (string) $row['direction'];
        $lvl = (string) ($row['org_level'] ?? '');

        return [
            'id'             => (int) $row['id'],
            'org_id'         => (int) $row['agency_id'],
            'org_name'       => (string) ($row['org_name'] ?? ''),
            'org_level'      => $lvl,
            'org_level_label'=> Org::levelLabel($lvl),
            'direction'      => $dir,
            'direction_label'=> $dir === 'credit' ? '입금' : '출금',
            'reason'         => (string) $row['reason'],
            'reason_label'   => self::reasonLabel((string) $row['reason']),
            'amount'         => (int) $row['amount'],
            'balance_after'  => (int) $row['balance_after'],
            'ref_id'         => isset($row['ref_id']) ? (int) $row['ref_id'] : 0,
            'note'           => (string) ($row['note'] ?? ''),
            'actor_name'     => (string) ($row['actor_name'] ?? ''),
            'created_at'     => substr((string) ($row['created_at'] ?? ''), 0, 16),
        ];
    }
}
