<?php

declare(strict_types=1);

/**
 * 정산 반영 — 백그라운드 작업 + 진행 상황 조회 (2026-09-17)
 *
 * 갑: "뭐가 멈춘거 같은 느낌도 안나고 프로세스에 문제가 없어?"
 *
 * 예전엔 「정산 반영」 한 번의 HTTP 요청 안에서 반영 → PG 조달(라이더 수만큼 카드 결제) →
 * 자동출금 → 알림톡을 **끝까지** 돌렸다. 라이더가 많으면 화면이 한참 멈춰 있었고,
 * 두 명이 거의 동시에 누르면 「이미 결제됐나」 확인과 결제 사이 틈으로 **이중 결제**가 날 수 있었다.
 *
 * 지금은:
 *   1. start()  — 작업 행을 만들고 워커 프로세스(`scripts/settlement_apply_worker.php`)를 띄운 뒤 바로 응답.
 *   2. run()    — 워커가 작업을 «선점»(queued→running)하고 DB 잠금(GET_LOCK)을 잡은 채 기존과 **같은 순서**로 처리.
 *   3. status() — 팝업이 1~2초마다 호출. 라이더별 결제·이체 상태를 DB 에서 그대로 읽는다.
 *
 * 안전장치
 *  - **업로드당 동시에 하나만.** GET_LOCK 은 커넥션에 묶여 있어 워커가 죽으면 자동으로 풀린다.
 *    잠금이 없는데 running 으로 남은 작업은 «중단됨»으로 보고 새로 시작할 수 있다.
 *  - **선점은 원자적 UPDATE.** 워커와 폴백(인라인 실행)이 동시에 떠도 한쪽만 처리한다.
 *  - 창을 닫아도 서버 작업은 계속된다. 다시 열면 진행 중인 작업을 이어서 보여준다.
 *  - 중간에 죽어도 재반영하면 이미 반영·결제된 건은 건너뛴다(applyUpload·fundAppliedUpload 가 원래 멱등).
 */
final class SettlementApplyJob
{
    /** 워커가 이 시간 안에 작업을 선점하지 않으면 요청 안에서 직접 처리한다(exec 불가 환경 대비). */
    private const SPAWN_GRACE_SECONDS = 4;

    /** 같은 업로드의 앞선 작업이 끝나길 기다리는 최대 시간(초). */
    private const LOCK_WAIT_SECONDS = 900;

    public static function tableExists(): bool
    {
        return db_table_exists('settlement_apply_jobs');
    }

    private static function lockName(int $uploadId): string
    {
        return 'settle_apply_' . DB_NAME . '_' . $uploadId;
    }

    /** 이 업로드를 지금 누가 처리 중인가 — 잠금이 실제로 잡혀 있어야 «진행 중»이다. */
    private static function lockHeld(int $uploadId): bool
    {
        return db_row('SELECT IS_USED_LOCK(?) AS c', [self::lockName($uploadId)])['c'] !== null;
    }

    /** @return array<string,mixed>|null */
    public static function find(int $jobId): ?array
    {
        return db_row('SELECT * FROM settlement_apply_jobs WHERE id = ? LIMIT 1', [$jobId]);
    }

    /**
     * 작업 시작. 이미 진행 중인 작업이 있으면 **새로 만들지 않고** 그 작업을 돌려준다.
     *
     * @return array{job_id:int, reused:bool}
     */
    public static function start(int $uploadId, ?int $adminId): array
    {
        if (!self::tableExists()) {
            throw new RuntimeException('settlement_apply_jobs 테이블이 없습니다. php migrate.php 를 실행하세요.');
        }

        // 「진행 중 작업 확인 → 새 작업 등록」 사이에 두 명이 끼어들지 못하게 짧게 잠근다.
        $startLock = 'settle_apply_start_' . DB_NAME . '_' . $uploadId;
        db_row('SELECT GET_LOCK(?, 10) AS g', [$startLock]);
        try {
            $active = db_row(
                "SELECT * FROM settlement_apply_jobs
                  WHERE upload_id = ? AND status IN ('queued','running')
                  ORDER BY id DESC LIMIT 1",
                [$uploadId]
            );
            if ($active !== null) {
                $fresh = (string) $active['status'] === 'queued'
                    && strtotime((string) $active['created_at']) > time() - self::SPAWN_GRACE_SECONDS * 3;
                if ($fresh || self::lockHeld($uploadId)) {
                    return ['job_id' => (int) $active['id'], 'reused' => true];
                }
                // 잠금이 없다 = 처리하던 프로세스가 죽었다. 기록만 정리하고 새로 시작한다.
                self::finish((int) $active['id'], 'failed', '처리 중단됨 — 서버 프로세스가 끝나지 않았습니다. 다시 반영하면 남은 건만 처리합니다.', null);
            }

            $jobId = db_insert(
                "INSERT INTO settlement_apply_jobs (upload_id, status, stage, started_by, created_at, updated_at)
                 VALUES (?, 'queued', 'queued', ?, NOW(), NOW())",
                [$uploadId, $adminId]
            );

            return ['job_id' => $jobId, 'reused' => false];
        } finally {
            db_row('SELECT RELEASE_LOCK(?) AS r', [$startLock]);
        }
    }

    /**
     * 워커 프로세스를 띄운다. 띄우지 못했거나 제때 선점하지 않으면 **이 요청 안에서 직접** 처리한다
     * (기존 동작과 같음 — 화면은 끝난 뒤 결과를 보여준다). 어느 쪽이든 선점은 한 번만 성공한다.
     */
    public static function dispatch(int $jobId): void
    {
        $spawned = self::spawn($jobId);

        if ($spawned) {
            $until = microtime(true) + self::SPAWN_GRACE_SECONDS;
            while (microtime(true) < $until) {
                usleep(250_000);
                $st = (string) (self::find($jobId)['status'] ?? '');
                if ($st !== 'queued') {
                    return;   // 워커가 가져갔다
                }
            }
            // 아직 queued 인데 이 업로드 잠금이 잡혀 있으면 앞선 작업이 도는 중이고,
            // 워커는 그게 끝나길 기다리고 있다 — 여기서 또 처리하면 요청만 오래 붙잡는다.
            $uploadId = (int) (self::find($jobId)['upload_id'] ?? 0);
            if ($uploadId > 0 && self::lockHeld($uploadId)) {
                return;
            }
        }

        // 폴백 — 응답을 늦추더라도 처리는 반드시 된다.
        @set_time_limit(0);
        ignore_user_abort(true);
        self::run($jobId);
    }

    private static function spawn(int $jobId): bool
    {
        if (!function_exists('exec') && !function_exists('popen')) {
            return false;
        }
        require_once INC_PATH . '/Deployer.php';
        $script = ROOT_PATH . '/scripts/settlement_apply_worker.php';

        try {
            if (PHP_OS_FAMILY === 'Windows') {
                // 개발 PC — start /B 로 떼어 낸다.
                $h = @popen('start "" /B php ' . escapeshellarg($script) . ' ' . $jobId . ' > NUL 2>&1', 'r');
                if ($h === false) {
                    return false;
                }
                pclose($h);

                return true;
            }
            $cmd = 'nohup ' . Deployer::phpCmd() . ' ' . escapeshellarg($script) . ' ' . $jobId
                 . ' > /dev/null 2>&1 &';
            @exec($cmd, $o, $code);

            return $code === 0;
        } catch (Throwable $e) {
            error_log('[SettlementApplyJob] spawn 실패 job#' . $jobId . ': ' . $e->getMessage());

            return false;
        }
    }

    /**
     * 실제 처리 — 워커와 폴백이 공통으로 부른다. 선점에 실패하면 아무것도 하지 않는다.
     */
    public static function run(int $jobId): void
    {
        $job = self::find($jobId);
        if ($job === null) {
            return;
        }
        $uploadId = (int) $job['upload_id'];

        // 잠금 먼저 — 같은 업로드를 다른 작업이 처리 중이면 **끝날 때까지 기다렸다가** 이어서 한다.
        // 그냥 포기하면 이 작업이 queued 로 남아 팝업이 계속 돈다. 기다린 뒤 다시 돌려도
        // 이미 반영·결제·출금된 건은 건너뛰므로(멱등) 같은 돈이 두 번 나가지 않는다.
        $got = (int) (db_row('SELECT GET_LOCK(?, ?) AS g', [self::lockName($uploadId), self::LOCK_WAIT_SECONDS])['g'] ?? 0);
        if ($got !== 1) {
            self::finish($jobId, 'failed', '다른 정산 반영이 오래 진행 중이라 시작하지 못했습니다. 잠시 후 다시 시도하세요.', null);

            return;
        }

        try {
            $claimed = db_execute(
                "UPDATE settlement_apply_jobs SET status = 'running', stage = 'apply', started_at = NOW(), updated_at = NOW()
                  WHERE id = ? AND status = 'queued'",
                [$jobId]
            );
            if ($claimed < 1) {
                return;   // 다른 쪽이 이미 가져갔다
            }

            self::actAs($job['started_by'] !== null ? (int) $job['started_by'] : null);

            $out = self::process($jobId, $uploadId, $job['started_by'] !== null ? (int) $job['started_by'] : null);
            self::finish($jobId, 'done', $out['message'], $out);
        } catch (Throwable $e) {
            self::finish($jobId, 'failed', $e->getMessage(), null);
            error_log('[SettlementApplyJob] job#' . $jobId . ' 실패: ' . $e->getMessage());
        } finally {
            db_row('SELECT RELEASE_LOCK(?) AS r', [self::lockName($uploadId)]);
        }
    }

    /**
     * 워커는 세션이 없다 — 버튼을 누른 관리자로 **같은 권한 범위**를 재현해야
     * 조직 스코프 판정과 감사 로그 주체가 웹에서 실행할 때와 똑같이 남는다.
     */
    private static function actAs(?int $adminId): void
    {
        if (PHP_SAPI !== 'cli' || $adminId === null || $adminId < 1) {
            return;   // 웹 폴백은 이미 로그인 세션 안이다
        }
        $a = db_row('SELECT id, login_id, name, role, org_id FROM admins WHERE id = ? LIMIT 1', [$adminId]);
        if ($a === null) {
            return;
        }
        $_SESSION['admin_auth']     = true;
        $_SESSION['admin_id']       = (int) $a['id'];
        $_SESSION['admin_login_id'] = (string) $a['login_id'];
        $_SESSION['admin_name']     = (string) $a['name'];
        $_SESSION['admin_role']     = (string) $a['role'];
        $_SESSION['admin_org_id']   = $a['org_id'] !== null ? (int) $a['org_id'] : 0;
        Org::clearCache();
    }

    private static function stage(int $jobId, string $stage): void
    {
        db_execute('UPDATE settlement_apply_jobs SET stage = ?, updated_at = NOW() WHERE id = ?', [$stage, $jobId]);
    }

    /** @param array<string,mixed>|null $result */
    private static function finish(int $jobId, string $status, string $message, ?array $result): void
    {
        db_execute(
            "UPDATE settlement_apply_jobs
                SET status = ?, stage = ?, message = ?, result_json = ?, finished_at = NOW(), updated_at = NOW()
              WHERE id = ?",
            [
                $status,
                $status,
                mb_substr($message, 0, 4000),
                $result !== null ? json_encode($result, JSON_UNESCAPED_UNICODE) : null,
                $jobId,
            ]
        );
    }

    /**
     * 정산 반영 본체 — 예전 `admin/api/settlement_apply.php` 의 처리 순서를 **그대로** 옮겼다.
     * ⚠️ 순서: 정산 반영 → PG 조달(대리점 지갑 충전) → 라이더 출금. 돈이 대리점→라이더로
     *    흐르므로 조달이 출금보다 먼저여야 한다.
     *
     * @return array<string,mixed>
     */
    private static function process(int $jobId, int $uploadId, ?int $adminId): array
    {
        require_once INC_PATH . '/SettlementLedger.php';
        require_once INC_PATH . '/AuditLog.php';
        require_once INC_PATH . '/PgPayment.php';
        require_once INC_PATH . '/DailyAutoWithdrawal.php';
        require_once INC_PATH . '/RiderStatement.php';

        $agencyId = (int) (db_row('SELECT agency_id FROM settlement_uploads WHERE id = ? LIMIT 1', [$uploadId])['agency_id'] ?? 0);

        // ── ① 정산 반영 ──
        $result = SettlementLedger::applyUpload($uploadId, $adminId);
        if ($result['applied'] === 0 && $result['errors'] !== []) {
            throw new InvalidArgumentException(implode(' / ', $result['errors']));
        }
        AuditLog::record(
            'settlement.apply',
            (string) $uploadId,
            "정산 반영 {$result['applied']}명 · 건너뜀 {$result['skipped']}명"
        );

        // ── ② 자금 조달(PG 카드결제) — 플랫폼 수수료가 여기서 발생한다 ──
        self::stage($jobId, 'fund');
        $fund = PgPayment::fundAppliedUpload($uploadId, $agencyId, $adminId);
        if ($fund['charged'] > 0) {
            AuditLog::record(
                'settlement.pg_fund',
                (string) $uploadId,
                sprintf(
                    'PG 자금조달 %d건 · 조달 %s원 · 플랫폼수수료 %s원 · 실패 %d건',
                    $fund['charged'],
                    number_format($fund['funded']),
                    number_format($fund['fee']),
                    count($fund['failed'])
                )
            );
        }

        // ── ③ 일일정산 자동출금 ──
        // 대상은 "이번에 새로 반영된 사람"이 아니라 "미출금 정산분이 남은 사람" — 계좌를 뒤늦게
        // 등록하고 재반영했을 때 자동출금이 재시도돼야 하기 때문이다(runForUpload 주석 참고).
        self::stage($jobId, 'withdraw');
        $auto = DailyAutoWithdrawal::runForUpload($uploadId);
        $dailyTotal = (int) (db_row(
            'SELECT COUNT(DISTINCT dr.rider_id) AS cnt
               FROM settlement_daily_riders dr
               INNER JOIN riders r ON r.id = dr.rider_id
              WHERE dr.upload_id = ? AND r.is_daily_settlement = 1',
            [$uploadId]
        )['cnt'] ?? 0);
        if ($auto['targets'] > 0) {
            AuditLog::record(
                'settlement.auto_withdraw',
                (string) $uploadId,
                sprintf(
                    '일일정산 자동출금 대상 %d명 · 지급 %d명(%s원) · 실패 %d명 · 건너뜀 %d명',
                    $auto['targets'],
                    $auto['paid'],
                    number_format($auto['paid_amount']),
                    $auto['failed'],
                    $auto['skipped']
                )
            );
        }

        // ── ④ 일정산 명세서 알림톡 ──
        self::stage($jobId, 'statement');
        $stmt = RiderStatement::enqueueDailyStatements($uploadId, $agencyId, $adminId);
        if ($stmt['queued'] > 0) {
            AuditLog::record(
                'settlement.statement_alimtalk',
                (string) $uploadId,
                sprintf('일정산 명세서 알림톡 큐 적재 %d건 · 실패 %d건', $stmt['queued'], $stmt['skipped'])
            );
        }

        // ── 안내 문구 ──
        $message = "정산 반영 {$result['applied']}명 완료" . ($result['skipped'] > 0 ? " (건너뜀 {$result['skipped']}명)" : '');
        if ($result['applied'] === 0) {
            $message .= "\n(이미 반영된 건은 다시 반영되지 않습니다)";
        }
        if ($fund['skipped_reason'] !== '') {
            $message .= "\n\n자금 조달(플랫폼 수수료): " . $fund['skipped_reason'];
        } elseif ($fund['charged'] > 0) {
            $message .= sprintf(
                "\n\n자금 조달 %d건: %s원 충전 · 플랫폼 수수료 %s원 발생",
                $fund['charged'],
                number_format($fund['funded']),
                number_format($fund['fee'])
            );
            if ($fund['failed'] !== []) {
                $message .= ' · 실패 ' . count($fund['failed']) . '건';
            }
        }
        if ($auto['targets'] > 0) {
            $message .= sprintf(
                "\n\n일일정산 자동출금 (대상 %d명): 지급 %d명 (%s원)",
                $auto['targets'],
                $auto['paid'],
                number_format($auto['paid_amount'])
            );
            if ($auto['failed'] > 0) {
                $message .= " · 실패 {$auto['failed']}명";
            }
            if ($auto['skipped'] > 0) {
                $message .= " · 미지급 {$auto['skipped']}명";
            }
        } elseif ($dailyTotal > 0) {
            $message .= "\n\n일일정산 자동출금: 출금할 정산분이 남은 대상이 없습니다(이미 전부 지급됨).";
        }
        if ($stmt['queued'] > 0) {
            $message .= sprintf("\n\n일정산 명세서 알림톡: %d건 발송 대기(큐)", $stmt['queued']);
            if ($stmt['skipped'] > 0) {
                $message .= " · 실패 {$stmt['skipped']}건";
            }
        }

        return [
            'message'            => $message,
            'result'             => $result,
            'pg_fund'            => $fund,
            'auto_withdraw'      => $auto,
            'statement_alimtalk' => $stmt,
        ];
    }

    /**
     * 팝업 폴링 — 작업 상태 + 라이더별 행(결제·이체)을 DB 에서 그대로 읽는다.
     *
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function status(array $job): array
    {
        $uploadId = (int) $job['upload_id'];
        $status   = (string) $job['status'];

        // 처리 중인데 잠금이 없으면 프로세스가 죽은 것이다 — 팝업이 영원히 돌지 않게 정리한다.
        if ($status === 'running' && !self::lockHeld($uploadId)) {
            $again = self::find((int) $job['id']);
            if ($again !== null && (string) $again['status'] === 'running') {
                self::finish((int) $job['id'], 'failed', '처리 중단됨 — 서버 프로세스가 끝나지 않았습니다. 다시 반영하면 남은 건만 처리합니다.', null);
                $again = self::find((int) $job['id']);
            }
            $job    = $again ?? $job;
            $status = (string) $job['status'];
        }

        $result = $job['result_json'] !== null ? (array) json_decode((string) $job['result_json'], true) : [];

        // 자동출금 건너뜀·실패 사유는 DB 에 남지 않는 것이 있어 완료 결과에서 가져온다.
        $autoMsg = [];
        foreach ((array) ($result['auto_withdraw']['results'] ?? []) as $r) {
            $autoMsg[(int) ($r['rider_id'] ?? 0)] = [(string) ($r['status'] ?? ''), (string) ($r['message'] ?? '')];
        }

        $since = (string) ($job['started_at'] ?? $job['created_at']);
        $rows  = [];
        foreach (db_rows(
            "SELECT r.id AS rider_id, r.name, r.is_daily_settlement,
                    c.gross_amount + c.support_amount AS settle,
                    p.total_charged, p.status AS pay_status, p.fail_reason,
                    k.alias, k.brand, k.last4,
                    w.status AS wd_status, w.amount AS wd_amount, w.fail_reason AS wd_fail
               FROM settlement_rider_cycles c
               INNER JOIN riders r ON r.id = c.rider_id
               LEFT JOIN pg_payments p ON p.id = (
                    SELECT MAX(p2.id) FROM pg_payments p2
                     WHERE p2.upload_id = c.upload_id AND p2.rider_id = c.rider_id)
               LEFT JOIN agency_cards k ON k.id = p.card_id
               LEFT JOIN withdrawal_requests w ON w.id = (
                    SELECT MAX(w2.id) FROM withdrawal_requests w2
                     WHERE w2.rider_id = c.rider_id AND w2.requested_at >= ?)
              WHERE c.upload_id = ?
              ORDER BY r.name",
            [$since, $uploadId]
        ) as $x) {
            $card = trim((string) ($x['alias'] ?? ''));
            if ($card === '' && ($x['last4'] ?? '') !== '') {
                $card = trim((string) $x['brand'] . ' ****' . (string) $x['last4']);
            }

            $pay = match ((string) ($x['pay_status'] ?? '')) {
                'success'  => ['done', '결제완료'],
                'failed'   => ['fail', '결제실패' . (($x['fail_reason'] ?? '') !== '' ? ' · ' . $x['fail_reason'] : '')],
                'canceled' => ['warn', '결제취소'],
                default    => in_array($status, ['done', 'failed'], true)
                    ? ['idle', '미결제']
                    : ((string) $job['stage'] === 'fund' ? ['busy', '결제 대기'] : ['idle', '대기']),
            };

            $wd = null;
            if ((int) $x['is_daily_settlement'] === 1) {
                $wd = match ((string) ($x['wd_status'] ?? '')) {
                    'completed'                => ['done', '이체완료'],
                    'transferring', 'pending',
                    'downloaded'               => ['busy', '이체 접수'],
                    'failed', 'rejected'       => ['fail', '이체실패' . (($x['wd_fail'] ?? '') !== '' ? ' · ' . $x['wd_fail'] : '')],
                    default                    => null,
                };
                if ($wd === null) {
                    $m = $autoMsg[(int) $x['rider_id']] ?? null;
                    $wd = $m !== null && $m[0] === 'skipped'
                        ? ['idle', '출금 안 함 · ' . $m[1]]
                        : ($status === 'done' ? null : ['idle', '출금 대기']);
                }
            }

            $rows[] = [
                'rider_id' => (int) $x['rider_id'],
                'name'     => (string) $x['name'],
                'amount'   => (int) $x['settle'],
                'charged'  => $x['total_charged'] !== null ? (int) $x['total_charged'] : null,
                'card'     => $card,
                'pay'      => ['state' => $pay[0], 'label' => $pay[1]],
                'transfer' => $wd !== null ? ['state' => $wd[0], 'label' => $wd[1]] : null,
            ];
        }

        $paidOrFailed = count(array_filter($rows, static fn (array $r): bool => in_array($r['pay']['state'], ['done', 'fail', 'warn'], true)));
        $transferring = count(array_filter($rows, static fn (array $r): bool => ($r['transfer']['state'] ?? '') === 'busy'));

        return [
            'job_id'       => (int) $job['id'],
            'status'       => $status,          // queued | running | done | failed
            'stage'        => (string) $job['stage'],
            'message'      => (string) ($job['message'] ?? ''),
            'rows'         => $rows,
            'total'        => count($rows),
            'processed'    => $paidOrFailed,
            'transferring' => $transferring,    // 끝난 뒤에도 이체 결과(웹훅)를 기다리는 건수
        ];
    }
}
