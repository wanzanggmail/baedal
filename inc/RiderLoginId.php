<?php

declare(strict_types=1);

/**
 * 라이더 앱 로그인 ID 자동생성.
 *
 * 기존엔 관리자가 등록 시 매번 로그인 ID를 손으로 입력해야 했고, 의미 없는 임의
 * 숫자가 들어가는 경우가 잦았다. 라이더 이름·쿠팡/배민 아이디는 겸업·이관 시
 * 대리점 간 중복이 정상이라(§5.2) 그대로는 못 쓴다.
 *
 * 채택한 규칙: **휴대전화번호(숫자만) 그대로를 기본으로 쓰고, 충돌하면 소문자
 * a, b, c…를 순차로 붙여 유일화**한다. 본인 전화번호라 외우기 쉽고, 접미사도
 * 한 글자라 짧게 유지된다.
 *
 *   01012345678a           ← 같은 번호가 이미 있으면(겸업·재등록) a부터
 *   01012345678b           ← 그래도 겹치면 b, c, d… 순으로
 *
 * `riders.login_id` 형식 제약(영문·숫자·_·.·@·-, 3~60자)은 그대로 만족한다.
 */
final class RiderLoginId
{
    public static function generate(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        if ($digits === '') {
            return self::randomFallback();
        }

        // 전화번호 정책상 최대 15자리(E.164)까지 가정 — login_id 60자 제한에 여유
        $digits = substr($digits, 0, 20);

        if (!self::exists($digits)) {
            return $digits;
        }

        // 충돌 시 a, b, c … z, aa, ab … 순으로 붙여 유일화(26자 넘어가면 2글자로 자연 확장)
        for ($i = 0; $i < 26 * 26; $i++) {
            $candidate = $digits . self::suffixLetters($i);
            if (!self::exists($candidate)) {
                return $candidate;
            }
        }

        return self::randomFallback();
    }

    /** 0→a, 1→b, …, 25→z, 26→aa, 27→ab … (엑셀 열 이름과 같은 규칙) */
    private static function suffixLetters(int $i): string
    {
        $s = '';
        do {
            $s = chr(97 + ($i % 26)) . $s;
            $i = intdiv($i, 26) - 1;
        } while ($i >= 0);

        return $s;
    }

    private static function exists(string $loginId): bool
    {
        return db_row('SELECT id FROM riders WHERE login_id = ? LIMIT 1', [$loginId]) !== null;
    }

    /** 전화번호가 없거나 위 규칙으로도 유일화가 안 될 때의 최후 폴백 */
    private static function randomFallback(): string
    {
        do {
            $candidate = 'R' . date('ymd') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
        } while (self::exists($candidate));

        return $candidate;
    }
}
