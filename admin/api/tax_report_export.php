<?php

declare(strict_types=1);

/**
 * 세무신고용 엑셀 — 대리점별·기간별 (2026-09-05 갑).
 * GET ?agency_id=N&from=YYYY-MM-DD&to=YYYY-MM-DD
 *
 * 갑이 실제로 쓰던 「세무신고용_YYYY-MM-DD_YYYY-MM-DD.xlsx」 레이아웃을 그대로 재현한다.
 * PG 결제금액(110%) 열은 갑 지시로 뺐다.
 *
 * 권한: 세무대리(tax_agent) 계정 또는 본사 super.
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/Org.php';
require_once INC_PATH . '/TaxReport.php';
require_once INC_PATH . '/download_response.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
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

$agencyId = (int) ($_GET['agency_id'] ?? 0);
$from     = trim((string) ($_GET['from'] ?? ''));
$to       = trim((string) ($_GET['to'] ?? ''));
$okDate   = static fn (string $d): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);

if ($agencyId < 1) {
    $err('대리점을 선택하세요.', 422);
}
if (!$okDate($from) || !$okDate($to) || $from > $to) {
    $err('기간이 올바르지 않습니다.', 422);
}

$org = Org::find($agencyId);
if ($org === null) {
    $err('대리점을 찾을 수 없습니다.', 404);
}

$rows    = TaxReport::riders($agencyId, $from, $to);
$summary = TaxReport::summary($agencyId, $from, $to);
if ($rows === []) {
    $err('해당 기간에 신고할 원천세 내역이 없습니다.', 422);
}

$prev = set_error_handler(static function (int $severity): bool {
    return $severity === E_DEPRECATED || $severity === E_USER_DEPRECATED;
});

try {
    $book = new Spreadsheet();
    $ws   = $book->getActiveSheet();
    $ws->setTitle('세무신고용');

    $money = '#,##0';
    $bold  = static function (string $range) use ($ws): void {
        $ws->getStyle($range)->getFont()->setBold(true);
    };

    // ── 상단 요약 (A1:C7) — 샘플 파일과 같은 순서 ──────────────────────────
    $head = [
        ['해당 지사 총 콜수',  $summary['calls'],        '콜'],
        ['세무비용 단가',      $summary['fee_per_call'], '원/콜'],
        ['최종 세무 비용',     $summary['fee_total'],    '총 콜수 × ' . $summary['fee_per_call'] . '원'],
        ['기사정산원금 합계',  $summary['base'],         '원금 신고 기준'],
        ['프로모션금액 합계',  $summary['promo'],        '프로모션 신고 기준'],
        ['합산 기준금액',      $summary['total_base'],   '기사정산원금 + 프로모션금액'],
        ['총 징수원천세',      $summary['total_wh'],     '원금 원천세 + 프로모션 원천세'],
    ];
    foreach ($head as $i => [$label, $value, $note]) {
        $r = $i + 1;
        $ws->setCellValue("A{$r}", $label);
        $ws->setCellValue("B{$r}", $value);
        $ws->setCellValue("C{$r}", $note);
        $ws->getStyle("B{$r}")->getNumberFormat()->setFormatCode($money);
    }
    $bold('A1:A7');
    $ws->getStyle('C1:C7')->getFont()->getColor()->setRGB('888888');

    // ── 라이더 표 ────────────────────────────────────────────────────────
    $headerRow = 9;
    $cols = [
        '이름', '주민번호', '기사정산원금', '원금 원천세 3.3%', '프로모션금액',
        '프로모션 원천세 3.3%', '합산 기준금액', '총 징수원천세',
        '세금신고유무', '금액조정필요', '조정금액', '비고',
    ];
    foreach ($cols as $i => $label) {
        $ws->setCellValue(chr(65 + $i) . $headerRow, $label);
    }
    $lastCol = chr(65 + count($cols) - 1);
    $bold("A{$headerRow}:{$lastCol}{$headerRow}");
    $ws->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")
        ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F2F4F7');
    $ws->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")
        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $r = $headerRow + 1;
    foreach ($rows as $row) {
        $ws->setCellValue("A{$r}", $row['name']);
        // 주민번호는 시스템에 저장하지 않는다 — 세무대리가 채우는 빈 칸(문자열로 둬야 앞자리 0이 안 날아간다).
        $ws->setCellValueExplicit("B{$r}", (string) $row['rrn'], DataType::TYPE_STRING);
        $ws->setCellValue("C{$r}", $row['base']);
        $ws->setCellValue("D{$r}", $row['base_wh']);
        $ws->setCellValue("E{$r}", $row['promo']);
        $ws->setCellValue("F{$r}", $row['promo_wh']);
        $ws->setCellValue("G{$r}", $row['total_base']);
        $ws->setCellValue("H{$r}", $row['total_wh']);
        $ws->setCellValue("I{$r}", $row['report'] ? 'Y' : 'N');
        $ws->setCellValue("J{$r}", $row['adjust_note']);
        // K(조정금액)·L(비고)는 세무대리가 직접 채우는 칸이라 비워 둔다.
        $r++;
    }
    $lastDataRow = $r - 1;

    // ── 총액 합계 ────────────────────────────────────────────────────────
    $r++;
    $ws->setCellValue("A{$r}", '총액 합계');
    foreach (['C', 'D', 'E', 'F', 'G', 'H'] as $col) {
        $ws->setCellValue("{$col}{$r}", "=SUM({$col}" . ($headerRow + 1) . ":{$col}{$lastDataRow})");
    }
    $bold("A{$r}:{$lastCol}{$r}");
    $ws->getStyle("A{$r}:{$lastCol}{$r}")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);

    $ws->getStyle('C' . ($headerRow + 1) . ":H{$r}")->getNumberFormat()->setFormatCode($money);
    $ws->getStyle("I" . ($headerRow + 1) . ":I{$lastDataRow}")
        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    foreach (range('A', $lastCol) as $col) {
        $ws->getColumnDimension($col)->setAutoSize(true);
    }
    $ws->freezePane('A' . ($headerRow + 1));

    $tmp = tempnam(sys_get_temp_dir(), 'taxrep_');
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

    $name = sprintf('세무신고용_%s_%s_%s.xlsx', (string) ($org['name'] ?? 'agency'), $from, $to);
    send_download_response($bin, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
} finally {
    if ($prev !== null) {
        set_error_handler($prev);
    } else {
        restore_error_handler();
    }
}
