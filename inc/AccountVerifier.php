<?php

declare(strict_types=1);

require_once __DIR__ . '/FirmConfig.php';

/**
 * 계좌 실재·예금주 확인 — 펌뱅킹 「예금주 조회」를 감싼다.
 *
 * 왜 필요한가: 지금까지 계좌번호는 `/^[0-9\-]{1,40}$/` 정규식만 통과하면 저장됐다.
 * 숫자만 맞으면 그만이라, **한 자리 잘못 입력하면 모르는 사람에게 돈이 간다.**
 * 계좌이체는 되돌리기가 매우 어렵다 — 받은 사람이 동의해야 하고, 안 하면 소송이다.
 * 등록 시점에 한 번 확인하는 것이 사고를 막는 가장 싼 방법이다.
 *
 * ⚠️ 실 연동이 꺼져 있으면(`driver=mock`) **확인할 수 없다**. 그때는 "확인 불가"를
 *    돌려주고 저장은 막지 않는다 — 연동 전에도 시스템은 돌아가야 한다.
 */
final class AccountVerifier
{
    /** 확인 결과 상태 */
    public const OK          = 'ok';          // 조회 성공(예금주 확인됨)
    public const MISMATCH    = 'mismatch';    // 조회는 됐는데 입력한 예금주와 다름
    public const NOT_FOUND   = 'not_found';   // 계좌를 찾을 수 없음
    public const UNAVAILABLE = 'unavailable'; // 실 연동이 꺼져 있어 확인 못 함

    public static function available(): bool
    {
        return FirmConfig::isReady();
    }

    /**
     * 예금주명 비교용 정규화.
     *
     * 은행은 공백을 넣기도 빼기도 한다("홍 길동" / "홍길동"). 괄호 표기((주) 등)나
     * 대소문자 차이도 흔하다. 그대로 비교하면 멀쩡한 계좌를 불일치로 튕긴다.
     */
    public static function normalizeName(string $name): string
    {
        $s = preg_replace('/\s+/u', '', trim($name)) ?? '';

        return mb_strtolower($s);
    }

    /**
     * 계좌 확인.
     *
     * @param string $expectedHolder 입력한 예금주명(비우면 일치 검사를 하지 않는다)
     * @return array{state:string, ok:bool, holder:string, matched:?bool, message:string, code:string}
     */
    public static function verify(string $bankCode, string $accountNo, string $expectedHolder = ''): array
    {
        $bankCode  = trim($bankCode);
        $accountNo = preg_replace('/\D/', '', $accountNo) ?? '';

        if ($bankCode === '' || $accountNo === '') {
            return self::result(self::NOT_FOUND, '', null, '은행과 계좌번호를 모두 입력하세요.');
        }
        if (!self::available()) {
            return self::result(self::UNAVAILABLE, '', null, '펌뱅킹 실 연동이 꺼져 있어 계좌를 확인할 수 없습니다.');
        }

        require_once __DIR__ . '/BaumFirmGateway.php';

        try {
            $res = (new BaumFirmGateway())->accountHolder($bankCode, $accountNo);
        } catch (Throwable $e) {
            // 조회 실패가 등록을 막으면 안 된다 — 확인 못 했다는 사실만 알린다.
            return self::result(self::UNAVAILABLE, '', null, '조회 중 오류가 발생했습니다: ' . $e->getMessage());
        }

        if (!$res['ok'] || $res['holder'] === '') {
            return self::result(
                self::NOT_FOUND,
                '',
                null,
                $res['message'] !== '' ? $res['message'] : '계좌를 찾을 수 없습니다. 은행과 계좌번호를 확인하세요.',
                $res['code']
            );
        }

        $holder = $res['holder'];
        if (trim($expectedHolder) === '') {
            return self::result(self::OK, $holder, null, '예금주: ' . $holder, $res['code']);
        }

        $matched = self::normalizeName($holder) === self::normalizeName($expectedHolder);

        return $matched
            ? self::result(self::OK, $holder, true, '예금주 확인: ' . $holder, $res['code'])
            : self::result(
                self::MISMATCH,
                $holder,
                false,
                sprintf('예금주가 다릅니다 — 실제 "%s" / 입력 "%s"', $holder, trim($expectedHolder)),
                $res['code']
            );
    }

    /**
     * 라이더 계좌 확인 결과를 기록해 둔다 — 화면에 "확인됨" 배지를 띄우고,
     * 같은 계좌를 매번 다시 조회하지 않기 위해서다.
     */
    public static function markRiderVerified(int $riderId, string $holder): void
    {
        if ($riderId < 1 || !db_table_exists('riders')) {
            return;
        }
        $cols = array_column(db_rows('SHOW COLUMNS FROM riders'), 'Field');
        if (!in_array('bank_verified_at', $cols, true)) {
            return; // 마이그레이션 전이면 조용히 넘어간다
        }
        db_execute(
            'UPDATE riders SET bank_verified_at = NOW(), bank_verified_name = ? WHERE id = ?',
            [mb_substr($holder, 0, 80), $riderId]
        );
    }

    /** 계좌가 바뀌면 확인 기록을 지운다 — 옛 확인이 새 계좌를 보증하지 않는다. */
    public static function clearRiderVerified(int $riderId): void
    {
        if ($riderId < 1 || !db_table_exists('riders')) {
            return;
        }
        $cols = array_column(db_rows('SHOW COLUMNS FROM riders'), 'Field');
        if (!in_array('bank_verified_at', $cols, true)) {
            return;
        }
        db_execute("UPDATE riders SET bank_verified_at = NULL, bank_verified_name = '' WHERE id = ?", [$riderId]);
    }

    /**
     * @return array{state:string, ok:bool, holder:string, matched:?bool, message:string, code:string}
     */
    private static function result(string $state, string $holder, ?bool $matched, string $message, string $code = ''): array
    {
        return [
            'state'   => $state,
            'ok'      => $state === self::OK,
            'holder'  => $holder,
            'matched' => $matched,
            'message' => $message,
            'code'    => $code,
        ];
    }
}
