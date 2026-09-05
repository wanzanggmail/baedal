<?php

declare(strict_types=1);

/**
 * 라이더 일괄등록 템플릿(xlsx) 다운로드 — 신규 대리점 입점 시 기존에 관리하던 라이더를
 * 한 번에 옮겨 적기 위한 빈 양식. GET ?agency_id=N (대리점 계정은 자기 대리점 고정)
 *
 * 컬럼: 이름·휴대전화 만 필수. 로그인ID·라이더코드·차량종류·팀코드는 받지 않고 자동 처리한다.
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

// (헤더, 예시값) — 이름/휴대전화만 필수. 나머지는 비워도 등록 가능.
//
// 2026-09-05 갑 지시로 **로그인ID·라이더코드·차량종류·팀코드 컬럼을 뺐다**:
//   · 로그인ID  = 휴대전화에서 숫자만 뽑아 자동 생성(RiderLoginId::generate)
//   · 라이더코드 = 자동 생성
//   · 차량종류  = 전부 오토바이(motor)
//   · 팀코드    = 사용하지 않음(etc 고정)
// 대신 정산에 직접 영향을 주는 **일정산 대상·원천세 대상**을 받는다.
$columns = [
    ['이름*',         '홍길동'],
    ['휴대전화*',      '01012345678'],
    ['일정산대상',     'Y'],
    ['원천세대상',     'Y'],
    ['은행코드',       ''],
    ['계좌번호',       ''],
    ['예금주',         ''],
    ['이메일',         ''],
    ['쿠팡ID',         ''],
    ['배민ID',         ''],
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
        '',
        '[일정산대상]',
        '- 매일 정산해서 바로 출금할 수 있는 라이더면 Y, 주정산이면 N 입니다.',
        '- Y 인 라이더만 대행수수료(선정산수수료)가 붙습니다.',
        '',
        '[원천세대상]',
        '- 사업소득 3.3% 원천징수 대상이면 Y, 아니면 N 입니다.',
        '- Y 인 라이더의 공제분만 대리점 예수금으로 쌓여 세무대리가 월별로 가져갑니다.',
        '',
        '- 두 항목 모두 Y / N 으로 적습니다. O·X, 1·0, 예·아니오 도 인식합니다.',
        '- **비워두면 N(미대상)** 으로 등록됩니다. 등록 후 라이더 상세에서 개별 변경할 수 있습니다.',
        '',
        '[자동 처리되는 항목]',
        '- 로그인ID: 휴대전화에서 숫자만 뽑아 만듭니다(010-1234-5678 → 01012345678).',
        '  같은 번호가 이미 있으면 뒤에 a, b, c … 를 붙여 구분합니다.',
        '- 라이더코드: 자동 생성됩니다.',
        '- 차량종류: 전부 오토바이로 등록됩니다.',
        '- 초기 비밀번호는 0000이며, 최초 로그인 시 변경이 강제됩니다.',
        '',
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
