<?php

declare(strict_types=1);

require_once __DIR__ . '/PgPayment.php';
require_once __DIR__ . '/PgFeeConfig.php';
require_once __DIR__ . '/RiderWallet.php';
require_once __DIR__ . '/AgencyWallet.php';
require_once __DIR__ . '/SettlementLedger.php';

/**
 * 프로모션 지급 (LOGIC §5.8).
 *
 * 흐름: 템플릿(xlsx) 다운로드 → 금액 채워서 업로드 → 미리보기(매칭·중복 확인) → 확정
 *   확정 시 **라이더별로** 카드결제(프로모션액 + 플랫폼 수수료)를 실행하고
 *   결제가 성공한 건만 라이더 지갑에 적립한다(정산과 달리 실패 건은 지급되지 않음).
 *
 * 정산 사이클(settlement_rider_cycles)과는 완전히 별개다 — 프로모션은 플랫폼 정산서가 아니라
 * 대리점이 자체적으로 주는 돈이다. 다만 세무상으로는 정산과 마찬가지로 라이더에게 실제 귀속되는
 * 소득이라, **정산과 같은 요율(원천세·고용보험·산재보험, `deduction_global_config`)로 공제한 뒤
 * 순액만 지갑에 적립**한다(2026-08-10 확정). 원천세는 원천세 대상 라이더(`withholding_tax_enabled`)
 * 에게만, 고용·산재보험은 정산과 동일하게 전원 무조건 공제 — `SettlementLedger::buildFeeItems()`와
 * 완전히 같은 규칙을 재사용해 두 화면의 계산이 어긋나지 않게 한다.
 *
 * 카드결제(PG)는 여전히 **공제 전 총액**(promo1+promo2)을 청구한다 — 대리점이 실제로 지출하는
 * 돈은 프로모션 총액이고, 공제는 "그중 얼마를 라이더에게 줄지"에 대한 것이지 대리점이 덜 내는
 * 게 아니기 때문이다. 원천세 공제분은 정산과 동일하게 대리점 지갑의 `withholding_reserve`
 * (신고·납부 예수금)에 쌓이고, 고용·산재보험 공제분은 별도 적립처가 없다(정산과 동일 — 실제
 * 보험료는 쿠팡 등 플랫폼이 대납 처리하므로 예수금이 아니라 단순히 라이더 몫에서 빠진다).
 */
final class Promotion
{
    /** 엑셀 템플릿 헤더 — 업로드 파싱도 이 순서를 기준으로 한다. */
    public const TEMPLATE_HEADERS = ['라이더코드', '라이더이름', '프로모션1', '프로모션2'];

    public static function tableReady(): bool
    {
        return db_table_exists('promotion_batches') && db_table_exists('promotion_entries');
    }

    /**
     * 템플릿에 채워 넣을 대리점 소속 활동 라이더 목록.
     *
     * @return list<array{rider_code:string, name:string}>
     */
    public static function templateRiders(int $agencyId): array
    {
        if ($agencyId < 1) {
            return [];
        }

        return array_map(
            static fn (array $r): array => ['rider_code' => (string) $r['rider_code'], 'name' => (string) $r['name']],
            db_rows(
                "SELECT rider_code, name FROM riders
                  WHERE agency_id = ? AND status = 'active'
                  ORDER BY name ASC",
                [$agencyId]
            )
        );
    }

    /**
     * 업로드된 엑셀 행 → 라이더 매칭 + 검증 (저장 전 미리보기용).
     *
     * @param list<array{rider_code:string, name:string, promo1:int, promo2:int}> $rows
     * @return array{rows:list<array<string,mixed>>, matched:int, unmatched:int, skipped:int, total_amount:int, dup_riders:list<string>}
     */
    public static function preview(array $rows, int $agencyId, string $payDate): array
    {
        $out          = [];
        $matched      = 0;
        $unmatched    = 0;
        $skipped      = 0;
        $totalAmount  = 0;
        $dupRiders    = [];

        foreach ($rows as $r) {
            $code   = trim((string) ($r['rider_code'] ?? ''));
            $promo1 = max(0, (int) ($r['promo1'] ?? 0));
            $promo2 = max(0, (int) ($r['promo2'] ?? 0));
            $total  = $promo1 + $promo2;

            // 라이더코드로 매칭 — 대리점 스코프 안에서만 찾는다(다른 대리점 코드는 매칭 불가)
            $rider = $code !== ''
                ? db_row('SELECT id, name FROM riders WHERE rider_code = ? AND agency_id = ? LIMIT 1', [$code, $agencyId])
                : null;

            $status = 'pending';
            $note   = '';
            if ($rider === null) {
                $status = 'unmatched';
                $note   = '라이더코드를 찾을 수 없습니다.';
                $unmatched++;
            } elseif ($total <= 0) {
                $status = 'skipped';
                $note   = '금액이 0이라 지급 대상이 아닙니다.';
                $skipped++;
            } else {
                $matched++;
                $totalAmount += $total;

                // 같은 지급일자에 이미 지급받은 이력이 있으면 경고(차단은 아님 — 추가 지급 허용)
                $already = db_row(
                    "SELECT e.id FROM promotion_entries e
                       INNER JOIN promotion_batches b ON b.id = e.batch_id
                      WHERE e.rider_id = ? AND b.pay_date = ? AND e.status = 'paid' LIMIT 1",
                    [(int) $rider['id'], $payDate]
                );
                if ($already !== null) {
                    $note        = '이 날짜에 이미 지급받은 이력이 있습니다(추가 지급됩니다).';
                    $dupRiders[] = (string) $rider['name'];
                }
            }

            $out[] = [
                'rider_code'   => $code,
                'rider_name'   => (string) ($rider['name'] ?? ($r['name'] ?? '')),
                'rider_id'     => $rider !== null ? (int) $rider['id'] : null,
                'promo1'       => $promo1,
                'promo2'       => $promo2,
                'total'        => $total,
                'status'       => $status,
                'note'         => $note,
            ];
        }

        return [
            'rows'         => $out,
            'matched'      => $matched,
            'unmatched'    => $unmatched,
            'skipped'      => $skipped,
            'total_amount' => $totalAmount,
            'dup_riders'   => array_values(array_unique($dupRiders)),
        ];
    }

    /**
     * 배치 저장(확정 전 draft) — 미리보기에서 확인한 내용을 그대로 기록한다.
     *
     * @param list<array<string,mixed>> $previewRows preview()의 rows
     */
    public static function createBatch(
        int $agencyId,
        string $payDate,
        array $previewRows,
        string $filename = '',
        string $memo = '',
        ?int $adminId = null
    ): int {
        if (!self::tableReady()) {
            throw new RuntimeException('프로모션 테이블이 없습니다. php migrate.php 를 실행하세요.');
        }
        if ($agencyId < 1) {
            throw new InvalidArgumentException('대리점이 지정되지 않았습니다.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $payDate)) {
            throw new InvalidArgumentException('지급일자 형식이 올바르지 않습니다. (YYYY-MM-DD)');
        }

        $payable = array_filter($previewRows, static fn (array $r): bool => ($r['status'] ?? '') === 'pending');
        if ($payable === []) {
            throw new InvalidArgumentException('지급할 대상이 없습니다. (매칭 실패 또는 금액 0)');
        }

        return db_transaction(static function () use ($agencyId, $payDate, $previewRows, $payable, $filename, $memo, $adminId): int {
            $totalAmount = array_sum(array_map(static fn (array $r): int => (int) $r['total'], $payable));

            $batchId = db_insert(
                'INSERT INTO promotion_batches
                    (agency_id, pay_date, original_filename, memo, status, total_riders, total_amount, operator_id)
                 VALUES (?, ?, ?, ?, \'draft\', ?, ?, ?)',
                [
                    $agencyId, $payDate, mb_substr($filename, 0, 255), mb_substr($memo, 0, 255),
                    count($payable), $totalAmount,
                    ($adminId !== null && $adminId > 0) ? $adminId : null,
                ]
            );

            // 미매칭·0원 행도 기록해 둔다(왜 안 나갔는지 나중에 확인할 수 있어야 함)
            foreach ($previewRows as $r) {
                $st = match ((string) $r['status']) {
                    'pending' => 'pending',
                    default   => 'skipped',
                };
                db_insert(
                    'INSERT INTO promotion_entries
                        (batch_id, rider_id, rider_code_raw, rider_name_raw, promo1_amount, promo2_amount, total_amount, status, fail_reason)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $batchId,
                        $r['rider_id'] ?? null,
                        mb_substr((string) $r['rider_code'], 0, 60),
                        mb_substr((string) $r['rider_name'], 0, 100),
                        (int) $r['promo1'], (int) $r['promo2'], (int) $r['total'],
                        $st,
                        $st === 'skipped' ? mb_substr((string) ($r['note'] ?? ''), 0, 300) : '',
                    ]
                );
            }

            return $batchId;
        });
    }

    private static function pctAmount(int $base, float $pct): int
    {
        if ($base <= 0 || $pct <= 0) {
            return 0;
        }

        return (int) round($base * $pct / 100);
    }

    /**
     * 프로모션 총액(공제 전)에서 정산과 같은 요율로 원천세·고용보험·산재보험을 계산한다.
     * `SettlementLedger::buildFeeItems()`와 같은 규칙: 원천세는 대상 라이더만, 보험 둘은 무조건.
     *
     * @return array{withholding:int, employment:int, accident:int, net:int}
     */
    private static function computeDeductions(int $grossAmount, int $riderId, ?int $agencyId): array
    {
        $rates = SettlementLedger::deductionRates($agencyId);
        $withholdRider = (int) (db_row(
            'SELECT withholding_tax_enabled FROM riders WHERE id = ? LIMIT 1',
            [$riderId]
        )['withholding_tax_enabled'] ?? 0) === 1;

        $withholding = $withholdRider ? self::pctAmount($grossAmount, (float) $rates['withholding_tax_pct']) : 0;
        $employment  = self::pctAmount($grossAmount, (float) $rates['employment_ins_pct']);
        $accident    = self::pctAmount($grossAmount, (float) $rates['industrial_accident_ins_pct']);
        $net         = max(0, $grossAmount - $withholding - $employment - $accident);

        return ['withholding' => $withholding, 'employment' => $employment, 'accident' => $accident, 'net' => $net];
    }

    /**
     * 지급 실행 — 라이더별로 카드결제 후 성공 건만 지갑 적립.
     *
     * 재실행 안전: 이미 paid 인 건은 건너뛰므로, 일부 실패 후 다시 눌러 재시도할 수 있다.
     *
     * @return array{paid:int, failed:int, paid_amount:int, fee_amount:int, deduction_amount:int, errors:list<string>}
     */
    public static function pay(int $batchId, ?int $adminId = null): array
    {
        $batch = self::findBatch($batchId);
        if ($batch === null) {
            throw new InvalidArgumentException('프로모션 배치를 찾을 수 없습니다.');
        }

        $agencyId = (int) $batch['agency_id'];
        $entries  = db_rows(
            "SELECT * FROM promotion_entries
              WHERE batch_id = ? AND status = 'pending' AND rider_id IS NOT NULL AND total_amount > 0
              ORDER BY id ASC",
            [$batchId]
        );

        $paid = 0;
        $failed = 0;
        $paidAmount = 0;
        $feeAmount = 0;
        $deductionAmount = 0;
        $errors = [];

        foreach ($entries as $e) {
            $entryId = (int) $e['id'];
            $riderId = (int) $e['rider_id'];
            $amount  = (int) $e['total_amount']; // 공제 전 총액 — 카드 청구는 이 금액 기준(대리점 실지출)

            try {
                $ded = self::computeDeductions($amount, $riderId, $agencyId > 0 ? $agencyId : null);

                // 카드결제: 프로모션액(공제 전) + 플랫폼 수수료. 성공 시 대리점 지갑에 자금이 충전된다.
                $res = PgPayment::chargeForRider($agencyId, $riderId, $amount, null, $adminId);

                if (!$res['success']) {
                    db_execute(
                        "UPDATE promotion_entries SET status = 'failed', fail_reason = ?, fee_amount = ? WHERE id = ?",
                        [mb_substr((string) $res['fail_reason'], 0, 300), (int) $res['fee'], $entryId]
                    );
                    $failed++;
                    $errors[] = (string) $e['rider_name_raw'] . ': ' . $res['fail_reason'];
                    continue;
                }

                // 결제 성공분만 라이더 지갑에 적립 — 단, 원천세·보험 공제 후 순액만.
                // accrued_days 는 증가시키지 않는다 — 프로모션은 "정산 1일치"가 아니라 별도 지급이라
                // 출금 수수료 산정(경과일)에 영향을 주면 안 된다.
                db_transaction(static function () use ($riderId, $entryId, $res, $ded, $agencyId): void {
                    if ($ded['net'] > 0) {
                        RiderWallet::credit($riderId, $ded['net'], false);
                    }
                    db_execute(
                        "UPDATE promotion_entries
                            SET status = 'paid', pg_payment_id = ?, fee_amount = ?,
                                withholding_amount = ?, employment_ins_amount = ?, accident_ins_amount = ?, net_amount = ?,
                                fail_reason = '', paid_at = NOW()
                          WHERE id = ?",
                        [
                            (int) $res['pg_id'], (int) $res['fee'],
                            $ded['withholding'], $ded['employment'], $ded['accident'], $ded['net'],
                            $entryId,
                        ]
                    );
                    // 원천세 공제분은 정산과 동일하게 대리점의 신고·납부 예수금으로 적립.
                    // 고용·산재보험은 예수금이 아니라 단순 차감(정산과 동일 처리 — buildFeeItems 주석 참고).
                    if ($ded['withholding'] > 0 && $agencyId > 0) {
                        AgencyWallet::addWithholdingReserve($agencyId, $ded['withholding']);
                    }
                });

                $paid++;
                $paidAmount      += $ded['net'];
                $feeAmount       += (int) $res['fee'];
                $deductionAmount += $ded['withholding'] + $ded['employment'] + $ded['accident'];
            } catch (Throwable $ex) {
                db_execute(
                    "UPDATE promotion_entries SET status = 'failed', fail_reason = ? WHERE id = ?",
                    [mb_substr($ex->getMessage(), 0, 300), $entryId]
                );
                $failed++;
                $errors[] = (string) $e['rider_name_raw'] . ': ' . $ex->getMessage();
            }
        }

        self::refreshBatchTotals($batchId);

        return [
            'paid' => $paid, 'failed' => $failed,
            'paid_amount' => $paidAmount, 'fee_amount' => $feeAmount, 'deduction_amount' => $deductionAmount,
            'errors' => $errors,
        ];
    }

    /** 배치 집계·상태 갱신 (지급 실행 후 호출) */
    public static function refreshBatchTotals(int $batchId): void
    {
        $agg = db_row(
            "SELECT
                SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END)            AS paid_cnt,
                SUM(CASE WHEN status = 'paid' THEN net_amount ELSE 0 END)   AS paid_amt,
                SUM(CASE WHEN status = 'paid' THEN fee_amount ELSE 0 END)   AS fee_amt,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END)         AS pending_cnt,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END)          AS failed_cnt
               FROM promotion_entries WHERE batch_id = ?",
            [$batchId]
        ) ?: [];

        $paidCnt    = (int) ($agg['paid_cnt'] ?? 0);
        $pendingCnt = (int) ($agg['pending_cnt'] ?? 0);
        $failedCnt  = (int) ($agg['failed_cnt'] ?? 0);

        $status = 'draft';
        if ($pendingCnt === 0 && $paidCnt > 0 && $failedCnt === 0) {
            $status = 'paid';
        } elseif ($paidCnt > 0 && ($failedCnt > 0 || $pendingCnt > 0)) {
            $status = 'partial';
        } elseif ($paidCnt === 0 && $failedCnt > 0) {
            $status = 'failed';
        }

        db_execute(
            'UPDATE promotion_batches
                SET paid_riders = ?, paid_amount = ?, fee_amount = ?, status = ?, updated_at = NOW()
              WHERE id = ?',
            [$paidCnt, (int) ($agg['paid_amt'] ?? 0), (int) ($agg['fee_amt'] ?? 0), $status, $batchId]
        );
    }

    /** @return array<string,mixed>|null */
    public static function findBatch(int $batchId): ?array
    {
        if ($batchId < 1 || !self::tableReady()) {
            return null;
        }

        return db_row(
            'SELECT b.*, o.name AS agency_name, a.name AS operator_name
               FROM promotion_batches b
               LEFT JOIN organizations o ON o.id = b.agency_id
               LEFT JOIN admins a ON a.id = b.operator_id
              WHERE b.id = ? LIMIT 1',
            [$batchId]
        );
    }

    /** @return list<array<string,mixed>> */
    public static function entries(int $batchId): array
    {
        if (!self::tableReady()) {
            return [];
        }

        return db_rows(
            'SELECT e.*, r.rider_code, r.name AS rider_name
               FROM promotion_entries e
               LEFT JOIN riders r ON r.id = e.rider_id
              WHERE e.batch_id = ?
              ORDER BY FIELD(e.status, \'failed\', \'pending\', \'paid\', \'skipped\'), e.id ASC',
            [$batchId]
        );
    }

    /**
     * 배치 목록 (대리점 스코프 적용).
     *
     * @return list<array<string,mixed>>
     */
    public static function listBatches(array $filters = []): array
    {
        if (!self::tableReady()) {
            return [];
        }

        $where  = ['1=1'];
        $params = [];

        $from = trim((string) ($filters['from'] ?? ''));
        if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $where[]  = 'b.pay_date >= ?';
            $params[] = $from;
        }
        $to = trim((string) ($filters['to'] ?? ''));
        if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $where[]  = 'b.pay_date <= ?';
            $params[] = $to;
        }

        [$scopeSql, $scopeParams] = Org::agencyScopeClause('b.agency_id');
        if ($scopeSql !== '') {
            $where[] = $scopeSql;
            $params  = array_merge($params, $scopeParams);
        }

        $limit = max(1, min(300, (int) ($filters['limit'] ?? 100)));

        return db_rows(
            'SELECT b.*, o.name AS agency_name, a.name AS operator_name
               FROM promotion_batches b
               LEFT JOIN organizations o ON o.id = b.agency_id
               LEFT JOIN admins a ON a.id = b.operator_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY b.pay_date DESC, b.id DESC
              LIMIT ' . $limit,
            $params
        );
    }

    /**
     * 라이더 본인이 받은 프로모션 내역 (라이더 앱용).
     *
     * @return list<array<string,mixed>>
     */
    public static function listForRider(int $riderId, int $limit = 50): array
    {
        if ($riderId < 1 || !self::tableReady()) {
            return [];
        }
        $limit = max(1, min(200, $limit));

        return db_rows(
            "SELECT e.promo1_amount, e.promo2_amount, e.total_amount,
                    e.withholding_amount, e.employment_ins_amount, e.accident_ins_amount, e.net_amount,
                    e.paid_at, b.pay_date
               FROM promotion_entries e
               INNER JOIN promotion_batches b ON b.id = e.batch_id
              WHERE e.rider_id = ? AND e.status = 'paid'
              ORDER BY b.pay_date DESC, e.id DESC
              LIMIT " . $limit,
            [$riderId]
        );
    }

    /**
     * 라이더 본인이 받은(또는 받을 예정인) 프로모션 내역 — 정산 화면의 「프로모션」 섹션용.
     * `listForRider()`와 달리 기간(pay_date) 필터를 받고, 대기(pending)·실패(failed)까지
     * 함께 보여준다(라이더가 "왜 안 들어왔지"를 스스로 확인할 수 있게).
     *
     * @param array{from?:string, to?:string} $filters
     * @return list<array<string,mixed>>
     */
    public static function listForRiderPeriod(int $riderId, array $filters = [], int $limit = 100): array
    {
        if ($riderId < 1 || !self::tableReady()) {
            return [];
        }
        $limit = max(1, min(200, $limit));

        $where  = ['e.rider_id = ?', "e.status IN ('paid', 'pending', 'failed')"];
        $params = [$riderId];

        $from = trim((string) ($filters['from'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $where[]  = 'b.pay_date >= ?';
            $params[] = $from;
        }
        $to = trim((string) ($filters['to'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $where[]  = 'b.pay_date <= ?';
            $params[] = $to;
        }

        return db_rows(
            'SELECT e.promo1_amount, e.promo2_amount, e.total_amount,
                    e.withholding_amount, e.employment_ins_amount, e.accident_ins_amount, e.net_amount,
                    e.status, e.fail_reason, e.paid_at, b.pay_date
               FROM promotion_entries e
               INNER JOIN promotion_batches b ON b.id = e.batch_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY b.pay_date DESC, e.id DESC
              LIMIT ' . $limit,
            $params
        );
    }

    /** 배치 삭제 (draft·전건 실패만 — 지급된 건이 있으면 금지) */
    public static function deleteBatch(int $batchId): void
    {
        $batch = self::findBatch($batchId);
        if ($batch === null) {
            throw new InvalidArgumentException('배치를 찾을 수 없습니다.');
        }
        if ((int) $batch['paid_riders'] > 0) {
            throw new InvalidArgumentException('이미 지급된 건이 있는 배치는 삭제할 수 없습니다.');
        }
        db_execute('DELETE FROM promotion_batches WHERE id = ?', [$batchId]);
    }
}
