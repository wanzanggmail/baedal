<?php

declare(strict_types=1);

/**
 * 정산 업로드 상세 — 저장된 원본 데이터를 엑셀로 재다운로드.
 * GET ?id=<upload_id>
 *
 * 시트 구성: 정산상세(settlement_daily_riders, 항상 포함) +
 *            오더상세/시간제보험/지원금/차감내역(데이터가 있을 때만 추가).
 * 업로드 화면에 보이는 것과 동일한 원본 데이터를 그대로 재export한다(가공 없음).
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

$uploadId = (int) ($_GET['id'] ?? 0);
if ($uploadId < 1) {
    $err('업로드 ID가 올바르지 않습니다.', 400);
}

$upload = db_row('SELECT * FROM settlement_uploads WHERE id = ? AND kind = ?', [$uploadId, 'daily']);
if ($upload === null) {
    $err('업로드 이력을 찾을 수 없습니다.', 404);
}
if (!Org::canAccessAgency((int) ($upload['agency_id'] ?? 0))) {
    $err('이 업로드에 접근할 권한이 없습니다.', 403);
}

/**
 * 헤더+행 배열을 시트 하나로 쓴다. 열 이름은 Coordinate 헬퍼로 계산해 26열(Z) 이상도 안전.
 * 첫 호출은 스프레드시트 기본 시트를 재사용하고, 이후 호출은 새 시트를 만든다.
 *
 * @param list<string>              $headers
 * @param list<list<int|float|string|null>> $rows
 */
function settlement_export_write_sheet(Spreadsheet $book, bool $isFirstSheet, string $title, array $headers, array $rows, array $textCols = []): void
{
    $ws = $isFirstSheet ? $book->getActiveSheet() : $book->createSheet();
    $ws->setTitle(mb_substr($title, 0, 31));

    foreach ($headers as $i => $h) {
        $ws->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . '1', $h);
    }
    $ws->getStyle(Coordinate::stringFromColumnIndex(1) . '1:' . Coordinate::stringFromColumnIndex(count($headers)) . '1')
        ->getFont()->setBold(true);

    $r = 2;
    foreach ($rows as $row) {
        foreach ($row as $i => $v) {
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
}

$prev = set_error_handler(static function (int $severity): bool {
    return $severity === E_DEPRECATED || $severity === E_USER_DEPRECATED;
});

try {
    $book = new Spreadsheet();

    // ── 1. 정산상세 (settlement_daily_riders) — 항상 포함 ──
    $dr = db_rows(
        'SELECT dr.*, r.rider_code, r.name AS rider_name
           FROM settlement_daily_riders dr
           LEFT JOIN riders r ON r.id = dr.rider_id
          WHERE dr.upload_id = ?
          ORDER BY dr.id ASC',
        [$uploadId]
    );
    $drHeaders = [
        '엑셀이름', '매칭라이더', '라이더코드', '라이선스ID', '오더건수', '정산예정액',
        '픽업', '배달비', '지역단가', '거리구간건수', '거리구간할증', '픽업콜건수', '픽업콜할증',
        '도착콜건수', '도착콜할증', '기상할증건수', '기상할증금액',
        '프로모션1', '프로모션2', '프로모션3', '프로모션4', '시간제보험', '지원금', '실지급액', '정산일',
    ];
    $drRows = array_map(static fn (array $d): array => [
        $d['rider_name_raw'], $d['rider_name'] ?? '', $d['rider_code'] ?? '', $d['license_id'],
        (int) $d['order_count'], (int) $d['gross_amount'],
        (int) $d['fee_pickup'], (int) $d['fee_delivery'], (int) $d['fee_area'],
        (int) $d['fee_dist_cnt'], (int) $d['fee_dist_surge'], (int) $d['fee_pickup_cnt'], (int) $d['fee_pickup_surge'],
        (int) $d['fee_dest_cnt'], (int) $d['fee_dest_surge'], (int) $d['fee_weather_cnt'], (int) $d['fee_weather'],
        (int) $d['fee_promo1'], (int) $d['fee_promo2'], (int) $d['fee_promo3'], (int) $d['fee_promo4'],
        (int) $d['hourly_insurance'], (int) $d['support_amount'], (int) $d['payout_amount'],
        (string) $d['settlement_date'],
    ], $dr);
    settlement_export_write_sheet($book, true, '정산상세', $drHeaders, $drRows, [0, 1, 2, 3]);

    // ── 2. 오더상세 (settlement_order_details) — 있을 때만 ──
    if (db_table_exists('settlement_order_details')) {
        $od = db_rows('SELECT * FROM settlement_order_details WHERE upload_id = ? ORDER BY id ASC', [$uploadId]);
        if ($od !== []) {
            $odHeaders = [
                '엑셀이름', '주문번호', '매장명', '픽업지역', '배달지역', '배차시각', '수락시각', '배달완료시각',
                '소요시간(분)', '피크시간', '거리(m)', '배달유형', '픽업', '배달비', '지역단가',
                '거리할증', '픽업할증', '도착할증', '기상할증', '프로모션1', '프로모션2', '프로모션3', '프로모션4', '순수익',
            ];
            $odRows = array_map(static fn (array $d): array => [
                $d['rider_name_raw'], $d['order_no'], $d['store_name'], $d['pickup_area'], $d['delivery_area'],
                $d['assigned_at'], $d['accepted_at'], $d['delivered_at'],
                $d['duration_minutes'] !== null ? (int) $d['duration_minutes'] : '', $d['peak_time'],
                $d['distance_m'] !== null ? (int) $d['distance_m'] : '', $d['delivery_type'],
                (int) $d['fee_pickup'], (int) $d['fee_delivery'], (int) $d['fee_area'],
                (int) $d['fee_dist_surge'], (int) $d['fee_pickup_surge'], (int) $d['fee_dest_surge'], (int) $d['fee_weather'],
                (int) $d['fee_promo1'], (int) $d['fee_promo2'], (int) $d['fee_promo3'], (int) $d['fee_promo4'],
                (int) $d['net_amount'],
            ], $od);
            settlement_export_write_sheet($book, false, '오더상세', $odHeaders, $odRows, [0, 1, 2, 3, 4, 11]);
        }
    }

    // ── 3. 시간제보험 (settlement_hourly_insurance) — 있을 때만 ──
    if (db_table_exists('settlement_hourly_insurance')) {
        $hi = db_rows('SELECT * FROM settlement_hourly_insurance WHERE upload_id = ? ORDER BY id ASC', [$uploadId]);
        if ($hi !== []) {
            $hiHeaders = ['엑셀이름', '발생일', '금액'];
            $hiRows = array_map(static fn (array $d): array => [
                $d['rider_name_raw'], $d['occurred_date'], (int) $d['amount'],
            ], $hi);
            settlement_export_write_sheet($book, false, '시간제보험', $hiHeaders, $hiRows, [0]);
        }
    }

    // ── 4. 지원금 (settlement_support_amounts) — 있을 때만 ──
    if (db_table_exists('settlement_support_amounts')) {
        $sa = db_rows('SELECT * FROM settlement_support_amounts WHERE upload_id = ? ORDER BY id ASC', [$uploadId]);
        if ($sa !== []) {
            $saHeaders = [
                '엑셀이름', '유형', '주문번호', '매장명', '픽업지역', '배달지역',
                '배차시각', '수락시각', '배달완료시각', '소요시간(분)', '피크시간', '카테고리', '금액',
            ];
            $kindLabel = ['support' => '지원금', 'add_support' => '추가지원금'];
            $saRows = array_map(static fn (array $d): array => [
                $d['rider_name_raw'], $kindLabel[(string) $d['kind']] ?? $d['kind'], $d['order_no'], $d['store_name'],
                $d['pickup_area'], $d['delivery_area'], $d['assigned_at'], $d['accepted_at'], $d['delivered_at'],
                $d['duration_minutes'] !== null ? (int) $d['duration_minutes'] : '', $d['peak_time'], $d['category'],
                (int) $d['amount'],
            ], $sa);
            settlement_export_write_sheet($book, false, '지원금', $saHeaders, $saRows, [0, 1, 2, 3]);
        }
    }

    // ── 5. 차감내역 (settlement_weekly_deductions) — 있을 때만 ──
    if (db_table_exists('settlement_weekly_deductions')) {
        $wd = db_rows('SELECT * FROM settlement_weekly_deductions WHERE upload_id = ? ORDER BY id ASC', [$uploadId]);
        if ($wd !== []) {
            $wdHeaders = ['엑셀이름', '주문일', '주문번호', '차감유형', '매장명', '배차시각', '메뉴가격', '배달비', '차감액', '등록여부'];
            $wdRows = array_map(static fn (array $d): array => [
                $d['rider_name_raw'], $d['order_date'], $d['order_no'], $d['deduction_type'], $d['store_name'],
                $d['assigned_at'], (int) $d['menu_price'], (int) $d['delivery_fee'], (int) $d['amount'],
                $d['registered_entry_id'] !== null ? '등록됨' : '미등록',
            ], $wd);
            settlement_export_write_sheet($book, false, '차감내역', $wdHeaders, $wdRows, [0, 2, 3, 4]);
        }
    }

    $tmp = tempnam(sys_get_temp_dir(), 'settlement_export_');
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

    $name = sprintf(
        '정산업로드_%s_%s_%s.xlsx',
        (string) $upload['settlement_date'],
        (string) $upload['platform'],
        (string) $uploadId
    );
    send_download_response($bin, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
} finally {
    if ($prev !== null) {
        set_error_handler($prev);
    } else {
        restore_error_handler();
    }
}
