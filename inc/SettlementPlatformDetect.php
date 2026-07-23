<?php

declare(strict_types=1);

/**
 * 정산 엑셀 파일 플랫폼 자동 감지
 *
 * 쿠팡이츠 일간 정산서(종합/오더별상세 등 다중 시트)와 배달의민족 정산서(배달 내역 상세 단일 시트)를
 * 지원합니다. 각 파서로 파싱 가능 여부 + 헤더 마커로 플랫폼을 판정합니다.
 */
final class SettlementPlatformDetect
{
    /** @var list<string> */
    public const PLATFORMS = ['baemin', 'coupang', 'other'];

    /** 현재 파서가 실제로 처리하는 플랫폼 */
    public const PARSER_PLATFORM = 'coupang';

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            'baemin'  => '배달의민족',
            'coupang' => '쿠팡이츠',
            'other'   => '기타',
        ];
    }

    /**
     * @return array{
     *   platform: ?string,
     *   confidence: 'high'|'medium'|'low'|'none',
     *   baemin_score: int,
     *   coupang_score: int,
     *   reasons: list<string>,
     *   sheet_names: list<string>,
     *   parse_row_count: int
     * }
     */
    public static function analyze(XlsxParser $parser, string $filename, string $settlementDate = ''): array
    {
        $reasons = [];
        $baemin  = 0;
        $coupang = 0;

        $base = mb_strtolower(pathinfo($filename, PATHINFO_FILENAME));
        if (preg_match('/coupang|쿠팡|(?:^|_)ce(?:_|$)|eats/u', $base)) {
            $coupang += 3;
            $reasons[] = '파일명에 쿠팡 관련 키워드';
        }
        if (preg_match('/baemin|배민|배달의민족|woowahan|우아/u', $base)) {
            $baemin += 3;
            $reasons[] = '파일명에 배민 관련 키워드';
        }

        $sheetNames = $parser->getSheetNames();
        foreach ($sheetNames as $name) {
            if (preg_match('/쿠팡/u', $name)) {
                $coupang += 2;
                $reasons[] = '시트명에 쿠팡 키워드';
            }
            if (preg_match('/배민|배달의민족/u', $name)) {
                $baemin += 2;
                $reasons[] = '시트명에 배민 관련 키워드';
            }
        }

        $textParts = implode(' ', $sheetNames);
        $rows      = $parser->readSheet(0, 1, 20);
        foreach ($rows as $row) {
            foreach ($row as $cell) {
                if ($cell === null || $cell === '') {
                    continue;
                }
                $textParts .= ' ' . (string) $cell;
            }
        }

        /** @var array<string, int> $coupangMarkers */
        $coupangMarkers = [
            '쿠팡이츠'      => 4,
            'Coupang Eats' => 4,
            'COUPANG'      => 3,
            '쿠팡'         => 2,
        ];
        foreach ($coupangMarkers as $marker => $score) {
            if (stripos($textParts, $marker) !== false) {
                $coupang += $score;
                $reasons[] = "내용에「{$marker}」";
            }
        }

        if (preg_match('/배달의민족|우아한형제들/u', $textParts)) {
            $baemin += 3;
            $reasons[] = '내용에 배달의민족 관련 문구';
        }

        /** 배민 정산서(배달 내역 상세) 고유 헤더 마커 */
        /** @var array<string, int> $baeminMarkers */
        $baeminMarkers = [
            '배달처리비'  => 4,
            '협력사아이디' => 3,
            '배달번호'    => 2,
            '전달완료'    => 1,
        ];
        foreach ($baeminMarkers as $marker => $score) {
            if (str_contains($textParts, $marker)) {
                $baemin += $score;
                if ($score >= 3) {
                    $reasons[] = "헤더「{$marker}」(배민 배달내역 형식)";
                }
            }
        }

        /** 현재 파서가 기대하는 일간 정산 헤더 — 쿠팡이츠 정산서에서 확인된 형식 */
        /** @var array<string, int> $parserMarkers */
        $parserMarkers = [
            '라이선스'     => 3,
            '픽업 비용'    => 2,
            '배달 비용'    => 2,
            '보수액'       => 2,
            '총 정산'      => 1,
            '오더수'       => 1,
            '배달거리 할증' => 1,
            '프로모션1'    => 1,
        ];
        foreach ($parserMarkers as $marker => $score) {
            if (str_contains($textParts, $marker)) {
                $coupang += $score;
                if ($score >= 2) {
                    $reasons[] = "헤더「{$marker}」(쿠팡이츠 일간 정산 형식)";
                }
            }
        }

        if ($settlementDate === '') {
            if (preg_match('/(\d{4})(\d{2})(\d{2})/', $filename, $m)) {
                $settlementDate = "{$m[1]}-{$m[2]}-{$m[3]}";
            } else {
                $settlementDate = date('Y-m-d');
            }
        }

        $parseRowCount = 0;
        try {
            $parsed        = $parser->parseDailySheet($settlementDate);
            $parseRowCount = count($parsed['rows'] ?? []);
            if ($parseRowCount > 0) {
                $coupang += 6;
                $reasons[] = "쿠팡이츠 일간 정산 형식으로 {$parseRowCount}명 파싱 가능";
            }
        } catch (Throwable) {
        }

        if (count($parser->parseDeductionSheet()) > 0) {
            $coupang += 1;
        }

        // 배민 파서로 주문이 파싱되면 강한 신호
        $baeminRowCount = 0;
        try {
            $baeminRowCount = count($parser->parseBaeminOrders());
            if ($baeminRowCount > 0) {
                $baemin += 6;
                $reasons[] = "배민 배달내역 형식으로 {$baeminRowCount}건 파싱 가능";
            }
        } catch (Throwable) {
        }

        $platform   = null;
        $confidence = 'none';

        if ($parseRowCount > 0 && $coupang >= $baemin) {
            $platform   = 'coupang';
            $confidence = 'high';
        } elseif ($baeminRowCount > 0 && $baemin > $coupang) {
            $platform   = 'baemin';
            $confidence = $baemin >= 6 ? 'high' : 'medium';
        } elseif ($coupang >= 4 && $coupang > $baemin) {
            $platform   = 'coupang';
            $confidence = $coupang >= 6 ? 'high' : 'medium';
        } elseif ($baemin >= 4 && $baemin > $coupang) {
            $platform   = 'baemin';
            $confidence = 'medium';
        } elseif ($coupang >= 3) {
            $platform   = 'coupang';
            $confidence = 'medium';
        } elseif ($baemin >= 3) {
            $platform   = 'baemin';
            $confidence = 'low';
        }

        return [
            'platform'         => $platform,
            'confidence'       => $confidence,
            'baemin_score'     => $baemin,
            'coupang_score'    => $coupang,
            'reasons'          => array_values(array_unique($reasons)),
            'sheet_names'      => $sheetNames,
            'parse_row_count'  => $parseRowCount,
            'baemin_row_count' => $baeminRowCount,
        ];
    }

    /**
     * @param array<string, mixed> $analysis
     */
    public static function mismatchError(string $selected, array $analysis, bool $forceConfirmed): ?string
    {
        if ($selected === 'other' || $forceConfirmed) {
            return null;
        }

        $detected = $analysis['platform'] ?? null;
        if ($detected === null || $selected === $detected) {
            return null;
        }

        $labels   = self::labels();
        $selLabel = $labels[$selected] ?? $selected;
        $detLabel = $labels[$detected] ?? $detected;
        $conf     = (string) ($analysis['confidence'] ?? 'none');
        $parseRows = (int) ($analysis['parse_row_count'] ?? 0);

        if ($parseRows > 0 && $selected === 'baemin' && $detected === 'coupang') {
            return '파일이 쿠팡이츠 일간 정산 형식으로 파싱되는데 배달의민족을 선택했습니다. 플랫폼을 「쿠팡이츠」로 변경해 주세요.';
        }

        if (in_array($conf, ['high', 'medium'], true)) {
            return "선택한 플랫폼({$selLabel})과 파일 형식({$detLabel})이 일치하지 않습니다. "
                . "플랫폼을 「{$detLabel}」로 변경하거나, 정말 맞다면 「플랫폼 확인 후 강제 업로드」를 체크해 주세요.";
        }

        return null;
    }
}
