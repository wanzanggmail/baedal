<?php

declare(strict_types=1);

require_once __DIR__ . '/FirmConfig.php';
require_once __DIR__ . '/FirmTransfer.php';
require_once __DIR__ . '/BaumFirmGateway.php';

/**
 * 바움P&S 「계좌이체 처리결과 통보」 수신 — 매뉴얼 v1.1.8.
 *
 * ⚠️ **여기가 돈의 결과를 확정하는 곳이다.** PG 웹훅(`PgWebhook`)은 대사만 했지만
 *    펌뱅킹은 다르다 — 접수 시점에는 아무것도 확정하지 않았으므로, 이 통보를 받아야
 *    출금이 완료되고 지갑이 차감된다.
 *
 * 🔐 **요청 Body 도 응답 Body 도 암호화**된다(AES-256-CBC, `BaumCrypto`).
 *    응답으로 `{"success": true}` 를 **암호화해서** 돌려줘야 정상 처리된다.
 *    평문으로 주면 바움이 실패로 보고 1분 간격으로 최대 10회 재전송한다.
 *
 * 멱등: 재전송이 정상 동작이므로 같은 건이 다시 와도 안전해야 한다.
 * `FirmTransfer::updateStatus()` 가 **이미 확정된 건은 바꾸지 않고 false** 를 돌려주고,
 * 후속 처리(지갑 차감)는 그 값이 true 일 때만 한다.
 */
final class FirmWebhook
{
    public static function tableExists(): bool
    {
        return db_table_exists('firm_webhook_events');
    }

    /**
     * 허용 IP 목록. 비어 있으면 검사하지 않는다.
     *
     * ⚠️ 매뉴얼에 발신 IP 가 **적혀 있지 않다**(REF_FIRM_BAUM.md §9). 바움에 문의해
     *    채워야 한다. 그 전까지는 암호화 키를 아는 쪽만 유효한 본문을 만들 수 있다는 점이
     *    사실상 인증 역할을 한다 — 복호화에 실패하면 어차피 거절된다.
     *
     * @return list<string>
     */
    public static function allowedIps(): array
    {
        $raw = FirmConfig::tableExists() ? trim((string) FirmConfig::get()['noti_allow_ips']) : '';
        if ($raw === '') {
            return [];
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
     * ⚠️ X-Forwarded-For 는 **신뢰하지 않는다** — 아무나 헤더로 넣어 보낼 수 있어
     *    그걸 믿으면 IP 검사가 무의미해진다. 프록시 뒤에 둘 거라면 프록시가
     *    REMOTE_ADDR 를 실제 원격 주소로 바꿔 주도록 서버에서 설정할 것.
     *
     * @param array<string,mixed> $server
     */
    public static function clientIp(array $server): string
    {
        return (string) ($server['REMOTE_ADDR'] ?? '');
    }

    /**
     * 통보 1건 처리.
     *
     * @param array<string,mixed> $server
     * @return array{status:int, body:array<string,mixed>, encrypt:bool, note:string}
     */
    public static function handle(string $rawBody, array $server): array
    {
        // 실패 응답도 규격대로 `{"success": false}` 로 준다. 그래야 바움이 재전송한다.
        $fail = static fn (int $code, string $msg, bool $encrypt = true): array => [
            'status'  => $code,
            'body'    => ['success' => false],
            'encrypt' => $encrypt,
            'note'    => $msg,
        ];

        if (!FirmConfig::isReady()) {
            return $fail(503, '펌뱅킹 설정이 완료되지 않았습니다.', false);
        }
        if (!self::tableExists() || !FirmTransfer::tableExists()) {
            return $fail(503, 'firm_* 테이블이 없습니다. php migrate.php 를 실행하세요.', false);
        }

        $ip    = self::clientIp($server);
        $allow = self::allowedIps();
        if ($allow !== [] && !in_array($ip, $allow, true)) {
            // 허용 목록 밖 → 기록도 남기지 않는다(스팸으로 테이블이 부푼다).
            return $fail(403, '허용되지 않은 IP: ' . $ip, false);
        }

        // 복호화 — 여기서 실패하면 우리 키로 만든 본문이 아니다(위조이거나 키 불일치).
        try {
            $payload = FirmConfig::crypto()->decryptJson(trim($rawBody));
        } catch (Throwable $e) {
            return $fail(400, '본문 복호화 실패: ' . $e->getMessage(), false);
        }

        $txId   = trim((string) ($payload['transactionId'] ?? ''));
        $status = strtoupper(trim((string) ($payload['transferStatus'] ?? '')));
        $sign   = trim((string) ($payload['amountSign'] ?? ''));
        $amount = (int) ($payload['amount'] ?? 0);

        if ($txId === '' || $status === '') {
            return $fail(400, 'transactionId 또는 transferStatus 가 없습니다.');
        }

        // ── 입금 통보(+) ── 우리 계좌로 돈이 들어온 건이다. 출금 장부와 무관하므로
        //    기록만 남기고 성공을 돌려준다(거절하면 재전송만 반복된다).
        if ($sign === '+') {
            self::log($payload, $ip, false, '입금 통보 — 출금 건 아님');

            return ['status' => 200, 'body' => ['success' => true], 'encrypt' => true, 'note' => '입금 통보 기록'];
        }

        $tr = FirmTransfer::findByTransactionId($txId);
        if ($tr === null) {
            // 우리 장부에 없는 건 — 접수 기록이 실패했거나 다른 시스템의 건이다.
            // 사람이 확인해야 하므로 기록은 남기되, 재전송을 멈추도록 성공을 돌려준다.
            self::log($payload, $ip, false, '우리 장부에서 찾지 못함 — 확인 필요');

            return ['status' => 200, 'body' => ['success' => true], 'encrypt' => true, 'note' => '미매칭 통보'];
        }

        // 금액이 다르면 확정하지 않는다 — 사람이 봐야 한다.
        if ($amount > 0 && $amount !== (int) $tr['amount']) {
            self::log($payload, $ip, true, sprintf(
                '금액 불일치 — 통보 %s원 / 장부 %s원',
                number_format($amount),
                number_format((int) $tr['amount'])
            ));

            return ['status' => 200, 'body' => ['success' => true], 'encrypt' => true, 'note' => '금액 불일치'];
        }

        $reason  = trim((string) ($payload['resultMessage'] ?? ''));
        $changed = FirmTransfer::updateStatus($txId, $status, $status === BaumFirmGateway::ST_SUCCESS ? '' : $reason);

        $note = $changed ? ('상태 ' . $status . ' 반영') : ('이미 확정된 건(멱등 처리) · ' . $status);
        if ($changed) {
            $note .= self::applyResult($tr, $status, $reason);
        }
        self::log($payload, $ip, true, $note);

        return ['status' => 200, 'body' => ['success' => true], 'encrypt' => true, 'note' => $note];
    }

    /**
     * 확정된 결과를 원본 장부에 반영한다.
     *
     * **`updateStatus()` 가 true 를 돌려준 경우에만 호출된다** — 즉 이 건을 확정한 게
     * 이번 호출뿐임이 보장된 상태다. 그래서 지갑이 두 번 깎이지 않는다.
     *
     * @param array<string,mixed> $tr
     */
    private static function applyResult(array $tr, string $status, string $reason): string
    {
        $kind  = (string) $tr['kind'];
        $refId = (int) $tr['ref_id'];
        if ($refId < 1) {
            return ' · 원본 id 없음';
        }

        if ($kind === FirmTransfer::KIND_WITHDRAWAL) {
            require_once __DIR__ . '/Withdrawal.php';

            if ($status === BaumFirmGateway::ST_SUCCESS) {
                $ok = Withdrawal::finalizeSuccess(
                    $refId,
                    '펌뱅킹 이체 완료 · 바움P&S · 접수번호 ' . (string) $tr['reception_id']
                );

                return $ok ? ' · 출금 #' . $refId . ' 완료 확정(지갑 차감)' : ' · 출금 #' . $refId . ' 은 이미 처리됨';
            }

            $msg = $status === BaumFirmGateway::ST_CANCELLED ? '이체 취소됨' : '이체 실패';
            if ($reason !== '') {
                $msg .= ' — ' . $reason;
            }
            $ok = Withdrawal::markTransferFailed($refId, $msg);

            return $ok ? ' · 출금 #' . $refId . ' 실패 처리' : ' · 출금 #' . $refId . ' 상태 변화 없음';
        }

        // 일일지급·대리점 인출은 아직 비동기 경로를 쓰지 않는다(모의 게이트웨이).
        // 나중에 붙일 때 여기에 분기를 추가한다.
        return ' · ' . $kind . ' 는 후속 처리 미구현';
    }

    /**
     * 수신 기록. **계좌번호는 마스킹**해서 남긴다.
     *
     * @param array<string,mixed> $payload
     */
    private static function log(array $payload, string $ip, bool $matched, string $note): void
    {
        try {
            $safe = $payload;
            if (isset($safe['accountNumber'])) {
                $safe['accountNumber'] = FirmTransfer::maskAccount((string) $safe['accountNumber']);
            }

            db_execute(
                'INSERT INTO firm_webhook_events
                    (transaction_id, reception_id, transfer_status, amount, amount_sign, matched, note, source_ip, raw_body)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    mb_substr((string) ($payload['transactionId'] ?? ''), 0, 60),
                    mb_substr((string) ($payload['receptionId'] ?? ''), 0, 60),
                    mb_substr((string) ($payload['transferStatus'] ?? ''), 0, 20),
                    (int) ($payload['amount'] ?? 0),
                    mb_substr((string) ($payload['amountSign'] ?? ''), 0, 2),
                    $matched ? 1 : 0,
                    mb_substr($note, 0, 300),
                    mb_substr($ip, 0, 45),
                    mb_substr((string) json_encode($safe, JSON_UNESCAPED_UNICODE), 0, 60000),
                ]
            );
        } catch (Throwable $e) {
            error_log('[FirmWebhook] 기록 실패: ' . $e->getMessage());
        }
    }

    /**
     * 최근 수신 목록(화면용).
     *
     * @return list<array<string,mixed>>
     */
    public static function recent(int $limit = 100): array
    {
        if (!self::tableExists()) {
            return [];
        }

        return db_rows('SELECT * FROM firm_webhook_events ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)));
    }
}
