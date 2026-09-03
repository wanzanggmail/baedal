<?php

declare(strict_types=1);

/**
 * 대리점별 원천세 — 특정 정산 귀속월의 대리점별 걷힘·수집·미수집을 엑셀로 다운로드(2026-09-04 갑).
 * GET ?period=YYYY-MM  (없으면 미수집 남은 최신월)
 *
 * 권한: 세무대리(tax_agent) 계정 또는 본사 super(점검용).
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/Org.php';
require_once INC_PATH . '/TaxAgent.php';
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
if (admin_org_level() !== Org::LEVEL_TAX_AGENT && !admin_has_role('super')) {
    $err('세무대리 계정만 사용할 수 있습니다.', 403);
}
if (!TaxAgent::ready()) {
    $err('세무대리 테이블이 없습니다. php migrate.php 를 실행하세요.', 500);
}

// 대상 월 — 지정 없으면 미수집 남은 최신월(없으면 최신월/이번 달).
$period = (string) ($_GET['period'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
    $period = '';
    foreach (TaxAgent::months() as $m) {
        if ((int) $m['uncollected'] > 0) {
            $period = (string) $m['period'];
            break;
        }
    }
    if ($period === '') {
        $all = TaxAgent::months();
        $period = $all !== [] ? (string) $all[0]['period'] : date('Y-m');
    }
}

$rows = TaxAgent::agencySummary($period);

$prev = set_error_handler(static function (int $severity): bool {
    return $severity === E_DEPRECATED || $severity === E_USER_DEPRECATED;
});

try {
    $book = new Spreadsheet();
    $ws   = $book->getActiveSheet();
    $ws->setTitle('원천세');

    $headers = ['정산귀속월', '대리점', '대리점코드', '걷힌 원천세', '이미 수집', '미수집'];
    foreach ($headers as $i => $h) {
        $ws->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . '1', $h);
    }
    $ws->getStyle('A1:' . Coordinate::stringFromColumnIndex(count($headers)) . '1')->getFont()->setBold(true);

    $r = 2;
    $sumAccrued = 0;
    $sumCollected = 0;
    $sumUncollected = 0;
    foreach ($rows as $a) {
        $ws->setCellValueExplicit('A' . $r, $period, DataType::TYPE_STRING);
        $ws->setCellValueExplicit('B' . $r, (string) $a['agency_name'], DataType::TYPE_STRING);
        $ws->setCellValueExplicit('C' . $r, (string) $a['code'], DataType::TYPE_STRING);
        $ws->setCellValue('D' . $r, (int) $a['accrued']);
        $ws->setCellValue('E' . $r, (int) $a['collected']);
        $ws->setCellValue('F' . $r, (int) $a['uncollected']);
        $sumAccrued     += (int) $a['accrued'];
        $sumCollected   += (int) $a['collected'];
        $sumUncollected += (int) $a['uncollected'];
        $r++;
    }

    // 합계 행
    $ws->setCellValue('C' . $r, '합계');
    $ws->getStyle('C' . $r . ':F' . $r)->getFont()->setBold(true);
    $ws->setCellValue('D' . $r, $sumAccrued);
    $ws->setCellValue('E' . $r, $sumCollected);
    $ws->setCellValue('F' . $r, $sumUncollected);

    // 금액 열 천단위 서식
    $ws->getStyle('D2:F' . $r)->getNumberFormat()->setFormatCode('#,##0');
    foreach (range(1, count($headers)) as $i) {
        $ws->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
    }
    $ws->freezePane('A2');

    $tmp = tempnam(sys_get_temp_dir(), 'tax_export_');
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

    $name = sprintf('대리점별_원천세_%s.xlsx', $period);
    send_download_response($bin, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
} finally {
    if ($prev !== null) {
        set_error_handler($prev);
    } else {
        restore_error_handler();
    }
}
