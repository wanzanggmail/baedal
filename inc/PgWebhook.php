<?php

declare(strict_types=1);

require_once __DIR__ . '/PgConfig.php';

/**
 * PG(위루트) 결제통지 수신 — REF_PG_WEROUTE.md §5.
 *
 * ⚠️ **이 경로는 돈을 움직이지 않는다.**
 * 우리 결제는 요청→응답 동기 흐름이라(`PgPayment::chargeForRider`) 승인 응답을 받은 그 자리에서
 * 지갑이 이미 충전된다. 웹훅에서 또 충전하면 같은 결제가 두 번 반영된다. 그래서 여기서는
 * **받은 통지를 기록하고 기존 `pg_payments` 행에 붙이는 대사(확인)만** 한다. 우리 기록에 없는
 * 통지나 금액이 다른 통지는 `match_state`로 드러내 사람이 확인하게 한다.
 *
 * 응답 규격(문서 §5-4)을 반드시 지켜야 한다 — 어기면 **1분 간격으로 재전송**된다.
 *   - 성공 : HTTP 200 + body `{}`
 *   - 실패 : HTTP 200 외 + body `{"message":"사유"}`
 *
 * 재전송이 정상 동작이므로 `trx_id` UNIQUE로 멱등을 보장한다. 같은 건이 다시 와도
 * 새 행을 만들지 않고 그대로 200을 돌려준다(그래야 재전송이 멈춘다).
 */
final class PgWebhook
{
    /**
     * 결제통지 발신 서버 IP — 설정이 비어 있을 때의 기본값.
     *
     * ⚠️ 2026-08-25 정정 — `221.168.33.162` 는 잘못된 문서(위루트)의 값이었다.
     * 실제 문서(루트업)는 **`221.168.33.227`** 이다. 이걸 안 고치면 진짜 통지가
     * 전부 403 으로 막히고, PG 는 1분 간격으로 재전송만 계속한다.
     */
    public const DEFAULT_ALLOW_IP = '221.168.33.227';

    /** 우리 건을 식별하는 모듈 타입 — 4=빌링(문서 §5-5) */
    public const MODULE_BILLING = '4';

    public static function tableExists(): bool
    {
        return db_table_exists('pg_webhook_events');
    }

    /**
     * 허용 IP 목록. 설정이 비어 있으면 기본값 1개를 쓴다.
     *
     * @return list<string>
     */
    public static function allowedIps(): array
    {
        $raw = '';
        if (PgConfig::tableExists()) {
            $raw = trim((string) (PgConfig::get()['noti_allow_ips'] ?? ''));
        }
        if ($raw === '') {
            $raw = self::DEFAULT_ALLOW_IP;
        }

        $out = [];
        foreach (preg_split('/[,\s]+/', $raw) ?: [] as $ip) {
            $ip = trim($ip);
            if ($ip !== '') {
                $out[] = $ip;
            }
        }

        return $out;
    }

    /**
     * 호출자 IP.
     *
     * ⚠️ X-Forwarded-For는 **신뢰하지 않는다** — 아무나 헤더로 넣어 보낼 수 있어
     * 그걸 믿으면 IP 검사가 무의미해진다. 리버스 프록시 뒤에 둘 거라면 그 프록시가
     * REMOTE_ADDR를 실제 원격 주소로 바꿔 주도록 서버에서 설정할 것.
     *
     * @param array<string,mixed> $server
     */
    public static function clientIp(array $server): string
    {
        return (string) ($server['REMOTE_ADDR'] ?? '');
    }

    public static function ipAllowed(string $ip): bool
    {
        $allow = self::allowedIps();
        if ($allow === []) {
            return true; // 목록을 비우면 검사를 끈다(사내망 테스트용)
        }

        return in_array($ip, $allow, true);
    }

    /**
     * 서명 검증 — `sha256("sign_key={값}&timestamp={값}&mid={값}")` (문서 §5-3).
     *
     * `sign_key`는 아직 PG사에서 못 받았다(REF §9-2). 설정이 비어 있으면 **검증을 건너뛰되**
     * 그 사실을 `verified=0`으로 남긴다 — 나중에 키를 받으면 자동으로 켜진다.
     *
     * @param array<string,mixed> $payload
     * @return array{skipped:bool, ok:bool}
     */
    public static function verifySignature(array $payload): array
    {
        $cfg     = PgConfig::tableExists() ? PgConfig::get() : [];
        $signKey = trim((string) ($cfg['sign_key'] ?? ''));
        if ($signKey === '') {
            return ['skipped' => true, 'ok' => false];
        }

        $expect = hash('sha256', sprintf(
            'sign_key=%s&timestamp=%s&mid=%s',
            $signKey,
            (string) ($payload['timestamp'] ?? ''),
            (string) ($payload['mid'] ?? '')
        ));
        $got = strtolower(trim((string) ($payload['signature'] ?? '')));

        return ['skipped' => false, 'ok' => $got !== '' && hash_equals($expect, $got)];
    }

    /**
     * 승인 요청 때 실어 보낸 `temp`를 되받아 우리 결제 id를 꺼낸다(문서 §5-6).
     * base64(JSON) 권장 형식이지만, 평문 JSON이나 숫자만 온 경우도 받아준다.
     */
    private static function paymentIdFromTemp(string $temp): int
    {
        $temp = trim($temp);
        if ($temp === '') {
            return 0;
        }
        if (ctype_digit($temp)) {
            return (int) $temp;
        }

        $decoded = base64_decode($temp, true);
        foreach ([$decoded, $temp] as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }
            $json = json_decode($candidate, true);
            if (is_array($json) && isset($json['payment_id'])) {
                return (int) $json['payment_id'];
            }
        }

        return 0;
    }

    /**
     * 통지를 우리 결제 기록에 붙인다. temp → ord_num → pg_tid 순으로 찾는다.
     *
     * @param array<string,mixed> $payload
     * @return array{payment_id:?int, state:string, note:string}
     */
    private static function matchPayment(array $payload): array
    {
        if (!db_table_exists('pg_payments')) {
            return ['payment_id' => null, 'state' => 'unmatched', 'note' => 'pg_payments 테이블 없음'];
        }

        $row = null;

        $pid = self::paymentIdFromTemp((string) ($payload['temp'] ?? ''));
        if ($pid > 0) {
            $row = db_row('SELECT id, total_charged, status FROM pg_payments WHERE id = ? LIMIT 1', [$pid]);
        }
        if ($row === null) {
            $ord = trim((string) ($payload['ord_num'] ?? ''));
            if ($ord !== '') {
                $row = db_row('SELECT id, total_charged, status FROM pg_payments WHERE ord_num = ? ORDER BY id DESC LIMIT 1', [$ord]);
            }
        }
        if ($row === null) {
            $tid = trim((string) ($payload['trx_id'] ?? ''));
            if ($tid !== '') {
                $row = db_row('SELECT id, total_charged, status FROM pg_payments WHERE pg_tid = ? ORDER BY id DESC LIMIT 1', [$tid]);
            }
        }

        if ($row === null) {
            return [
                'payment_id' => null,
                'state'      => 'unmatched',
                'note'       => '우리 결제 기록에서 찾지 못함 — 확인 필요',
            ];
        }

        $paymentId = (int) $row['id'];
        $amount    = (int) ($payload['amount'] ?? 0);
        $ours      = (int) $row['total_charged'];

        // 금액이 오면 대조한다. 안 오는 통지도 있을 수 있어 0이면 비교하지 않는다.
        if ($amount > 0 && $amount !== $ours) {
            return [
                'payment_id' => $paymentId,
                'state'      => 'mismatch',
                'note'       => sprintf('금액 불일치 — 통지 %s원 / 우리 기록 %s원', number_format($amount), number_format($ours)),
            ];
        }

        return ['payment_id' => $paymentId, 'state' => 'matched', 'note' => ''];
    }

    /**
     * 결제통지 1건 처리.
     *
     * @param array<string,mixed> $server
     * @return array{status:int, body:array<string,mixed>, stored:bool, note:string}
     */
    public static function handle(string $rawBody, array $server): array
    {
        $fail = static fn (int $code, string $msg): array => [
            'status' => $code,
            'body'   => ['message' => $msg],
            'stored' => false,
            'note'   => $msg,
        ];

        if (!self::tableExists()) {
            return $fail(503, 'pg_webhook_events 테이블이 없습니다. php migrate.php 를 실행하세요.');
        }

        $ip = self::clientIp($server);
        if (!self::ipAllowed($ip)) {
            // 허용 목록 밖 → 우리 PG가 보낸 게 아니다. 기록도 남기지 않는다(스팸으로 테이블이 부푼다).
            return $fail(403, '허용되지 않은 IP입니다.');
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return $fail(400, 'JSON 본문을 해석할 수 없습니다.');
        }

        $trxId = trim((string) ($payload['trx_id'] ?? ''));
        if ($trxId === '') {
            return $fail(400, 'trx_id 가 없습니다.');
        }

        // ── 멱등 ── 재전송은 정상 동작이다. 이미 받은 건이면 아무것도 하지 않고 성공을 돌려줘야
        //           재전송이 멈춘다. 여기서 실패를 주면 1분마다 영원히 다시 온다.
        $exists = db_row('SELECT id FROM pg_webhook_events WHERE trx_id = ? LIMIT 1', [$trxId]);
        if ($exists !== null) {
            return ['status' => 200, 'body' => [], 'stored' => false, 'note' => '이미 수신한 통지(멱등 처리)'];
        }

        $sig      = self::verifySignature($payload);
        $verified = (!$sig['skipped'] && $sig['ok']) ? 1 : 0;
        if (!$sig['skipped'] && !$sig['ok']) {
            // 서명이 틀리면 위조일 수 있다 — 저장하지 않고 거절한다.
            return $fail(401, '서명 검증에 실패했습니다.');
        }

        $moduleType = (string) ($payload['module_type'] ?? '');
        if ($moduleType !== '' && $moduleType !== self::MODULE_BILLING) {
            // 우리 건이 아니다(빌링 아님). 기록만 남기고 성공 처리한다 — 거절하면 재전송이 계속된다.
            $match = ['payment_id' => null, 'state' => 'ignored', 'note' => "module_type={$moduleType} — 빌링(4) 아님"];
        } else {
            $match = self::matchPayment($payload);
        }

        $note = (string) $match['note'];
        if ($sig['skipped']) {
            $note = trim($note . ' · sign_key 미설정으로 서명 검증 생략');
        }

        db_execute(
            'INSERT INTO pg_webhook_events
                (trx_id, ord_num, mid, module_type, amount, payment_id, match_state, verified, source_ip, raw_body, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $trxId,
                mb_substr(trim((string) ($payload['ord_num'] ?? '')), 0, 60),
                mb_substr(trim((string) ($payload['mid'] ?? '')), 0, 50),
                mb_substr($moduleType, 0, 10),
                (int) ($payload['amount'] ?? 0),
                $match['payment_id'],
                $match['state'],
                $verified,
                mb_substr($ip, 0, 45),
                mb_substr($rawBody, 0, 60000),
                mb_substr($note, 0, 300),
            ]
        );

        // 우리 기록과 어긋나도 **PG에게는 성공**을 돌려준다. 통지 자체는 정상 수신했고,
        // 여기서 실패를 주면 1분마다 재전송만 반복될 뿐 불일치가 해결되지 않는다.
        // 불일치는 match_state 로 남겨 사람이 확인한다.
        return ['status' => 200, 'body' => [], 'stored' => true, 'note' => $note];
    }
}
