<?php

declare(strict_types=1);

/**
 * 라이더 일괄등록 템플릿(xlsx) 다운로드 — 신규 대리점 입점 시 기존에 관리하던 라이더를
 * 한 번에 옮겨 적기 위한 빈 양식. GET ?agency_id=N (대리점 계정은 자기 대리점 고정)
 *
 * 컬럼: 이름·휴대전화 만 필수, 나머지는 비워두면 자동생성되거나 미입력 처리된다.
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/Org.php';
require_once INC_PATH . '/RiderRegistration.php';
require_once INC_PATH . '/download_response.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
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

$org = Org::find($agencyId);

// (헤더, 예시값, 폭) — 이름/휴대전화만 필수. 나머지는 비워도 등록 가능.
$columns = [
    ['이름*',        '홍길동',             true],
    ['휴대전화*',     '01012345678',       true],
    ['로그인ID',      '(비우면 자동생성)',   false],
    ['라이더코드',    '(비우면 자동생성)',   false],
    ['차량종류',      'motor',             false],
    ['팀코드',        'etc',               false],
    ['은행코드',      '',                  false],
    ['계좌번호',      '',                  false],
    ['예금주',        '',                  false],
    ['이메일',        '',                  false],
    ['쿠팡ID',        '',                  false],
    ['배민ID',        '',                  false],
];

$prev = set_error_handler(static function (int $severity): bool {
    return $severity === E_DEPRECATED || $severity === E_USER_DEPRECATED;
});

try {
    $book = new Spreadsheet();
    $ws   = $book->getActiveSheet();
    $ws->setTitle('라이더 일괄등록');

    foreach ($columns as $i => [$header]) {
        $ws->setCellValue(chr(65 + $i) . '1', $header);
    }
    $lastCol = chr(65 + count($columns) - 1);
    $ws->getStyle("A1:{$lastCol}1")->getFont()->setBold(true);

    foreach ($columns as $i => [, $example]) {
        $ws->setCellValueExplicit(chr(65 + $i) . '2', $example, DataType::TYPE_STRING);
    }

    $ws->getStyle("A2:{$lastCol}2")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
    $ws->getStyle("A2:{$lastCol}2")->getFont()->setItalic(true)->getColor()->setRGB('999999');
    foreach (range('A', $lastCol) as $col) {
        $ws->getColumnDimension($col)->setAutoSize(true);
    }
    $ws->freezePane('A2');

    // 안내 시트
    $note = $book->createSheet();
    $note->setTitle('안내');
    $note->setCellValue('A1', '작성 안내');
    $note->getStyle('A1')->getFont()->setBold(true)->setSize(13);
    $lines = [
        '- 이름, 휴대전화만 필수입니다. 나머지는 비워두고 업로드해도 됩니다.',
        '- 로그인ID·라이더코드를 비우면 자동으로 생성됩니다.',
        '- 초기 비밀번호는 0000이며, 최초 로그인 시 변경이 강제됩니다.',
        '- 차량종류: motor(오토바이) / bike(자전거) / car(자동차) / walk(도보) / kick(전동킥보드). 비우거나 잘못 입력하면 motor로 저장됩니다.',
        '- 쿠팡ID/배민ID는 같은 대리점 안에서 다른 라이더와 중복될 수 없습니다.',
        '- 2번째 줄(예시 행)은 지우고 업로드하거나, 그대로 두면 미리보기 화면에서 확인 후 빼면 됩니다.',
    ];
    foreach ($lines as $i => $line) {
        $note->setCellValue('A' . ($i + 3), $line);
    }
    $note->getColumnDimension('A')->setWidth(90);

    $book->setActiveSheetIndex(0);

    $tmp = tempnam(sys_get_temp_dir(), 'rider_tpl_');
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

    $name = sprintf('라이더_일괄등록_%s_%s.xlsx', (string) ($org['name'] ?? 'agency'), date('Ymd'));
    send_download_response($bin, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
} finally {
    if ($prev !== null) {
        set_error_handler($prev);
    } else {
        restore_error_handler();
    }
}
