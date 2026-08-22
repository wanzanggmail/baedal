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
require_once INC_PATH . '/SettlementAmounts.php';

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
// 파일명은 브라우저·OS가 보내는 값이라 UTF-8이 아닐 수 있다(윈도우 CP949 등).
// 그대로 두면 utf8mb4 컬럼 INSERT에서 "Incorrect string value"로 업로드가 통째로 실패한다.
if (!mb_check_encoding($origName, 'UTF-8')) {
    $converted = @mb_convert_encoding($origName, 'UTF-8', 'CP949, EUC-KR, SJIS, UTF-8');
    $origName  = is_string($converted) && mb_check_encoding($converted, 'UTF-8')
        ? $converted
        : preg_replace('/[^\x20-\x7E.]/', '_', $origName); // 그래도 안 되면 ASCII로 강등
}
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

// 팀/지역은 파일명(팀_지역_날짜.xlsx)에서 뽑되, 업로드 폼에서 직접 지정하면 그 값을 우선한다.
// ⚠️ 반드시 유니코드 정규화(NFC)할 것 — 업로드 환경에 따라 조합형/완성형이 섞여 들어와
//    "눈에는 같은데 다른 팀지역"이 되면 같은 날 중복 정산이 생긴다(2026-08-04 실데이터 사고).
$teamName   = '';
$regionName = '';
$baseName   = pathinfo($origName, PATHINFO_FILENAME);
$parts      = explode('_', $baseName);
if (count($parts) >= 3) {
    $teamName   = $parts[0] ?? '';
    $regionName = implode('_', array_slice($parts, 1, -1));
}
$teamInput   = trim((string) ($_POST['team_name'] ?? ''));
$regionInput = trim((string) ($_POST['region_name'] ?? ''));
if ($teamInput !== '') {
    $teamName = $teamInput;
}
if ($regionInput !== '') {
    $regionName = $regionInput;
}
$teamName   = mb_substr(normalize_hangul_nfc($teamName), 0, 60);
$regionName = mb_substr(normalize_hangul_nfc($regionName), 0, 60);

$fileHash = hash_file('sha256', $tmpPath) ?: '';
$dupError = settlement_upload_duplicate_error($platform, $settlementDate, $origName, $fileHash, $agencyId, $teamName, $regionName);
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
// 🔑 일일/주간은 **열기 암호가 다르다**(배민 확인). 어느 쪽인지 아직 모르므로 둘 다 시도하고,
//    파일을 연 뒤 시트 구성으로 종류를 판별한다($detectedKind).
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

    // 📄 일일/주간 자동 판별 — 사용자가 고르지 않아도 시트 구성으로 갈린다.
    //    주간이면 라이더별 주간 정산(을지)만 읽고, 일일 파싱 경로는 아예 타지 않는다.
    $kindDetect   = SettlementPlatformDetect::detectKind($parser, $origName);
    $detectedKind = (string) $kindDetect['kind'];

    if ($detectedKind === 'weekly') {
        // 배민은 을지(라이더별 표)에서, 쿠팡은 시간제보험(차감) 시트에서 읽는다.
        // 두 플랫폼의 주정산서 구조가 완전히 달라 파서를 나눈다.
        $weeklyRows = $parser->parseBaeminWeeklyRiders();
        $weeklyIns  = [];
        if ($weeklyRows === []) {
            // 쿠팡 주정산서 — 반영 대상(시간제보험)만 뽑는다. 라이더별 표는 일일과 컬럼이 같아
            // 그대로 읽으면 한 주치가 하루치로 저장되므로 **의도적으로 읽지 않는다**.
            $weeklyIns = $parser->parseHourlyInsuranceSheet();
            if ($weeklyIns === []) {
                echo json_encode([
                    'ok'    => false,
                    'error' => '주간 정산서로 판별했지만 반영할 내역(라이더별 정산 또는 시간제보험)을 읽지 못했습니다. 파일 형식을 확인해 주세요.',
                    'kind'  => 'weekly',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        settlement_weekly_handle($weeklyRows, $weeklyIns, $kindDetect, $origName, $platform, $agencyId, $teamName, $regionName, $metaJson, $dryRun, $dupWarning);
        exit; // settlement_weekly_handle()이 응답까지 마친다
    }

    if ($platform === 'baemin') {
        // 배민: 시트 1개(주문 단위) → 라이더·운행일별 집계로 정규화
        $baeminOrders           = $parser->parseBaeminOrders();
        [$parsed, $orderDetails] = settlement_baemin_normalize($baeminOrders, $settlementDate);
        $deductions             = [];
        $hourlyIns              = [];
        $supports               = [];
        $addSupports            = [];
        // 업로드 표기일 = 파일명 범위 시작 대신 실제 최소 운행일(더 정확)
        $baeminDates = array_column($parsed['rows'], 'settlement_date');
        if ($baeminDates !== []) {
            $settlementDate = min($baeminDates);
        }
    } else {
        $parsed       = $parser->parseDailySheet($settlementDate);
        $deductions   = $parser->parseDeductionSheet();
        $orderDetails = $parser->parseOrderDetailSheet();
        $hourlyIns    = $parser->parseHourlyInsuranceSheet();
        // 지원금/추가지원금(§7 격차): 정산금액과 별개로 존재하며 최종 지급액에 가산되는 항목(parser.py 확인).
        $supports     = $parser->parseSupportSheet();
        $addSupports  = $parser->parseAddSupportSheet();
    }
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
        $matchKey = settlement_row_match_key($platform, $row);
        $rid = settlement_match_rider_id($platform, $matchKey, (string) $row['name'], $agencyId);
        $rInfo = null;
        if ($rid !== null) {
            $rInfo = db_row('SELECT name, rider_code FROM riders WHERE id = ? LIMIT 1', [$rid]);
            $matchedCnt++;
        } else {
            $unmatchedCnt++;
        }
        $previewRows[] = [
            'license_id'    => (string) $row['license_id'],
            'match_key'     => $matchKey,   // 연결/등록 시 external_id 로 사용(쿠팡=성함, 배민=UserID)
            'name_raw'      => (string) $row['name_raw'],
            'name'          => (string) $row['name'],
            'order_count'   => (int) $row['order_count'],
            'gross_amount'  => SettlementAmounts::exVat($row),
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
            'total'            => count($rows),
            'matched'          => $matchedCnt,
            'unmatched'        => $unmatchedCnt,
            'deductions'       => $dedCount,
            'order_details'    => count($orderDetails),
            'hourly_insurance' => count($hourlyIns),
            'support'          => count($supports),
            'add_support'      => count($addSupports),
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
        $orderDetails,
        $hourlyIns,
        $supports,
        $addSupports,
        $adminId,
        $agencyId,
        $teamName,
        $regionName
    ): array {
        $dupError = settlement_upload_duplicate_error($platform, $settlementDate, $origName, $fileHash, $agencyId);
        if ($dupError !== null) {
            throw new RuntimeException($dupError);
        }

        $totalRows = count($rows);
        $uploadId  = db_insert(
            'INSERT INTO settlement_uploads
                (kind, platform, agency_id, team_name, region_name, original_filename, stored_path, settlement_date,
                 total_rows, ok_rows, skipped_rows, error_rows, status, operator_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?, ?)',
            ['daily', $platform, $agencyId, $teamName, $regionName, $origName, $metaJson, $settlementDate, $totalRows, 'parsed', $adminId]
        );

        // 시간제보험(§7 #6): 라이더 이름(원본 문자열) → 금액. 종합탭 daily_riders.hourly_insurance 채우기 + 별도 내역 테이블 저장에 공용.
        $hourlyByNameRaw = [];
        foreach ($hourlyIns as $hi) {
            $hourlyByNameRaw[$hi['name_raw']] = ($hourlyByNameRaw[$hi['name_raw']] ?? 0) + (int) $hi['amount'];
        }

        // 지원금+추가지원금 합계: 라이더 이름(원본 문자열) → 금액. daily_riders.support_amount 채우기 + 원본은 별도 저장.
        $supportByNameRaw = [];
        foreach ($supports as $sp) {
            $supportByNameRaw[$sp['name_raw']] = ($supportByNameRaw[$sp['name_raw']] ?? 0) + (int) $sp['amount'];
        }
        foreach ($addSupports as $as) {
            $supportByNameRaw[$as['name_raw']] = ($supportByNameRaw[$as['name_raw']] ?? 0) + (int) $as['amount'];
        }

        $inserted        = 0;
        $matched         = 0;
        $unmatched       = [];
        $nameRawToRiderId = [];

        foreach ($rows as $row) {
            $riderId = settlement_match_rider_id($platform, settlement_row_match_key($platform, $row), (string) $row['name'], $agencyId);

            if ($riderId !== null) {
                $matched++;
                $nameRawToRiderId[$row['name_raw']] = $riderId;
            } else {
                $unmatched[] = $row['name_raw'];
            }

            $hourlyForRider = (int) ($hourlyByNameRaw[$row['name_raw']] ?? 0);
            $supportForRider = (int) ($supportByNameRaw[$row['name_raw']] ?? 0);
            $rowDate        = (string) ($row['settlement_date'] ?? $settlementDate);

            db_insert(
                'INSERT INTO settlement_daily_riders
                    (upload_id, settlement_date, platform, rider_id, license_id, rider_name_raw,
                     order_count, gross_amount, fee_pickup, fee_delivery, fee_area,
                     fee_dist_cnt, fee_dist_surge, fee_pickup_cnt, fee_pickup_surge,
                     fee_dest_cnt, fee_dest_surge, fee_weather_cnt, fee_weather,
                     fee_promo1, fee_promo2, fee_promo3, fee_promo4, payout_amount, hourly_insurance,
                     support_amount)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $uploadId,
                    $rowDate,
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
                    $hourlyForRider,
                    $supportForRider,
                ]
            );
            $inserted++;
        }

        // 오더별 상세내역(주문 단위 원본) — 이름은 같은 파일 내 종합탭 매칭 결과를 우선 사용, 없으면 DB 조회로 폴백.
        $orderDetailCount = 0;
        foreach ($orderDetails as $od) {
            $riderId = $nameRawToRiderId[$od['name_raw']]
                ?? settlement_match_rider_id($platform, '', $od['name'], $agencyId);

            db_insert(
                'INSERT INTO settlement_order_details
                    (upload_id, settlement_date, rider_id, rider_name_raw, order_no, store_name,
                     pickup_area, delivery_area, assigned_at, accepted_at, delivered_at, duration_minutes,
                     peak_time, distance_m, delivery_type, fee_pickup, fee_delivery, fee_area,
                     fee_dist_surge, fee_pickup_surge, fee_dest_surge, fee_weather,
                     fee_promo1, fee_promo2, fee_promo3, fee_promo4, net_amount)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $uploadId,
                    (string) ($od['settlement_date'] ?? $settlementDate),
                    $riderId,
                    $od['name_raw'],
                    $od['order_no'],
                    $od['store_name'],
                    $od['pickup_area'],
                    $od['delivery_area'],
                    $od['assigned_at'],
                    $od['accepted_at'],
                    $od['delivered_at'],
                    $od['duration_minutes'],
                    $od['peak_time'],
                    $od['distance_m'],
                    $od['delivery_type'],
                    $od['fee_pickup'],
                    $od['fee_delivery'],
                    $od['fee_area'],
                    $od['fee_dist_surge'],
                    $od['fee_pickup_surge'],
                    $od['fee_dest_surge'],
                    $od['fee_weather'],
                    $od['fee_promo1'],
                    $od['fee_promo2'],
                    $od['fee_promo3'],
                    $od['fee_promo4'],
                    $od['net_amount'],
                ]
            );
            $orderDetailCount++;
        }

        // 시간제보험 내역(라이더·일자별) — 신고/조회용 별도 저장.
        $hourlyInsCount = 0;
        foreach ($hourlyIns as $hi) {
            $riderId = $nameRawToRiderId[$hi['name_raw']]
                ?? settlement_match_rider_id($platform, '', $hi['name'], $agencyId);

            db_insert(
                'INSERT INTO settlement_hourly_insurance
                    (upload_id, settlement_date, occurred_date, rider_id, rider_name_raw, amount)
                 VALUES (?,?,?,?,?,?)',
                [$uploadId, $settlementDate, $hi['occurred_date'], $riderId, $hi['name_raw'], $hi['amount']]
            );
            $hourlyInsCount++;
        }

        // 지원금/추가지원금 원본 저장(신고/조회용) — daily_riders.support_amount의 근거.
        $supportCount = 0;
        foreach ($supports as $sp) {
            $riderId = $nameRawToRiderId[$sp['name_raw']]
                ?? settlement_match_rider_id($platform, '', $sp['name'], $agencyId);

            db_insert(
                'INSERT INTO settlement_support_amounts
                    (upload_id, settlement_date, rider_id, rider_name_raw, kind, order_no, store_name,
                     pickup_area, delivery_area, assigned_at, accepted_at, delivered_at, duration_minutes,
                     peak_time, amount)
                 VALUES (?,?,?,?,\'support\',?,?,?,?,?,?,?,?,?,?)',
                [
                    $uploadId,
                    (string) ($sp['order_date'] ?: $settlementDate),
                    $riderId,
                    $sp['name_raw'],
                    $sp['order_no'],
                    $sp['store_name'],
                    $sp['pickup_area'],
                    $sp['delivery_area'],
                    $sp['assigned_at'],
                    $sp['accepted_at'],
                    $sp['delivered_at'],
                    $sp['duration_minutes'],
                    $sp['peak_time'],
                    $sp['amount'],
                ]
            );
            $supportCount++;
        }
        foreach ($addSupports as $as) {
            $riderId = $nameRawToRiderId[$as['name_raw']]
                ?? settlement_match_rider_id($platform, '', $as['name'], $agencyId);

            db_insert(
                'INSERT INTO settlement_support_amounts
                    (upload_id, settlement_date, rider_id, rider_name_raw, kind, order_no, category, amount)
                 VALUES (?,?,?,?,\'add_support\',?,?,?)',
                [
                    $uploadId,
                    (string) ($as['order_date'] ?: $settlementDate),
                    $riderId,
                    $as['name_raw'],
                    $as['order_no'],
                    $as['category'],
                    $as['amount'],
                ]
            );
            $supportCount++;
        }

        // 차감내역 — 헤더 기반 매핑으로 정정(구 버전은 배달비를 실제 금액으로 잘못 저장하던 버그가 있었음).
        $deductionCount = 0;
        foreach ($deductions as $ded) {
            if ($ded['order_no'] === '' && $ded['amount'] === 0) {
                continue;
            }

            $riderId = $nameRawToRiderId[$ded['name_raw']]
                ?? settlement_match_rider_id($platform, '', $ded['name'], $agencyId);

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
                    $riderId,
                    $ded['name_raw'],
                    $ded['type'],
                    $ded['store_name'],
                    $ded['assigned_at'],
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
            'upload_id'      => $uploadId,
            'inserted'       => $inserted,
            'matched'        => $matched,
            'unmatched'      => $unmatched,
            'deductions'     => $deductionCount,
            'order_details'  => $orderDetailCount,
            'hourly_insurance' => $hourlyInsCount,
            'support'        => $supportCount,
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

// 쿠팡/기타는 "종합"(라이더 요약)과 "오더별"(건단위) 탭을 각각 별도로 파싱한다.
// 요약탭은 찾았는데 오더별 탭을 못 찾으면(이름 변경·형식 상이 등) 업로드 자체는 성공하지만
// 건단위 데이터가 조용히 0건이 되므로, 놓치기 쉬운 이 상황을 명시적으로 경고한다.
// 배민은 두 데이터가 같은 시트·같은 루프에서 함께 만들어져 이 문제가 구조적으로 없다.
$warnings = [];
if ($platform !== 'baemin' && $result['matched'] > 0 && $result['order_details'] === 0) {
    $warnings[] = '"오더별" 상세 탭을 찾지 못해 건단위(오더상세) 데이터가 저장되지 않았습니다. 파일의 탭 이름/헤더를 확인해 주세요. (라이더 요약은 정상 저장됨)';
}

echo json_encode([
    'ok'         => true,
    'upload_id'  => $result['upload_id'],
    'date'       => $settlementDate,
    'team'       => $teamName,
    'region'     => $regionName,
    'rows'       => $result['inserted'],
    'matched'    => $result['matched'],
    'deductions' => $result['deductions'],
    'order_details' => $result['order_details'],
    'hourly_insurance' => $result['hourly_insurance'],
    'support'    => $result['support'],
    'unmatched'  => $result['unmatched'],
    'warnings'   => $warnings,
    'message'    => "총 {$result['inserted']}명 정산 데이터가 저장되었습니다. (라이더 매칭 {$result['matched']}명, 오더 상세 {$result['order_details']}건, 시간제보험 {$result['hourly_insurance']}건, 지원금 {$result['support']}건)",
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
 * 정산 행 라이더 매칭 — **반드시 대리점(agency_id) 범위 내에서만** 매칭.
 * 라이더는 대리점 이동 시 별도 등록되므로 매칭키(쿠팡=성함, 배민=UserID)는 전역 유일이 아니라
 * 대리점 안에서만 유일하다고 가정한다.
 *
 * @param string $matchKey rider_platforms.external_id 와 대조할 값(쿠팡=성함, 배민=UserID)
 * @param string $nameFallback 정리된 이름(폴백 매칭용)
 */
function settlement_match_rider_id(string $platform, string $matchKey, string $nameFallback, int $agencyId): ?int
{
    if ($agencyId < 1) {
        return null;
    }
    if ($matchKey !== '') {
        $rp = db_row(
            'SELECT rp.rider_id FROM rider_platforms rp
               INNER JOIN riders r ON r.id = rp.rider_id
              WHERE rp.platform = ? AND rp.external_id = ? AND r.agency_id = ? LIMIT 1',
            [$platform, $matchKey, $agencyId]
        );
        if ($rp) {
            return (int) $rp['rider_id'];
        }
    }
    if ($nameFallback !== '') {
        $r = db_row('SELECT id FROM riders WHERE name = ? AND agency_id = ? LIMIT 1', [$nameFallback, $agencyId]);
        if ($r) {
            return (int) $r['id'];
        }
    }

    return null;
}

/**
 * 플랫폼별 매칭키 추출 — 쿠팡=성함(name_raw, "박성준1682"), 배민=UserID(K), 기타=license_id.
 *
 * @param array<string,mixed> $row
 */
function settlement_row_match_key(string $platform, array $row): string
{
    return match ($platform) {
        'coupang' => (string) ($row['name_raw'] ?? ''),
        'baemin'  => (string) ($row['alt_id'] ?? ''),
        default   => (string) ($row['license_id'] ?? ''),
    };
}

/**
 * 배민 주문 행들을 라이더·운행일별로 집계해 종합(daily) 행 + 오더별 상세 행으로 정규화.
 * 쿠팡 parseDailySheet/parseOrderDetailSheet의 반환 shape에 맞춘다(기존 저장 로직 재사용).
 *
 * @param list<array<string,mixed>> $orders
 * @return array{0: array{rows: list<array<string,mixed>>}, 1: list<array<string,mixed>>}
 */
function settlement_baemin_normalize(array $orders, string $fallbackDate): array
{
    $agg          = [];
    $orderDetails = [];
    foreach ($orders as $o) {
        $date  = (string) ($o['settlement_date'] ?? $fallbackDate);
        $riderKey = (string) ($o['rider_id'] ?? '');
        if ($riderKey === '') {
            $riderKey = (string) ($o['user_id'] ?? $o['name_raw'] ?? '');
        }
        $key = $riderKey . '|' . $date;

        if (!isset($agg[$key])) {
            $agg[$key] = [
                'license_id'       => (string) ($o['rider_id'] ?? ''),  // 배민 라이더ID를 외부ID로 사용
                'alt_id'           => (string) ($o['user_id'] ?? ''),
                'name_raw'         => (string) ($o['name_raw'] ?? ''),
                'name'             => (string) ($o['name'] ?? ''),
                'settlement_date'  => $date,
                'order_count'      => 0,
                'gross_amount'     => 0,
                // 쿠팡 전용 세부 수수료 컬럼은 배민 체계와 달라 0으로 둠(금액은 payout이 정확)
                'fee_pickup'       => 0, 'fee_delivery' => 0, 'fee_area' => 0,
                'fee_dist_cnt'     => 0, 'fee_dist_surge' => 0, 'fee_pickup_cnt' => 0, 'fee_pickup_surge' => 0,
                'fee_dest_cnt'     => 0, 'fee_dest_surge' => 0, 'fee_weather_cnt' => 0, 'fee_weather' => 0,
                'fee_promo1'       => 0, 'fee_promo2' => 0, 'fee_promo3' => 0, 'fee_promo4' => 0,
                'payout_amount'    => 0,
            ];
        }
        // 금액은 전 건 합산, 건수는 배민 「처리건수」 규칙(픽업완료 & 금액>0)을 따르는 건만.
        // 상세는 XlsxParser::parseBaeminOrders()의 `counted` 주석 참고.
        if (!empty($o['counted'])) {
            $agg[$key]['order_count']++;
        }
        $agg[$key]['gross_amount']  += (int) ($o['payout'] ?? 0);
        $agg[$key]['payout_amount'] += (int) ($o['payout'] ?? 0);

        // 오더별 상세 (settlement_order_details shape). 배민 수수료는 net_amount(배달처리비)만 정확 저장.
        $orderDetails[] = [
            'settlement_date'  => $date,
            'name_raw'         => (string) ($o['name_raw'] ?? ''),
            'name'             => (string) ($o['name'] ?? ''),
            'order_no'         => (string) ($o['order_no'] ?? ''),
            'store_name'       => (string) ($o['store_name'] ?? ''),
            'pickup_area'      => (string) ($o['pickup_area'] ?? ''),
            'delivery_area'    => (string) ($o['delivery_area'] ?? ''),
            'assigned_at'      => $o['assigned_at'] ?? null,
            'accepted_at'      => $o['accepted_at'] ?? null,
            'delivered_at'     => $o['delivered_at'] ?? null,
            'duration_minutes' => 0,
            'peak_time'        => '',
            'distance_m'       => (int) ($o['distance_m'] ?? 0),
            'delivery_type'    => (string) ($o['delivery_type'] ?? ''),
            'fee_pickup'       => 0,
            'fee_delivery'     => (int) ($o['fee_base'] ?? 0),
            'fee_area'         => (int) ($o['fee_area'] ?? 0),
            'fee_dist_surge'   => 0,
            'fee_pickup_surge' => 0,
            'fee_dest_surge'   => 0,
            'fee_weather'      => (int) ($o['fee_weather'] ?? 0),
            'fee_promo1'       => (int) ($o['fee_peak'] ?? 0),
            'fee_promo2'       => (int) ($o['fee_extra'] ?? 0),
            'fee_promo3'       => (int) ($o['fee_bulk'] ?? 0),
            'fee_promo4'       => 0,
            'net_amount'       => (int) ($o['payout'] ?? 0),
        ];
    }

    return [['rows' => array_values($agg)], $orderDetails];
}

/**
 * 동일 파일·동일 일자 정산 중복 업로드 여부
 */
function settlement_upload_duplicate_error(
    string $platform,
    string $settlementDate,
    string $origName,
    string $fileHash,
    int $agencyId,
    string $teamName = '',
    string $regionName = ''
): ?string {
    if (!db_table_exists('settlement_uploads')) {
        return null;
    }

    // 중복 판정은 같은 대리점 + **같은 팀지역** 범위 내에서.
    // 한 대리점이 같은 날 여러 팀지역 정산서를 올리는 게 정상이므로, 팀지역이 다르면 중복이 아니다.
    $hasTeamCol = in_array('team_name', array_column(db_rows('SHOW COLUMNS FROM settlement_uploads'), 'Field'), true);

    $sql    = 'SELECT id, original_filename, stored_path
                 FROM settlement_uploads
                WHERE kind = ? AND platform = ? AND settlement_date = ? AND agency_id = ?';
    $params = ['daily', $platform, $settlementDate, $agencyId];
    if ($hasTeamCol) {
        $sql     .= ' AND team_name = ? AND region_name = ?';
        $params[] = $teamName;
        $params[] = $regionName;
    }
    $sql .= ' ORDER BY id DESC';

    $rows = db_rows($sql, $params);

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
        $where = trim($teamName . ' ' . $regionName);
        $whereLabel = $where !== '' ? "{$where} " : '';

        return "해당 일자·플랫폼의 {$whereLabel}정산이 이미 등록되어 있습니다. ({$settlementDate}, 기존: {$existingName}, 업로드 #{$existingId}) "
            . '다른 팀지역이라면 팀/지역명을 확인하고, 같은 자료 교체라면 기존 업로드를 삭제한 뒤 다시 업로드하세요.';
    }

    return null;
}

/**
 * **주간 정산서** 처리 — 미리보기/확정 응답까지 여기서 마친다.
 *
 * 일일 경로(`settlement_daily_riders` → 정산 반영)와 **완전히 분리**했다. 주간은 일자별이 아니라
 * 주 단위 1행이라, 같은 테이블에 밀어 넣으면 두 체계가 섞여 어느 쪽 숫자인지 알 수 없게 된다.
 *
 * ## 반영 범위 — **프로모션 + 시간제보험만** (2026-08-22 갑 확정)
 * 갑: *"주간정산서는 프로모션 금액과 시간제 보험만 반영해, (고용 산재 원천)은 기존처럼
 * 일일정산서 업로드 할때 우리 내부 기준에 맞춰서 계산하니까"*
 *
 * 그래서 고용보험·산재보험·원천세는 주간 파일에 값이 있어도 **반영하지 않는다** — 우리
 * 자체 요율(`deduction_global_config`)로 일일 반영 때 계산하는 게 기준이다. 다만 파일에 있는
 * 값은 대조용으로 **저장은 해둔다**(플랫폼이 준 값과 우리 계산이 얼마나 벌어지는지 확인 가능).
 *
 * ⚠️ 아직 **저장만** 하고 지갑 적립은 하지 않는다. 프로모션·시간제보험을 라이더 지갑에 어떤
 *    시점에 넣을지는 일일 반영과 순서가 얽혀 별도 결정이 필요하다.
 *
 * @param list<array<string,mixed>> $rows       배민 을지(라이더별). 쿠팡이면 빈 배열.
 * @param list<array<string,mixed>> $hourlyIns  쿠팡 「시간제보험(차감)」. 배민이면 빈 배열.
 * @param array<string,mixed>       $kindDetect
 */
function settlement_weekly_handle(
    array $rows,
    array $hourlyIns,
    array $kindDetect,
    string $origName,
    string $platform,
    int $agencyId,
    string $teamName,
    string $regionName,
    string $metaJson,
    bool $dryRun,
    ?string $dupWarning
): void {
    // 정산 기간 — 파일명 "20260812~20260818" 에서 뽑고, 없으면 오늘 기준 주간으로 둔다.
    $weekStart = null;
    $weekEnd   = null;
    if (preg_match('/(\d{8})\s*~\s*(\d{8})/', $origName, $m)) {
        $weekStart = substr($m[1], 0, 4) . '-' . substr($m[1], 4, 2) . '-' . substr($m[1], 6, 2);
        $weekEnd   = substr($m[2], 0, 4) . '-' . substr($m[2], 4, 2) . '-' . substr($m[2], 6, 2);
    }
    if ($weekStart === null || $weekEnd === null) {
        $weekEnd   = date('Y-m-d');
        $weekStart = date('Y-m-d', strtotime('-6 days'));
    }

    // 쿠팡 주정산서는 라이더별 표 대신 「시간제보험(차감)」만 읽는다(반영 대상이 그것뿐).
    // 이름별로 합쳐 배민 을지와 같은 형태(`$rows`)로 맞춰, 아래 저장 로직을 그대로 재사용한다.
    if ($rows === [] && $hourlyIns !== []) {
        $byName = [];
        foreach ($hourlyIns as $h) {
            $nm = (string) ($h['name'] ?? '');
            if ($nm === '') {
                continue;
            }
            if (!isset($byName[$nm])) {
                $byName[$nm] = [
                    'user_id' => '', 'name_raw' => (string) ($h['name_raw'] ?? $nm), 'name' => $nm,
                    'order_count' => 0, 'delivery_fee' => 0, 'extra_pay' => 0, 'total_fee' => 0,
                    'hourly_ins' => 0, 'expense' => 0, 'reward' => 0,
                    'emp_ins_rider' => 0, 'acc_ins_rider' => 0, 'settle_amount' => 0,
                    'income_tax' => 0, 'resident_tax' => 0, 'withholding' => 0, 'payout' => 0,
                ];
            }
            $byName[$nm]['hourly_ins'] += (int) ($h['amount'] ?? 0);
        }
        $rows = array_values($byName);
    }

    // 라이더 매칭 — 배민은 User ID, 쿠팡은 성함(이름)이 매칭키다(일일 경로와 동일 기준).
    $matched = 0;
    foreach ($rows as $i => $r) {
        $rid = settlement_match_rider_id($platform, (string) ($r['user_id'] ?? ''), (string) ($r['name'] ?? ''), $agencyId);
        $rows[$i]['rider_id'] = $rid;
        if ($rid !== null) {
            $matched++;
        }
    }

    $sum = static function (string $key) use ($rows): int {
        $t = 0;
        foreach ($rows as $r) {
            $t += (int) ($r[$key] ?? 0);
        }

        return $t;
    };
    $summary = [
        'riders'       => count($rows),
        'matched'      => $matched,
        'unmatched'    => count($rows) - $matched,
        'order_count'  => $sum('order_count'),
        'delivery_fee' => $sum('delivery_fee'),
        'extra_pay'    => $sum('extra_pay'),
        'total_fee'    => $sum('total_fee'),
        'hourly_ins'   => $sum('hourly_ins'),
        'withholding'  => $sum('withholding'),
        'payout'       => $sum('payout'),
    ];

    if ($dryRun) {
        echo json_encode([
            'ok'           => true,
            'preview'      => true,
            'kind'         => 'weekly',
            'kind_reasons' => $kindDetect['reasons'] ?? [],
            'platform'     => $platform,
            'agency_id'    => $agencyId,
            'week_start'   => $weekStart,
            'week_end'     => $weekEnd,
            'summary'      => $summary,
            'duplicate_warning' => $dupWarning,
            'rows'         => array_map(static fn (array $r): array => [
                'user_id'      => (string) ($r['user_id'] ?? ''),
                'name_raw'     => (string) ($r['name_raw'] ?? ''),
                'matched'      => $r['rider_id'] !== null,
                'order_count'  => (int) ($r['order_count'] ?? 0),
                'delivery_fee' => (int) ($r['delivery_fee'] ?? 0),
                'extra_pay'    => (int) ($r['extra_pay'] ?? 0),
                'payout'       => (int) ($r['payout'] ?? 0),
            ], $rows),
        ], JSON_UNESCAPED_UNICODE);

        return;
    }

    if (!db_table_exists('settlement_weekly_riders')) {
        echo json_encode(['ok' => false, 'error' => 'settlement_weekly_riders 테이블이 없습니다. php migrate.php 를 실행하세요.'], JSON_UNESCAPED_UNICODE);

        return;
    }

    $uploadId = db_insert(
        "INSERT INTO settlement_uploads
            (kind, platform, agency_id, team_name, region_name, original_filename, stored_path,
             settlement_date, total_rows, ok_rows, skipped_rows, error_rows, status, operator_id)
         VALUES ('weekly', ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 'parsed', ?)",
        [
            $platform,
            $agencyId,
            $teamName,
            $regionName,
            $origName,
            $metaJson,
            $weekStart,
            count($rows),
            $matched,
            count($rows) - $matched,
            (int) ($_SESSION['admin_id'] ?? 0) ?: null,
        ]
    );

    $saved = 0;
    foreach ($rows as $r) {
        db_execute(
            'INSERT INTO settlement_weekly_riders
                (upload_id, agency_id, week_start, week_end, rider_id, user_id_raw, rider_name_raw,
                 order_count, delivery_fee, extra_pay, total_fee, hourly_ins, expense, reward,
                 emp_ins_rider, acc_ins_rider, settle_amount, income_tax, resident_tax, withholding, payout)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                rider_id = VALUES(rider_id), order_count = VALUES(order_count),
                delivery_fee = VALUES(delivery_fee), extra_pay = VALUES(extra_pay),
                total_fee = VALUES(total_fee), hourly_ins = VALUES(hourly_ins),
                expense = VALUES(expense), reward = VALUES(reward),
                emp_ins_rider = VALUES(emp_ins_rider), acc_ins_rider = VALUES(acc_ins_rider),
                settle_amount = VALUES(settle_amount), income_tax = VALUES(income_tax),
                resident_tax = VALUES(resident_tax), withholding = VALUES(withholding),
                payout = VALUES(payout)',
            [
                $uploadId, $agencyId, $weekStart, $weekEnd,
                $r['rider_id'],
                // UNIQUE(upload_id, user_id_raw) 키 — 배민은 User ID, 쿠팡은 User ID가 없어
                // 성함을 키로 쓴다. 안 그러면 쿠팡 행이 전부 빈 문자열로 겹쳐 1명만 남는다.
                ((string) ($r['user_id'] ?? '')) !== '' ? (string) $r['user_id'] : (string) ($r['name'] ?? ''),
                (string) ($r['name_raw'] ?? ''),
                (int) $r['order_count'], (int) $r['delivery_fee'], (int) $r['extra_pay'], (int) $r['total_fee'],
                (int) $r['hourly_ins'], (int) $r['expense'], (int) $r['reward'],
                (int) $r['emp_ins_rider'], (int) $r['acc_ins_rider'], (int) $r['settle_amount'],
                (int) $r['income_tax'], (int) $r['resident_tax'], (int) $r['withholding'], (int) $r['payout'],
            ]
        );
        $saved++;
    }

    require_once INC_PATH . '/AuditLog.php';
    AuditLog::record('settlement.weekly_upload', (string) $uploadId, sprintf(
        '배민 주간정산서 업로드 · %s~%s · %d명(매칭 %d) · 지급합계 %s원',
        $weekStart,
        $weekEnd,
        count($rows),
        $matched,
        number_format($summary['payout'])
    ));

    echo json_encode([
        'ok'         => true,
        'kind'       => 'weekly',
        'upload_id'  => $uploadId,
        'week_start' => $weekStart,
        'week_end'   => $weekEnd,
        'saved'      => $saved,
        'summary'    => $summary,
        'message'    => sprintf(
            '주간 정산서 저장 완료 — %s~%s · %d명(매칭 %d명) · 지급합계 %s원',
            $weekStart,
            $weekEnd,
            count($rows),
            $matched,
            number_format($summary['payout'])
        ),
    ], JSON_UNESCAPED_UNICODE);
}
