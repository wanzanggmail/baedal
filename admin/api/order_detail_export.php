<?php

declare(strict_types=1);

/**
 * 오더별 상세 내역 — 필터 결과 전체를 엑셀로 다운로드.
 * GET 파라미터는 admin/views/order_detail_list.php의 필터와 동일(from/to/agency/platform/rider/store/order_no/upload_id).
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/Org.php';
require_once INC_PATH . '/download_response.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

ini_set('display_errors', '0');
if (ob_get_level() === 0) {
    ob_start();
}

$err = static function (string $msg, int $code): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
};

if (!admin_is_logged_in()) {
    $err('인증이 필요합니다.', 401);
}
if (!db_table_exists('settlement_order_details')) {
    $err('settlement_order_details 테이블이 없습니다. php migrate.php 를 실행하세요.', 500);
}

const ORDER_DETAIL_EXPORT_CAP = 20000;

$isAgencyLevel = admin_org_level() === Org::LEVEL_AGENCY;

$filterFrom     = trim((string) ($_GET['from'] ?? ''));
$filterTo       = trim((string) ($_GET['to'] ?? ''));
$filterAgency   = (int) ($_GET['agency'] ?? 0);
$filterPlatform = trim((string) ($_GET['platform'] ?? ''));
$filterRider    = trim((string) ($_GET['rider'] ?? ''));
$filterStore    = trim((string) ($_GET['store'] ?? ''));
$filterOrderNo  = trim((string) ($_GET['order_no'] ?? ''));
$filterUploadId = (int) ($_GET['upload_id'] ?? 0);

if ($filterFrom === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFrom)) {
    $filterFrom = date('Y-m-d', strtotime('-6 days'));
}
if ($filterTo === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterTo)) {
    $filterTo = date('Y-m-d');
}

[$scopeSql, $scopeParams] = Org::agencyScopeClause('u.agency_id');
$conds  = [];
$params = [];
if ($filterUploadId > 0) {
    $conds[] = 'od.upload_id = ?';
    $params[] = $filterUploadId;
} else {
    $conds[] = 'od.settlement_date >= ?';
    $conds[] = 'od.settlement_date <= ?';
    $params = [$filterFrom, $filterTo];
}
if ($scopeSql !== '') {
    $conds[] = $scopeSql;
    $params  = array_merge($params, $scopeParams);
}
if (!$isAgencyLevel && $filterAgency > 0) {
    $conds[] = 'u.agency_id = ?';
    $params[] = $filterAgency;
}
if (in_array($filterPlatform, ['baemin', 'coupang', 'other'], true)) {
    $conds[] = 'u.platform = ?';
    $params[] = $filterPlatform;
}
if ($filterRider !== '') {
    $conds[] = '(od.rider_name_raw LIKE ? OR r.name LIKE ? OR r.rider_code LIKE ?)';
    $like = '%' . $filterRider . '%';
    $params = array_merge($params, [$like, $like, $like]);
}
if ($filterStore !== '') {
    $conds[] = 'od.store_name LIKE ?';
    $params[] = '%' . $filterStore . '%';
}
if ($filterOrderNo !== '') {
    $conds[] = 'od.order_no LIKE ?';
    $params[] = '%' . $filterOrderNo . '%';
}
$whereSql = implode(' AND ', $conds);

$platformLabels = ['baemin' => '배달의민족', 'coupang' => '쿠팡이츠', 'other' => '기타'];

$rows = db_rows(
    "SELECT od.*, u.platform, o.name AS agency_name, r.name AS rider_name, r.rider_code
       FROM settlement_order_details od
       INNER JOIN settlement_uploads u ON u.id = od.upload_id
       LEFT JOIN organizations o ON o.id = u.agency_id
       LEFT JOIN riders r ON r.id = od.rider_id
      WHERE {$whereSql}
      ORDER BY od.settlement_date DESC, od.id DESC
      LIMIT " . ORDER_DETAIL_EXPORT_CAP,
    $params
);

$prev = set_error_handler(static function (int $severity): bool {
    return $severity === E_DEPRECATED || $severity === E_USER_DEPRECATED;
});

try {
    $book = new Spreadsheet();
    $ws = $book->getActiveSheet();
    $ws->setTitle('오더상세');

    $headers = [
        '정산일', '대리점', '플랫폼', '엑셀이름', '매칭라이더', '라이더코드', '주문번호', '매장명',
        '픽업지역', '배달지역', '배차시각', '수락시각', '배달완료시각', '소요시간(분)', '피크시간',
        '거리(m)', '배달유형', '픽업', '배달비', '지역단가', '거리할증', '픽업할증', '도착할증', '기상할증',
        '프로모션1', '프로모션2', '프로모션3', '프로모션4', '순수익',
    ];
    foreach ($headers as $i => $h) {
        $ws->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . '1', $h);
    }
    $ws->getStyle('A1:' . Coordinate::stringFromColumnIndex(count($headers)) . '1')->getFont()->setBold(true);

    $textCols = [3, 4, 5, 6, 7, 8, 9]; // 엑셀이름~배달지역
    $r = 2;
    foreach ($rows as $d) {
        $vals = [
            (string) $d['settlement_date'],
            (string) ($d['agency_name'] ?? ''),
            $platformLabels[$d['platform']] ?? (string) $d['platform'],
            $d['rider_name_raw'], $d['rider_name'] ?? '', $d['rider_code'] ?? '',
            $d['order_no'], $d['store_name'], $d['pickup_area'], $d['delivery_area'],
            $d['assigned_at'], $d['accepted_at'], $d['delivered_at'],
            $d['duration_minutes'] !== null ? (int) $d['duration_minutes'] : '',
            $d['peak_time'], $d['distance_m'] !== null ? (int) $d['distance_m'] : '', $d['delivery_type'],
            (int) $d['fee_pickup'], (int) $d['fee_delivery'], (int) $d['fee_area'],
            (int) $d['fee_dist_surge'], (int) $d['fee_pickup_surge'], (int) $d['fee_dest_surge'], (int) $d['fee_weather'],
            (int) $d['fee_promo1'], (int) $d['fee_promo2'], (int) $d['fee_promo3'], (int) $d['fee_promo4'],
            (int) $d['net_amount'],
        ];
        foreach ($vals as $i => $v) {
            $col = Coordinate::stringFromColumnIndex($i + 1) . $r;
            if (in_array($i, $textCols, true)) {
                $ws->setCellValueExplicit($col, (string) ($v ?? ''), DataType::TYPE_STRING);
            } else {
                $ws->setCellValue($col, $v ?? '');
            }
        }
        $r++;
    }
    foreach (range(1, count($headers)) as $i) {
        $ws->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
    }
    $ws->freezePane('A2');

    $tmp = tempnam(sys_get_temp_dir(), 'od_export_');
    if ($tmp === false) {
        $err('임시 파일을 만들 수 없습니다.', 500);
    }
    try {
        (new Xlsx($book))->save($tmp);
        $bin = file_get_contents($tmp);
        if ($bin === false) {
            $err('엑셀 파일을 읽을 수 없습니다.', 500);
        }
    } finally {
        @unlink($tmp);
        $book->disconnectWorksheets();
    }

    $name = sprintf('오더상세_%s_%s.xlsx', $filterFrom, $filterTo);
    send_download_response($bin, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
} finally {
    if ($prev !== null) {
        set_error_handler($prev);
    } else {
        restore_error_handler();
    }
}
