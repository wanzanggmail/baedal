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

    /** 잔액이 상각(감소)되는 종류. 리스는 반복 부과라 잔액이 줄지 않음 */
    private const AMORTIZING = ['loan', 'advance'];

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
        // 리스는 원금 개념이 없어 잔액 0에서 시작, 대여금/선지급은 잔액=원금
        $balance = in_array($kind, self::AMORTIZING, true) ? $principal : 0;

        $openedOn  = self::normDate($in['opened_on'] ?? null);
        $plannedEnd = $kind === 'lease' ? self::normDate($in['planned_end_on'] ?? null) : null;
        if ($plannedEnd !== null && $openedOn !== null && $plannedEnd < $openedOn) {
            throw new InvalidArgumentException('계약 종료 예정일은 시작일보다 앞설 수 없습니다.');
        }

        // 리스 전용 — 제공 주체·배분액·차대번호. 대여금/선지급은 해당 없음.
        $lease = $kind === 'lease'
            ? self::normalizeLeaseFees($in, $daily, self::orgChainForRider($riderId))
            : ['lease_provider' => null, 'fee_hq' => 0, 'fee_distributor' => 0, 'fee_agency' => 0];
        $vin = $kind === 'lease' ? mb_substr(trim((string) ($in['vin'] ?? '')), 0, 30) : '';

        return db_insert(
            'INSERT INTO rider_debts
                (rider_id, kind, title, principal_amount, balance_amount, daily_amount,
                 creditor, status, opened_on, planned_end_on, note,
                 lease_provider, vin, fee_hq, fee_distributor, fee_agency)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
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
        if (array_key_exists('daily_amount', $in)) { $sets[] = 'daily_amount = ?'; $params[] = max(0, (int) $in['daily_amount']); }
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
        if (array_key_exists('planned_end_on', $in) && (string) $debt['kind'] === 'lease') {
            $plannedEnd = self::normDate($in['planned_end_on']);
            $openedOn   = array_key_exists('opened_on', $in) ? self::normDate($in['opened_on']) : self::normDate($debt['opened_on'] ?? null);
            if ($plannedEnd !== null && $openedOn !== null && $plannedEnd < $openedOn) {
                throw new InvalidArgumentException('계약 종료 예정일은 시작일보다 앞설 수 없습니다.');
            }
            $sets[]   = 'planned_end_on = ?';
            $params[] = $plannedEnd;
        }
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
        // 일납이 없거나 개시일이 없으면 자동계산 불가 — 수동 차감(applyRepayment)으로 처리한다.
        if ($daily <= 0 || $opened === null) {
            return null;
        }

        $pe = self::normDate($periodEnd);
        if ($pe === null) {
            throw new InvalidArgumentException('정산기간 형식이 올바르지 않습니다. (YYYY-MM-DD)');
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
