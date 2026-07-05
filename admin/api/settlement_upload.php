<?php

declare(strict_types=1);

/**
 * 정산 엑셀 업로드 API
 * POST /admin/api/settlement_upload.php
 *
 * DB 스키마: 초기 설계(settlement_uploads) + settlement_daily_riders(일별 라이더 요약)
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/XlsxParser.php';
require_once INC_PATH . '/XlsxDecrypt.php';
require_once INC_PATH . '/SettlementExcelConfig.php';
require_once INC_PATH . '/SettlementPlatformDetect.php';
require_once INC_PATH . '/AuditLog.php';

header('Content-Type: application/json; charset=utf-8');

admin_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST 요청만 허용됩니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

admin_deny_write_json('settlement');

// 멀티테넌시: 업로드 소유 대리점 결정 (대리점 계정은 자기 대리점, 본사/총판은 선택)
require_once INC_PATH . '/Org.php';
if (admin_org_level() === Org::LEVEL_AGENCY) {
    $agencyId = admin_org_id();
} else {
    $agencyId  = (int) ($_POST['agency_id'] ?? 0);
    $agencyOrg = $agencyId > 0 ? Org::find($agencyId) : null;
    if ($agencyOrg === null || (string) $agencyOrg['level'] !== Org::LEVEL_AGENCY || !Org::canAccessAgency($agencyId)) {
        echo json_encode(['ok' => false, 'error' => '업로드할 대리점을 선택하세요.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$platform  = trim((string) ($_POST['platform'] ?? 'coupang'));
$dateInput = trim((string) ($_POST['settlement_date'] ?? ''));

if (!in_array($platform, ['baemin', 'coupang', 'other'], true)) {
    $platform = 'coupang';
}

// 미리보기(파싱+매칭만, 저장 안 함) 모드
$dryRun = (string) ($_POST['mode'] ?? '') === 'preview';

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $errCode = $_FILES['file']['error'] ?? 'N/A';
    echo json_encode(['ok' => false, 'error' => "파일 업로드 실패 (코드: {$errCode})"], JSON_UNESCAPED_UNICODE);
    exit;
}

$origName = (string) ($_FILES['file']['name'] ?? 'upload.xlsx');
$tmpPath  = (string) ($_FILES['file']['tmp_name'] ?? '');

if ($tmpPath === '' || !is_file($tmpPath)) {
    echo json_encode(['ok' => false, 'error' => '업로드된 임시 파일을 찾을 수 없습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
if ($ext !== 'xlsx') {
    echo json_encode(['ok' => false, 'error' => '.xlsx 파일만 업로드 가능합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$settlementDate = '';
if (preg_match('/(\d{4})(\d{2})(\d{2})/', $origName, $m)) {
    $settlementDate = "{$m[1]}-{$m[2]}-{$m[3]}";
}
if ($settlementDate === '' && $dateInput !== '') {
    $settlementDate = $dateInput;
}
if ($settlementDate === '') {
    $settlementDate = date('Y-m-d');
}

$teamName   = '';
$regionName = '';
$baseName   = pathinfo($origName, PATHINFO_FILENAME);
$parts      = explode('_', $baseName);
if (count($parts) >= 3) {
    $teamName   = $parts[0] ?? '';
    $regionName = implode('_', array_slice($parts, 1, -1));
}

$fileHash = hash_file('sha256', $tmpPath) ?: '';
$dupError = settlement_upload_duplicate_error($platform, $settlementDate, $origName, $fileHash, $agencyId);
$dupWarning = null;
if ($dupError !== null) {
    if ($dryRun) {
        // 미리보기에서는 막지 않고 경고만 (확정 시 차단)
        $dupWarning = $dupError;
    } else {
        echo json_encode(['ok' => false, 'error' => $dupError, 'duplicate' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$metaJson = json_encode(
    ['team' => $teamName, 'region' => $regionName, 'file_hash' => $fileHash],
    JSON_UNESCAPED_UNICODE
);

$uploadPassword = SettlementExcelConfig::normalizePassword((string) ($_POST['excel_password'] ?? ''));
$passwords      = SettlementExcelConfig::passwordsToTry(
    $platform,
    $uploadPassword !== '' ? $uploadPassword : null,
    $agencyId
);

$parsePath = $tmpPath;
$parser    = new XlsxParser();
try {
    $parsePath = XlsxDecrypt::prepareForParsing($tmpPath, $passwords, $platform);
    $parser->open($parsePath);
    $parsed     = $parser->parseDailySheet($settlementDate);
    $deductions = $parser->parseDeductionSheet();
} catch (Throwable $e) {
    if (isset($parser)) {
        $parser->close();
    }
    XlsxDecrypt::cleanupTemps();
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

$analysis     = SettlementPlatformDetect::analyze($parser, $origName, $settlementDate);
$forceConfirm = (string) ($_POST['confirm_platform_mismatch'] ?? '') === '1';
$mismatchErr  = SettlementPlatformDetect::mismatchError($platform, $analysis, $forceConfirm);
if ($mismatchErr !== null) {
    $parser->close();
    XlsxDecrypt::cleanupTemps();
    $labels = SettlementPlatformDetect::labels();
    echo json_encode([
        'ok'                => false,
        'error'             => $mismatchErr,
        'code'              => 'platform_mismatch',
        'detected_platform' => $analysis['platform'],
        'detected_label'    => $analysis['platform'] !== null
            ? ($labels[$analysis['platform']] ?? $analysis['platform'])
            : '',
        'selected_platform' => $platform,
        'confidence'        => $analysis['confidence'],
        'reasons'           => $analysis['reasons'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$parser->close();
XlsxDecrypt::cleanupTemps();

$rows = $parsed['rows'] ?? [];
if ($rows === []) {
    echo json_encode(['ok' => false, 'error' => '파싱된 데이터가 없습니다. 파일 형식을 확인해 주세요.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── 미리보기 모드: 파싱+매칭 결과만 반환 (저장 안 함) ──────────────
if ($dryRun) {
    $previewRows = [];
    $matchedCnt = 0;
    $unmatchedCnt = 0;
    foreach ($rows as $row) {
        $rid = settlement_match_rider_id($platform, (string) $row['license_id'], (string) $row['name'], $agencyId);
        $rInfo = null;
        if ($rid !== null) {
            $rInfo = db_row('SELECT name, rider_code FROM riders WHERE id = ? LIMIT 1', [$rid]);
            $matchedCnt++;
        } else {
            $unmatchedCnt++;
        }
        $previewRows[] = [
            'license_id'    => (string) $row['license_id'],
            'name_raw'      => (string) $row['name_raw'],
            'name'          => (string) $row['name'],
            'order_count'   => (int) $row['order_count'],
            'gross_amount'  => (int) $row['gross_amount'],
            'payout_amount' => (int) $row['payout_amount'],
            'matched'       => $rid !== null,
            'rider_id'      => $rid,
            'rider_name'    => (string) ($rInfo['name'] ?? ''),
            'rider_code'    => (string) ($rInfo['rider_code'] ?? ''),
        ];
    }

    $dedCount = 0;
    foreach ($deductions as $ded) {
        if (!($ded['order_no'] === '' && $ded['amount'] === 0)) {
            $dedCount++;
        }
    }

    echo json_encode([
        'ok'                => true,
        'preview'           => true,
        'settlement_date'   => $settlementDate,
        'platform'          => $platform,
        'agency_id'         => $agencyId,
        'team'              => $teamName,
        'region'            => $regionName,
        'summary'           => [
            'total'      => count($rows),
            'matched'    => $matchedCnt,
            'unmatched'  => $unmatchedCnt,
            'deductions' => $dedCount,
        ],
        'rows'              => $previewRows,
        'duplicate_warning' => $dupWarning,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$adminId = (int) ($_SESSION['admin_id'] ?? 0);
if ($adminId <= 0) {
    $adminId = null;
}

try {
    $result = db_transaction(static function () use (
        $platform,
        $settlementDate,
        $origName,
        $metaJson,
        $fileHash,
        $rows,
        $deductions,
        $adminId,
        $agencyId
    ): array {
        $dupError = settlement_upload_duplicate_error($platform, $settlementDate, $origName, $fileHash, $agencyId);
        if ($dupError !== null) {
            throw new RuntimeException($dupError);
        }

        $totalRows = count($rows);
        $uploadId  = db_insert(
            'INSERT INTO settlement_uploads
                (kind, platform, agency_id, original_filename, stored_path, settlement_date,
                 total_rows, ok_rows, skipped_rows, error_rows, status, operator_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?, ?)',
            ['daily', $platform, $agencyId, $origName, $metaJson, $settlementDate, $totalRows, 'parsed', $adminId]
        );

        $inserted  = 0;
        $matched   = 0;
        $unmatched = [];

        foreach ($rows as $row) {
            $riderId = settlement_match_rider_id($platform, (string) $row['license_id'], (string) $row['name'], $agencyId);

            if ($riderId !== null) {
                $matched++;
            } else {
                $unmatched[] = $row['name_raw'];
            }

            db_insert(
                'INSERT INTO settlement_daily_riders
                    (upload_id, settlement_date, platform, rider_id, license_id, rider_name_raw,
                     order_count, gross_amount, fee_pickup, fee_delivery, fee_area,
                     fee_dist_cnt, fee_dist_surge, fee_pickup_cnt, fee_pickup_surge,
                     fee_dest_cnt, fee_dest_surge, fee_weather_cnt, fee_weather,
                     fee_promo1, fee_promo2, fee_promo3, fee_promo4, payout_amount)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $uploadId,
                    $settlementDate,
                    $platform,
                    $riderId,
                    $row['license_id'],
                    $row['name_raw'],
                    $row['order_count'],
                    $row['gross_amount'],
                    $row['fee_pickup'],
                    $row['fee_delivery'],
                    $row['fee_area'],
                    $row['fee_dist_cnt'],
                    $row['fee_dist_surge'],
                    $row['fee_pickup_cnt'],
                    $row['fee_pickup_surge'],
                    $row['fee_dest_cnt'],
                    $row['fee_dest_surge'],
                    $row['fee_weather_cnt'],
                    $row['fee_weather'],
                    $row['fee_promo1'],
                    $row['fee_promo2'],
                    $row['fee_promo3'],
                    $row['fee_promo4'],
                    $row['payout_amount'],
                ]
            );
            $inserted++;
        }

        $deductionCount = 0;
        foreach ($deductions as $ded) {
            if ($ded['order_no'] === '' && $ded['amount'] === 0) {
                continue;
            }

            db_insert(
                'INSERT INTO settlement_weekly_deductions
                    (upload_id, week_start, order_date, order_no, rider_id, rider_name_raw,
                     deduction_type, store_name, assigned_at, menu_price, delivery_fee, amount)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $uploadId,
                    $settlementDate,
                    settlement_parse_date($ded['order_date']),
                    $ded['order_no'],
                    null,
                    '',
                    $ded['type'],
                    $ded['store_name'],
                    null,
                    $ded['menu_price'],
                    $ded['delivery_fee'],
                    $ded['amount'],
                ]
            );
            $deductionCount++;
        }

        $errorRows = count($unmatched);
        db_execute(
            'UPDATE settlement_uploads
                SET ok_rows = ?, skipped_rows = 0, error_rows = ?, status = ?
              WHERE id = ?',
            [$matched, $errorRows, 'parsed', $uploadId]
        );

        return [
            'upload_id'  => $uploadId,
            'inserted'   => $inserted,
            'matched'    => $matched,
            'unmatched'  => $unmatched,
            'deductions' => $deductionCount,
        ];
    });
} catch (Throwable $e) {
    $msg = $e->getMessage();
    if (str_contains($msg, 'settlement_daily_riders')) {
        $msg .= ' — php migrate.php 를 한 번 실행해 주세요.';
    }
    $isDup = str_contains($msg, '이미 업로드') || str_contains($msg, '이미 등록');
    echo json_encode(['ok' => false, 'error' => $msg, 'duplicate' => $isDup], JSON_UNESCAPED_UNICODE);
    exit;
}

AuditLog::record(
    'settlement.upload',
    $origName,
    "{$platform} · {$settlementDate} · {$result['inserted']}명 저장"
);

echo json_encode([
    'ok'         => true,
    'upload_id'  => $result['upload_id'],
    'date'       => $settlementDate,
    'team'       => $teamName,
    'region'     => $regionName,
    'rows'       => $result['inserted'],
    'matched'    => $result['matched'],
    'deductions' => $result['deductions'],
    'unmatched'  => $result['unmatched'],
    'message'    => "총 {$result['inserted']}명 정산 데이터가 저장되었습니다. (라이더 매칭 {$result['matched']}명)",
], JSON_UNESCAPED_UNICODE);

/**
 * @param mixed $value
 */
function settlement_parse_date($value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    if (is_numeric($value)) {
        $unix = ((int) $value - 25569) * 86400;

        return gmdate('Y-m-d', $unix);
    }
    $s = trim((string) $value);
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) {
        return substr($s, 0, 10);
    }

    return null;
}

/**
 * 정산 행 라이더 매칭 (대리점 범위 내). 라이선스 ID → 이름 순.
 */
function settlement_match_rider_id(string $platform, string $licenseId, string $name, int $agencyId): ?int
{
    if ($licenseId !== '') {
        $rp = db_row(
            'SELECT rp.rider_id FROM rider_platforms rp
               INNER JOIN riders r ON r.id = rp.rider_id
              WHERE rp.platform = ? AND rp.external_id = ? AND r.agency_id = ?',
            [$platform, $licenseId, $agencyId]
        );
        if ($rp) {
            return (int) $rp['rider_id'];
        }
    }
    if ($name !== '') {
        $r = db_row('SELECT id FROM riders WHERE name = ? AND agency_id = ? LIMIT 1', [$name, $agencyId]);
        if ($r) {
            return (int) $r['id'];
        }
    }

    return null;
}

/**
 * 동일 파일·동일 일자 정산 중복 업로드 여부
 */
function settlement_upload_duplicate_error(
    string $platform,
    string $settlementDate,
    string $origName,
    string $fileHash,
    int $agencyId
): ?string {
    if (!db_table_exists('settlement_uploads')) {
        return null;
    }

    // 중복 판정은 같은 대리점 범위 내에서 (대리점마다 같은 날짜 파일을 각각 업로드)
    $rows = db_rows(
        'SELECT id, original_filename, stored_path
           FROM settlement_uploads
          WHERE kind = ? AND platform = ? AND settlement_date = ? AND agency_id = ?
          ORDER BY id DESC',
        ['daily', $platform, $settlementDate, $agencyId]
    );

    foreach ($rows as $row) {
        $existingId   = (int) ($row['id'] ?? 0);
        $existingName = (string) ($row['original_filename'] ?? '');

        if ($existingName === $origName) {
            return "이미 업로드된 파일입니다. ({$origName}, 귀속일 {$settlementDate}, 업로드 #{$existingId}) "
                . '수정이 필요하면 기존 업로드를 삭제한 뒤 다시 올려 주세요.';
        }

        if ($fileHash !== '') {
            $meta = json_decode((string) ($row['stored_path'] ?? ''), true);
            if (is_array($meta) && ($meta['file_hash'] ?? '') === $fileHash) {
                return "동일한 파일 내용이 이미 등록되어 있습니다. (기존: {$existingName}, 업로드 #{$existingId})";
            }
        }
    }

    if ($rows !== []) {
        $first     = $rows[0];
        $existingId   = (int) ($first['id'] ?? 0);
        $existingName = (string) ($first['original_filename'] ?? '');

        return "해당 일자·플랫폼 정산이 이미 등록되어 있습니다. ({$settlementDate}, 기존: {$existingName}, 업로드 #{$existingId}) "
            . '다른 파일로 교체하려면 기존 업로드를 삭제한 뒤 다시 업로드하세요.';
    }

    return null;
}
