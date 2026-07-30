<?php

declare(strict_types=1);

/**
 * 라이더 부채 원장 — 대여금(loan)·리스/렌탈(lease)·선지급(advance)
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

    public static function tableReady(): bool
    {
        return db_table_exists('rider_debts') && db_table_exists('rider_debt_entries');
    }

    public static function kindLabel(string $kind): string
    {
        return self::KINDS[$kind] ?? $kind;
    }

    /**
     * 라이더의 부채 목록 (라벨·이력수 포함)
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
     * 부채 신규 등록.
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
            throw new InvalidArgumentException('부채 종류가 올바르지 않습니다.');
        }
        $principal = max(0, (int) ($in['principal_amount'] ?? 0));
        $daily     = max(0, (int) ($in['daily_amount'] ?? 0));
        // 리스는 원금 개념이 없어 잔액 0에서 시작, 대여금/선지급은 잔액=원금
        $balance = in_array($kind, self::AMORTIZING, true) ? $principal : 0;

        return db_insert(
            'INSERT INTO rider_debts
                (rider_id, kind, title, principal_amount, balance_amount, daily_amount,
                 creditor, status, opened_on, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $riderId,
                $kind,
                trim((string) ($in['title'] ?? '')),
                $principal,
                $balance,
                $daily,
                trim((string) ($in['creditor'] ?? '')),
                'active',
                self::normDate($in['opened_on'] ?? null),
                trim((string) ($in['note'] ?? '')),
            ]
        );
    }

    /**
     * 부채 기본정보 수정(제목·일납·채권자·상태·메모, 원금/잔액 보정).
     *
     * @param array<string,mixed> $in
     */
    public static function update(int $id, array $in): void
    {
        $debt = self::find($id);
        if ($debt === null) {
            throw new InvalidArgumentException('부채를 찾을 수 없습니다.');
        }
        $sets   = [];
        $params = [];
        if (array_key_exists('title', $in))    { $sets[] = 'title = ?';    $params[] = trim((string) $in['title']); }
        if (array_key_exists('daily_amount', $in)) { $sets[] = 'daily_amount = ?'; $params[] = max(0, (int) $in['daily_amount']); }
        if (array_key_exists('creditor', $in)) { $sets[] = 'creditor = ?'; $params[] = trim((string) $in['creditor']); }
        if (array_key_exists('note', $in))     { $sets[] = 'note = ?';     $params[] = trim((string) $in['note']); }
        if (array_key_exists('opened_on', $in)){ $sets[] = 'opened_on = ?';$params[] = self::normDate($in['opened_on']); }
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
    public static function applyRepayment(int $debtId, string $appliedDate, int $days, ?int $amount, string $memo = ''): array
    {
        $debt = self::find($debtId);
        if ($debt === null) {
            throw new InvalidArgumentException('부채를 찾을 수 없습니다.');
        }
        if (($debt['status'] ?? '') !== 'active') {
            throw new InvalidArgumentException('활성 상태의 부채만 차감할 수 있습니다.');
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

        return db_transaction(static function () use (
            $debtId, $debt, $appliedDate, $days, $charge, $balanceAfter, $isAmortizing, $dedKind, $note, $memo
        ): array {
            // 1) 정산 반영이 소비할 deduction_entries
            $dedId = db_insert(
                'INSERT INTO deduction_entries (rider_id, applied_date, kind, amount, note) VALUES (?, ?, ?, ?, ?)',
                [(int) $debt['rider_id'], $appliedDate, $dedKind, $charge, mb_substr($note, 0, 255)]
            );
            // 2) 이력
            $entryId = db_insert(
                'INSERT INTO rider_debt_entries
                    (debt_id, rider_id, applied_date, days, amount, balance_after, deduction_entry_id, memo)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$debtId, (int) $debt['rider_id'], $appliedDate, $days, $charge, $balanceAfter, $dedId, mb_substr($memo, 0, 255)]
            );
            // 3) 잔액·미납갱신일 갱신
            if ($isAmortizing) {
                $newStatus = $balanceAfter <= 0 ? 'closed' : (string) $debt['status'];
                db_execute(
                    'UPDATE rider_debts SET balance_amount = ?, due_updated_on = ?, status = ?, closed_on = ? WHERE id = ?',
                    [$balanceAfter, $appliedDate, $newStatus, $newStatus === 'closed' ? $appliedDate : $debt['closed_on'], $debtId]
                );
            } else {
                db_execute('UPDATE rider_debts SET due_updated_on = ? WHERE id = ?', [$appliedDate, $debtId]);
            }

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
            throw new InvalidArgumentException('부채를 찾을 수 없습니다.');
        }
        $isAmortizing = in_array((string) $debt['kind'], self::AMORTIZING, true);

        db_transaction(static function () use ($entry, $debt, $isAmortizing): void {
            // 연결된 deduction_entries 제거(정산 반영 전이라면 실제 차감도 취소됨)
            if (!empty($entry['deduction_entry_id'])) {
                db_execute('DELETE FROM deduction_entries WHERE id = ?', [(int) $entry['deduction_entry_id']]);
            }
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
        $debt = self::find($debtId);
        if ($debt === null || (string) $debt['kind'] !== 'lease' || (string) $debt['status'] !== 'active') {
            return null;
        }

        $daily = (int) $debt['daily_amount'];
        $opened = self::normDate($debt['opened_on'] ?? null);
        $plannedEnd = self::normDate($debt['planned_end_on'] ?? null);
        // 계약기간(출고일~종료예정일)이 설정 안 된 리스는 자동계산 불가 — 수동 차감(applyRepayment)으로 처리해야 함.
        if ($daily <= 0 || $opened === null || $plannedEnd === null) {
            return null;
        }

        $ps = self::normDate($periodStart);
        $pe = self::normDate($periodEnd);
        if ($ps === null || $pe === null) {
            throw new InvalidArgumentException('정산기간 형식이 올바르지 않습니다. (YYYY-MM-DD)');
        }

        $overlapStart = max($ps, $opened);
        $overlapEnd   = min($pe, $plannedEnd);
        if ($overlapStart > $overlapEnd) {
            return null; // 계약기간과 정산기간이 겹치지 않음
        }

        $days = (int) (new DateTime($overlapStart))->diff(new DateTime($overlapEnd))->days + 1;
        $amount = $days * $daily;
        if ($amount <= 0) {
            return null;
        }

        try {
            return self::applyRepayment(
                $debtId,
                $pe,
                $days,
                $amount,
                sprintf('자동계산: 계약기간∩정산기간(%s~%s) %d일', $overlapStart, $overlapEnd, $days)
            );
        } catch (Throwable $e) {
            // (debt_id, applied_date) UNIQUE 위반 = 이미 이 정산기간으로 처리됨 → 재실행 시 조용히 skip
            if (str_contains($e->getMessage(), 'uq_rde_debt_applied') || str_contains($e->getMessage(), 'Duplicate entry')) {
                return null;
            }
            throw $e;
        }
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
