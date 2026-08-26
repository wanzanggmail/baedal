<?php

declare(strict_types=1);

require_once __DIR__ . '/FirmBankingGateway.php';
require_once __DIR__ . '/FirmConfig.php';
require_once __DIR__ . '/FirmApiLog.php';

/**
 * 바움P&S 펌뱅킹 실 연동 게이트웨이 — 매뉴얼 v1.1.8.
 *
 * ⚠️ **이 API 의 이체는 동기가 아니다.**
 *    `transfer-submission` 은 **접수(RECEPTION)** 만 응답하고, 실제 성공/실패는 나중에
 *    「계좌이체 처리결과 통보」(웹훅)로 온다:
 *
 *        RECEPTION → PROGRESS → NEED_CHECK → SUCCESS / FAILED / CANCELLED
 *
 *    그래서 `transfer()` 가 `TransferResult::ok()` 를 돌려줘도 **돈이 나간 게 아니다.**
 *    호출부가 이를 "완료"로 처리하면 접수만 된 건에 지갑이 깎인다. 접수 성공은
 *    `TransferResult::$txId` 에 `receptionId` 를 담아 돌려주고, **최종 확정은 웹훅**이 한다.
 *    호출부는 이에 맞춰 「접수중」(`transferring`)으로 두고 통보를 기다린다
 *    (`Withdrawal::executeTransfers()` → `FirmWebhook` / `FirmReconciler`).
 *
 * 🔐 `/auth/access_token` 을 제외한 **모든 요청/응답 Body 는 AES-256-CBC 로 암호화**된다
 *    (`BaumCrypto`). 우리 DB 저장 암호화(`Crypto`)와는 별개다.
 */
final class BaumFirmGateway implements FirmBankingGateway
{
    /** 접수만 된 상태 — 아직 돈이 나가지 않았다. */
    public const ST_RECEPTION  = 'RECEPTION';
    public const ST_PROGRESS   = 'PROGRESS';
    public const ST_NEED_CHECK = 'NEED_CHECK';
    public const ST_SUCCESS    = 'SUCCESS';
    public const ST_FAILED     = 'FAILED';
    public const ST_CANCELLED  = 'CANCELLED';

    /** 결과가 확정된 상태 — 더 기다릴 필요가 없다. */
    public const FINAL_STATES = [self::ST_SUCCESS, self::ST_FAILED, self::ST_CANCELLED];

    private int $timeout;

    public function __construct(int $timeoutSeconds = 20)
    {
        $this->timeout = max(5, $timeoutSeconds);
    }

    public function providerLabel(): string
    {
        return '바움P&S';
    }

    /**
     * 바움은 **접수만 즉시 응답**한다 — 성공/실패는 「계좌이체 처리결과 통보」로 온다.
     * 호출부가 이 값을 보고 "완료" 대신 "접수중" 으로 처리해야 한다.
     */
    public function isAsync(): bool
    {
        return true;
    }

    // ─────────────────────────────── 은행코드 ───────────────────────────────

    /**
     * 우리 은행코드(3자리 표준) → 바움 코드(`C` + 3자리).
     *
     * 매뉴얼 「은행 코드」 별첨과 대조해 **우리가 쓰는 13개 코드가 전부 매핑됨을 확인**했다.
     * 이미 `C` 로 시작하면 그대로 둔다(설정에서 바움 코드를 직접 넣은 경우 대비).
     */
    public static function bankCode(string $ourCode): string
    {
        $c = strtoupper(trim($ourCode));
        if ($c === '') {
            return '';
        }
        if (str_starts_with($c, 'C')) {
            return $c;
        }

        return 'C' . str_pad(preg_replace('/\D/', '', $c) ?? '', 3, '0', STR_PAD_LEFT);
    }

    /**
     * 이체 1건의 고유 ID. 바움은 이 값으로 중복 접수를 막고, 조회·취소에도 쓴다.
     *
     * 우리 출금 요청 id 를 앞에 두어 **로그만 보고도 어느 건인지 알 수 있게** 한다.
     * 재시도 때 같은 id 를 그대로 쓰면 `DUPLICATE_SUBMISSION` 으로 막히므로 뒤에 시각을 붙인다.
     */
    public static function makeTransactionId(string $kind, int $refId): string
    {
        return sprintf('%s%d-%s-%s', strtoupper(substr($kind, 0, 2)), $refId, date('YmdHis'), strtoupper(bin2hex(random_bytes(2))));
    }

    // ─────────────────────────────── 통신 ───────────────────────────────

    /**
     * Access Token — 캐시가 유효하면 재사용, 아니면 재발급.
     *
     * 발급 요청만 **암호화하지 않는다**(매뉴얼). 인증은 Basic base64("id:secret").
     */
    public function accessToken(bool $forceRenew = false): string
    {
        if (!$forceRenew) {
            $cached = FirmConfig::validAccessToken();
            if ($cached !== '') {
                return $cached;
            }
        }

        $cfg   = FirmConfig::get();
        $basic = base64_encode(trim((string) $cfg['client_id']) . ':' . trim((string) $cfg['secret_key']));
        $url   = FirmConfig::host((string) $cfg['env']) . FirmConfig::EP_TOKEN;

        $started = microtime(true);
        [$body, $http, $errNo, $errMsg] = $this->http('POST', $url, null, [
            'accept: application/json',
            'authorization: Basic ' . $basic,
        ]);
        $ms = (int) round((microtime(true) - $started) * 1000);

        $data = json_decode((string) $body, true);
        $data = is_array($data) ? $data : [];
        $tok  = trim((string) ($data['access_token'] ?? ''));
        $ok   = $errNo === 0 && $http >= 200 && $http < 300 && $tok !== '';

        FirmApiLog::record(
            FirmConfig::EP_TOKEN,
            'POST',
            ['client_id' => (string) $cfg['client_id'], 'secret_key' => (string) $cfg['secret_key']],
            $ok ? ['access_token' => $tok, 'expires_in' => $data['expires_in'] ?? null] : $data,
            $http,
            (string) ($data['errorCode'] ?? ''),
            $ok ? '토큰 발급' : (string) ($data['errorMessage'] ?? $errMsg),
            $ms,
            $ok
        );

        if (!$ok) {
            throw new RuntimeException('펌뱅킹 인증 실패: ' . ($data['errorMessage'] ?? ($errMsg !== '' ? $errMsg : 'HTTP ' . $http)));
        }

        FirmConfig::storeAccessToken($tok, (int) ($data['expires_in'] ?? 0));

        return $tok;
    }

    /**
     * 암호화 API 호출 — Body 를 암호화해 보내고, 응답을 복호화해 배열로 돌려준다.
     *
     * 토큰 만료(401)면 **한 번만** 재발급해 재시도한다. 무한 재시도는 하지 않는다 —
     * 이체 요청을 여러 번 보내면 중복 이체 위험이 있다(같은 transactionId 라 바움이
     * 막아주긴 하지만, 우리가 먼저 조심하는 게 맞다).
     *
     * @param array<mixed>|null $body
     * @return array{ok:bool, http:int, data:array<mixed>, error_code:string, error_message:string}
     */
    public function call(string $method, string $path, ?array $body = null, string $ref = '', bool $retryOn401 = true): array
    {
        $cfg    = FirmConfig::get();
        $crypto = FirmConfig::crypto();
        $url    = FirmConfig::host((string) $cfg['env']) . $path;
        $token  = $this->accessToken();

        $payload = $body === null ? null : $crypto->encryptJson($body);

        $started = microtime(true);
        [$raw, $http, $errNo, $errMsg] = $this->http($method, $url, $payload, [
            'accept: application/json',
            'Content-Type: application/json; charset=UTF-8',
            'authorization: Bearer ' . $token,
        ]);
        $ms = (int) round((microtime(true) - $started) * 1000);

        // 토큰 만료 — 한 번만 재발급 후 재시도.
        if ($retryOn401 && $http === 401) {
            $this->accessToken(true);

            return $this->call($method, $path, $body, $ref, false);
        }

        $data    = [];
        $decoded = '';
        if (is_string($raw) && trim($raw) !== '') {
            try {
                $data    = $crypto->decryptJson(trim($raw));
                $decoded = 'ok';
            } catch (Throwable $e) {
                // 암호문이 아닐 수도 있다(게이트웨이 오류 페이지·평문 에러 등) → 평문 JSON 도 시도.
                $plain = json_decode(trim($raw), true);
                if (is_array($plain)) {
                    $data    = $plain;
                    $decoded = 'plain';
                } else {
                    $decoded = '실패: ' . $e->getMessage();
                }
            }
        }

        $errCode = (string) ($data['errorCode'] ?? '');
        $errMsgR = (string) ($data['errorMessage'] ?? '');
        $ok      = $errNo === 0 && $http >= 200 && $http < 300 && ($data['success'] ?? false) === true;

        FirmApiLog::record(
            $path,
            $method,
            $body ?? [],
            $data !== [] ? $data : ['_raw' => mb_substr((string) $raw, 0, 500), '_decode' => $decoded],
            $http,
            $errCode,
            $errMsgR !== '' ? $errMsgR : ($errMsg !== '' ? $errMsg : ($ok ? '성공' : '응답 해석 실패')),
            $ms,
            $ok,
            $ref
        );

        return [
            'ok'            => $ok,
            'http'          => $http,
            'data'          => $data,
            'error_code'    => $errCode,
            'error_message' => $errMsgR !== '' ? $errMsgR : ($errMsg !== '' ? $errMsg : ''),
        ];
    }

    // ─────────────────────────────── 기능 ───────────────────────────────

    /**
     * 예금주 조회 — 계좌 등록 시 검증에 쓴다.
     *
     * @return array{ok:bool, holder:string, message:string, code:string}
     */
    public function accountHolder(string $ourBankCode, string $accountNo, int $amount = 0): array
    {
        $res = $this->call('POST', FirmConfig::EP_ACCOUNT_HOLDER, [
            'bankCode'          => self::bankCode($ourBankCode),
            'accountNumber'     => preg_replace('/\D/', '', $accountNo) ?? '',
            'transactionAmount' => $amount,
        ]);

        $d = is_array($res['data']['data'] ?? null) ? $res['data']['data'] : [];

        return [
            'ok'      => (bool) $res['ok'] && trim((string) ($d['accountHolder'] ?? '')) !== '',
            'holder'  => trim((string) ($d['accountHolder'] ?? '')),
            'message' => (string) ($d['resultMessage'] ?? $res['error_message']),
            'code'    => (string) ($d['resultCode'] ?? $res['error_code']),
        ];
    }

    /**
     * 잔액 조회(전체 포켓) — 이체 전 가용 금액 확인.
     *
     * @return array{ok:bool, total:int, pending:int, pockets:list<array<string,mixed>>, message:string}
     */
    public function balance(): array
    {
        $res = $this->call('GET', FirmConfig::EP_POCKETS);
        $d   = is_array($res['data']['data'] ?? null) ? $res['data']['data'] : [];

        $pockets = [];
        if (is_array($d['defaultPocket'] ?? null)) {
            $pockets[] = $d['defaultPocket'] + ['is_default' => true];
        }
        foreach (($d['additionalPocketList'] ?? []) as $p) {
            if (is_array($p)) {
                $pockets[] = $p + ['is_default' => false];
            }
        }

        return [
            'ok'      => (bool) $res['ok'],
            'total'   => (int) ($d['totalBalance'] ?? 0),
            'pending' => (int) ($d['totalWpAmount'] ?? 0),
            'pockets' => $pockets,
            'message' => (string) $res['error_message'],
        ];
    }

    /**
     * 계좌이체 접수 — **최대 100건 배치**.
     *
     * ⚠️ 성공 응답은 "접수됨" 이지 "이체됨" 이 아니다. 클래스 주석 참고.
     *
     * @param list<array<string,mixed>> $items 각 항목: transactionId, bankCode(우리코드), accountNumber, amount, ...
     * @return array{ok:bool, accepted:array<string,array<string,mixed>>, errors:array<string,array<string,mixed>>, message:string}
     */
    public function submit(array $items): array
    {
        if ($items === []) {
            return ['ok' => true, 'accepted' => [], 'errors' => [], 'message' => ''];
        }
        if (count($items) > FirmConfig::MAX_BATCH) {
            throw new InvalidArgumentException('한 번에 접수할 수 있는 이체는 ' . FirmConfig::MAX_BATCH . '건까지입니다.');
        }

        $pocket  = trim((string) FirmConfig::get()['pocket_code']);
        $payload = [];
        foreach ($items as $it) {
            $row = [
                'transactionId' => (string) $it['transactionId'],
                'bankCode'      => self::bankCode((string) $it['bankCode']),
                'accountNumber' => preg_replace('/\D/', '', (string) $it['accountNumber']) ?? '',
                'amount'        => (int) $it['amount'],
            ];
            // 선택 필드는 **값이 있을 때만** 넣는다. 빈 문자열을 보내면 예금주 검증이
            // 빈 이름으로 돌아 실패할 수 있다(매뉴얼: 입력 시 검증).
            foreach (['receiverMemo', 'memo', 'accountHolder', 'reservationTime', 'metadata'] as $opt) {
                $v = trim((string) ($it[$opt] ?? ''));
                if ($v !== '') {
                    $row[$opt] = $v;
                }
            }
            if ($pocket !== '') {
                $row['pocketCode'] = $pocket;
            }
            $payload[] = $row;
        }

        $ref = count($payload) === 1 ? (string) $payload[0]['transactionId'] : ('배치 ' . count($payload) . '건');
        $res = $this->call('POST', FirmConfig::EP_SUBMIT, $payload, $ref);

        $accepted = [];
        foreach (($res['data']['data'] ?? []) as $d) {
            if (is_array($d) && isset($d['transactionId'])) {
                $accepted[(string) $d['transactionId']] = $d;
            }
        }
        $errors = [];
        foreach (($res['data']['errorData'] ?? []) as $e) {
            if (is_array($e) && isset($e['transactionId'])) {
                $errors[(string) $e['transactionId']] = $e;
            }
        }

        return [
            'ok'       => $accepted !== [] || (bool) $res['ok'],
            'accepted' => $accepted,
            'errors'   => $errors,
            'message'  => (string) $res['error_message'],
        ];
    }

    /**
     * 이체 상태 조회 — 웹훅이 유실됐을 때 확인하는 보정 경로.
     *
     * @return array{ok:bool, status:string, data:array<mixed>, message:string}
     */
    public function transferInfo(string $transactionId): array
    {
        $res = $this->call('GET', FirmConfig::EP_TRANSFER_INFO . rawurlencode($transactionId), null, $transactionId);
        $d   = is_array($res['data']['data'] ?? null) ? $res['data']['data'] : [];

        return [
            'ok'      => (bool) $res['ok'],
            'status'  => (string) ($d['transferStatus'] ?? ''),
            'data'    => $d,
            'message' => (string) $res['error_message'],
        ];
    }

    /**
     * 이체 취소 — **접수(RECEPTION) 상태만 가능**하다. 진행 중이면 못 막는다.
     *
     * @param list<string> $transactionIds
     * @return array{ok:bool, cancelled:list<string>, errors:array<string,array<string,mixed>>, message:string}
     */
    public function cancel(array $transactionIds): array
    {
        $ids = array_values(array_filter(array_map('strval', $transactionIds), static fn (string $s): bool => $s !== ''));
        if ($ids === []) {
            return ['ok' => true, 'cancelled' => [], 'errors' => [], 'message' => ''];
        }

        $res = $this->call('POST', FirmConfig::EP_CANCEL, $ids, count($ids) === 1 ? $ids[0] : ('취소 ' . count($ids) . '건'));

        $errors = [];
        foreach (($res['data']['errorData'] ?? []) as $e) {
            if (is_array($e) && isset($e['transactionId'])) {
                $errors[(string) $e['transactionId']] = $e;
            }
        }

        return [
            'ok'        => (bool) $res['ok'],
            'cancelled' => array_values(array_filter(array_map('strval', $res['data']['data'] ?? []))),
            'errors'    => $errors,
            'message'   => (string) $res['error_message'],
        ];
    }

    // ─────────────────────────── 인터페이스 구현 ───────────────────────────

    /**
     * 단건 이체 — 기존 호출부(`Withdrawal::executeTransfers` 등)와의 호환용.
     *
     * ⚠️ 여기서 반환하는 `ok` 는 **접수 성공**이다. 호출부가 이를 "이체 완료"로 처리하면
     *    안 된다(클래스 주석). 배치로 보낼 수 있는 곳은 `submit()` 을 직접 쓰는 게 좋다 —
     *    100건을 한 번에 보내면 왕복이 100분의 1이 된다.
     *
     * @param array<string,mixed> $meta
     */
    public function transfer(int $agencyId, string $toBankCode, string $toAccount, string $holder, int $amount, array $meta = []): TransferResult
    {
        $txId = (string) ($meta['transaction_id'] ?? self::makeTransactionId(
            (string) ($meta['kind'] ?? 'WD'),
            (int) ($meta['request_id'] ?? 0)
        ));

        try {
            $res = $this->submit([[
                'transactionId' => $txId,
                'bankCode'      => $toBankCode,
                'accountNumber' => $toAccount,
                'amount'        => $amount,
                'accountHolder' => $holder,
                'receiverMemo'  => (string) ($meta['receiver_memo'] ?? ''),
                'memo'          => (string) ($meta['memo'] ?? ''),
                'metadata'      => (string) json_encode(
                    ['org' => $agencyId] + array_intersect_key($meta, array_flip(['request_id', 'rider_code', 'kind'])),
                    JSON_UNESCAPED_UNICODE
                ),
            ]]);
        } catch (Throwable $e) {
            return TransferResult::fail('이체 요청 오류: ' . $e->getMessage());
        }

        if (isset($res['errors'][$txId])) {
            $e = $res['errors'][$txId];

            return TransferResult::fail(trim(((string) ($e['errorCode'] ?? '')) . ' ' . ((string) ($e['errorMessage'] ?? '접수 거절'))));
        }
        if (!isset($res['accepted'][$txId])) {
            return TransferResult::fail($res['message'] !== '' ? $res['message'] : '이체 접수 응답에 해당 건이 없습니다.');
        }

        // 접수 ID 를 돌려준다 — 웹훅/조회로 결과를 확정할 때 이 값으로 찾는다.
        $a = $res['accepted'][$txId];

        return TransferResult::ok((string) ($a['receptionId'] ?? $txId));
    }

    // ─────────────────────────────── 내부 ───────────────────────────────

    /**
     * @return array{0:string|false, 1:int, 2:int, 3:string}
     * @param list<string> $headers
     */
    private function http(string $method, string $url, ?string $body, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw   = curl_exec($ch);
        $http  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errNo = curl_errno($ch);
        $err   = curl_error($ch);
        // PHP 8.0+ 에서 curl_close() 는 무의미하고 8.5 부터는 Deprecated 경고가 뜬다.
        // 핸들은 $ch 가 스코프를 벗어날 때 정리된다.

        return [$raw, $http, $errNo, $err];
    }
}
