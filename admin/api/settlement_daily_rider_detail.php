<?php

declare(strict_types=1);

/**
 * settlement_daily_riders 원본 1행 상세 조회 (엑셀 파싱 원본 + 정산 반영과 같은 공제 산식)
 * GET ?id=N
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/Org.php';
require_once INC_PATH . '/SettlementLedger.php';
require_once INC_PATH . '/SettlementAmounts.php';

header('Content-Type: application/json; charset=utf-8');

if (!admin_is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => '인증이 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$err = static function (string $msg, int $code = 422): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
};

$id = (int) ($_GET['id'] ?? 0);
if ($id < 1) {
    $err('id가 올바르지 않습니다.', 400);
}

$row = db_row(
    'SELECT dr.*,
            u.agency_id AS upload_agency_id, u.platform AS upload_platform, u.original_filename,
            r.id AS matched_rider_id, r.rider_code, r.name AS rider_name, r.phone, r.team_code,
            r.status AS rider_status, r.agency_id AS rider_agency_id
       FROM settlement_daily_riders dr
       INNER JOIN settlement_uploads u ON u.id = dr.upload_id
       LEFT JOIN riders r ON r.id = dr.rider_id
      WHERE dr.id = ?
      LIMIT 1',
    [$id]
);

if ($row === null) {
    $err('데이터를 찾을 수 없습니다.', 404);
}

if (!Org::canAccessAgency((int) ($row['upload_agency_id'] ?? 0))) {
    $err('이 데이터에 접근할 권한이 없습니다.', 403);
}

$platformLabels = ['baemin' => '배달의민족', 'coupang' => '쿠팡이츠', 'other' => '기타'];
$statusLabels   = ['active' => '활동 중', 'suspended' => '일시 정지', 'leave_request' => '탈퇴 요청', 'offboarded' => '계약 종료'];
$matched        = $row['matched_rider_id'] !== null;

$orgId   = (int) ($row['upload_agency_id'] ?? 0) ?: null;
$preview = SettlementLedger::previewFromDailyRow($row, $orgId);
$earn    = $preview['earn'];
$hourly  = (int) ($row['hourly_insurance'] ?? 0);

$weekly = [];
foreach (SettlementAmounts::excelDeductions(
    (int) $row['upload_id'],
    (int) ($row['rider_id'] ?? 0),
    (string) $row['rider_name_raw']
) as $w) {
    $weekly[] = [
        'type'       => $w['type'],
        'store_name' => $w['store_name'],
        'amount'     => $w['amount'],
        'order_date' => $w['order_date'],
    ];
}

// ── 배민 정산금액 구성 ────────────────────────────────────────────────────
// 배민은 일자 요약에 **총액(배달처리비)만** 들어 있고 구성 컬럼은 0이다(쿠팡 체계와 달라서).
// 그래서 「정산금액 구성」이 배민만 비어 보였다. 구성은 오더별 상세에 있으므로 **여기서 합산해
// 표시용으로만** 내려준다 — 일자 요약 컬럼을 채우면 `SettlementAmounts::exVat()` 가 구성 합을
// 정산 기준액으로 삼아 **정산액이 달라진다**(실측: 구성 합이 배달처리비보다 하루 2,400원까지 작다).
// 차액은 우리가 아직 안 읽는 할증 열이라, 합계가 맞도록 「기타」 로 묶어 보여준다.
$compose = null;
if ((string) $row['platform'] === 'baemin' && db_table_exists('settlement_order_details')) {
    $where  = 'upload_id = ? AND settlement_date = ?';
    $params = [(int) $row['upload_id'], (string) $row['settlement_date']];
    if ((int) ($row['rider_id'] ?? 0) > 0) {
        $where   .= ' AND rider_id = ?';
        $params[] = (int) $row['rider_id'];
    } else {
        $where   .= ' AND rider_name_raw = ?';
        $params[] = (string) $row['rider_name_raw'];
    }
    $o = db_row(
        "SELECT COUNT(*) c,
                COALESCE(SUM(fee_delivery),0) base, COALESCE(SUM(fee_area),0) area,
                COALESCE(SUM(fee_weather),0) weather, COALESCE(SUM(fee_promo1),0) peak,
                COALESCE(SUM(fee_promo2),0) extra, COALESCE(SUM(fee_promo3),0) bulk,
                COALESCE(SUM(fee_promo4),0) area2
           FROM settlement_order_details WHERE {$where}",
        $params
    );
    if ($o !== null && (int) $o['c'] > 0) {
        // 라벨은 배민 정산서 표기 그대로. (DB 컬럼명이 쿠팡 기준이라 promo1~3 에 담겨 있을 뿐이다.)
        $parts = [
            ['기본단가', (int) $o['base']],
            ['지역 할증', (int) $o['area']],
            ['지역할증2', (int) $o['area2']],
            ['기상할증', (int) $o['weather']],
            ['피크할증', (int) $o['peak']],
            ['추가할증', (int) $o['extra']],
            ['대량할증', (int) $o['bulk']],
        ];
        $sum     = array_sum(array_column($parts, 1));
        $compose = [];
        foreach ($parts as [$label, $amt]) {
            if ($amt !== 0) {
                $compose[] = ['label' => $label, 'amount' => $amt];
            }
        }
        $etc = $earn - $sum;
        if ($etc !== 0) {
            $compose[] = ['label' => '기타(미분류 할증)', 'amount' => $etc];
        }
    }
}

$feesOut = [];
foreach ($preview['fees'] as $f) {
    $feesOut[] = [
        'fee_code' => $f['fee_code'],
        'label'    => $f['label'],
        'amount'   => (int) $f['amount'],
    ];
}

echo json_encode([
    'ok'   => true,
    'row'  => [
        'id'               => (int) $row['id'],
        'upload_id'        => (int) $row['upload_id'],
        'settlement_date'  => (string) $row['settlement_date'],
        'platform'         => (string) $row['platform'],
        'platform_label'   => $platformLabels[$row['platform']] ?? $row['platform'],
        'original_filename'=> (string) ($row['original_filename'] ?? ''),
        'license_id'       => (string) $row['license_id'],
        'rider_name_raw'   => (string) $row['rider_name_raw'],
        'order_count'      => (int) $row['order_count'],
        'gross_amount'     => $earn,
        'earn_amount'      => $earn,
        'fee_hourly'       => $hourly,
        'hourly_insurance' => $hourly,
        'support_amount'   => (int) ($row['support_amount'] ?? 0),
        'fees'             => $feesOut,
        'total_fee'        => $preview['total_fee'],
        'estimated_net'    => $preview['net'],
        'fee_pickup'       => (int) $row['fee_pickup'],
        'fee_delivery'     => (int) $row['fee_delivery'],
        'fee_area'         => (int) $row['fee_area'],
        'fee_dist_cnt'     => (int) $row['fee_dist_cnt'],
        'fee_dist_surge'   => (int) $row['fee_dist_surge'],
        'fee_pickup_cnt'   => (int) $row['fee_pickup_cnt'],
        'fee_pickup_surge' => (int) $row['fee_pickup_surge'],
        'fee_dest_cnt'     => (int) $row['fee_dest_cnt'],
        'fee_dest_surge'   => (int) $row['fee_dest_surge'],
        'fee_weather_cnt'  => (int) $row['fee_weather_cnt'],
        'fee_weather'      => (int) $row['fee_weather'],
        'fee_promo1'       => (int) $row['fee_promo1'],
        'fee_promo2'       => (int) $row['fee_promo2'],
        'fee_promo3'       => (int) $row['fee_promo3'],
        'fee_promo4'       => (int) $row['fee_promo4'],
        // 배민처럼 일자 요약에 구성이 없는 플랫폼용 — 있으면 화면이 이걸 그대로 쓴다.
        'compose'          => $compose,
        'weekly_deductions'=> $weekly,
        'created_at'       => substr((string) $row['created_at'], 0, 19),
        'matched'          => $matched,
        'rider'            => $matched ? [
            'id'           => (int) $row['matched_rider_id'],
            'rider_code'   => (string) $row['rider_code'],
            'name'         => (string) $row['rider_name'],
            'phone'        => (string) $row['phone'],
            'team_code'    => (string) $row['team_code'],
            'status'       => (string) $row['rider_status'],
            'status_label' => $statusLabels[$row['rider_status']] ?? (string) $row['rider_status'],
        ] : null,
    ],
], JSON_UNESCAPED_UNICODE);
