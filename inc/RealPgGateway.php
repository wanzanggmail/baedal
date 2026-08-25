<?php

declare(strict_types=1);

require_once __DIR__ . '/PgGateway.php';
require_once __DIR__ . '/PgConfig.php';
require_once __DIR__ . '/PgApiLog.php';

/**
 * 루트업(routeup) 실 연동 드라이버 — REF_PG_WEROUTE.md §1·§2·§4·§11.
 *
 * ⚠️ **2026-08-25 문서 교체** — 처음 받은 `api.weroutefincorp.com` 문서는 우리 가맹점의 것이
 * 아니었다(모든 요청이 `RV406 가맹점을 찾을 수 없습니다`). 실제는 `https://api.routeup.kr`.
 * **경로·필드·인증은 두 문서가 동일**해서 호스트만 바꾸니 가맹점 조회가 통과했다.
 *
 * ## 응답 규격 (루트업 문서 + 실 호출로 확인)
 *   {"result_cd":"0000","result_msg":"...", ...필드..., "temp":"..."}
 * **성공은 `result_cd === '0000'`** 이고 실패는 그 외 코드가 HTTP 4xx 와 함께 온다.
 * 승인 실패 시에는 `result_cd`·`result_msg` 만 오고 나머지 필드는 비어 있다.
 *
 * ## 인증 (확인됨)
 *   Authorization: {pay_key}     ← Bearer 아님. 원문 그대로.
 *
 * ## ✅ 2026-08-25 실 빌키 발급 성공으로 응답 필드까지 확정
 * `{"result_cd":"0000","result_msg":"성공","bill_code":..,"bill_key":..,"issuer":"하나(구외환)",
 *   "issuer_code":"03","trx_id":"","trx_dttm":..,"ord_num":..}`
 * ⚠️ 문서엔 `trx_id` 가 필수(O)로 돼 있지만 **빌키 발급 응답에서는 빈 값**으로 온다.
 *    빌키 발급은 거래가 아니라서인 듯하다 — 우리는 이 값을 저장하지 않으므로 문제없다.
 *
 * ## ⚠️ 아직 확정하지 못한 것
 * - **에러 코드표** — 루트업 문서에도 코드 목록이 없다("성공시 0000, 이외 에러코드"뿐).
 *   한도초과 등 "다음 카드로 폴백" 판정을 `result_msg` **문구**로 하고 있다.
 *   코드표를 받으면 **반드시 코드 기준으로 바꿀 것** — 문구는 PG가 바꾸면 폴백이 조용히 멈춘다.
 * - **카드 필드 암호화** — 루트업 문서에 암호화 요구가 **없다**(평문 + HTTPS). 받아둔
 *   AES 키/IV(`PgCrypto`)는 이 거래 API용이 아닌 것으로 보인다(대사/SAMW 쪽 가능성).
 */
final class RealPgGateway implements PgGateway
{
    /** 연결·응답 타임아웃(초). 승인은 카드사를 거쳐 느릴 수 있어 넉넉히 준다. */
    private const CONNECT_TIMEOUT = 8;
    private const TIMEOUT         = 30;

    /** @param array<string,mixed> $cfg PgConfig::get() 결과 */
    public function __construct(private readonly array $cfg)
    {
    }

    public function label(): string
    {
        return '루트업';
    }

    // ── 결제 ────────────────────────────────────────────────────────────

    public function charge(PgChargeRequest $req): PgChargeResult
    {
        $body = [
            'mid'         => (string) $this->cfg['mid'],
            'tid'         => (string) $this->cfg['tid'],
            'ord_num'     => $req->orderNo,
            'bill_key'    => $req->billingKey,
            'amount'      => (string) $req->amount,
            'item_name'   => $req->itemName,
            'buyer_name'  => $req->buyerName,
            'buyer_phone' => $req->buyerPhone,
            'installment' => (string) max(0, $req->installment),
        ];
        // 웹훅으로 되돌아온다(문서 §5-6) — 우리 결제를 찾는 열쇠라 반드시 싣는다.
        if (!empty($req->meta['payment_id'])) {
            $body['temp'] = base64_encode(json_encode(['payment_id' => (int) $req->meta['payment_id']], JSON_UNESCAPED_UNICODE));
        }

        $res = $this->post(PgConfig::EP_BILL_PAY, $body);

        // ⚠️ 타임아웃은 **승인 여부를 모르는 상태**다. 카드사에서 승인됐는데 응답만 못 받았을 수
        //    있어 그냥 실패 처리하면 돈이 나간 채로 우리는 미결제로 남는다. 망상취소로 되돌린다.
        if ($res['timeout']) {
            $this->netCancel($req->orderNo, $req->amount);

            return PgChargeResult::fail('TIMEOUT', '응답 시간 초과 — 망상취소를 요청했습니다. 잠시 후 거래조회로 확인하세요.');
        }

        if (!$res['ok']) {
            return PgChargeResult::fail($this->failCode($res), $this->failMessage($res));
        }

        $d = $res['data'];

        return PgChargeResult::ok(
            tid: $this->pick($d, ['trx_id', 'tid', 'transaction_id']),
            apprNum: $this->pick($d, ['appr_num', 'approval_no', 'auth_no']),
            issuer: $this->pick($d, ['issuer', 'issuer_name', 'card_name']),
            issuerCode: $this->pick($d, ['issuer_code', 'issuer_cd']),
            cardNum: $this->pick($d, ['card_num', 'card_no'])
        );
    }

    // ── 빌키 ────────────────────────────────────────────────────────────

    public function issueBillingKey(PgBillingKeyRequest $req): PgBillingKeyResult
    {
        $body = array_merge([
            'mid'         => (string) $this->cfg['mid'],
            'tid'         => (string) $this->cfg['tid'],
            'ord_num'     => $req->orderNo,
            'buyer_name'  => $req->buyerName,
            'buyer_phone' => $req->buyerPhone,
        ], $this->buildCardFields($req));

        $res = $this->post(PgConfig::EP_BILL_KEY, $body);

        if ($res['timeout']) {
            // 빌키 발급은 돈이 나가지 않으므로 망상취소가 없다. 실패로 두고 재시도하면 된다.
            return PgBillingKeyResult::fail('TIMEOUT', '응답 시간 초과 — 다시 시도해 주세요.');
        }
        if (!$res['ok']) {
            return PgBillingKeyResult::fail($this->failCode($res), $this->failMessage($res));
        }

        $d       = $res['data'];
        $billKey = $this->pick($d, ['bill_key', 'billkey', 'billing_key']);
        if ($billKey === '') {
            // 2xx 인데 키가 없다 = 우리가 응답 키 이름을 잘못 알고 있다는 뜻이다.
            // 빈 키를 저장하면 나중에 결제가 통째로 깨지므로 여기서 막는다.
            return PgBillingKeyResult::fail('NO_BILL_KEY', '응답에 빌키가 없습니다. 응답 형식을 확인해야 합니다: ' . $this->shortJson($d));
        }

        return PgBillingKeyResult::ok(
            billKey: $billKey,
            billCode: $this->pick($d, ['bill_code', 'billcode']),
            issuer: $this->pick($d, ['issuer', 'issuer_name', 'card_name']),
            issuerCode: $this->pick($d, ['issuer_code', 'issuer_cd']),
            tid: $this->pick($d, ['trx_id', 'tid'])
        );
    }

    public function deleteBillingKey(string $billingKey, string $orderNo): PgSimpleResult
    {
        $res = $this->request('DELETE', PgConfig::EP_BILL_KEY, [
            'mid'      => (string) $this->cfg['mid'],
            'tid'      => (string) $this->cfg['tid'],
            'ord_num'  => $orderNo,
            'bill_key' => $billingKey,
        ]);

        if ($res['timeout']) {
            return PgSimpleResult::fail('응답 시간 초과 — 해지 여부가 불확실합니다. 잠시 후 다시 시도하세요.');
        }

        return $res['ok'] ? PgSimpleResult::ok() : PgSimpleResult::fail($this->failMessage($res));
    }

    // ── 취소 ────────────────────────────────────────────────────────────

    /**
     * 승인 취소 — `POST /api/v2/pay/cancel` (mid·tid·amount·trx_id).
     *
     * 정산이 D+1 이라 **당일 취소는 받아준다**. 정산이 넘어간 건은 PG 가 거절하므로
     * 그 사유를 그대로 화면에 올려 사람이 판단하게 한다(우리가 임의로 성공 처리하지 않는다).
     *
     * ⚠️ 타임아웃은 **취소 여부를 모르는 상태**다. 취소는 승인과 달리 다시 불러도 안전하지
     *    않다(이미 취소된 건을 또 취소하면 PG 가 거절하거나, 최악은 중복 취소로 대사가 꼬인다).
     *    그래서 자동 재시도하지 않고 실패로 두되, 거래조회로 확인하라고 알린다.
     */
    public function cancel(string $trxId, int $amount): PgCancelResult
    {
        $res = $this->post(PgConfig::EP_CANCEL, [
            'mid'    => (string) $this->cfg['mid'],
            'tid'    => (string) $this->cfg['tid'],
            'amount' => (string) $amount,
            'trx_id' => $trxId,
        ]);

        if ($res['timeout']) {
            return PgCancelResult::fail(
                'TIMEOUT',
                '응답 시간 초과 — 취소 여부가 불확실합니다. PG 가맹점 관리자나 거래조회로 확인한 뒤 다시 시도하세요.'
            );
        }
        if (!$res['ok']) {
            return PgCancelResult::fail($this->failCode($res), $this->failMessage($res));
        }

        // 취소 응답의 trx_id 는 **취소 거래번호**다(원거래는 ori_trx_id 로 따로 온다).
        return PgCancelResult::ok($this->pick($res['data'], ['trx_id', 'cancel_trx_id']));
    }

    // ── 보조 ────────────────────────────────────────────────────────────

    /**
     * 카드 필드. 위루트가 암호화를 요구하면 **여기만** 고치면 된다.
     *
     * @return array<string,string>
     */
    private function buildCardFields(PgBillingKeyRequest $req): array
    {
        return [
            'card_num' => $req->cardNumber,
            'yymm'     => $req->expiry,
            'auth_num' => $req->authNum,
            'card_pw'  => $req->cardPw,
        ];
    }

    /**
     * 망상취소 — 승인 응답을 못 받았을 때 되돌린다(문서 §1).
     * 이것마저 실패하면 사람이 거래조회로 확인해야 하므로 로그를 남긴다.
     */
    private function netCancel(string $orderNo, int $amount): void
    {
        $res = $this->post(PgConfig::EP_NET_CANCEL, [
            'mid'     => (string) $this->cfg['mid'],
            'tid'     => (string) $this->cfg['tid'],
            'ord_num' => $orderNo,
            'amount'  => (string) $amount,
        ]);
        if (!$res['ok']) {
            error_log(sprintf('[PG] 망상취소 실패 ord_num=%s : %s', $orderNo, $this->failMessage($res)));
        }
    }

    /**
     * 응답에서 값을 꺼낸다.
     *
     * 필드명은 문서 + **실 성공 응답**으로 확정됐다(2026-08-25). 후보를 훑는 형태를 남겨 둔 것은
     * 승인/취소 응답까지 전부 실물로 본 게 아니라서다 — **첫 번째가 확정 키**이고,
     * 나머지는 PG 가 표기를 바꿨을 때 조용히 깨지지 않게 하는 여유분이다.
     *
     * @param array<string,mixed> $data
     * @param list<string> $keys
     */
    private function pick(array $data, array $keys): string
    {
        foreach ($keys as $k) {
            if (isset($data[$k]) && is_scalar($data[$k]) && (string) $data[$k] !== '') {
                return (string) $data[$k];
            }
        }

        return '';
    }

    /**
     * 재시도 대상(다음 카드로 폴백) 판정.
     *
     * ⚠️ 코드표를 못 받아 **문구로 판정**한다. 실 코드표를 받으면 코드 기준으로 바꿀 것 —
     * 문구는 PG가 언제든 바꿀 수 있어 폴백이 조용히 멈출 수 있다.
     *
     * @param array{ok:bool, http:int, code:string, msg:string, data:array<string,mixed>, timeout:bool, err:string} $res
     */
    private function failCode(array $res): string
    {
        $msg = $res['msg'];
        foreach (['한도' => 'LIMIT_EXCEEDED', '잔액' => 'INSUFFICIENT', '정지' => 'CARD_DECLINED', '거절' => 'CARD_DECLINED', '분실' => 'CARD_DECLINED', '도난' => 'CARD_DECLINED'] as $needle => $code) {
            if (str_contains($msg, $needle)) {
                return $code;
            }
        }

        return $res['code'] !== '' ? $res['code'] : 'PG_ERROR';
    }

    /** @param array<string,mixed> $res */
    private function failMessage(array $res): string
    {
        if ($res['err'] !== '') {
            return '통신 오류: ' . $res['err'];
        }
        $msg = (string) $res['msg'];
        if ($msg === '') {
            $msg = 'PG 오류 (HTTP ' . $res['http'] . ')';
        }

        return $res['code'] !== '' ? $msg . ' [' . $res['code'] . ']' : $msg;
    }

    /** @param array<string,mixed> $d */
    private function shortJson(array $d): string
    {
        return mb_substr((string) json_encode($d, JSON_UNESCAPED_UNICODE), 0, 200);
    }

    /**
     * @param array<string,mixed> $body
     * @return array{ok:bool, http:int, code:string, msg:string, data:array<string,mixed>, timeout:bool, err:string}
     */
    private function post(string $path, array $body): array
    {
        return $this->request('POST', $path, $body);
    }

    /**
     * @param array<string,mixed> $body
     * @return array{ok:bool, http:int, code:string, msg:string, data:array<string,mixed>, timeout:bool, err:string}
     */
    private function request(string $method, string $path, array $body): array
    {
        $ch = curl_init(PgConfig::HOST . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_HTTPHEADER     => [
                'Authorization: ' . (string) $this->cfg['pay_key'], // Bearer 아님(확인됨)
                'Accept: application/json',
                'Content-Type: application/json',
            ],
        ]);
        $startedAt = microtime(true);
        $raw     = curl_exec($ch);
        $errNo   = curl_errno($ch);
        $errMsg  = curl_error($ch);
        $http    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $timeout = in_array($errNo, [CURLE_OPERATION_TIMEDOUT, CURLE_COULDNT_CONNECT], true);

        $data = [];
        if (is_string($raw) && $raw !== '') {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                $data = $json;
            }
        }

        // 성공은 result_cd='0000' 이다(루트업 문서). HTTP 2xx 만 보고 판단하면 2xx 로 내려오는
        // 업무 실패를 성공으로 오인할 수 있다. 코드가 아예 없는 응답은 HTTP 상태로만 판단한다.
        $resultCd = (string) ($data['result_cd'] ?? '');
        $code2xx  = $http >= 200 && $http < 300;

        $out = [
            'ok'      => $errNo === 0 && $code2xx && ($resultCd === '' || $resultCd === '0000'),
            'http'    => $http,
            'code'    => $resultCd,
            'msg'     => (string) ($data['result_msg'] ?? ''),
            'data'    => $data,
            'timeout' => $timeout,
            'err'     => $errNo !== 0 && !$timeout ? $errMsg : '',
        ];

        // 이력 — 카드정보·키는 PgApiLog 가 지우고 넣는다. 헤더는 아예 넘기지 않는다
        // (Authorization 에 pay_key 원문이 들어 있다).
        PgApiLog::record(
            endpoint: $path,
            method: $method,
            request: $body,
            response: $data !== [] ? $data : ['_raw' => mb_substr((string) $raw, 0, 500)],
            httpCode: $http,
            resultCd: $out['code'],
            resultMsg: $timeout ? '응답 시간 초과' : ($out['err'] !== '' ? $out['err'] : $out['msg']),
            durationMs: (int) round((microtime(true) - $startedAt) * 1000),
            ok: $out['ok'],
            ordNum: (string) ($body['ord_num'] ?? '')
        );

        return $out;
    }
}
