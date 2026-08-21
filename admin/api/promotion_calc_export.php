<?php

declare(strict_types=1);

/**
 * 프로모션 계산 결과 엑셀 다운로드.
 *
 * GET  ?agency_id=&from=&to=&tiers=<json>
 *
 * 시트 2개:
 *  1) **프로모션** — `Promotion::TEMPLATE_HEADERS`와 **완전히 같은 형식**(라이더코드·라이더이름·
 *     프로모션1·프로모션2). 계산된 금액이 프로모션1에 들어가 있어, 이 파일을 그대로
 *     「프로모션 지급」에 업로드하면 지급까지 이어진다. ⚠️ 헤더·순서를 바꾸면 업로드가 깨진다.
 *  2) **계산근거** — 건수·구간별 내역. 라이더가 "왜 이 금액이냐"고 물을 때 근거로 쓴다.
 *     (업로드 파서는 첫 시트만 읽으므로 이 시트가 있어도 무방하다.)
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/Org.php';
require_once INC_PATH . '/Promotion.php';
require_once INC_PATH . '/PromotionCalculator.php';
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
if (!admin_can_access_route('promotion')) {
    $jsonErr('프로모션 권한이 없습니다.', 403);
}

if (admin_org_level() === Org::LEVEL_AGENCY) {
    $agencyId = admin_org_id();
} else {
    $agencyId = (int) ($_GET['agency_id'] ?? 0);
    if ($agencyId < 1) {
        $jsonErr('대리점을 선택하세요.', 422);
    }
    if (!Org::canAccessAgency($agencyId)) {
        $jsonErr('이 대리점에 접근할 권한이 없습니다.', 403);
    }
}

$normDate = static function ($v): ?string {
    $v = trim((string) ($v ?? ''));

    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
};
$from = $normDate($_GET['from'] ?? null);
$to   = $normDate($_GET['to'] ?? null);
if ($from === null || $to === null || $from > $to) {
    $jsonErr('기간을 올바르게 지정하세요.', 422);
}

$tiersRaw = json_decode((string) ($_GET['tiers'] ?? '[]'), true);
if (!is_array($tiersRaw)) {
    $jsonErr('구간 정보를 읽을 수 없습니다.', 422);
}

try {
    $tiers  = PromotionCalculator::normalizeTiers($tiersRaw);
    $result = PromotionCalculator::calculate($agencyId, $from, $to, $tiers);
} catch (InvalidArgumentException $e) {
    $jsonErr($e->getMessage(), 422);
}

$org  = Org::find($agencyId);
$rows = $result['rows'];
$rule = PromotionCalculator::describeTiers($tiers);

$prev = set_error_handler(static function (int $severity): bool {
    return $severity === E_DEPRECATED || $severity === E_USER_DEPRECATED;
});

try {
    $book = new Spreadsheet();

    // ── 시트 1: 업로드용(기존 템플릿과 동일 형식) ──────────────
    $ws = $book->getActiveSheet();
    $ws->setTitle('프로모션');
    foreach (Promotion::TEMPLATE_HEADERS as $i => $h) {
        $ws->setCellValue(chr(65 + $i) . '1', $h);
    }
    $ws->getStyle('A1:D1')->getFont()->setBold(true);

    $r = 2;
    foreach ($rows as $row) {
        $ws->setCellValueExplicit('A' . $r, (string) $row['rider_code'], DataType::TYPE_STRING);
        $ws->setCellValueExplicit('B' . $r, (string) $row['name'], DataType::TYPE_STRING);
        $ws->setCellValue('C' . $r, (int) $row['amount']);
        $ws->setCellValue('D' . $r, 0);
        $r++;
    }
    $last = max(2, $r - 1);
    $ws->getStyle('A2:B' . $last)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
    $ws->getStyle('C2:D' . $last)->getNumberFormat()->setFormatCode('#,##0');
    foreach (['A', 'B', 'C', 'D'] as $c) {
        $ws->getColumnDimension($c)->setAutoSize(true);
    }
    $ws->freezePane('A2');

    // ── 시트 2: 계산 근거 ─────────────────────────────────────
    $ws2 = $book->createSheet();
    $ws2->setTitle('계산근거');
    $ws2->setCellValue('A1', '기간');
    $ws2->setCellValue('B1', $from . ' ~ ' . $to);
    $ws2->setCellValue('A2', '구간 룰');
    $ws2->setCellValue('B2', $rule);
    $ws2->setCellValue('A3', '건수 기준');
    $ws2->setCellValue('B3', '정산 반영(지갑 적립) 완료된 배달 건수');
    $ws2->getStyle('A1:A3')->getFont()->setBold(true);

    $head = ['라이더코드', '라이더이름', '정산일수', '배달건수', '정산액', '프로모션', '계산내역'];
    foreach ($head as $i => $h) {
        $ws2->setCellValue(chr(65 + $i) . '5', $h);
    }
    $ws2->getStyle('A5:G5')->getFont()->setBold(true);

    $r = 6;
    foreach ($rows as $row) {
        $detail = implode(' + ', array_map(
            static fn (array $b): string => sprintf('%d~%d건 %d건×%d원', $b['from'], $b['to'], $b['orders'], $b['amount']),
            $row['breakdown']
        ));
        $ws2->setCellValueExplicit('A' . $r, (string) $row['rider_code'], DataType::TYPE_STRING);
        $ws2->setCellValueExplicit('B' . $r, (string) $row['name'], DataType::TYPE_STRING);
        $ws2->setCellValue('C' . $r, (int) $row['days']);
        $ws2->setCellValue('D' . $r, (int) $row['order_count']);
        $ws2->setCellValue('E' . $r, (int) $row['net_amount']);
        $ws2->setCellValue('F' . $r, (int) $row['amount']);
        $ws2->setCellValueExplicit('G' . $r, $detail !== '' ? $detail : '구간 미달', DataType::TYPE_STRING);
        $r++;
    }
    $last2 = max(6, $r - 1);
    $ws2->getStyle('C6:F' . $last2)->getNumberFormat()->setFormatCode('#,##0');
    foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $c) {
        $ws2->getColumnDimension($c)->setAutoSize(true);
    }
    $ws2->freezePane('A6');

    $book->setActiveSheetIndex(0);

    $tmp = tempnam(sys_get_temp_dir(), 'promo_calc_');
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

    $name = sprintf(
        '프로모션계산_%s_%s~%s.xlsx',
        (string) ($org['name'] ?? 'agency'),
        str_replace('-', '', $from),
        str_replace('-', '', $to)
    );
    send_download_response($bin, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
} finally {
    if ($prev !== null) {
        set_error_handler($prev);
    } else {
        restore_error_handler();
    }
}
