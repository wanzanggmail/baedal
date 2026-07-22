<?php

declare(strict_types=1);

/**
 * settlement_daily_riders 원본 1행 상세 조회 (엑셀 파싱 원본 전체 컬럼)
 * GET ?id=N
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/Org.php';

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

// 멀티테넌시: 업로드 소유 대리점 스코프 밖이면 차단
if (!Org::canAccessAgency((int) ($row['upload_agency_id'] ?? 0))) {
    $err('이 데이터에 접근할 권한이 없습니다.', 403);
}

$platformLabels = ['baemin' => '배달의민족', 'coupang' => '쿠팡이츠', 'other' => '기타'];
$statusLabels   = ['active' => '활동 중', 'suspended' => '일시 정지', 'leave_request' => '탈퇴 요청', 'offboarded' => '계약 종료'];
$matched        = $row['matched_rider_id'] !== null;

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
        'gross_amount'     => (int) $row['gross_amount'],
        'payout_amount'    => (int) $row['payout_amount'],
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
        'hourly_insurance' => (int) ($row['hourly_insurance'] ?? 0),
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
