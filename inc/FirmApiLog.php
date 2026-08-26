<?php

declare(strict_types=1);

require_once __DIR__ . '/PgApiLog.php';

/**
 * 펌뱅킹 API 호출 이력 — 요청/응답을 남겨 이체 문제를 사후에 추적한다.
 *
 * PG 로그(`PgApiLog`)와 테이블을 나눈 이유: 조회 화면과 보관 주기가 다르고,
 * 결제 이력에 이체가 섞이면 둘 다 읽기 어려워진다. 다만 **마스킹 규칙은 공유한다** —
 * `PgApiLog::mask()` 한 곳에서만 관리해야 언젠가 한쪽만 빠뜨리는 일이 없다.
 *
 * 🔒 저장하는 건 **복호화된 평문**이다(암호문을 남기면 사후 추적이 불가능하다).
 *    대신 계좌번호는 뒤 4자리만 남기고, 키·토큰은 길이만 남긴다.
 */
final class FirmApiLog
{
    /** 저장할 본문 최대 길이 — 100건 배치 요청이 로그로 DB를 잡아먹지 않게 자른다. */
    private const MAX_BODY = 6000;

    /** 펌뱅킹 고유 민감 키 — PG 목록에 없는 것만 추가로 가린다. */
    private const EXTRA_SECRET_KEYS = ['accountnumber', 'account_number', 'secret_key', 'secretkey', 'access_token'];

    public static function tableExists(): bool
    {
        return db_table_exists('firm_api_logs');
    }

    /**
     * 계좌번호는 뒤 4자리만 남긴다 — 대사할 때 "어느 계좌였나"는 알아야 한다.
     *
     * @param array<mixed> $data
     * @return array<mixed>
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
            if (in_array($key, self::EXTRA_SECRET_KEYS, true)) {
                $s = trim((string) $v);
                if ($s === '') {
                    $out[$k] = '';
                } elseif (str_contains($key, 'account')) {
                    $digits  = preg_replace('/\D/', '', $s) ?? '';
                    $out[$k] = $digits === '' ? '***' : str_repeat('*', max(0, strlen($digits) - 4)) . substr($digits, -4);
                } else {
                    $out[$k] = '*** (' . mb_strlen($s) . '자)';
                }
                continue;
            }
            $out[$k] = $v;
        }

        // PG 쪽 규칙(카드번호·pay_key·bill_key 등)도 함께 적용한다.
        return PgApiLog::mask($out);
    }

    /**
     * 호출 1건 기록. **로그 실패가 이체를 막으면 안 된다** — 예외를 삼키고 error_log 로만 남긴다.
     *
     * @param array<mixed> $request
     * @param array<mixed> $response
     */
    public static function record(
        string $endpoint,
        string $method,
        array $request,
        array $response,
        int $httpCode,
        string $resultCode,
        string $resultMsg,
        int $durationMs,
        bool $ok,
        string $ref = ''
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
                'INSERT INTO firm_api_logs
                    (endpoint, method, ref, http_code, result_code, result_msg, ok, duration_ms, request_body, response_body)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    mb_substr($endpoint, 0, 120),
                    mb_substr($method, 0, 10),
                    mb_substr($ref, 0, 60),
                    $httpCode,
                    mb_substr($resultCode, 0, 40),
                    mb_substr($resultMsg, 0, 300),
                    $ok ? 1 : 0,
                    $durationMs,
                    $enc($request),
                    $enc($response),
                ]
            );
        } catch (Throwable $e) {
            error_log('[FirmApiLog] 기록 실패: ' . $e->getMessage());
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
        $ref = trim((string) ($filters['ref'] ?? ''));
        if ($ref !== '') {
            $where[]  = 'ref LIKE ?';
            $params[] = '%' . $ref . '%';
        }

        return db_rows(
            'SELECT * FROM firm_api_logs'
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
        $r = db_row('SELECT COUNT(*) t, SUM(ok = 0) f, COALESCE(ROUND(AVG(duration_ms)), 0) a FROM firm_api_logs') ?: [];

        return [
            'total'  => (int) ($r['t'] ?? 0),
            'fail'   => (int) ($r['f'] ?? 0),
            'avg_ms' => (int) ($r['a'] ?? 0),
        ];
    }
}
