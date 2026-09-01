<?php

declare(strict_types=1);

require_once __DIR__ . '/MessageDeliveryException.php';
require_once __DIR__ . '/MessagingConfig.php';

/**
 * 문자(SMS)·알림톡 발송 큐 (2026-09-01 갑).
 *
 * 필요한 내용을 큐에 쌓아 두고, 관리자가 발송(또는 예약 발송)한다. 실제 발송사(SMS/알림톡)는
 * 아직 계약 전이라 **모의(mock) 게이트웨이**로 동작한다 — 발송을 누르면 "보냄"으로 처리되고
 * 가짜 발송 ref 를 남긴다. 실 연동 시 `deliver()` 한 곳만 실제 API 호출로 바꾸면 된다
 * (PG·펌뱅킹의 mock 패턴과 동일).
 *
 * **알림톡 SMS 대체발송**(2026-09-02): 알림톡이 카카오 수신불가(미설치·차단·미사용자 등)로
 * 실패하면 같은 내용을 SMS 로 자동 재발송한다(`FALLBACK_REASONS`·`messaging_config.alimtalk_fallback_sms`).
 */
final class MessageQueue
{
    public const CHANNELS = ['sms' => '문자(SMS)', 'alimtalk' => '알림톡'];
    public const STATUSES = [
        'queued'   => '대기',
        'sending'  => '발송중',
        'sent'     => '발송완료',
        'failed'   => '실패',
        'canceled' => '취소',
    ];

    /**
     * SMS 대체발송 대상이 되는 알림톡 실패 사유 — **카카오로는 받을 수 없는** 경우.
     * 실 발송사 연동 시 응답 코드를 이 키로 매핑해 MessageDeliveryException(fallbackEligible=true)을 던진다.
     */
    public const FALLBACK_REASONS = [
        'not_kakao_user'      => '카카오톡 미사용자',
        'kakao_not_installed' => '카카오톡 미설치',
        'kakao_blocked'       => '채널 차단',
        'kakao_disabled'      => '알림톡 수신거부',
        'kakao_timeout'       => '카카오 전송 시간초과',
    ];

    /**
     * 발송 게이트웨이 훅. null 이면 mock(항상 성공). 실 연동/테스트에서
     * `fn(array $msg): array{provider:string,ref:string}` 를 주입한다(실패 시 MessageDeliveryException).
     *
     * @var (callable(array<string,mixed>):array{provider:string,ref:string})|null
     */
    public static $deliverHook = null;

    public static function ready(): bool
    {
        return db_table_exists('message_queue');
    }

    /**
     * 큐에 메시지 적재.
     *
     * @param array{channel?:string, rider_id?:int, recipient_name?:string, recipient_phone:string,
     *              title?:string, content:string, scheduled_at?:string} $data
     */
    public static function enqueue(array $data, ?int $adminId = null, ?int $fallbackFrom = null): int
    {
        if (!self::ready()) {
            throw new RuntimeException('message_queue 테이블이 없습니다. php migrate.php 를 실행하세요.');
        }
        $channel = isset($data['channel']) && isset(self::CHANNELS[$data['channel']]) ? (string) $data['channel'] : 'sms';
        $phone   = preg_replace('/[^0-9]/', '', (string) ($data['recipient_phone'] ?? '')) ?? '';
        if ($phone === '' || strlen($phone) < 9) {
            throw new InvalidArgumentException('받는 전화번호가 올바르지 않습니다.');
        }
        $content = trim((string) ($data['content'] ?? ''));
        if ($content === '') {
            throw new InvalidArgumentException('보낼 내용을 입력하세요.');
        }
        $scheduled = null;
        if (!empty($data['scheduled_at']) && preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', (string) $data['scheduled_at'])) {
            $scheduled = str_replace('T', ' ', substr((string) $data['scheduled_at'], 0, 16)) . ':00';
        }

        // fallback_from 컬럼이 있을 때만 채운다(마이그레이션 이전 호환).
        $hasFallbackCol = self::hasColumn('fallback_from');
        $cols = 'channel, rider_id, recipient_name, recipient_phone, title, content, status, scheduled_at, created_by, created_at'
              . ($hasFallbackCol ? ', fallback_from' : '');
        $ph   = '?, ?, ?, ?, ?, ?, \'queued\', ?, ?, NOW()' . ($hasFallbackCol ? ', ?' : '');
        $params = [
            $channel,
            (int) ($data['rider_id'] ?? 0) ?: null,
            mb_substr(trim((string) ($data['recipient_name'] ?? '')), 0, 80) ?: null,
            $phone,
            mb_substr(trim((string) ($data['title'] ?? '')), 0, 120) ?: null,
            mb_substr($content, 0, 2000),
            $scheduled,
            ($adminId !== null && $adminId > 0) ? $adminId : null,
        ];
        if ($hasFallbackCol) {
            $params[] = ($fallbackFrom !== null && $fallbackFrom > 0) ? $fallbackFrom : null;
        }

        return db_insert("INSERT INTO message_queue ({$cols}) VALUES ({$ph})", $params);
    }

    /** 컬럼 존재 여부 캐시(마이그레이션 이전 호환). */
    private static function hasColumn(string $col): bool
    {
        static $cache = [];
        if (!isset($cache[$col])) {
            $cache[$col] = self::ready()
                && in_array($col, array_column(db_rows('SHOW COLUMNS FROM message_queue'), 'Field'), true);
        }

        return $cache[$col];
    }

    /** 라이더에게 보낼 때 — 문자 수신용 번호(sms_phone) 우선, 없으면 기본 phone. */
    public static function enqueueForRider(int $riderId, string $channel, string $content, ?string $title = null, ?int $adminId = null): int
    {
        $r = db_row('SELECT id, name, phone, sms_phone FROM riders WHERE id = ? LIMIT 1', [$riderId]);
        if ($r === null) {
            throw new InvalidArgumentException('라이더를 찾을 수 없습니다.');
        }
        $phone = trim((string) ($r['sms_phone'] ?? '')) !== '' ? (string) $r['sms_phone'] : (string) $r['phone'];

        return self::enqueue([
            'channel'         => $channel,
            'rider_id'        => $riderId,
            'recipient_name'  => (string) $r['name'],
            'recipient_phone' => $phone,
            'title'           => $title ?? '',
            'content'         => $content,
        ], $adminId);
    }

    /**
     * @param array{status?:string, channel?:string, q?:string} $filters
     * @return list<array<string,mixed>>
     */
    public static function listQueue(array $filters = [], int $limit = 200): array
    {
        if (!self::ready()) {
            return [];
        }
        $where  = ['1=1'];
        $params = [];
        if (!empty($filters['status']) && isset(self::STATUSES[$filters['status']])) {
            $where[]  = 'status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['channel']) && isset(self::CHANNELS[$filters['channel']])) {
            $where[]  = 'channel = ?';
            $params[] = $filters['channel'];
        }
        if (!empty($filters['q'])) {
            $where[]  = '(recipient_phone LIKE ? OR recipient_name LIKE ? OR content LIKE ?)';
            $kw       = '%' . $filters['q'] . '%';
            $params[] = $kw; $params[] = $kw; $params[] = $kw;
        }
        $sql = 'SELECT * FROM message_queue WHERE ' . implode(' AND ', $where)
             . ' ORDER BY id DESC LIMIT ' . max(1, min(1000, $limit));

        return db_rows($sql, $params);
    }

    /** 상태별 건수. @return array<string,int> */
    public static function counts(): array
    {
        $out = array_fill_keys(array_keys(self::STATUSES), 0);
        if (!self::ready()) {
            return $out;
        }
        foreach (db_rows('SELECT status, COUNT(*) AS c FROM message_queue GROUP BY status') as $r) {
            $out[(string) $r['status']] = (int) $r['c'];
        }

        return $out;
    }

    /** 한 건 발송(대기·실패 건만). 성공하면 sent, 아니면 failed. */
    public static function send(int $id, ?int $adminId = null): array
    {
        if (!self::ready()) {
            throw new RuntimeException('message_queue 테이블이 없습니다.');
        }
        // 발송 대상으로 선점(동시 발송 방지) — queued/failed 만.
        $n = db_execute("UPDATE message_queue SET status='sending' WHERE id = ? AND status IN ('queued','failed')", [$id]);
        if ($n < 1) {
            $row = db_row('SELECT status FROM message_queue WHERE id = ? LIMIT 1', [$id]);

            throw new InvalidArgumentException('발송할 수 있는 상태가 아닙니다. (현재: ' . self::statusLabel((string) ($row['status'] ?? '')) . ')');
        }
        $msg = db_row('SELECT * FROM message_queue WHERE id = ? LIMIT 1', [$id]);

        try {
            $res = self::deliver($msg);
            db_execute(
                "UPDATE message_queue SET status='sent', provider=?, provider_ref=?, error=NULL, sent_at=NOW() WHERE id = ?",
                [$res['provider'], $res['ref'], $id]
            );
            self::logAttempt($msg, 'sent', $res['provider'], $res['ref'], null, null, $adminId);

            return ['ok' => true, 'id' => $id, 'ref' => $res['ref']];
        } catch (Throwable $e) {
            $reason   = $e instanceof MessageDeliveryException ? $e->reasonCode : '';
            $eligible = $e instanceof MessageDeliveryException && $e->fallbackEligible;
            $err      = mb_substr(($reason !== '' ? '[' . $reason . '] ' : '') . $e->getMessage(), 0, 250);
            db_execute("UPDATE message_queue SET status='failed', error=? WHERE id = ?", [$err, $id]);
            self::logAttempt($msg, 'failed', null, null, $err, $reason ?: null, $adminId);

            // 알림톡이 카카오 수신불가로 실패 → SMS 대체발송.
            $fallbackId = $eligible ? self::maybeFallbackToSms($msg, $adminId) : null;

            return ['ok' => false, 'id' => $id, 'error' => $e->getMessage(), 'reason' => $reason, 'fallback_id' => $fallbackId];
        }
    }

    /**
     * 알림톡 수신불가 실패 → 같은 내용을 SMS 로 **대체발송**(2026-09-02 갑).
     * 조건: 원본이 알림톡 · 설정(alimtalk_fallback_sms) 켬 · 자신이 대체발송본이 아님 · 이미 만든 대체본 없음.
     * SMS 를 새로 큐에 넣고 즉시 발송한다. 실패해도 원본 처리는 이미 끝났다.
     *
     * @param array<string,mixed> $msg 실패한 원본 메시지 행
     * @return int|null 생성된 SMS message_queue.id (안 만들면 null)
     */
    private static function maybeFallbackToSms(array $msg, ?int $adminId): ?int
    {
        if ((string) ($msg['channel'] ?? '') !== 'alimtalk') {
            return null;
        }
        if (!empty($msg['fallback_from'])) {
            return null; // 이미 대체발송본(무한 루프 방지)
        }
        if (!MessagingConfig::alimtalkFallbackSms()) {
            return null;
        }
        $originId = (int) ($msg['id'] ?? 0);
        // 같은 원본으로 이미 대체 SMS 를 만들었으면 재사용(중복 방지).
        if (self::hasColumn('fallback_from') && $originId > 0) {
            $exists = db_row('SELECT id FROM message_queue WHERE fallback_from = ? ORDER BY id ASC LIMIT 1', [$originId]);
            if ($exists !== null) {
                return (int) $exists['id'];
            }
        }

        try {
            $smsId = self::enqueue([
                'channel'         => 'sms',
                'rider_id'        => (int) ($msg['rider_id'] ?? 0),
                'recipient_name'  => (string) ($msg['recipient_name'] ?? ''),
                'recipient_phone' => (string) ($msg['recipient_phone'] ?? ''),
                'title'           => (string) ($msg['title'] ?? ''),
                'content'         => (string) ($msg['content'] ?? ''),
            ], $adminId, $originId > 0 ? $originId : null);
        } catch (Throwable) {
            return null; // 번호 이상 등으로 SMS 조차 못 만들면 포기(원본 실패는 이미 기록됨).
        }

        // 즉시 발송(대기로 두지 않고 바로 시도). SMS 는 채널이 sms 라 다시 대체발송되지 않는다.
        self::send($smsId, $adminId);

        return $smsId;
    }

    /**
     * 발송 시도 1건을 append-only 로그에 기록(성공·실패 모두). 로그 기록 실패가 발송 처리를 막지 않는다.
     *
     * @param array<string,mixed> $msg message_queue 행
     */
    private static function logAttempt(array $msg, string $status, ?string $provider, ?string $ref, ?string $error, ?string $reasonCode, ?int $adminId): void
    {
        if (!db_table_exists('message_send_logs')) {
            return;
        }
        $hasReason = in_array('reason_code', array_column(db_rows('SHOW COLUMNS FROM message_send_logs'), 'Field'), true);
        try {
            $cols = 'message_id, channel, rider_id, recipient_name, recipient_phone, title, content, status, provider, provider_ref, error, attempted_by, attempted_at'
                  . ($hasReason ? ', reason_code' : '');
            $ph   = '?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()' . ($hasReason ? ', ?' : '');
            $params = [
                (int) ($msg['id'] ?? 0) ?: null,
                (string) ($msg['channel'] ?? 'sms'),
                (int) ($msg['rider_id'] ?? 0) ?: null,
                ($msg['recipient_name'] ?? null) !== null ? mb_substr((string) $msg['recipient_name'], 0, 80) : null,
                (string) ($msg['recipient_phone'] ?? ''),
                ($msg['title'] ?? null) !== null ? mb_substr((string) $msg['title'], 0, 120) : null,
                mb_substr((string) ($msg['content'] ?? ''), 0, 2000),
                $status === 'sent' ? 'sent' : 'failed',
                $provider,
                $ref,
                $error !== null ? mb_substr($error, 0, 250) : null,
                ($adminId !== null && $adminId > 0) ? $adminId : null,
            ];
            if ($hasReason) {
                $params[] = $reasonCode !== null ? mb_substr($reasonCode, 0, 40) : null;
            }
            db_insert("INSERT INTO message_send_logs ({$cols}) VALUES ({$ph})", $params);
        } catch (Throwable) {
            // 로그 적재 실패는 무시(발송 자체는 이미 처리됨).
        }
    }

    /**
     * 발송 로그 조회(append-only). 최신순.
     *
     * @param array{from?:string, to?:string, channel?:string, status?:string, q?:string} $filters
     * @return list<array<string,mixed>>
     */
    public static function listLogs(array $filters = [], int $limit = 300): array
    {
        if (!db_table_exists('message_send_logs')) {
            return [];
        }
        $where  = ['1=1'];
        $params = [];
        if (!empty($filters['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $filters['from'])) {
            $where[]  = 'l.attempted_at >= ?';
            $params[] = $filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $filters['to'])) {
            $where[]  = 'l.attempted_at <= ?';
            $params[] = $filters['to'] . ' 23:59:59';
        }
        if (!empty($filters['channel']) && isset(self::CHANNELS[$filters['channel']])) {
            $where[]  = 'l.channel = ?';
            $params[] = $filters['channel'];
        }
        if (!empty($filters['status']) && in_array($filters['status'], ['sent', 'failed'], true)) {
            $where[]  = 'l.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['q'])) {
            $where[]  = '(l.recipient_phone LIKE ? OR l.recipient_name LIKE ? OR l.content LIKE ?)';
            $kw       = '%' . $filters['q'] . '%';
            $params[] = $kw; $params[] = $kw; $params[] = $kw;
        }
        $sql = 'SELECT l.*, a.name AS sender_name
                  FROM message_send_logs l
                  LEFT JOIN admins a ON a.id = l.attempted_by
                 WHERE ' . implode(' AND ', $where)
             . ' ORDER BY l.id DESC LIMIT ' . max(1, min(2000, $limit));

        return db_rows($sql, $params);
    }

    /**
     * 로그 상태별 건수(같은 필터 적용). @return array{sent:int, failed:int, total:int}
     *
     * @param array<string,mixed> $filters
     */
    public static function logCounts(array $filters = []): array
    {
        $out = ['sent' => 0, 'failed' => 0, 'total' => 0];
        if (!db_table_exists('message_send_logs')) {
            return $out;
        }
        // listLogs 와 동일 필터를 재사용하려 status 는 제외하고 집계
        $f = $filters;
        unset($f['status']);
        foreach (self::listLogs($f, 2000) as $r) {
            $s = (string) $r['status'];
            if ($s === 'sent' || $s === 'failed') {
                $out[$s]++;
                $out['total']++;
            }
        }

        return $out;
    }

    /** 대기 중(예약 시각 지난 것 포함) 일괄 발송. */
    public static function processQueued(int $limit = 100, ?int $adminId = null): array
    {
        if (!self::ready()) {
            return ['sent' => 0, 'failed' => 0];
        }
        $rows = db_rows(
            "SELECT id FROM message_queue
              WHERE status='queued' AND (scheduled_at IS NULL OR scheduled_at <= NOW())
              ORDER BY id ASC LIMIT " . max(1, min(500, $limit))
        );
        $sent = 0; $failed = 0;
        foreach ($rows as $r) {
            $res = self::send((int) $r['id'], $adminId);
            $res['ok'] ? $sent++ : $failed++;
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    public static function cancel(int $id): bool
    {
        if (!self::ready()) {
            return false;
        }

        return db_execute("UPDATE message_queue SET status='canceled' WHERE id = ? AND status IN ('queued','failed')", [$id]) > 0;
    }

    public static function channelLabel(string $c): string
    {
        return self::CHANNELS[$c] ?? $c;
    }

    public static function statusLabel(string $s): string
    {
        return self::STATUSES[$s] ?? $s;
    }

    /**
     * 실제 발송. 기본은 **모의(mock)** — 실 SMS/알림톡 발송사 연동 전까지 항상 성공 처리하고 가짜 ref 반환.
     *
     * 실 연동/테스트는 `MessageQueue::$deliverHook` 로 주입한다(여기만 교체하는 seam). 알림톡이
     * 카카오 수신불가면 `MessageDeliveryException($reasonCode, true)` 를 던지면 상위(`send`)가 SMS 로
     * 대체발송한다. 그 외 실패는 `fallbackEligible=false` 로 던진다.
     *
     * @param array<string,mixed> $msg
     * @return array{provider:string, ref:string}
     */
    private static function deliver(array $msg): array
    {
        if (self::$deliverHook !== null) {
            return (self::$deliverHook)($msg); // 실패 시 MessageDeliveryException 을 던짐
        }

        // 모의(mock): 항상 성공.
        return [
            'provider' => 'mock',
            'ref'      => 'MOCK-' . date('YmdHis') . '-' . str_pad((string) ($msg['id'] ?? 0), 5, '0', STR_PAD_LEFT),
        ];
    }
}
