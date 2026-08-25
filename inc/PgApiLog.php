<?php

declare(strict_types=1);

/**
 * PG API 호출 이력 — 요청/응답을 남겨 결제 문제를 사후에 추적한다.
 *
 * 실 연동에서 승인이 안 되거나 금액이 어긋났을 때, 우리가 무엇을 보냈고 PG가 무엇을
 * 돌려줬는지 없으면 원인을 못 찾는다. `pg_payments`는 **결과**만 남기므로(성공/실패·사유)
 * 그 사이의 대화는 여기에 남긴다.
 *
 * 🔒 **민감값은 절대 저장하지 않는다.**
 *   - `card_num`·`card_pw`·`auth_num` — 마스킹. 카드번호를 로그에 남기면 DB가 PCI-DSS
 *     범위로 들어온다.
 *   - `Authorization`(pay_key) — 헤더는 아예 저장하지 않는다.
 *   마스킹은 `mask()` 한 곳에서만 한다 — 여러 군데로 흩어지면 언젠가 빠뜨린다.
 */
final class PgApiLog
{
    /** 마스킹 대상 키 — 값 전체를 지운다(길이만 남긴다). */
    private const SECRET_KEYS = [
        'card_num', 'card_no', 'card_pw', 'auth_num',
        'pay_key', 'sign_key', 'api_key', 'enc_key', 'enc_iv',
        // 빌키는 카드번호가 아니지만 pay_key 와 합치면 **결제가 가능한 자격증명**이다.
        // 로그에 평문으로 남기면 로그 열람 권한이 곧 결제 권한이 된다.
        'bill_key', 'billkey', 'billing_key',
    ];

    /** 저장할 본문 최대 길이 — 응답이 커도 로그가 DB를 잡아먹지 않게 자른다. */
    private const MAX_BODY = 4000;

    public static function tableExists(): bool
    {
        return db_table_exists('pg_api_logs');
    }

    /**
     * 값을 알아볼 수는 있되 재사용은 못 하게 가린다.
     * 카드번호는 뒤 4자리만 남긴다 — 대사할 때 "어느 카드였나"는 알아야 한다.
     */
    private static function maskValue(string $key, string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (in_array($key, ['card_num', 'card_no'], true)) {
            $digits = preg_replace('/\D/', '', $value) ?? '';

            return $digits === '' ? '***' : str_repeat('*', max(0, strlen($digits) - 4)) . substr($digits, -4);
        }

        return '*** (' . mb_strlen($value) . '자)';
    }

    /**
     * 요청/응답 본문에서 민감값을 지운다. 중첩 배열도 훑는다.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function mask(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $key = is_string($k) ? strtolower($k) : (string) $k;
            if (is_array($v)) {
                $out[$k] = self::mask($v);
                continue;
            }
            if (!is_scalar($v) && $v !== null) {
                $out[$k] = '(비스칼라)';
                continue;
            }
            $out[$k] = in_array($key, self::SECRET_KEYS, true)
                ? self::maskValue($key, (string) $v)
                : $v;
        }

        return $out;
    }

    /**
     * 호출 1건 기록. **로그 실패가 결제를 막으면 안 된다** — 예외를 삼키고 error_log 로만 남긴다.
     *
     * @param array<string,mixed> $request
     * @param array<string,mixed> $response
     */
    public static function record(
        string $endpoint,
        string $method,
        array $request,
        array $response,
        int $httpCode,
        string $resultCd,
        string $resultMsg,
        int $durationMs,
        bool $ok,
        string $ordNum = ''
    ): void {
        try {
            if (!self::tableExists()) {
                return;
            }
            $enc = static fn (array $a): string => mb_substr(
                (string) json_encode(self::mask($a), JSON_UNESCAPED_UNICODE),
                0,
                self::MAX_BODY
            );

            db_execute(
                'INSERT INTO pg_api_logs
                    (endpoint, method, ord_num, http_code, result_cd, result_msg, ok, duration_ms, request_body, response_body)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    mb_substr($endpoint, 0, 120),
                    mb_substr($method, 0, 10),
                    mb_substr($ordNum, 0, 60),
                    $httpCode,
                    mb_substr($resultCd, 0, 20),
                    mb_substr($resultMsg, 0, 300),
                    $ok ? 1 : 0,
                    $durationMs,
                    $enc($request),
                    $enc($response),
                ]
            );
        } catch (Throwable $e) {
            error_log('[PgApiLog] 기록 실패: ' . $e->getMessage());
        }
    }

    /**
     * 최근 호출 목록.
     *
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public static function recent(array $filters = [], int $limit = 200): array
    {
        if (!self::tableExists()) {
            return [];
        }
        $where  = [];
        $params = [];

        $only = trim((string) ($filters['only'] ?? ''));
        if ($only === 'fail') {
            $where[] = 'ok = 0';
        } elseif ($only === 'ok') {
            $where[] = 'ok = 1';
        }
        $ord = trim((string) ($filters['ord_num'] ?? ''));
        if ($ord !== '') {
            $where[]  = 'ord_num LIKE ?';
            $params[] = '%' . $ord . '%';
        }

        return db_rows(
            'SELECT * FROM pg_api_logs'
            . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)),
            $params
        );
    }

    /** @return array{total:int, fail:int, avg_ms:int} */
    public static function stats(): array
    {
        if (!self::tableExists()) {
            return ['total' => 0, 'fail' => 0, 'avg_ms' => 0];
        }
        $r = db_row('SELECT COUNT(*) t, SUM(ok = 0) f, COALESCE(ROUND(AVG(duration_ms)), 0) a FROM pg_api_logs') ?: [];

        return [
            'total'  => (int) ($r['t'] ?? 0),
            'fail'   => (int) ($r['f'] ?? 0),
            'avg_ms' => (int) ($r['a'] ?? 0),
        ];
    }
}
