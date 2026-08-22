<?php

declare(strict_types=1);

require_once __DIR__ . '/XlsxParser.php';
require_once __DIR__ . '/XlsxDecrypt.php';
require_once __DIR__ . '/SettlementExcelConfig.php';
require_once __DIR__ . '/SettlementPlatformDetect.php';

/**
 * 업로드 전 정산 파일 미리 분석 (플랫폼 감지·파싱 가능 여부)
 *
 * @return array{
 *   ok: bool,
 *   error?: string,
 *   settlement_date?: string,
 *   selected_platform?: string,
 *   mismatch?: bool,
 *   detected_platform?: ?string,
 *   detected_label?: string,
 *   confidence?: string,
 *   reasons?: list<string>,
 *   parse_row_count?: int,
 *   sheet_names?: list<string>
 * }
 */
function settlement_upload_inspect(
    string $tmpPath,
    string $origName,
    ?string $selectedPlatform = null,
    ?string $uploadPassword = null,
    ?int $agencyId = null
): array {
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if ($ext !== 'xlsx') {
        return ['ok' => false, 'error' => '.xlsx 파일만 업로드 가능합니다.'];
    }

    if ($tmpPath === '' || !is_file($tmpPath)) {
        return ['ok' => false, 'error' => '업로드된 임시 파일을 찾을 수 없습니다.'];
    }

    $settlementDate = '';
    if (preg_match('/(\d{4})(\d{2})(\d{2})/', $origName, $m)) {
        $settlementDate = "{$m[1]}-{$m[2]}-{$m[3]}";
    }

    $pwNorm     = SettlementExcelConfig::normalizePassword((string) ($uploadPassword ?? ''));
    $passwords  = SettlementExcelConfig::allPasswordsToTry($pwNorm !== '' ? $pwNorm : null, $agencyId);
    $parser     = new XlsxParser();
    $parsePath  = $tmpPath;

    try {
        $parsePath = XlsxDecrypt::prepareForParsing($tmpPath, $passwords, 'coupang');
        $parser->open($parsePath);
        $analysis = SettlementPlatformDetect::analyze($parser, $origName, $settlementDate);
        // 일간/주간도 함께 판별해 파일 선택 즉시 화면에 보여준다 — 둘은 처리 경로가 완전히 다르고,
        // 사용자가 미리 알아야 "왜 라이더 매칭 표가 안 나오지?" 같은 오해가 없다.
        $kindDetect = SettlementPlatformDetect::detectKind($parser, $origName);
    } catch (Throwable $e) {
        if (isset($parser)) {
            $parser->close();
        }
        XlsxDecrypt::cleanupTemps();

        return ['ok' => false, 'error' => $e->getMessage()];
    }

    $parser->close();
    XlsxDecrypt::cleanupTemps();

    $labels  = SettlementPlatformDetect::labels();
    $detected = $analysis['platform'];
    $selected = $selectedPlatform !== null ? trim($selectedPlatform) : '';

    $mismatch = false;
    if ($selected !== '' && in_array($selected, SettlementPlatformDetect::PLATFORMS, true)) {
        $mismatch = SettlementPlatformDetect::mismatchError($selected, $analysis, false) !== null;
    }

    return [
        'ok'                => true,
        'settlement_date'   => $settlementDate,
        'selected_platform' => $selected,
        'mismatch'          => $mismatch,
        'detected_platform' => $detected,
        'detected_label'    => $detected !== null ? ($labels[$detected] ?? $detected) : '',
        'confidence'        => $analysis['confidence'],
        'reasons'           => $analysis['reasons'],
        'parse_row_count'   => $analysis['parse_row_count'],
        'sheet_names'       => $analysis['sheet_names'],
        'detected_kind'       => $kindDetect['kind'],
        'detected_kind_label' => $kindDetect['kind'] === 'weekly' ? '주간 정산서' : '일간 정산서',
        'kind_confidence'     => $kindDetect['confidence'],
        'kind_reasons'        => $kindDetect['reasons'],
    ];
}
