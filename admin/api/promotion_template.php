<?php

declare(strict_types=1);

/**
 * 프로모션 템플릿(xlsx) 다운로드 — 대리점 소속 활동 라이더가 미리 채워진 양식.
 * GET ?agency_id=N
 *
 * 컬럼: 라이더코드 / 라이더이름 / 프로모션1 / 프로모션2
 * 관리자는 프로모션1·2 금액만 채워서 다시 업로드하면 된다.
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/Org.php';
require_once INC_PATH . '/Promotion.php';
require_once INC_PATH . '/download_response.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

ini_set('display_errors', '0');
if (ob_get_level() === 0) {
    ob_start();
}

$jsonErr = static function (string $msg, int $code): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
};

if (!admin_is_logged_in()) {
    $jsonErr('인증이 필요합니다.', 401);
}

// 대리점 계정은 자기 대리점, 본사·총판은 선택값 검증
if (admin_org_level() === Org::LEVEL_AGENCY) {
    $agencyId = admin_org_id();
} else {
    $agencyId = (int) ($_GET['agency_id'] ?? 0);
    if ($agencyId < 1) {
        $jsonErr('대리점을 선택하세요.', 422);
    }
}
if (!Org::canAccessAgency($agencyId)) {
    $jsonErr('이 대리점에 접근할 권한이 없습니다.', 403);
}

$org    = Org::find($agencyId);
$riders = Promotion::templateRiders($agencyId);

$prev = set_error_handler(static function (int $severity): bool {
    return $severity === E_DEPRECATED || $severity === E_USER_DEPRECATED;
});

try {
    $book = new Spreadsheet();
    $ws   = $book->getActiveSheet();
    $ws->setTitle('프로모션');

    $headers = Promotion::TEMPLATE_HEADERS;
    foreach ($headers as $i => $h) {
        $ws->setCellValue(chr(65 + $i) . '1', $h);
    }
    $ws->getStyle('A1:D1')->getFont()->setBold(true);

    $row = 2;
    foreach ($riders as $r) {
        // 라이더코드는 숫자로 오인되지 않게 문자열로 넣는다.
        $ws->setCellValueExplicit('A' . $row, $r['rider_code'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $ws->setCellValueExplicit('B' . $row, $r['name'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $ws->setCellValue('C' . $row, 0);
        $ws->setCellValue('D' . $row, 0);
        $row++;
    }

    $lastRow = max(2, $row - 1);
    $ws->getStyle('A2:B' . $lastRow)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
    $ws->getStyle('C2:D' . $lastRow)->getNumberFormat()->setFormatCode('#,##0');
    foreach (['A', 'B', 'C', 'D'] as $col) {
        $ws->getColumnDimension($col)->setAutoSize(true);
    }
    $ws->freezePane('A2');

    $tmp = tempnam(sys_get_temp_dir(), 'promo_tpl_');
    if ($tmp === false) {
        $jsonErr('임시 파일을 만들 수 없습니다.', 500);
    }
    try {
        (new Xlsx($book))->save($tmp);
        $bin = file_get_contents($tmp);
        if ($bin === false) {
            $jsonErr('엑셀 파일을 읽을 수 없습니다.', 500);
        }
    } finally {
        @unlink($tmp);
        $book->disconnectWorksheets();
    }

    $name = sprintf('프로모션_%s_%s.xlsx', (string) ($org['name'] ?? 'agency'), date('Ymd'));
    send_download_response($bin, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
} finally {
    if ($prev !== null) {
        set_error_handler($prev);
    } else {
        restore_error_handler();
    }
}
