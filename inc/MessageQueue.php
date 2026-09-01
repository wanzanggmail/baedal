<?php

declare(strict_types=1);

/**
 * 문자(SMS)·알림톡 발송 큐 (2026-09-01 갑).
 *
 * 필요한 내용을 큐에 쌓아 두고, 관리자가 발송(또는 예약 발송)한다. 실제 발송사(SMS/알림톡)는
 * 아직 계약 전이라 **모의(mock) 게이트웨이**로 동작한다 — 발송을 누르면 "보냄"으로 처리되고
 * 가짜 발송 ref 를 남긴다. 실 연동 시 `deliver()` 한 곳만 실제 API 호출로 바꾸면 된다
 * (PG·펌뱅킹의 mock 패턴과 동일).
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
    public static function enqueue(array $data, ?int $adminId = null): int
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

        return db_insert(
            'INSERT INTO message_queue
                (channel, rider_id, recipient_name, recipient_phone, title, content, status, scheduled_at, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, \'queued\', ?, ?, NOW())',
            [
                $channel,
                (int) ($data['rider_id'] ?? 0) ?: null,
                mb_substr(trim((string) ($data['recipient_name'] ?? '')), 0, 80) ?: null,
                $phone,
                mb_substr(trim((string) ($data['title'] ?? '')), 0, 120) ?: null,
                mb_substr($content, 0, 2000),
                $scheduled,
                ($adminId !== null && $adminId > 0) ? $adminId : null,
            ]
        );
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

            return ['ok' => true, 'id' => $id, 'ref' => $res['ref']];
        } catch (Throwable $e) {
            db_execute("UPDATE message_queue SET status='failed', error=? WHERE id = ?", [mb_substr($e->getMessage(), 0, 250), $id]);

            return ['ok' => false, 'id' => $id, 'error' => $e->getMessage()];
        }
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
     * 실제 발송 — **모의(mock)**. 실 SMS/알림톡 발송사 연동 전까지 항상 성공 처리하고 가짜 ref 반환.
     * 실 연동 시 여기만 발송사 API 호출로 교체한다(실패 시 예외를 던지면 상위가 failed 로 기록).
     *
     * @param array<string,mixed> $msg
     * @return array{provider:string, ref:string}
     */
    private static function deliver(array $msg): array
    {
        // TODO(실연동): SMS/알림톡 발송사 API 호출. 지금은 모의.
        return [
            'provider' => 'mock',
            'ref'      => 'MOCK-' . date('YmdHis') . '-' . str_pad((string) ($msg['id'] ?? 0), 5, '0', STR_PAD_LEFT),
        ];
    }
}
