<?php

declare(strict_types=1);

/**
 * 라이더 미수금 원장 — 대여금(loan)·리스/렌탈(lease)·선지급(advance)
 *
 * PDF 정산명세서의 대여금/리스/선지급 차감 명세 대응. 잔액이 주 단위로 이월되며
 * 일납(daily_amount) × 차감일수(days)만큼 상환/부과된다.
 *
 * 정산 반영 연동: 차감 실행(applyRepayment) 시 deduction_entries 행을 만들어
 * 기존 SettlementLedger::buildFeeItems 흐름이 그대로 차감하게 한다(중복 로직 없음).
 *
 * 참고: LOGIC.md §5.5, DB_SCHEMA.md
 */
final class RiderDebt
{
    /** 종류 표시명 */
    public const KINDS = [
        'loan'    => '대여금',
        'lease'   => '리스/렌탈',
        'advance' => '선지급금',
    ];

    /** 종류 → deduction_entries.kind (정산 반영이 소비하는 코드) */
    private const DEDUCTION_KIND = [
        'loan'    => 'loan',
        'lease'   => 'lease',
        'advance' => 'advance',
    ];

    /**
     * 잔액이 상각(감소)되는 종류 — **2026-09-04 부터 리스 포함**(갑 확정).
     *
     * 예전엔 리스를 "반복 부과"로 보고 잔액을 두지 않았다. 그 결과 리스 계약의
     * 잔여 리스료(실측 15,030,000원)가 미수금 집계 어디에도 안 잡혔다.
     * 리스도 `일납 × 계약일수` 로 총액이 정해지므로 대여금과 같은 상각 모델로 통일한다.
     *
     * ⚠️ 리스료(일납)를 바꾸면 총액·잔액을 다시 계산해야 한다 — update() 가 자동으로 한다.
     */
    private const AMORTIZING = ['loan', 'advance', 'lease'];

    /**
     * 리스 제공 주체 → 걷은 리스료를 나눠 갖는 조직들(2026-08-08 갑 확정).
     * 제공 주체 자신과 그 아래 계층만 배분에 참여한다.
     *   본사 제공   → 본사·총판·대리점 3자
     *   총판 제공   → 총판·대리점 2자
     *   대리점 제공 → 대리점 단독
     * 배분액은 **일 단위 정액(원)**이며 계약 건마다 직접 입력한다(요율 아님).
     */
    public const LEASE_PROVIDERS = [
        'hq'          => '본사',
        'distributor' => '총판',
        'agency'      => '대리점',
    ];

    /** 제공 주체별로 금액을 넣을 수 있는 배분 필드 */
    private const PROVIDER_FEE_FIELDS = [
        'hq'          => ['fee_hq', 'fee_distributor', 'fee_agency'],
        'distributor' => ['fee_distributor', 'fee_agency'],
        'agency'      => ['fee_agency'],
    ];

    public static function providerLabel(?string $p): string
    {
        return self::LEASE_PROVIDERS[(string) $p] ?? '—';
    }

    /**
     * 라이더 소속 대리점 기준 조직 체인 — 리스 수수료를 실제로 받을 조직들.
     * 대리점 위에 총판이 없는(본사 직속) 구조도 있으므로 각 레벨의 존재 여부를 함께 알려준다.
     *
     * @return array{agency:int, distributor:int, hq:int}
     */
    public static function orgChainForRider(int $riderId): array
    {
        require_once __DIR__ . '/Org.php';
        $out = ['agency' => 0, 'distributor' => 0, 'hq' => 0];
        if ($riderId < 1) {
            return $out;
        }
        $agencyId = (int) (db_row('SELECT agency_id FROM riders WHERE id = ? LIMIT 1', [$riderId])['agency_id'] ?? 0);

        return $agencyId > 0 ? Org::chainForAgency($agencyId) : $out;
    }

    /**
     * 리스 배분 금액 정규화 — 제공 주체가 가질 수 없는 몫은 0으로 떨어뜨리고,
     * 합계가 일납을 넘으면 거부한다(걷는 돈보다 많이 나눠 가질 수 없다).
     *
     * $chain 이 주어지면 **받을 조직이 실제로 존재하는지**도 확인한다
     * (예: 본사 직속 대리점이라 총판이 없는데 총판 몫을 넣으면 그 돈은 갈 곳이 없다).
     *
     * @param array<string,mixed> $in
     * @param array{agency:int, distributor:int, hq:int}|null $chain
     * @return array{lease_provider:?string, fee_hq:int, fee_distributor:int, fee_agency:int}
     */
    private static function normalizeLeaseFees(array $in, int $dailyAmount, ?array $chain = null): array
    {
        $provider = trim((string) ($in['lease_provider'] ?? ''));
        if ($provider === '' || !isset(self::LEASE_PROVIDERS[$provider])) {
            throw new InvalidArgumentException('리스 제공 주체(본사/총판/대리점)를 선택하세요.');
        }

        $allowed = self::PROVIDER_FEE_FIELDS[$provider];
        $out     = ['lease_provider' => $provider, 'fee_hq' => 0, 'fee_distributor' => 0, 'fee_agency' => 0];
        $sum     = 0;
        foreach (['fee_hq', 'fee_distributor', 'fee_agency'] as $f) {
            if (!in_array($f, $allowed, true)) {
                continue; // 제공 주체보다 상위 조직은 배분 대상이 아니다 → 0 유지
            }
            $v = max(0, (int) ($in[$f] ?? 0));
            $out[$f] = $v;
            $sum += $v;
        }

        if ($sum > $dailyAmount) {
            throw new InvalidArgumentException(sprintf(
                '수수료 배분 합계(%s원)가 일납 리스료(%s원)보다 큽니다.',
                number_format($sum),
                number_format($dailyAmount)
            ));
        }

        // 받을 조직이 없는 몫은 갈 곳이 없다 — 지갑 이동 단계에서 돈이 증발하므로 미리 막는다.
        if ($chain !== null) {
            if ($out['fee_hq'] > 0 && $chain['hq'] < 1) {
                throw new InvalidArgumentException('본사 조직을 찾을 수 없어 본사 몫을 배분할 수 없습니다.');
            }
            if ($out['fee_distributor'] > 0 && $chain['distributor'] < 1) {
                throw new InvalidArgumentException('이 라이더의 대리점은 총판 소속이 아니라 총판 몫을 배분할 수 없습니다. (본사 직속)');
            }
            if ($out['fee_agency'] > 0 && $chain['agency'] < 1) {
                throw new InvalidArgumentException('라이더의 소속 대리점을 찾을 수 없습니다.');
            }
        }

        return $out;
    }

    /**
     * 리스 수수료 상위 배분 실행 — **대리점 지갑에서 빼서 본사·총판 지갑으로 옮긴다.**
     *
     * 왜 대리점에서 빼는가: 리스료를 라이더 정산에서 차감하면 그만큼 라이더에게 덜 나가므로
     * 그 돈은 **자동으로 대리점 지갑에 남는다**(대리점 인출가능액 = 잔액 − 라이더 정산금 − 예수금).
     * 따라서 대리점 몫은 이동이 필요 없고, 상위 조직 몫만 실제로 올려보내면 된다.
     *
     * @param array{fee_hq:int, fee_distributor:int, fee_agency:int} $split
     * @param array{agency:int, distributor:int, hq:int} $chain
     * @param int $sign  1=배분 실행, -1=차감 취소 시 되돌리기
     */
    private static function moveLeaseFees(array $split, array $chain, int $sign, int $entryId, string $note): void
    {
        require_once __DIR__ . '/AgencyWallet.php';

        $hq   = (int) $split['fee_hq'];
        $dist = (int) $split['fee_distributor'];
        $up   = $hq + $dist;
        if ($up <= 0 || $chain['agency'] < 1) {
            return;
        }

        // 대리점 ← 상위 몫 회수(취소 시엔 반대로 되돌려줌)
        if ($sign > 0) {
            AgencyWallet::debit($chain['agency'], $up, 'lease_fee_up', $entryId, $note);
        } else {
            AgencyWallet::credit($chain['agency'], $up, 'lease_fee_up_rev', $entryId, $note . ' 취소');
        }

        foreach ([['hq', $hq], ['distributor', $dist]] as [$key, $amt]) {
            if ($amt <= 0 || $chain[$key] < 1) {
                continue;
            }
            if ($sign > 0) {
                AgencyWallet::credit($chain[$key], $amt, 'lease_fee_in', $entryId, $note);
            } else {
                AgencyWallet::debit($chain[$key], $amt, 'lease_fee_in_rev', $entryId, $note . ' 취소');
            }
        }
    }

    /**
     * 리스 차감 공백 경고 기준일수. 이 시스템엔 매일 도는 배치가 없어 리스 차감은
     * 정산 반영(엑셀 업로드) 시점에만 일어난다 — 그 사이 업로드가 뜸하면 계약기간은
     * 흘러가는데 차감은 안 되는 공백이 생길 수 있어, 최근 차감일이 이 일수 이상
     * 뒤처지면 "지연" 으로 표시한다. admin_debt_list.php 의 SQL 배너 카운트와 반드시
     * 같은 값을 써야 하므로 상수를 참조해 쓴다(§LOGIC.md 2026-08-08).
     */
    public const GAP_WARNING_DAYS = 7;

    public static function tableReady(): bool
    {
        return db_table_exists('rider_debts') && db_table_exists('rider_debt_entries');
    }

    public static function kindLabel(string $kind): string
    {
        return self::KINDS[$kind] ?? $kind;
    }

    /**
     * 라이더의 미수금 목록 (라벨·이력수 포함)
     *
     * @return list<array<string,mixed>>
     */
    public static function forRider(int $riderId, bool $activeOnly = false): array
    {
        if (!self::tableReady() || $riderId <= 0) {
            return [];
        }
        $where  = ['d.rider_id = ?'];
        $params = [$riderId];
        if ($activeOnly) {
            $where[] = "d.status = 'active'";
        }
        $rows = db_rows(
            'SELECT d.*, (SELECT COUNT(*) FROM rider_debt_entries e WHERE e.debt_id = d.id) AS entry_count
               FROM rider_debts d
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY (d.status = \'active\') DESC, d.id DESC',
            $params
        );
        foreach ($rows as &$r) {
            $r['kind_label'] = self::kindLabel((string) $r['kind']);
        }
        unset($r);

        return $rows;
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        if (!self::tableReady() || $id <= 0) {
            return null;
        }

        return db_row('SELECT * FROM rider_debts WHERE id = ?', [$id]);
    }

    /** @return list<array<string,mixed>> */
    public static function entries(int $debtId): array
    {
        if (!self::tableReady() || $debtId <= 0) {
            return [];
        }

        return db_rows(
            'SELECT * FROM rider_debt_entries WHERE debt_id = ? ORDER BY applied_date DESC, id DESC',
            [$debtId]
        );
    }

    /**
     * 미수금 신규 등록.
     *
     * @param array<string,mixed> $in
     */
    public static function create(array $in): int
    {
        $riderId = (int) ($in['rider_id'] ?? 0);
        $kind    = (string) ($in['kind'] ?? '');
        if ($riderId <= 0) {
            throw new InvalidArgumentException('라이더가 지정되지 않았습니다.');
        }
        if (!isset(self::KINDS[$kind])) {
            throw new InvalidArgumentException('미수금 종류가 올바르지 않습니다.');
        }
        $principal = max(0, (int) ($in['principal_amount'] ?? 0));
        $daily     = max(0, (int) ($in['daily_amount'] ?? 0));

        $openedOn   = self::normDate($in['opened_on'] ?? null);
        // 리스 종료예정일은 **입력받지 않고 계산한다** — 총 금액 ÷ 일납 = 걸리는 일수(2026-09-18 갑).
        $plannedEnd = null;

        // 선지급금(가불)은 **다음 정산에서 전액 회수**라 일납·계약기간 개념이 없다(2026-09-18 갑).
        // 입력도 금액·메모뿐이므로 개시일은 등록일로 채운다(자동 부과가 개시일을 기준으로 삼는다).
        if ($kind === 'advance') {
            $daily      = 0;
            $openedOn ??= date('Y-m-d');
        }

        // ── 기존 계약 이관 — 잔액 기준 (2026-09-18 갑) ─────────────────────────────
        // 이관 표시가 없으면 개시일부터 오늘까지 전부 미차감으로 보고 **소급 부과**된다
        // (실측: 180일 전 개시 리스가 첫 정산에서 4,887,000원). 이관 건은 남은 잔액과
        // 「이 날짜까지 정산 완료」를 받아 그다음 날부터만 부과한다.
        //
        // ⚠️ 이관이면 **잔액이 유일한 기준**이다(2026-09-18 재정비). 원 계약 총액을 같이 받으면
        //    둘이 어긋날 때 무엇이 맞는지 알 수 없다 — 예전엔 총액을 조용히 잔액으로 덮어써서
        //    입력값이 사라졌고, «잔액 > 총액» 같은 모순도 그대로 통과했다. 이제 총액은 받지 않는다.
        // 선지급금은 입력한 금액이 곧 남은 잔액이라 이관 입력이 의미가 없다 — 들어와도 무시한다.
        $isMigrated = !empty($in['is_migrated']) && $kind !== 'advance';
        $dueUpdated = null;
        if ($isMigrated) {
            $balance    = max(0, (int) ($in['migrate_balance'] ?? 0));
            $dueUpdated = self::normDate($in['migrate_as_of'] ?? null);
            if ($balance <= 0) {
                throw new InvalidArgumentException('이관할 남은 잔액을 입력하세요.');
            }
            if ($dueUpdated === null) {
                throw new InvalidArgumentException('「이 날짜까지 정산 완료」를 입력하세요. 그다음 날부터 차감이 시작됩니다.');
            }
            // 미래 날짜를 넣으면 그날까지 차감이 멈춘 채 조용히 지나간다 — 사람이 알아채기 어렵다.
            if ($dueUpdated > date('Y-m-d')) {
                throw new InvalidArgumentException('정산 완료일은 오늘보다 뒤일 수 없습니다. (그날까지 차감이 멈춥니다)');
            }
            // 개시일을 모르면 기준일로 채운다 — 옮겨오는 계약이라 원 개시일이 없을 수 있다.
            $openedOn ??= $dueUpdated;
            if ($dueUpdated < $openedOn) {
                throw new InvalidArgumentException('정산 완료일은 개시일보다 앞설 수 없습니다.');
            }
            // 이관 건의 «총액» 은 이관 시작 잔액이다 — 진행률이 이관 이후 기준으로 보인다.
            $principal = $balance;
        } else {
            $balance = in_array($kind, self::AMORTIZING, true) ? $principal : 0;
        }

        // ── 차감이 영영 안 되는 계약을 막는다 (2026-09-18) ──────────────────────────
        // 예전엔 일납 0·개시일 없음·금액 0 이 그대로 저장돼, 자동 부과도 수동 차감도
        // 안 되는 «죽은 계약» 이 조용히 만들어졌다(목록에서 구분도 안 됐다).
        if ($openedOn === null) {
            throw new InvalidArgumentException('개시일을 입력하세요.');
        }
        if ($kind !== 'advance' && $daily <= 0) {
            throw new InvalidArgumentException('일납금액을 입력하세요. (0이면 차감이 되지 않습니다)');
        }
        if ($principal <= 0) {
            throw new InvalidArgumentException(match ($kind) {
                'advance' => '선지급 금액을 입력하세요.',
                'lease'   => '리스/렌탈 총 금액을 입력하세요. (종료일은 총액 ÷ 일납으로 계산됩니다)',
                default   => '원금을 입력하세요.',
            });
        }

        // 리스 종료예정일 = 부과 시작일부터 «총액 ÷ 일납» 일수만큼. 이관이면 기준일 다음날부터 센다.
        if ($kind === 'lease') {
            $plannedEnd = self::leaseEndDate(
                $principal,
                $daily,
                $isMigrated ? self::addDays((string) $dueUpdated, 1) : $openedOn
            );
        }

        // 리스 전용 — 제공 주체·배분액·차대번호. 대여금/선지급은 해당 없음.
        $lease = $kind === 'lease'
            ? self::normalizeLeaseFees($in, $daily, self::orgChainForRider($riderId))
            : ['lease_provider' => null, 'fee_hq' => 0, 'fee_distributor' => 0, 'fee_agency' => 0];
        $vin = $kind === 'lease' ? mb_substr(trim((string) ($in['vin'] ?? '')), 0, 30) : '';

        return db_insert(
            'INSERT INTO rider_debts
                (rider_id, kind, title, principal_amount, balance_amount, daily_amount,
                 creditor, status, opened_on, planned_end_on, due_updated_on, is_migrated, note,
                 lease_provider, vin, fee_hq, fee_distributor, fee_agency)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $riderId,
                $kind,
                trim((string) ($in['title'] ?? '')),
                $principal,
                $balance,
                $daily,
                trim((string) ($in['creditor'] ?? '')),
                'active',
                $openedOn,
                $plannedEnd,
                $dueUpdated,
                $isMigrated ? 1 : 0,
                trim((string) ($in['note'] ?? '')),
                $lease['lease_provider'],
                $vin,
                $lease['fee_hq'],
                $lease['fee_distributor'],
                $lease['fee_agency'],
            ]
        );
    }

    /**
     * 미수금 기본정보 수정(제목·일납·채권자·상태·메모, 원금/잔액 보정).
     *
     * @param array<string,mixed> $in
     */
    public static function update(int $id, array $in): void
    {
        $debt = self::find($id);
        if ($debt === null) {
            throw new InvalidArgumentException('미수금을 찾을 수 없습니다.');
        }
        $sets   = [];
        $params = [];
        if (array_key_exists('title', $in))    { $sets[] = 'title = ?';    $params[] = trim((string) $in['title']); }
        if (array_key_exists('daily_amount', $in)) {
            $newDailyIn = max(0, (int) $in['daily_amount']);
            // 등록에서 막은 «죽은 계약»을 수정으로 만들 수 있으면 의미가 없다 — 여기서도 0 을 막는다.
            if ($newDailyIn <= 0 && (string) $debt['kind'] !== 'advance') {
                throw new InvalidArgumentException('일납금액은 0보다 커야 합니다. (0이면 자동 차감이 멈춥니다)');
            }
            $sets[]   = 'daily_amount = ?';
            $params[] = $newDailyIn;
        }
        if (array_key_exists('creditor', $in)) { $sets[] = 'creditor = ?'; $params[] = trim((string) $in['creditor']); }
        if (array_key_exists('note', $in))     { $sets[] = 'note = ?';     $params[] = trim((string) $in['note']); }
        if (array_key_exists('opened_on', $in)){ $sets[] = 'opened_on = ?';$params[] = self::normDate($in['opened_on']); }
        // 리스 제공주체·배분액 — 셋 중 하나라도 오면 함께 재검증한다(합계 ≤ 일납).
        $leaseKeys = ['lease_provider', 'fee_hq', 'fee_distributor', 'fee_agency'];
        if ((string) $debt['kind'] === 'lease' && array_intersect($leaseKeys, array_keys($in)) !== []) {
            $daily = array_key_exists('daily_amount', $in)
                ? max(0, (int) $in['daily_amount'])
                : (int) $debt['daily_amount'];
            $merged = [
                'lease_provider'  => $in['lease_provider']  ?? $debt['lease_provider'],
                'fee_hq'          => $in['fee_hq']          ?? $debt['fee_hq'],
                'fee_distributor' => $in['fee_distributor'] ?? $debt['fee_distributor'],
                'fee_agency'      => $in['fee_agency']      ?? $debt['fee_agency'],
            ];
            $lease = self::normalizeLeaseFees($merged, $daily, self::orgChainForRider((int) $debt['rider_id']));
            foreach (['lease_provider', 'fee_hq', 'fee_distributor', 'fee_agency'] as $f) {
                $sets[]   = "{$f} = ?";
                $params[] = $lease[$f];
            }
        }
        if (array_key_exists('vin', $in) && (string) $debt['kind'] === 'lease') {
            $sets[]   = 'vin = ?';
            $params[] = mb_substr(trim((string) $in['vin']), 0, 30);
        }
        // 종료예정일은 자동 계산이라 직접 받지 않는다(아래에서 잔액 ÷ 일납으로 다시 잡는다).
        if (array_key_exists('status', $in)) {
            $status = (string) $in['status'];
            if (!in_array($status, ['active', 'paused', 'closed'], true)) {
                throw new InvalidArgumentException('상태값이 올바르지 않습니다.');
            }
            $sets[]   = 'status = ?';
            $params[] = $status;
            $sets[]   = 'closed_on = ?';
            $params[] = $status === 'closed' ? date('Y-m-d') : null;
        }
        // 리스 계약이 바뀌면 **종료예정일을 다시 계산한다** — 남은 잔액 ÷ 일납 (2026-09-18 갑).
        // 예전엔 반대로 «종료일 → 총액»을 계산했는데, 갑이 총 금액을 기준으로 잡기로 해서 방향이 뒤집혔다.
        // 총 금액을 고치면 아직 안 걷힌 만큼을 잔액에 반영하고, 그 잔액으로 종료일을 다시 잡는다.
        if ((string) $debt['kind'] === 'lease') {
            $newDaily = array_key_exists('daily_amount', $in) ? max(0, (int) $in['daily_amount']) : (int) $debt['daily_amount'];
            $newBal   = (int) $debt['balance_amount'];
            if (array_key_exists('principal_amount', $in)) {
                $collected = (int) (db_row(
                    'SELECT COALESCE(SUM(amount), 0) AS s FROM rider_debt_entries WHERE debt_id = ?',
                    [$id]
                )['s'] ?? 0);
                // 이관 건은 «이관 시작 잔액» 기준이라 그 뒤 걷은 것만 뺀다(이관 전 상환분은 이미 빠져 있다).
                $newBal   = max(0, (int) $in['principal_amount'] - $collected);
                $sets[]   = 'balance_amount = ?';
                $params[] = $newBal;
            }
            if (array_key_exists('balance_amount', $in)) {
                $newBal = max(0, (int) $in['balance_amount']);
            }
            // 부과가 시작되는 날 = 마지막 차감일 다음날(없으면 개시일)
            $lastCovered = self::normDate($debt['due_updated_on'] ?? null);
            $newOpen     = array_key_exists('opened_on', $in) ? self::normDate($in['opened_on']) : self::normDate($debt['opened_on'] ?? null);
            $from        = $lastCovered !== null ? self::addDays($lastCovered, 1) : $newOpen;
            $newEnd      = self::leaseEndDate($newBal, $newDaily, $from);
            if ($newEnd !== null) {
                $sets[]   = 'planned_end_on = ?';
                $params[] = $newEnd;
            }
        }

        // 잔액 수동 보정(상각형만)
        if (array_key_exists('balance_amount', $in) && in_array((string) $debt['kind'], self::AMORTIZING, true)) {
            $sets[]   = 'balance_amount = ?';
            $params[] = max(0, (int) $in['balance_amount']);
        }
        if (array_key_exists('principal_amount', $in)) {
            $sets[]   = 'principal_amount = ?';
            $params[] = max(0, (int) $in['principal_amount']);
        }
        if ($sets === []) {
            return;
        }
        $params[] = $id;
        db_execute('UPDATE rider_debts SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
    }

    /**
     * 차감(상환/부과) 실행 — 트랜잭션.
     *  1) 금액 = amount ?? (일납 × 일수). 상각형은 잔액 상한.
     *  2) deduction_entries 생성(정산 반영이 이 값을 차감).
     *  3) rider_debt_entries 이력 + 잔액 갱신 + 미납갱신일.
     *  4) 잔액 0 이면 완납 처리(closed).
     *
     * @return array{amount:int, balance_after:int, entry_id:int, deduction_entry_id:int}
     */
    public static function applyRepayment(int $debtId, string $appliedDate, int $days, ?int $amount, string $memo = '', ?string $coveredThrough = null): array
    {
        $debt = self::find($debtId);
        if ($debt === null) {
            throw new InvalidArgumentException('미수금을 찾을 수 없습니다.');
        }
        if (($debt['status'] ?? '') !== 'active') {
            throw new InvalidArgumentException('활성 상태의 미수금만 차감할 수 있습니다.');
        }
        $d = DateTime::createFromFormat('Y-m-d', $appliedDate);
        if (!$d || $d->format('Y-m-d') !== $appliedDate) {
            throw new InvalidArgumentException('차감 귀속일 형식이 올바르지 않습니다. (YYYY-MM-DD)');
        }
        if ($days < 0) {
            throw new InvalidArgumentException('차감일수가 올바르지 않습니다.');
        }

        $kind        = (string) $debt['kind'];
        $isAmortizing = in_array($kind, self::AMORTIZING, true);
        $balance     = (int) $debt['balance_amount'];

        // 금액 결정: 명시값 우선, 없으면 일납 × 일수
        $charge = $amount !== null ? (int) $amount : ((int) $debt['daily_amount'] * $days);
        if ($charge <= 0) {
            throw new InvalidArgumentException('차감액이 0보다 커야 합니다. (일납·일수 또는 금액을 확인)');
        }
        if ($isAmortizing) {
            if ($balance <= 0) {
                throw new InvalidArgumentException('남은 잔액이 없습니다.');
            }
            $charge = min($charge, $balance); // 잔액 초과 차감 금지
        }
        $balanceAfter = $isAmortizing ? ($balance - $charge) : $balance;

        $dedKind = self::DEDUCTION_KIND[$kind] ?? 'manual';
        $note    = trim(($debt['title'] !== '' ? $debt['title'] : self::kindLabel($kind)) . ($memo !== '' ? ' · ' . $memo : ''));

        // 리스 수수료 배분 스냅샷 — 설정은 "일 단위 정액"이므로 실제 차감일수를 곱한다.
        // 이력에 그대로 박아둬야 나중에 설정이 바뀌어도 과거 정산 근거가 보존된다.
        // 부분 차감(금액 직접 입력 등)으로 일납×일수보다 적게 걷혔으면 그 비율만큼 줄여
        // "걷은 돈보다 많이 나눠 갖는" 상황을 막는다.
        $split = ['fee_hq' => 0, 'fee_distributor' => 0, 'fee_agency' => 0];
        if ($kind === 'lease') {
            $expected = (int) $debt['daily_amount'] * $days;
            $ratio    = ($expected > 0 && $charge < $expected) ? ($charge / $expected) : 1.0;
            foreach ($split as $f => $_) {
                $split[$f] = (int) floor((int) ($debt[$f] ?? 0) * $days * $ratio);
            }
            $sum = array_sum($split);
            if ($sum > $charge) {
                // 반올림으로 넘치면 가장 큰 몫에서 깎아 총액을 맞춘다.
                arsort($split);
                $top = array_key_first($split);
                $split[$top] -= ($sum - $charge);
            }
        }

        // 차감 귀속일(applied_date)과 **부과가 커버한 마지막 날**은 다를 수 있다.
        // 부분 부과(여유분이 모자라 일부 일수만 걷을 때) 시 귀속일은 반드시 **정산일**이어야
        // buildFeeItems(applied_date = settlement_date 로 조회)가 그 차감을 실제로 소비한다.
        // 반면 due_updated_on 은 커버한 날까지만 밀려야 나머지가 다음 정산에서 다시 잡힌다.
        $covered = self::normDate($coveredThrough) ?? $appliedDate;

        $chain = $kind === 'lease' ? self::orgChainForRider((int) $debt['rider_id']) : ['agency' => 0, 'distributor' => 0, 'hq' => 0];

        return db_transaction(static function () use (
            $debtId, $debt, $appliedDate, $covered, $days, $charge, $balanceAfter, $isAmortizing, $dedKind, $note, $memo, $split, $chain
        ): array {
            // 1) 정산 반영이 소비할 deduction_entries
            $dedId = db_insert(
                'INSERT INTO deduction_entries (rider_id, applied_date, kind, amount, note) VALUES (?, ?, ?, ?, ?)',
                [(int) $debt['rider_id'], $appliedDate, $dedKind, $charge, mb_substr($note, 0, 255)]
            );
            // 2) 이력
            $entryId = db_insert(
                'INSERT INTO rider_debt_entries
                    (debt_id, rider_id, applied_date, days, amount, balance_after, deduction_entry_id, memo,
                     fee_hq, fee_distributor, fee_agency)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $debtId, (int) $debt['rider_id'], $appliedDate, $days, $charge, $balanceAfter, $dedId,
                    mb_substr($memo, 0, 255),
                    $split['fee_hq'], $split['fee_distributor'], $split['fee_agency'],
                ]
            );
            // 3) 잔액·미납갱신일 갱신
            if ($isAmortizing) {
                $newStatus = $balanceAfter <= 0 ? 'closed' : (string) $debt['status'];
                db_execute(
                    'UPDATE rider_debts SET balance_amount = ?, due_updated_on = ?, status = ?, closed_on = ? WHERE id = ?',
                    [$balanceAfter, $covered, $newStatus, $newStatus === 'closed' ? $appliedDate : $debt['closed_on'], $debtId]
                );
            } else {
                db_execute('UPDATE rider_debts SET due_updated_on = ? WHERE id = ?', [$covered, $debtId]);
            }

            // 4) 리스 수수료 상위 배분 — 대리점 지갑 → 본사·총판 지갑(같은 트랜잭션)
            self::moveLeaseFees($split, $chain, 1, $entryId, '리스 수수료 배분 · ' . $note);

            return [
                'amount'             => $charge,
                'balance_after'      => $balanceAfter,
                'entry_id'           => $entryId,
                'deduction_entry_id' => $dedId,
            ];
        });
    }

    /**
     * 차감 이력 취소 — 트랜잭션. deduction_entries 제거 + 잔액 복구 + 완납 해제.
     */
    public static function reverseEntry(int $entryId): void
    {
        $entry = db_row('SELECT * FROM rider_debt_entries WHERE id = ?', [$entryId]);
        if ($entry === null) {
            throw new InvalidArgumentException('차감 이력을 찾을 수 없습니다.');
        }
        $debt = self::find((int) $entry['debt_id']);
        if ($debt === null) {
            throw new InvalidArgumentException('미수금을 찾을 수 없습니다.');
        }
        $isAmortizing = in_array((string) $debt['kind'], self::AMORTIZING, true);

        // 이 차감 건이 실제로 옮긴 배분액(스냅샷)을 그대로 되돌린다.
        // 설정이 그 사이 바뀌었어도 "그때 옮긴 금액"으로 복구해야 지갑이 어긋나지 않는다.
        $split = [
            'fee_hq'          => (int) ($entry['fee_hq'] ?? 0),
            'fee_distributor' => (int) ($entry['fee_distributor'] ?? 0),
            'fee_agency'      => (int) ($entry['fee_agency'] ?? 0),
        ];
        $chain = (string) $debt['kind'] === 'lease'
            ? self::orgChainForRider((int) $debt['rider_id'])
            : ['agency' => 0, 'distributor' => 0, 'hq' => 0];

        db_transaction(static function () use ($entry, $debt, $isAmortizing, $split, $chain): void {
            // 연결된 deduction_entries 제거(정산 반영 전이라면 실제 차감도 취소됨)
            if (!empty($entry['deduction_entry_id'])) {
                db_execute('DELETE FROM deduction_entries WHERE id = ?', [(int) $entry['deduction_entry_id']]);
            }
            // 상위로 올려보낸 리스 수수료를 대리점에 되돌려준다.
            self::moveLeaseFees($split, $chain, -1, (int) $entry['id'], '리스 수수료 배분');
            db_execute('DELETE FROM rider_debt_entries WHERE id = ?', [(int) $entry['id']]);
            if ($isAmortizing) {
                // 잔액 복구 + 완납이었다면 active 로 되돌림
                db_execute(
                    'UPDATE rider_debts SET balance_amount = balance_amount + ?,
                            status = CASE WHEN status = \'closed\' THEN \'active\' ELSE status END,
                            closed_on = CASE WHEN status = \'closed\' THEN NULL ELSE closed_on END
                      WHERE id = ?',
                    [(int) $entry['amount'], (int) $debt['id']]
                );
            }
        });
    }

    /**
     * 리스/렌탈 자동 일수계산(§7 격차 — parser.py 확인 결과, 실제 운영은 계약기간
     * (opened_on~planned_end_on)과 정산기간이 겹치는 일수만큼 자동 차감한다).
     *
     * 트리거: 정산 반영(SettlementLedger::applyUpload)이 업로드의 정산기간(min~max
     * settlement_date)을 구해 매칭된 라이더의 활성 리스마다 1회 호출한다.
     *
     * 재실행 멱등성: applyRepayment()가 (debt_id, applied_date) UNIQUE에 걸리면
     * "이미 이 귀속일로 처리됨"으로 보고 조용히 null을 반환한다(같은 업로드를
     * 재반영해도 이중 차감되지 않음 — parser.py의 "처리키" 방지와 같은 목적).
     *
     * @return array{amount:int, balance_after:int, entry_id:int, deduction_entry_id:int}|null
     */
    public static function applyLeaseForPeriod(int $debtId, string $periodStart, string $periodEnd): ?array
    {
        return self::applyDailyAccrualForPeriod($debtId, $periodEnd);
    }

    /**
     * 일납 자동 부과 — **대여금·리스·선지급금 공통**(2026-09-04 갑 확정).
     *
     * 갑 원문: *"일하지 않는 날에도 차감이 생겨야해. 대여금 선지급금도 자동으로 되어야해"*
     *
     * ⚠️ **근무일이 아니라 달력일 기준**이다. 그래서 정산 반영이 뜸했던 구간(업로드가
     * 없어서 건너뛴 날들)도 이번 호출에서 **한꺼번에 메운다**. 이전 구현
     * (applyLeaseForPeriod)은 업로드의 정산기간(min~max)과 계약기간이 겹치는 날만
     * 셌기 때문에, 라이더가 쉬어서 파일에 안 나온 날은 영영 차감되지 않았다.
     *
     * 부과 구간 = (마지막 반영일+1) ~ min(정산기간 끝, 종료예정일)
     *   - 마지막 반영일: `due_updated_on`, 없으면 `opened_on - 1일`(= 개시일부터 전부)
     *   - `planned_end_on` 이 없으면(대여금·선지급금) 종료 상한 없이 정산기간 끝까지.
     *     대신 상각형이라 **잔액이 바닥나면 자동 완납(closed)** 되어 더 부과되지 않는다.
     *
     * 멱등성: 귀속일이 같으면 (debt_id, applied_date) UNIQUE 에 걸려 조용히 null 을
     * 반환한다(같은 업로드 재반영 시 이중 차감 없음).
     *
     * @return array{amount:int, balance_after:int, entry_id:int, deduction_entry_id:int}|null
     */
    public static function applyDailyAccrualForPeriod(int $debtId, string $periodEnd, ?int $headroom = null): ?array
    {
        $debt = self::find($debtId);
        if ($debt === null || (string) $debt['status'] !== 'active') {
            return null;
        }

        $daily  = (int) $debt['daily_amount'];
        $opened = self::normDate($debt['opened_on'] ?? null);

        $pe = self::normDate($periodEnd);
        if ($pe === null) {
            throw new InvalidArgumentException('정산기간 형식이 올바르지 않습니다. (YYYY-MM-DD)');
        }

        // 선지급금(가불)은 일수로 나누지 않고 **다음 정산에서 잔액 전액**을 건다(2026-09-18 갑).
        // 그날 실지급이 모자라면 걷을 수 있는 만큼만 걷고, 남은 잔액은 다음 정산에서 다시 전액 시도한다.
        //
        // 단 **일납이 들어 있는 옛 선지급금은 그대로 분할 회수**한다 — 라이더와 분할로 합의해 둔
        // 건을 규칙이 바뀌었다고 갑자기 전액 회수하면 그 달 실지급이 통째로 사라진다.
        // 새로 등록되는 선지급금은 일납 칸 자체가 없어 항상 전액 회수다.
        if ((string) $debt['kind'] === 'advance' && $daily <= 0) {
            return self::chargeAdvance($debt, $pe, $headroom);
        }

        // 일납이 없거나 개시일이 없으면 자동계산 불가 — 수동 차감(applyRepayment)으로 처리한다.
        if ($daily <= 0 || $opened === null) {
            return null;
        }

        // 상각형(대여금·선지급금)은 잔액이 남아 있어야 부과한다.
        $isAmortizing = in_array((string) $debt['kind'], self::AMORTIZING, true);
        $balance      = (int) $debt['balance_amount'];
        if ($isAmortizing && $balance <= 0) {
            return null;
        }

        // 부과 시작 = 마지막 반영 다음날(없으면 개시일)
        $lastCovered = self::normDate($debt['due_updated_on'] ?? null);
        $chargeStart = $lastCovered !== null ? self::addDays($lastCovered, 1) : $opened;
        if ($chargeStart < $opened) {
            $chargeStart = $opened;
        }

        // 부과 끝 = 정산기간 끝, 종료예정일이 있으면 그보다 늦지 않게
        $plannedEnd = self::normDate($debt['planned_end_on'] ?? null);
        $chargeEnd  = ($plannedEnd !== null && $plannedEnd < $pe) ? $plannedEnd : $pe;

        if ($chargeStart > $chargeEnd) {
            return null; // 이미 반영됐거나 계약 시작 전 / 종료 후
        }

        $days   = (int) (new DateTime($chargeStart))->diff(new DateTime($chargeEnd))->days + 1;

        // 그날 걷을 수 있는 여유분($headroom)이 주어지면 **걷을 수 있는 일수만큼만** 부과한다.
        // 나머지 날짜는 due_updated_on 이 안 밀리므로 다음 정산에서 자동으로 다시 잡힌다(이월).
        // 라이더 실수령이 0으로 잘리면서 차감액이 증발하는 걸 막는 장치다(2026-09-04 갑).
        if ($headroom !== null) {
            if ($headroom < $daily) {
                return null; // 하루치도 못 걷음 → 전부 이월
            }
            $affordable = intdiv($headroom, $daily);
            if ($affordable < $days) {
                $days      = $affordable;
                $chargeEnd = self::addDays($chargeStart, $days - 1);
            }
        }

        $amount = $days * $daily;
        if ($amount <= 0) {
            return null;
        }

        try {
            return self::applyRepayment(
                $debtId,
                $pe,          // 귀속일 = 정산일 — 이 사이클이 소비해야 한다
                $days,
                $amount,
                sprintf('자동계산: %s~%s %d일(달력일)', $chargeStart, $chargeEnd, $days),
                $chargeEnd    // 커버한 마지막 날 — 나머지는 다음 정산으로 이월
            );
        } catch (Throwable $e) {
            // (debt_id, applied_date) UNIQUE 위반 = 이미 이 귀속일로 처리됨 → 재실행 시 조용히 skip
            if (str_contains($e->getMessage(), 'uq_rde_debt_applied') || str_contains($e->getMessage(), 'Duplicate entry')) {
                return null;
            }
            throw $e;
        }
    }
    /**
     * 선지급금(가불) 회수 — 다음 정산에서 **잔액 전액**. 여유분이 모자라면 그만큼만 걷고
     * 잔액을 남겨 다음 정산에서 다시 전액을 시도한다(부분 회수).
     *
     * 개시일 이후의 정산부터 걸린다 — 오늘 등록한 선지급을 어제 정산이 가져가면 안 된다.
     *
     * @param array<string,mixed> $debt
     * @return array{amount:int, balance_after:int, entry_id:int, deduction_entry_id:int}|null
     */
    private static function chargeAdvance(array $debt, string $periodEnd, ?int $headroom): ?array
    {
        $balance = (int) $debt['balance_amount'];
        if ($balance <= 0) {
            return null;
        }

        // ⚠️ 정산일이 등록일보다 앞서도 걷는다. 일정산은 «지난 날짜» 로 올라오기 때문에
        //    (오늘 등록 → 어제자 정산 반영) 날짜로 막으면 «다음 정산에서 전액 회수» 가
        //    하루 이틀씩 밀린다. 이 함수는 정산 반영 시점에만 호출되므로, 등록 전에
        //    이미 반영이 끝난 정산이 뒤늦게 이 가불을 가져갈 일은 없다.

        $charge = $headroom !== null ? min($balance, max(0, $headroom)) : $balance;
        if ($charge <= 0) {
            return null;   // 이번 정산에선 한 푼도 못 걷음 → 다음 정산으로
        }

        try {
            return self::applyRepayment(
                (int) $debt['id'],
                $periodEnd,
                0,
                $charge,
                $charge < $balance ? '자동회수(일부) — 잔액 부족분은 다음 정산에서' : '자동회수(전액)',
                $periodEnd
            );
        } catch (Throwable $e) {
            // 같은 귀속일로 이미 처리됨 → 재반영 시 조용히 skip
            if (str_contains($e->getMessage(), 'uq_rde_debt_applied') || str_contains($e->getMessage(), 'Duplicate entry')) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * 같은 라이더에게 이미 있는 **진행 중인 같은 종류** 계약 — 중복 등록 경고용.
     * 대여금은 여러 건이 정상일 수 있어 차단하지 않고 화면에서 확인만 받는다.
     *
     * @return list<array<string,mixed>>
     */
    public static function activeSameKind(int $riderId, string $kind): array
    {
        if (!self::tableReady() || $riderId < 1 || !isset(self::KINDS[$kind])) {
            return [];
        }

        return db_rows(
            "SELECT id, title, balance_amount, daily_amount, opened_on
               FROM rider_debts
              WHERE rider_id = ? AND kind = ? AND status = 'active'
              ORDER BY id DESC",
            [$riderId, $kind]
        );
    }

    /**
     * 리스 수수료 배분 리포트 — 기간 내 실제로 배분된 금액을 조직별로 집계.
     * 현재 로그인 계정의 스코프(본사=전체 / 총판=하위 / 대리점=자기)를 자동 적용한다.
     *
     * @param array{from?:string, to?:string, agency_id?:int} $f
     * @return array{total:int, hq:int, distributor:int, agency:int, count:int, days:int}
     */
    public static function feeSummary(array $f = []): array
    {
        $zero = ['total' => 0, 'hq' => 0, 'distributor' => 0, 'agency' => 0, 'count' => 0, 'days' => 0];
        if (!self::tableReady()) {
            return $zero;
        }
        [$where, $params] = self::feeReportWhere($f);
        $row = db_row(
            "SELECT COUNT(*) cnt, COALESCE(SUM(e.days),0) d,
                    COALESCE(SUM(e.amount),0) amt,
                    COALESCE(SUM(e.fee_hq),0) hq,
                    COALESCE(SUM(e.fee_distributor),0) dist,
                    COALESCE(SUM(e.fee_agency),0) agy
               FROM rider_debt_entries e
               INNER JOIN rider_debts d ON d.id = e.debt_id
               INNER JOIN riders r ON r.id = e.rider_id
              WHERE {$where}",
            $params
        ) ?: [];

        return [
            'total'       => (int) ($row['amt'] ?? 0),
            'hq'          => (int) ($row['hq'] ?? 0),
            'distributor' => (int) ($row['dist'] ?? 0),
            'agency'      => (int) ($row['agy'] ?? 0),
            'count'       => (int) ($row['cnt'] ?? 0),
            'days'        => (int) ($row['d'] ?? 0),
        ];
    }

    /**
     * 리스 수수료 배분 상세 — 차감 건별 목록(라이더·대리점·계약·배분액).
     *
     * @param array{from?:string, to?:string, agency_id?:int} $f
     * @return list<array<string,mixed>>
     */
    public static function feeRows(array $f = [], int $limit = 500): array
    {
        if (!self::tableReady()) {
            return [];
        }
        [$where, $params] = self::feeReportWhere($f);
        $limit = max(1, min(2000, $limit));

        return db_rows(
            "SELECT e.id, e.applied_date, e.days, e.amount,
                    e.fee_hq, e.fee_distributor, e.fee_agency,
                    d.title, d.vin, d.lease_provider, d.daily_amount,
                    r.name AS rider_name, r.phone AS rider_phone,
                    o.name AS agency_name
               FROM rider_debt_entries e
               INNER JOIN rider_debts d ON d.id = e.debt_id
               INNER JOIN riders r ON r.id = e.rider_id
               LEFT JOIN organizations o ON o.id = r.agency_id
              WHERE {$where}
              ORDER BY e.applied_date DESC, e.id DESC
              LIMIT {$limit}",
            $params
        );
    }

    /**
     * 리포트 공통 WHERE — 리스 건만, 기간·대리점 필터 + 멀티테넌시 스코프.
     *
     * @param array{from?:string, to?:string, agency_id?:int} $f
     * @return array{0:string, 1:list<mixed>}
     */
    private static function feeReportWhere(array $f): array
    {
        require_once __DIR__ . '/Org.php';
        $conds  = ["d.kind = 'lease'"];
        $params = [];

        $from = trim((string) ($f['from'] ?? ''));
        $to   = trim((string) ($f['to'] ?? ''));
        if ($from !== '') { $conds[] = 'e.applied_date >= ?'; $params[] = $from; }
        if ($to !== '')   { $conds[] = 'e.applied_date <= ?'; $params[] = $to; }

        $agencyId = (int) ($f['agency_id'] ?? 0);
        if ($agencyId > 0 && Org::canAccessAgency($agencyId)) {
            $conds[]  = 'r.agency_id = ?';
            $params[] = $agencyId;
        }

        [$scope, $scopeParams] = Org::agencyScopeClause('r.agency_id');
        if ($scope !== '') {
            $conds[] = $scope;
            $params  = array_merge($params, $scopeParams);
        }

        return [implode(' AND ', $conds), $params];
    }

    /** @deprecated accrualGap() 을 쓸 것 — 리스만 보던 시절의 이름. */
    public static function leaseAccrualGap(array $debt, ?string $today = null): ?array
    {
        return (string) ($debt['kind'] ?? '') === 'lease' ? self::accrualGap($debt, $today) : null;
    }

    /**
     * 차감 공백 상태 — **대여금·리스·선지급금 공통**. 달력일은 흐르는데 정산 반영이
     * 뜸해서 차감이 밀린 일수를 관리자·라이더 화면에 동일하게 보여주기 위한 계산.
     * 판단만 하고 실제 차감은 applyDailyAccrualForPeriod()(정산 반영 시점)가 한다.
     *
     * 다음 정산 반영 때 여기 gap_days 만큼이 **한꺼번에 부과**된다.
     *
     * @param array<string,mixed> $debt rider_debts 행
     * @return array{missing_end_date: bool, overdue: bool, gap_days: int}|null
     *         active 가 아니거나 일납·개시일이 없으면 null
     */
    public static function accrualGap(array $debt, ?string $today = null): ?array
    {
        if ((string) ($debt['status'] ?? '') !== 'active') {
            return null;
        }
        $today  = self::normDate($today) ?? date('Y-m-d');
        $opened = self::normDate($debt['opened_on'] ?? null);
        $kind   = (string) ($debt['kind'] ?? '');

        // 리스는 계약 종료일이 있어야 자동계산이 성립한다(반복 부과라 잔액 상한이 없음).
        $plannedEnd = self::normDate($debt['planned_end_on'] ?? null);
        if ($kind === 'lease' && ($opened === null || $plannedEnd === null)) {
            return ['missing_end_date' => true, 'overdue' => false, 'gap_days' => 0];
        }
        if ($opened === null || (int) ($debt['daily_amount'] ?? 0) <= 0) {
            return null; // 일납/개시일 없는 건은 수동 차감 대상
        }
        // 상각형은 잔액이 없으면 더 부과되지 않으므로 공백도 없다.
        if (in_array($kind, self::AMORTIZING, true) && (int) ($debt['balance_amount'] ?? 0) <= 0) {
            return ['missing_end_date' => false, 'overdue' => false, 'gap_days' => 0];
        }
        if ($opened > $today) {
            return ['missing_end_date' => false, 'overdue' => false, 'gap_days' => 0]; // 개시 전
        }

        // 오늘(또는 종료예정일 중 이른 쪽)까지는 반영돼 있어야 한다.
        $coverageEnd = ($plannedEnd !== null && $plannedEnd < $today) ? $plannedEnd : $today;
        $lastCovered = self::normDate($debt['due_updated_on'] ?? null) ?? self::addDays($opened, -1);
        if ($lastCovered >= $coverageEnd) {
            return ['missing_end_date' => false, 'overdue' => false, 'gap_days' => 0];
        }

        $gapDays = (int) (new DateTime($lastCovered))->diff(new DateTime($coverageEnd))->days;

        return [
            'missing_end_date' => false,
            'overdue'          => $gapDays >= self::GAP_WARNING_DAYS,
            'gap_days'         => $gapDays,
        ];
    }
    /**
     * 리스 총액 = 일납 × 계약일수(개시일~종료예정일, 양끝 포함).
     * 계약 정보가 모자라면 0 — 자동계산이 안 되므로 수동 차감으로 처리해야 한다.
     */
    private static function leasePrincipal(int $daily, ?string $openedOn, ?string $plannedEnd): int
    {
        if ($daily <= 0 || $openedOn === null || $plannedEnd === null || $plannedEnd < $openedOn) {
            return 0;
        }
        $days = (int) (new DateTime($openedOn))->diff(new DateTime($plannedEnd))->days + 1;

        return $daily * $days;
    }

    /**
     * 리스 종료예정일 자동 계산 (2026-09-18 갑) — **총 금액 ÷ 일납 = 걸리는 일수**.
     *
     *   "총 금액을 넣고 시작일과 일금액을 넣으면 언제 종료가 되는지 종료일자를 자동으로 계산"
     *
     * 나누어떨어지지 않으면 마지막 날은 남은 금액만 걷으므로 **올림**한다
     * (예: 1,000,000 ÷ 27,000 = 37.03일 → 38일째에 잔액 1,000원을 걷고 끝).
     * 차감 로직은 잔액을 넘겨 걷지 않으므로 이 하루가 과징수가 되지 않는다.
     *
     * @param string $startOn 부과가 시작되는 날(신규=개시일, 이관=기준일 다음날)
     */
    public static function leaseEndDate(int $amount, int $daily, ?string $startOn): ?string
    {
        $startOn = self::normDate($startOn);
        if ($amount <= 0 || $daily <= 0 || $startOn === null) {
            return null;
        }

        return self::addDays($startOn, (int) ceil($amount / $daily) - 1);
    }
    private static function addDays(string $date, int $days): string
    {
        return (new DateTime($date))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    private static function normDate(mixed $v): ?string
    {
        $s = trim((string) ($v ?? ''));
        if ($s === '') {
            return null;
        }
        $d = DateTime::createFromFormat('Y-m-d', $s);

        return ($d && $d->format('Y-m-d') === $s) ? $s : null;
    }
}
