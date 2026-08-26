<?php

declare(strict_types=1);

require_once INC_PATH . '/WithdrawalConfig.php';
require_once INC_PATH . '/RiderWallet.php';
require_once INC_PATH . '/WithdrawalFeeShare.php';
require_once INC_PATH . '/AgencyWallet.php';

/**
 * 출금 신청 조회·상태 변경·라이더 신청
 */
final class Withdrawal
{
    /** @var array<string, array{0: string, 1: string}> */
    private const STATUS_LABELS = [
        'pending'    => ['대기', 'warning'],
        'downloaded' => ['다운로드 완료', 'primary'],
        // 펌뱅킹에 접수는 됐지만 결과가 아직 안 온 상태 — **돈이 나갔는지 미확정**이다.
        'transferring' => ['이체 접수중', 'info'],
        'completed'  => ['처리 완료', 'success'],
        'rejected'   => ['반려', 'danger'],
        'failed'     => ['이체 실패', 'danger'],
    ];

    /** @var array<string, string> */
    private const KIND_LABELS = [
        'auto_daily'    => '자동일일',
        'rider_manual'  => '라이더신청',
    ];

    public static function publicId(int $dbId, string $kind): string
    {
        return ($kind === 'auto_daily' ? 'wd-auto-' : 'wd-') . $dbId;
    }

    public static function parseId(mixed $raw): ?int
    {
        if (is_int($raw)) {
            return $raw > 0 ? $raw : null;
        }
        $s = trim((string) $raw);
        if ($s === '') {
            return null;
        }
        if (ctype_digit($s)) {
            return (int) $s;
        }
        if (preg_match('/^wd(?:-auto)?-(\d+)$/i', $s, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public static function list(array $filters = []): array
    {
        $where  = ['1=1'];
        $params = [];

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && isset(self::STATUS_LABELS[$status])) {
            $where[]  = 'wr.status = ?';
            $params[] = $status;
        }

        $kind = trim((string) ($filters['kind'] ?? ''));
        if ($kind !== '' && isset(self::KIND_LABELS[$kind])) {
            $where[]  = 'wr.kind = ?';
            $params[] = $kind;
        }

        $from = trim((string) ($filters['from'] ?? ''));
        if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $where[]  = 'DATE(wr.requested_at) >= ?';
            $params[] = $from;
        }

        $to = trim((string) ($filters['to'] ?? ''));
        if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $where[]  = 'DATE(wr.requested_at) <= ?';
            $params[] = $to;
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like     = '%' . $q . '%';
            // 계좌번호는 암호화 저장이라 LIKE 로 못 찾는다(같은 값도 매번 다른 암호문이 된다).
            $where[]  = '(r.name LIKE ? OR r.rider_code LIKE ? OR wr.account_holder LIKE ?)';
            $params   = array_merge($params, [$like, $like, $like]);
        }

        // 멀티테넌시: 라이더 소속 대리점 스코프
        [$scopeSql, $scopeParams] = Org::agencyScopeClause('r.agency_id');
        if ($scopeSql !== '') {
            $where[]  = $scopeSql;
            $params   = array_merge($params, $scopeParams);
        }

        $limit = max(1, min(500, (int) ($filters['limit'] ?? 300)));
        $whereStr = implode(' AND ', $where);

        $rows = db_rows(
            "SELECT wr.*,
                    r.name AS rider_name,
                    r.rider_code,
                    sc.label AS bank_label
               FROM withdrawal_requests wr
               INNER JOIN riders r ON r.id = wr.rider_id
               LEFT JOIN system_codes sc
                      ON sc.category = 'bank' AND sc.code = wr.bank_code
              WHERE {$whereStr}
              ORDER BY wr.requested_at DESC, wr.id DESC
              LIMIT {$limit}",
            $params
        );

        return array_map([self::class, 'mapRow'], $rows);
    }

    /**
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public static function listByIds(array $ids, string $requiredStatus = 'pending'): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params       = $ids;

        $statusSql = '';
        if ($requiredStatus !== '' && isset(self::STATUS_LABELS[$requiredStatus])) {
            $statusSql = ' AND wr.status = ?';
            $params[]  = $requiredStatus;
        }

        $rows = db_rows(
            "SELECT wr.*,
                    r.name AS rider_name,
                    r.rider_code,
                    sc.label AS bank_label
               FROM withdrawal_requests wr
               INNER JOIN riders r ON r.id = wr.rider_id
               LEFT JOIN system_codes sc
                      ON sc.category = 'bank' AND sc.code = wr.bank_code
              WHERE wr.id IN ({$placeholders}){$statusSql}
              ORDER BY wr.id ASC",
            $params
        );

        return array_map([self::class, 'mapRow'], $rows);
    }

    /**
     * 멀티테넌시: 주어진 출금 id 중 현재 계정 스코프(라이더 소속 대리점) 내의 것만 반환.
     *
     * @param list<int> $ids
     * @return list<int>
     */
    public static function scopeFilterIds(array $ids): array
    {
        $ids = self::normalizeIds($ids);
        if ($ids === []) {
            return [];
        }
        [$scopeSql, $scopeParams] = Org::agencyScopeClause('r.agency_id');
        if ($scopeSql === '') {
            return $ids; // 본사: 전체 허용
        }
        $ph   = implode(',', array_fill(0, count($ids), '?'));
        $rows = db_rows(
            "SELECT wr.id FROM withdrawal_requests wr
               INNER JOIN riders r ON r.id = wr.rider_id
              WHERE wr.id IN ({$ph}) AND {$scopeSql}",
            array_merge($ids, $scopeParams)
        );

        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }

    /**
     * @return array{pending_count: int, pending_amount: int, downloaded_count: int}
     */
    public static function summary(): array
    {
        // 멀티테넌시: 라이더 소속 대리점 스코프
        [$scopeSql, $scopeParams] = Org::agencyScopeClause('r.agency_id');
        $join     = $scopeSql !== '' ? 'INNER JOIN riders r ON r.id = wr.rider_id' : '';
        $whereSql = $scopeSql !== '' ? 'WHERE ' . $scopeSql : '';

        $row = db_row(
            "SELECT
                SUM(CASE WHEN wr.status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN wr.status = 'pending' THEN wr.amount ELSE 0 END) AS pending_amount,
                SUM(CASE WHEN wr.status = 'downloaded' THEN 1 ELSE 0 END) AS downloaded_count
             FROM withdrawal_requests wr {$join} {$whereSql}",
            $scopeParams
        );

        return [
            'pending_count'    => (int) ($row['pending_count'] ?? 0),
            'pending_amount'   => (int) ($row['pending_amount'] ?? 0),
            'downloaded_count' => (int) ($row['downloaded_count'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $w
     * @return array<string, mixed>
     */
    public static function mapRow(array $w): array
    {
        $dbId   = (int) $w['id'];
        $kind   = (string) ($w['kind'] ?? 'rider_manual');
        $status = (string) ($w['status'] ?? 'pending');
        [$statusLabel, $statusClass] = self::STATUS_LABELS[$status] ?? [$status, 'secondary'];
        $kindLabel = self::KIND_LABELS[$kind] ?? $kind;

        $withholdSum = (int) ($w['withhold_tax'] ?? 0)
            + (int) ($w['withhold_refund'] ?? 0)
            + (int) ($w['withhold_other'] ?? 0)
            + (int) ($w['withhold_min_retain'] ?? 0)
            + (int) ($w['withhold_round_trim'] ?? 0);

        $accruedDays = (int) ($w['accrued_days'] ?? 0);
        $reserve     = (int) ($w['withhold_min_retain'] ?? 0);
        $fee         = (int) ($w['withhold_other'] ?? 0);

        $tip = '';
        if ($kind === 'auto_daily' && (int) ($w['gross_amount'] ?? 0) > 0) {
            $tip = sprintf(
                '정산일 %s · 총 %s원 · 보류 %s원',
                $w['settlement_date'] ?? '',
                number_format((int) $w['gross_amount']),
                number_format($withholdSum)
            );
        } elseif ($kind === 'rider_manual') {
            $tip = sprintf(
                '잔액 %s원 · 보증금 %s원 · 수수료 %s원(건당) · 적립 %d일',
                number_format((int) ($w['gross_amount'] ?? 0)),
                number_format($reserve),
                number_format($fee),
                $accruedDays
            );
        }

        return [
            'db_id'            => $dbId,
            'id'               => self::publicId($dbId, $kind),
            'rider_id'         => (string) ($w['rider_code'] ?: $w['rider_id']),
            'rider_name'       => (string) $w['rider_name'],
            'bank'             => (string) ($w['bank_label'] ?: '—'),
            'bank_code'        => (string) ($w['bank_code'] ?? ''),
            'account'          => Crypto::decryptSafe((string) ($w['bank_account'] ?? '')),
            'holder'           => (string) ($w['account_holder'] ?? $w['rider_name']),
            'amount'           => (int) $w['amount'],
            'gross_amount'     => (int) ($w['gross_amount'] ?? 0),
            'reserve_amount'   => $reserve,
            'withdrawal_fee'   => $fee,
            'accrued_days'     => $accruedDays,
            'settlement_date'  => $w['settlement_date'] ? (string) $w['settlement_date'] : '',
            'kind'             => $kind,
            'kind_label'       => $kindLabel,
            'requested_at'     => substr((string) ($w['requested_at'] ?? $w['created_at']), 0, 19),
            'completed_at'     => $w['completed_at'] ? substr((string) $w['completed_at'], 0, 19) : '',
            'status'           => $status,
            'status_label'     => $statusLabel,
            'status_class'     => $statusClass,
            'note'             => (string) ($w['note'] ?? ''),
            // 이체 실패 사유 — 관리자가 원인을 알아야 계좌를 고치고 재시도할 수 있다.
            'fail_reason'      => (string) ($w['fail_reason'] ?? ''),
            'rejected_reason'  => (string) ($w['rejected_reason'] ?? ''),
            'tip'              => $tip,
        ];
    }

    /**
     * @param list<int> $ids
     */
    public static function markDownloaded(array $ids, ?int $batchId = null): int
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params       = $ids;

        if ($batchId !== null && $batchId > 0) {
            return db_execute(
                "UPDATE withdrawal_requests
                    SET status = 'downloaded', download_batch_id = ?
                  WHERE id IN ({$placeholders}) AND status = 'pending'",
                array_merge([$batchId], $params)
            );
        }

        return db_execute(
            "UPDATE withdrawal_requests
                SET status = 'downloaded'
              WHERE id IN ({$placeholders}) AND status = 'pending'",
            $params
        );
    }

    /**
     * @param list<int> $ids
     */
    public static function markCompleted(array $ids): int
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = db_rows(
            "SELECT wr.id, wr.rider_id, wr.kind, wr.amount, wr.withhold_other, wr.withhold_min_retain, wr.status,
                    COALESCE(wr.agency_id, r.agency_id) AS agency_id, r.rider_code
               FROM withdrawal_requests wr
               LEFT JOIN riders r ON r.id = wr.rider_id
              WHERE wr.id IN ({$placeholders}) AND wr.status = 'downloaded'",
            $ids
        );
        if ($rows === []) {
            return 0;
        }

        $completed = 0;
        db_transaction(static function () use ($rows, &$completed): void {
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $n  = db_execute(
                    "UPDATE withdrawal_requests
                        SET status = 'completed', completed_at = NOW()
                      WHERE id = ? AND status = 'downloaded'",
                    [$id]
                );
                if ($n < 1) {
                    continue;
                }
                $completed++;
                if ((string) ($row['kind'] ?? '') === 'rider_manual') {
                    // 지갑에서 실제로 빠지는 총액 = 실지급액 + 정산수수료(withhold_other).
                    // 보증금(withhold_min_retain)은 지급하지 않고 지갑에 남는 몫이라 차감 대상이 아니다.
                    RiderWallet::deductAfterWithdrawal(
                        (int) $row['rider_id'],
                        (int) ($row['amount'] ?? 0) + (int) ($row['withhold_other'] ?? 0)
                    );
                    // 실지급액을 대리점 지갑에서도 차감한다. 이 경로는 관리자가 은행에서 직접
                    // 이체를 끝낸 뒤 누르는 백업 흐름이라, 돈은 이미 나갔으므로 잔액이 음수가
                    // 되더라도 그대로 기록한다(음수 자체가 정산이 어긋났다는 신호다).
                    $agencyId = (int) ($row['agency_id'] ?? 0);
                    if ($agencyId > 0) {
                        AgencyWallet::debit(
                            $agencyId,
                            (int) ($row['amount'] ?? 0),
                            'rider_payout',
                            $id,
                            trim((string) ($row['rider_code'] ?? '')) . ' 주정산 출금 지급(수동 완료)',
                            null
                        );
                    }
                    // 정산수수료를 본사·총판·대리점 몫으로 배분(2026-08-12 갑 확정).
                    WithdrawalFeeShare::distribute(
                        $id,
                        (int) $row['rider_id'],
                        (int) ($row['withhold_other'] ?? 0)
                    );
                }
            }
        });

        return $completed;
    }

    /**
     * 신청 즉시 이체 — 대리점 설정(`auto_transfer_on_request`)이 켜져 있을 때만 실행한다.
     *
     * **라이더 본인 신청 경로에서만** 부른다. 「출금 대행」과 일일정산 자동출금은 자기
     * 흐름에서 이미 executeTransfers()를 직접 부르므로 여기서 또 부르면 안 된다
     * (그쪽에서 이 메서드를 타면 이체가 두 번 시도되고, 두 번째는 상태 필터에 걸려
     * skipped 로 떨어지면서 호출부가 "이체 실패"로 오인한다).
     *
     * 이체가 실패해도 신청 자체는 남는다(status=failed). 관리자가 「출금 신청 목록」에서
     * 재시도할 수 있어야 라이더가 다시 신청하는 헛수고를 안 한다.
     *
     * @return array{attempted:bool, ok:bool, message:string}
     */
    public static function autoTransferOnRequest(int $requestId, ?int $agencyId = null): array
    {
        require_once INC_PATH . '/WithdrawalConfig.php';

        if ($requestId < 1) {
            return ['attempted' => false, 'ok' => false, 'message' => ''];
        }

        if ($agencyId === null || $agencyId < 1) {
            $row = db_row(
                'SELECT COALESCE(wr.agency_id, r.agency_id) AS agency_id
                   FROM withdrawal_requests wr
                   LEFT JOIN riders r ON r.id = wr.rider_id
                  WHERE wr.id = ? LIMIT 1',
                [$requestId]
            );
            $agencyId = (int) ($row['agency_id'] ?? 0);
        }

        $cfg = WithdrawalConfig::get($agencyId > 0 ? $agencyId : null);
        if (empty($cfg['auto_transfer_on_request'])) {
            return ['attempted' => false, 'ok' => false, 'message' => ''];
        }

        $res = self::executeTransfers([$requestId]);
        if ((int) $res['completed'] > 0) {
            return ['attempted' => true, 'ok' => true, 'message' => ''];
        }

        // 실패 사유는 executeTransfers 가 건별 결과로 돌려준다 — 라이더에게 그대로 보여준다.
        $msg = '';
        foreach ($res['results'] as $r) {
            if ((int) $r['id'] === $requestId && empty($r['ok'])) {
                $msg = (string) $r['message'];
                break;
            }
        }

        return ['attempted' => true, 'ok' => false, 'message' => $msg];
    }
    /**
     * 「출금 확정」 — 펌뱅킹(쿠콘·하이픈)으로 **건별 이체를 즉시 실행**한다.
     *
     * 기존 `markDownloaded`+`markCompleted`(파일 다운로드 후 수동 입금) 경로는 백업용으로
     * 그대로 남아 있고, 이 메서드가 새 기본 경로다.
     *
     * 설계 원칙 — **건 단위 독립 처리(갑 확정 2026-08-08)**:
     *  - 한 건씩 순서대로 이체하고, **실패해도 멈추지 않고 다음 건을 계속** 진행한다.
     *  - 성공한 건만 `completed` + 지갑 차감, 실패한 건은 `failed` + `fail_reason` 기록.
     *  - 즉 **실제로 돈이 나간 건까지만 완료 처리**된다(예전처럼 일괄 완료 도장을 찍지 않음).
     *
     * ⚠️ **건별로 커밋한다(전체를 한 트랜잭션으로 묶지 않음).** 이체는 외부 송금이라
     * 되돌릴 수 없으므로, 뒷건이 실패했다고 앞건의 완료 기록을 롤백하면
     * "돈은 나갔는데 시스템은 미완료"인 최악의 불일치가 생긴다.
     *
     * **이체 실패 시 사이클 점유는 유지한다 — 2026-08-08 갑 확정.**
     * 실패해도 그 정산 건들은 여전히 이 요청 몫으로 잡아둔다. 이유: 이체 실패는 대부분
     * 계좌 오류 같은 일시적 문제라 요청 자체는 유효하고, 점유를 곧바로 풀면 원인 파악 전에
     * 라이더가 재신청해 **같은 정산 건을 두고 실패한 옛 요청과 새 요청이 공존**할 수 있다.
     * 따라서 관리자가 계좌를 고쳐 이 메서드를 다시 호출(재시도)하거나, 포기할 거면
     * `markRejected()`로 반려해야만 점유가 풀린다. 그 사이 라이더는 재신청할 수 없다
     * (`hasOpenRiderRequest()`가 'failed'도 진행중으로 취급).
     *
     * @param list<int> $ids
     * @return array{completed:int, failed:int, skipped:int, results:list<array{id:int, ok:bool, message:string}>}
     */
    public static function executeTransfers(array $ids): array
    {
        require_once INC_PATH . '/FirmBankingGateway.php';
        require_once INC_PATH . '/BaumFirmGateway.php';
        require_once INC_PATH . '/FirmTransfer.php';

        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        // accepted = 접수만 된 건(비동기 게이트웨이). completed 와 구분해야 화면에서
        //            "완료" 로 오해하지 않는다.
        $out = ['completed' => 0, 'accepted' => 0, 'failed' => 0, 'skipped' => 0, 'results' => []];
        if ($ids === []) {
            return $out;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        // 이체 대상 = 아직 돈이 안 나간 건. 실패 건도 포함해 **재시도**를 지원한다.
        $rows = db_rows(
            "SELECT wr.id, wr.rider_id, wr.agency_id, wr.kind, wr.amount, wr.withhold_other,
                    wr.bank_code, wr.bank_account, wr.account_holder, wr.status,
                    r.rider_code, r.agency_id AS rider_agency_id
               FROM withdrawal_requests wr
               LEFT JOIN riders r ON r.id = wr.rider_id
              WHERE wr.id IN ({$placeholders})
                AND wr.status IN ('pending', 'downloaded', 'failed')
              ORDER BY wr.id ASC",
            $ids
        );
        if ($rows === []) {
            $out['skipped'] = count($ids);

            return $out;
        }

        $gateway = FirmBankingGatewayFactory::make();

        foreach ($rows as $row) {
            $id       = (int) $row['id'];
            $amount   = (int) ($row['amount'] ?? 0);
            $agencyId = (int) ($row['agency_id'] ?: $row['rider_agency_id'] ?: 0);

            // 라이더에게 나가는 돈은 대리점 지갑에서 조달된다 — 잔액이 모자라면 **이체 전에** 막는다.
            // (이체부터 하고 나면 실제 돈은 나갔는데 지갑은 음수가 되어 되돌릴 수 없다.)
            if ((string) ($row['kind'] ?? '') === 'rider_manual' && $agencyId > 0) {
                $agencyBalance = AgencyWallet::get($agencyId)['balance'];
                if ($agencyBalance < $amount) {
                    $msg = sprintf(
                        '대리점 잔액 부족(잔액 %s원 < 지급 %s원). PG 충전 후 재시도하세요.',
                        number_format($agencyBalance),
                        number_format($amount)
                    );
                    db_execute(
                        "UPDATE withdrawal_requests SET status = 'failed', fail_reason = ?
                          WHERE id = ? AND status IN ('pending', 'downloaded', 'failed')",
                        [mb_substr($msg, 0, 300), $id]
                    );
                    $out['failed']++;
                    $out['results'][] = ['id' => $id, 'ok' => false, 'message' => $msg];
                    continue;
                }
            }

            // 거래 ID 를 **여기서** 만든다 — 게이트웨이에 넘기는 값과 우리 장부에 남기는 값이
            // 같아야 웹훅이 왔을 때 어느 건인지 찾을 수 있다.
            $txId = BaumFirmGateway::makeTransactionId('WD', $id);

            try {
                $res = $gateway->transfer(
                    $agencyId,
                    (string) ($row['bank_code'] ?? ''),
                    Crypto::decrypt((string) ($row['bank_account'] ?? '')),
                    (string) ($row['account_holder'] ?? ''),
                    $amount,
                    [
                        'transaction_id' => $txId,
                        'request_id'     => $id,
                        'rider_code'     => (string) ($row['rider_code'] ?? ''),
                        'kind'           => 'WD',
                        'receiver_memo'  => (string) ($row['account_holder'] ?? ''),
                    ]
                );
            } catch (Throwable $e) {
                // 게이트웨이 자체가 터진 경우도 "이 건 실패"로만 처리하고 다음 건을 계속한다.
                $res = TransferResult::fail('이체 요청 오류: ' . $e->getMessage());
            }

            if (!$res->success) {
                db_execute(
                    "UPDATE withdrawal_requests
                        SET status = 'failed', fail_reason = ?
                      WHERE id = ? AND status IN ('pending', 'downloaded', 'transferring', 'failed')",
                    [mb_substr($res->failReason, 0, 300), $id]
                );
                $out['failed']++;
                $out['results'][] = ['id' => $id, 'ok' => false, 'message' => $res->failReason];
                continue;
            }

            // ── 비동기 게이트웨이(바움) ──
            // 여기서 성공한 건 **접수**일 뿐 돈이 나간 게 아니다. 완료로 찍고 지갑을 깎으면
            // 이후 이체가 실패했을 때 "돈은 안 갔는데 잔액만 사라진" 상태가 된다.
            // 접수 사실만 남기고, 확정은 웹훅(`FirmWebhook`)이나 보정 조회가 한다.
            if ($gateway->isAsync()) {
                db_execute(
                    "UPDATE withdrawal_requests
                        SET status = 'transferring', fail_reason = '',
                            note = TRIM(CONCAT(COALESCE(note, ''), ' | ', ?))
                      WHERE id = ? AND status IN ('pending', 'downloaded', 'failed')",
                    ['펌뱅킹 이체 접수 · ' . $gateway->providerLabel() . ' · 접수번호 ' . $res->txId, $id]
                );
                try {
                    FirmTransfer::record([
                        'transaction_id' => $txId,
                        'reception_id'   => $res->txId,
                        'kind'           => FirmTransfer::KIND_WITHDRAWAL,
                        'ref_id'         => $id,
                        'agency_id'      => $agencyId,
                        'rider_id'       => (int) $row['rider_id'],
                        'amount'         => $amount,
                        'bank_code'      => (string) ($row['bank_code'] ?? ''),
                        'account'        => Crypto::decryptSafe((string) ($row['bank_account'] ?? '')),
                    ]);
                } catch (Throwable $e) {
                    // 장부에 못 남기면 결과를 이어붙일 방법이 사라진다 — 반드시 눈에 띄게 남긴다.
                    error_log('[FirmTransfer] 접수 기록 실패 wd#' . $id . ' tx=' . $txId . ' — ' . $e->getMessage());
                }
                $out['accepted']++;
                $out['results'][] = ['id' => $id, 'ok' => true, 'message' => '이체 접수됨 (결과 대기) · ' . $res->txId];
                continue;
            }

            // ── 동기 게이트웨이(모의) ── 응답이 곧 결과이므로 그 자리에서 확정한다.
            $note = '펌뱅킹 이체 완료 · ' . $gateway->providerLabel() . ' · 거래번호 ' . $res->txId;
            if (self::finalizeSuccess($id, $note)) {
                $out['completed']++;
                $out['results'][] = ['id' => $id, 'ok' => true, 'message' => $res->txId];
            } else {
                $out['skipped']++;
                $out['results'][] = ['id' => $id, 'ok' => false, 'message' => '이미 처리된 건입니다.'];
            }
        }

        $out['skipped'] = max(0, count($ids) - count($rows));

        return $out;
    }

    /**
     * 출금 1건을 **완료 확정**한다 — 상태 변경 + 지갑 차감 + 수수료 배분.
     *
     * 원래 `executeTransfers()` 안에 있던 블록을 꺼냈다. 비동기 게이트웨이(바움)에서는
     * 접수와 확정 시점이 갈리므로 **웹훅과 보정 조회도 같은 처리를 해야 하기 때문**이다.
     * 한 곳에만 두어야 "웹훅으로 확정한 건은 수수료 배분이 빠졌다" 같은 사고가 안 난다.
     *
     * 🔒 **멱등하다.** 웹훅은 최대 10회 재전송되고 보정 조회까지 겹칠 수 있는데, 그때마다
     *    지갑을 깎으면 안 된다. UPDATE 의 `status IN (…)` 조건이 걸러 주고, 이미 확정된
     *    건이면 아무것도 하지 않고 false 를 돌려준다.
     *
     * @return bool 이번 호출로 실제 확정됐으면 true(이미 확정된 건이면 false)
     */
    public static function finalizeSuccess(int $id, string $note): bool
    {
        $row = db_row(
            'SELECT wr.id, wr.rider_id, wr.agency_id, wr.kind, wr.amount, wr.withhold_other, r.rider_code
               FROM withdrawal_requests wr
               LEFT JOIN riders r ON r.id = wr.rider_id
              WHERE wr.id = ? LIMIT 1',
            [$id]
        );
        if ($row === null) {
            return false;
        }
        $agencyId = (int) ($row['agency_id'] ?? 0);
        $done     = false;

        db_transaction(static function () use ($id, $row, $note, $agencyId, &$done): void {
            $n = db_execute(
                // fail_reason 은 NOT NULL 이라 재시도 성공 시 빈 문자열로 지운다(NULL 대입 불가).
                "UPDATE withdrawal_requests
                    SET status = 'completed', completed_at = NOW(), fail_reason = '',
                        note = TRIM(CONCAT(COALESCE(note, ''), ' | ', ?))
                  WHERE id = ? AND status IN ('pending', 'downloaded', 'transferring', 'failed')",
                [$note, $id]
            );
            if ($n < 1) {
                return; // 이미 확정됐거나 반려된 건 — 지갑을 건드리지 않는다.
            }
            $done = true;

            if ((string) ($row['kind'] ?? '') === 'rider_manual') {
                // 지갑에서 빠지는 총액 = 실지급액 + 정산수수료. 보증금은 남는 몫이라 제외.
                RiderWallet::deductAfterWithdrawal(
                    (int) $row['rider_id'],
                    (int) ($row['amount'] ?? 0) + (int) ($row['withhold_other'] ?? 0)
                );
                // 실지급액은 대리점 지갑에서 나간 돈이다 — 지갑도 같이 줄여야 잔액이 실제와 맞는다.
                // (정산수수료는 대리점에 남았다가 아래 배분에서 본사·총판 몫만 빠져나간다.)
                if ($agencyId > 0) {
                    AgencyWallet::debit(
                        $agencyId,
                        (int) ($row['amount'] ?? 0),
                        'rider_payout',
                        $id,
                        trim((string) ($row['rider_code'] ?? '')) . ' 주정산 출금 지급',
                        null
                    );
                }
                // 정산수수료를 본사·총판·대리점 몫으로 배분(2026-08-12 갑 확정).
                WithdrawalFeeShare::distribute(
                    $id,
                    (int) $row['rider_id'],
                    (int) ($row['withhold_other'] ?? 0)
                );
            }
        });

        return $done;
    }

    /**
     * 이체가 **실패로 확정**됐을 때 — 접수중 상태를 되돌린다.
     *
     * 지갑은 애초에 건드리지 않았으므로 되돌릴 것이 없다(그게 접수/확정을 나눈 이유다).
     * 라이더는 관리자가 계좌를 고쳐 재시도하거나 반려해야 다시 신청할 수 있다
     * (`hasOpenRiderRequest()` 가 'failed' 도 진행중으로 취급).
     *
     * @return bool 이번 호출로 실제 바뀌었으면 true
     */
    public static function markTransferFailed(int $id, string $reason): bool
    {
        $n = db_execute(
            "UPDATE withdrawal_requests
                SET status = 'failed', fail_reason = ?
              WHERE id = ? AND status IN ('transferring', 'pending', 'downloaded')",
            [mb_substr($reason, 0, 300), $id]
        );

        return $n > 0;
    }

    /**
     * @param list<int> $ids
     */
    public static function markRejected(array $ids, string $reason = ''): int
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $reason       = mb_substr(trim($reason), 0, 300);

        require_once INC_PATH . '/WithdrawalCycles.php';

        return db_transaction(static function () use ($placeholders, $reason, $ids): int {
            // 실제로 반려된 건만 대상으로 사이클 점유를 해제한다.
            // ⚠️ 'transferring'(펌뱅킹 접수됨)은 **일부러 뺐다** — 이미 이체가 진행 중이라
            //    반려하면 돈은 나가는데 사이클 점유만 풀린다. 결과가 확정된 뒤에 처리해야 한다.
            $rejected = db_rows(
                "SELECT id FROM withdrawal_requests
                  WHERE id IN ({$placeholders}) AND status IN ('pending', 'downloaded', 'failed')",
                $ids
            );
            $rejectedIds = array_map(static fn (array $r): int => (int) $r['id'], $rejected);

            $n = db_execute(
                "UPDATE withdrawal_requests
                    SET status = 'rejected', rejected_reason = ?
                  WHERE id IN ({$placeholders}) AND status IN ('pending', 'downloaded', 'failed')",
                array_merge([$reason], $ids)
            );

            // §7 #18 — 반려된 출금이 점유했던 사이클을 미출금 상태로 되돌린다.
            WithdrawalCycles::release($rejectedIds);

            return $n;
        });
    }

    /**
     * @param list<mixed> $rawIds
     * @return list<int>
     */
    public static function normalizeIds(array $rawIds): array
    {
        $out = [];
        foreach ($rawIds as $raw) {
            $id = self::parseId($raw);
            if ($id !== null) {
                $out[] = $id;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<array<string, mixed>>
     */
    /**
     * @param array{from?:string, to?:string} $filters from/to는 신청일(requested_at) 기준, 'YYYY-MM-DD'
     */
    public static function listForRider(int $riderId, int $limit = 50, array $filters = []): array
    {
        if ($riderId < 1) {
            return [];
        }

        [$dateWhere, $dateParams] = self::riderDateWhere($filters);
        $limit = max(1, min(200, $limit));
        $rows  = db_rows(
            "SELECT wr.*,
                    r.name AS rider_name,
                    r.rider_code,
                    sc.label AS bank_label
               FROM withdrawal_requests wr
               INNER JOIN riders r ON r.id = wr.rider_id
               LEFT JOIN system_codes sc
                      ON sc.category = 'bank' AND sc.code = wr.bank_code
              WHERE wr.rider_id = ? {$dateWhere}
              ORDER BY wr.requested_at DESC, wr.id DESC
              LIMIT {$limit}",
            array_merge([$riderId], $dateParams)
        );

        return array_map([self::class, 'mapRow'], $rows);
    }

    /**
     * 기간 내 출금 신청 합계(라이더 화면 상단 요약용) — 화면 표시 상한(limit)과 무관하게
     * 항상 필터 전체를 집계한다.
     *
     * @param array{from?:string, to?:string} $filters
     * @return array{count:int, gross:int, reserve:int, fee:int, amount:int,
     *               completed_count:int, completed_amount:int,
     *               pending_count:int, pending_amount:int}
     */
    public static function sumForRider(int $riderId, array $filters = []): array
    {
        $empty = [
            'count' => 0, 'gross' => 0, 'reserve' => 0, 'fee' => 0, 'amount' => 0,
            'completed_count' => 0, 'completed_amount' => 0,
            'pending_count' => 0, 'pending_amount' => 0,
        ];
        if ($riderId < 1 || !db_table_exists('withdrawal_requests')) {
            return $empty;
        }

        [$dateWhere, $dateParams] = self::riderDateWhere($filters);
        $row = db_row(
            "SELECT COUNT(*) AS cnt,
                    COALESCE(SUM(gross_amount), 0) AS gross,
                    COALESCE(SUM(withhold_min_retain), 0) AS reserve,
                    COALESCE(SUM(withhold_other), 0) AS fee,
                    COALESCE(SUM(amount), 0) AS amount,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_count,
                    COALESCE(SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END), 0) AS completed_amount,
                    SUM(CASE WHEN status IN ('pending','downloaded','failed') THEN 1 ELSE 0 END) AS pending_count,
                    COALESCE(SUM(CASE WHEN status IN ('pending','downloaded','failed') THEN amount ELSE 0 END), 0) AS pending_amount
               FROM withdrawal_requests wr
              WHERE wr.rider_id = ? {$dateWhere}",
            array_merge([$riderId], $dateParams)
        );

        if ($row === null) {
            return $empty;
        }

        return [
            'count'             => (int) $row['cnt'],
            'gross'             => (int) $row['gross'],
            'reserve'           => (int) $row['reserve'],
            'fee'               => (int) $row['fee'],
            'amount'            => (int) $row['amount'],
            'completed_count'   => (int) $row['completed_count'],
            'completed_amount'  => (int) $row['completed_amount'],
            'pending_count'     => (int) $row['pending_count'],
            'pending_amount'    => (int) $row['pending_amount'],
        ];
    }

    /**
     * @param array{from?:string, to?:string} $filters
     * @return array{0:string, 1:list<string>}
     */
    private static function riderDateWhere(array $filters): array
    {
        $from = trim((string) ($filters['from'] ?? ''));
        $to   = trim((string) ($filters['to'] ?? ''));
        $sql  = '';
        $params = [];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $sql .= ' AND wr.requested_at >= ?';
            $params[] = $from . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $sql .= ' AND wr.requested_at <= ?';
            $params[] = $to . ' 23:59:59';
        }

        return [$sql, $params];
    }

    public static function hasOpenRiderRequest(int $riderId): bool
    {
        if ($riderId < 1) {
            return true;
        }

        // 'transferring'(펌뱅킹 접수됨)은 **결과를 기다리는 중**이라 당연히 진행 중이다.
        // 'failed'(이체 실패)도 **진행 중으로 본다** — 실패해도 그 요청이 점유한 정산 사이클은
        // 그대로 남아 있으므로(재시도 대상), 라이더가 새로 신청해 봐야 고를 사이클이 없다.
        // 관리자가 재시도하거나 반려(→ 점유 해제)해야 다음 신청이 가능해진다.
        $row = db_row(
            "SELECT id FROM withdrawal_requests
              WHERE rider_id = ? AND kind = 'rider_manual'
                AND status IN ('pending', 'downloaded', 'transferring', 'failed')
              LIMIT 1",
            [$riderId]
        );

        return $row !== null;
    }

    /**
     * 라이더 전액 출금 신청 (보증금·건당 수수료 차감)
     *
     * @return array<string, mixed>
     */
    /**
     * @param bool $allowDailySettlement 선정산(일일지급) 라이더도 허용할지.
     *   업무 정책상 선정산 라이더는 **대리점의 일일정산 지급**으로만 돈을 받는다(§5.4). 라이더가
     *   앱에서 따로 신청하면 같은 정산분이 두 경로로 나갈 수 있으므로 기본값은 차단이다.
     *   내부 자동출금(`DailyAutoWithdrawal`)만 true로 넘겨 이 경로를 재사용한다.
     */
    public static function applyForRider(int $riderId, ?string $toDate = null, bool $allowDailySettlement = false): array
    {
        if ($riderId < 1) {
            throw new InvalidArgumentException('라이더 정보가 없습니다.');
        }
        if ($toDate !== null && $toDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
            throw new InvalidArgumentException('출금 기간이 올바르지 않습니다.');
        }

        if (!db_table_exists('withdrawal_requests')) {
            throw new RuntimeException('출금 테이블이 없습니다. php migrate.php 를 실행하세요.');
        }
        if (!db_table_exists('rider_wallets')) {
            throw new RuntimeException('지갑 테이블이 없습니다. php migrate.php 를 실행하세요.');
        }

        $rider = db_row(
            'SELECT id, name, status, withdrawal_hold, is_daily_settlement, agency_id,
                    bank_code, bank_account, account_holder
             FROM riders WHERE id = ? LIMIT 1',
            [$riderId]
        );
        if ($rider === null) {
            throw new InvalidArgumentException('라이더를 찾을 수 없습니다.');
        }
        if ((string) ($rider['status'] ?? '') !== 'active') {
            throw new InvalidArgumentException('활동 중인 라이더만 출금 신청할 수 있습니다.');
        }
        // 선정산 라이더는 대리점의 일일정산 지급으로만 받는다 — 앱에서 따로 신청하면 중복 지급 위험.
        if (!$allowDailySettlement && (int) ($rider['is_daily_settlement'] ?? 0) === 1) {
            throw new InvalidArgumentException('선정산(일일지급) 대상 라이더입니다. 정산분은 대리점에서 매일 자동으로 지급되므로 별도 출금 신청이 필요하지 않습니다.');
        }
        if ((int) ($rider['withdrawal_hold'] ?? 0) === 1) {
            throw new InvalidArgumentException('출금 보류 상태입니다. 관리자에게 문의하세요.');
        }
        if (trim((string) ($rider['bank_code'] ?? '')) === '' || trim((string) ($rider['bank_account'] ?? '')) === '') {
            throw new InvalidArgumentException('출금 계좌를 먼저 등록해 주세요.');
        }
        if (self::hasOpenRiderRequest($riderId)) {
            throw new InvalidArgumentException('처리 중인 출금 신청이 있습니다. 완료 후 다시 신청해 주세요.');
        }

        $preview = RiderWallet::previewWithdrawal($riderId, $toDate);
        if (!(bool) ($preview['can_apply'] ?? false)) {
            throw new InvalidArgumentException(
                $toDate !== null && $toDate !== ''
                    ? '선택한 기간에 출금 가능한 정산 내역이 없습니다. (보증금·수수료 차감 후 0원)'
                    : '출금 가능 금액이 없습니다. (보증금·수수료 차감 후 0원)'
            );
        }

        $balance  = (int) $preview['balance'];
        $reserve  = (int) $preview['reserve_amount'];
        $fee      = (int) $preview['fee_per_tx'];
        $payout   = (int) $preview['payout_amount'];
        $accrued  = (int) $preview['accrued_days'];
        $picked   = (array) ($preview['picked_cycles'] ?? []);

        $hasAccruedCol = self::hasAccruedDaysColumn();
        // 기간 지정 출금이면 실제로 소진한 정산일 범위를 메모 앞에 남긴다.
        $periodNote = '';
        if ($picked !== []) {
            $pickedDates = array_column($picked, 'settlement_date');
            $periodNote  = sprintf('[%s~%s] ', min($pickedDates), max($pickedDates));
        }
        // §7 #18 — 사이클 기반이면 수수료 구간 내역을 메모에 남긴다(80원/40원 구간 분리 표기).
        $kindLabel = ($toDate !== null && $toDate !== '') ? '라이더 기간 출금' : '라이더 전액 출금';
        $note = (bool) ($preview['fee_cycle_based'] ?? false)
            ? sprintf(
                '%s%s · 보증금 %s원 · 정산수수료 %s원(%d건×%d원 + %d건×%d원)',
                $periodNote,
                $kindLabel,
                number_format($reserve),
                number_format($fee),
                (int) $preview['fee_short_orders'],
                (int) $preview['fee_rate_short'],
                (int) $preview['fee_long_orders'],
                (int) $preview['fee_rate_long']
            )
            : sprintf(
                '%s%s · 적립 %d일 · 보증금 %s원 · 수수료 %s원(건당)',
                $periodNote,
                $kindLabel,
                $accrued,
                number_format($reserve),
                number_format($fee)
            );

        // 신청 기록 + 사이클 점유를 한 트랜잭션으로 — 중간 실패 시 점유가 남지 않게 한다.
        require_once INC_PATH . '/WithdrawalCycles.php';
        $newId = db_transaction(static function () use (
            $hasAccruedCol, $riderId, $payout, $balance, $reserve, $fee, $accrued, $rider, $note, $picked
        ): int {
            if ($hasAccruedCol) {
                $id = db_insert(
                    'INSERT INTO withdrawal_requests
                        (rider_id, agency_id, kind, amount, gross_amount,
                         withhold_min_retain, withhold_other, accrued_days,
                         bank_code, bank_account, account_holder,
                         status, note, requested_at)
                     VALUES (?, ?, \'rider_manual\', ?, ?,
                             ?, ?, ?,
                             ?, ?, ?,
                             \'pending\', ?, NOW())',
                    [
                        $riderId,
                        (int) ($rider['agency_id'] ?? 0) ?: null,
                        $payout,
                        $balance,
                        $reserve,
                        $fee,
                        $accrued,
                        (string) $rider['bank_code'],
                        (string) $rider['bank_account'],
                        (string) ($rider['account_holder'] ?: $rider['name']),
                        $note,
                    ]
                );
            } else {
                $id = db_insert(
                    'INSERT INTO withdrawal_requests
                        (rider_id, agency_id, kind, amount, gross_amount,
                         withhold_min_retain, withhold_other,
                         bank_code, bank_account, account_holder,
                         status, note, requested_at)
                     VALUES (?, ?, \'rider_manual\', ?, ?,
                             ?, ?,
                             ?, ?, ?,
                             \'pending\', ?, NOW())',
                    [
                        $riderId,
                        (int) ($rider['agency_id'] ?? 0) ?: null,
                        $payout,
                        $balance,
                        $reserve,
                        $fee,
                        (string) $rider['bank_code'],
                        (string) $rider['bank_account'],
                        (string) ($rider['account_holder'] ?: $rider['name']),
                        $note,
                    ]
                );
            }

            // §7 #18 — 이번 출금이 소진하는 사이클을 연결하고 점유(이중 출금 방지)
            WithdrawalCycles::attach($id, $picked);

            return $id;
        });

        $row = db_row(
            'SELECT wr.*, r.name AS rider_name, r.rider_code, sc.label AS bank_label
             FROM withdrawal_requests wr
             INNER JOIN riders r ON r.id = wr.rider_id
             LEFT JOIN system_codes sc ON sc.category = \'bank\' AND sc.code = wr.bank_code
             WHERE wr.id = ? LIMIT 1',
            [$newId]
        );

        if ($row === null) {
            throw new RuntimeException('출금 신청 저장 후 조회에 실패했습니다.');
        }

        return self::mapRow($row);
    }

    private static function hasAccruedDaysColumn(): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        if (!db_table_exists('withdrawal_requests')) {
            $cache = false;

            return false;
        }
        $cols = array_column(db_rows('SHOW COLUMNS FROM withdrawal_requests'), 'Field');
        $cache = in_array('accrued_days', $cols, true);

        return $cache;
    }
}
