<?php

declare(strict_types=1);

/**
 * 은행 입력값 해석 — 코드든 이름이든 받아서 표준 3자리 코드로 바꾼다 (2026-09-06 갑).
 *
 * 갑: "은행코드 입력하려고 하니까 하나하나 찾아서 대입하기가 좀 어려운데
 *      은행코드, 은행명 아무거나 입력해도 알아서 받아줄수는 없을까?"
 *
 * 받아주는 것:
 *   088 · 88 · 국민 · 국민은행 · 카카오 · 카카오뱅크 · 신한 은행 · SC제일 · KB증권 …
 *
 * ⚠️ **애매하면 고르지 않는다.** 「신한」은 신한은행(088)과 신한금융투자(278) 둘 다에
 *    걸리고, 「하나」도 하나은행(081)·하나금융투자(270)에 걸린다. 이럴 때 시스템이
 *    임의로 하나를 고르면 **엉뚱한 계좌로 돈이 나간다.** 후보만 돌려주고 사람이 고르게 한다.
 *    다만 「신한」처럼 입력값+"은행" 이 정확히 있는 경우는 그쪽을 택한다 — 사람이 은행을
 *    말할 때의 상식과 같다.
 */
final class BankResolver
{
    /** @var array<string,string>|null code => label */
    private static ?array $banks = null;

    /** @return array<string,string> code => label */
    public static function banks(): array
    {
        if (self::$banks !== null) {
            return self::$banks;
        }
        self::$banks = [];
        if (db_table_exists('system_codes')) {
            foreach (db_rows(
                "SELECT code, label FROM system_codes
                  WHERE category = 'bank' AND is_active = 1
                  ORDER BY sort_order ASC, code ASC"
            ) as $r) {
                self::$banks[(string) $r['code']] = (string) $r['label'];
            }
        }

        return self::$banks;
    }

    /** 비교용 정규화 — 공백·괄호·점·하이픈을 없애고 대문자로. */
    private static function norm(string $s): string
    {
        $s = preg_replace('/[\s()（）\.\-_·]/u', '', $s) ?? '';

        return mb_strtoupper(trim($s));
    }

    /**
     * @return array{status:string, code:string, label:string, candidates:list<array{code:string,label:string}>}
     *         status: ok | empty | ambiguous | unknown
     */
    public static function resolve(string $input): array
    {
        $none = static fn (string $st, array $cand = []): array
            => ['status' => $st, 'code' => '', 'label' => '', 'candidates' => $cand];

        $raw = trim($input);
        if ($raw === '') {
            return $none('empty');
        }

        $banks = self::banks();
        if ($banks === []) {
            return $none('unknown');
        }

        // ── 1) 숫자만 있으면 코드로 본다(4 → 004) ──
        if (preg_match('/^\d{1,3}$/', $raw)) {
            $code = str_pad($raw, 3, '0', STR_PAD_LEFT);

            return isset($banks[$code])
                ? ['status' => 'ok', 'code' => $code, 'label' => $banks[$code], 'candidates' => []]
                : $none('unknown');
        }

        $n = self::norm($raw);
        if ($n === '') {
            return $none('empty');
        }

        // ── 2) 이름이 정확히 같은 것 ──
        foreach ($banks as $code => $label) {
            if (self::norm($label) === $n) {
                return ['status' => 'ok', 'code' => $code, 'label' => $label, 'candidates' => []];
            }
        }

        // ── 3) 입력값 + "은행" 이 정확히 있는 것 — 「신한」→ 신한은행(신한금융투자 아님) ──
        foreach (['은행', '뱅크'] as $suffix) {
            $want = self::norm($raw . $suffix);
            foreach ($banks as $code => $label) {
                if (self::norm($label) === $want) {
                    return ['status' => 'ok', 'code' => $code, 'label' => $label, 'candidates' => []];
                }
            }
        }

        // ── 4) 부분 일치 — 하나면 채택, 여럿이면 사람에게 물어본다 ──
        $hit = [];
        foreach ($banks as $code => $label) {
            $ln = self::norm($label);
            if (str_contains($ln, $n) || str_contains($n, $ln)) {
                $hit[] = ['code' => (string) $code, 'label' => $label];
            }
        }
        if (count($hit) === 1) {
            return ['status' => 'ok', 'code' => $hit[0]['code'], 'label' => $hit[0]['label'], 'candidates' => []];
        }
        if (count($hit) > 1) {
            return $none('ambiguous', $hit);
        }

        return $none('unknown');
    }

    /**
     * 미리보기 드롭다운용 전체 목록.
     *
     * @return list<array{code:string,label:string}>
     */
    public static function options(): array
    {
        $out = [];
        foreach (self::banks() as $code => $label) {
            $out[] = ['code' => (string) $code, 'label' => $label];
        }

        return $out;
    }
}
