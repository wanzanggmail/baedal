<?php

declare(strict_types=1);

/**
 * 데모용 정산서(.xlsx) 생성 — 쿠팡이츠 일일 정산서 형식.
 *
 * 실제 정산서를 데모에 쓸 수 없으니(개인정보), 파서가 기대하는 구조를 그대로 지킨
 * 가짜 파일을 만든다. `XlsxParser::parseDailySheet()` 가 읽는 규칙을 따른다:
 *
 *   - 시트 0 = 「종합」. 헤더 행에 '라이선스'/'성함' 이 있어야 찾아진다
 *   - 데이터는 **헤더 다음다음 행**부터 (헤더가 2줄이라는 전제)
 *   - B열 = 라이선스 ID (숫자 9~15자리)
 *   - 컬럼 매핑은 헤더 문구로 결정된다(`buildColumnMap`) — 문구를 바꾸면 안 된다
 *
 * 사용: php tools/make_demo_xlsx.php [정산일 YYYY-MM-DD] [출력경로]
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$date = $argv[1] ?? date('Y-m-d', strtotime('-1 day'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    fwrite(STDERR, "정산일 형식이 올바르지 않습니다 (YYYY-MM-DD)\n");
    exit(1);
}
$out = $argv[2] ?? dirname(__DIR__) . '/demo_정산서_' . str_replace('-', '', $date) . '.xlsx';

/**
 * 데모 라이더 — 이름은 **팀도깨비(#13)에 실제로 있는 이름**을 쓴다.
 * 그래야 업로드 후 자동 매칭되어 「정산 반영」까지 바로 보여줄 수 있다.
 *
 * 마지막 한 명(`한지훈0000`)만 일부러 시스템에 없는 이름이다 —
 * **미매칭 → 「연결/등록」 흐름**을 시연하기 위한 것이다.
 */
$riders = [
    // [라이선스ID, 이름, 오더수, 픽업비용, 배달비용, 지역단가, 거리할증건, 거리할증, 프로모션1]
    ['130721014470', '이인용9205', 34, 23_800, 24_500, 43_200, 14, 17_300, 12_400],
    ['130721036454', '최진수8604', 28, 19_600, 20_200, 35_600, 11, 13_900, 9_800],
    ['130721078761', '권성진4418', 31, 21_700, 22_400, 39_500, 13, 16_100, 11_200],
    ['130721099213', '이효섭0268', 26, 18_200, 18_900, 33_100, 9,  11_500, 8_600],
    ['130721103387', '이충회4339', 22, 15_400, 16_000, 27_800, 8,  10_200, 7_100],
    ['130721117905', '노동현0647', 19, 13_300, 13_800, 24_100, 6,  7_800,  6_200],
    ['130721124018', '한지훈0000', 17, 11_900, 12_400, 21_500, 5,  6_400,  5_300],
];

$book  = new Spreadsheet();
$sheet = $book->getActiveSheet();
$sheet->setTitle('종합');

// ── 제목 줄(파서는 무시한다. 사람이 보기 위한 것) ──
$sheet->setCellValue('A1', '쿠팡이츠 일일 정산 내역서');
$sheet->setCellValue('A2', '정산일 : ' . $date);
$sheet->setCellValue('A3', '위탁사 : 팀도깨비');

// ── 헤더 2줄 ──
// ⚠️ 문구가 `buildColumnMap()` 의 매칭 기준이다. 바꾸면 컬럼이 어긋난다.
$h1 = 5; // 헤더 1행
$h2 = 6; // 헤더 2행 (데이터는 7행부터 = 헤더 + 2)

$cols = [
    'A' => ['번호', ''],
    'B' => ['라이선스', 'ID'],
    'C' => ['성함', ''],
    'D' => ['연락처', ''],
    'E' => ['총 정산', '금액'],
    'F' => ['오더수', ''],
    'G' => ['픽업 비용', ''],
    'H' => ['배달 비용', ''],
    'I' => ['지역 단가', ''],
    'J' => ['배달거리 할증', '건수'],
    'K' => ['배달거리 할증', '금액'],
    'L' => ['픽업지 할증', '건수'],
    'M' => ['픽업지 할증', '금액'],
    'N' => ['도착지 할증', '건수'],
    'O' => ['도착지 할증', '금액'],
    'P' => ['기상 할증', '건수'],
    'Q' => ['기상 할증', '금액'],
    'R' => ['기타', ''],
    'S' => ['프로모션1', ''],
    'T' => ['', ''],
    'U' => ['프로모션2', ''],
    'V' => ['', ''],
    'W' => ['프로모션3', ''],
    'X' => ['', ''],
    'Y' => ['프로모션4', ''],
    'Z' => ['', ''],
    'AH' => ['시간제보험료', ''],
    'AM' => ['실지급', '금액'],
];
foreach ($cols as $col => [$a, $b]) {
    $sheet->setCellValue($col . $h1, $a);
    $sheet->setCellValue($col . $h2, $b);
}

// ── 데이터 ──
$row      = $h2 + 1;
$totalNet = 0;
foreach ($riders as $i => [$lic, $name, $cnt, $pickup, $delivery, $area, $distCnt, $dist, $promo1]) {
    // 총 정산 금액 = 각 항목 합. 실지급 = 총액 − 시간제보험료.
    $gross  = $pickup + $delivery + $area + $dist + $promo1;
    $hourly = (int) round($gross * 0.019); // 시간제보험료(약 1.9%)
    $net    = $gross - $hourly;
    $totalNet += $net;

    $sheet->setCellValue('A' . $row, $i + 1);
    $sheet->setCellValueExplicit('B' . $row, $lic, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValue('C' . $row, $name);
    $sheet->setCellValue('D' . $row, '010-****-' . str_pad((string) (1000 + $i * 7), 4, '0', STR_PAD_LEFT));
    $sheet->setCellValue('E' . $row, $gross);
    $sheet->setCellValue('F' . $row, $cnt);
    $sheet->setCellValue('G' . $row, $pickup);
    $sheet->setCellValue('H' . $row, $delivery);
    $sheet->setCellValue('I' . $row, $area);
    $sheet->setCellValue('J' . $row, $distCnt);
    $sheet->setCellValue('K' . $row, $dist);
    $sheet->setCellValue('L' . $row, 0);
    $sheet->setCellValue('M' . $row, 0);
    $sheet->setCellValue('N' . $row, 0);
    $sheet->setCellValue('O' . $row, 0);
    $sheet->setCellValue('P' . $row, 0);
    $sheet->setCellValue('Q' . $row, 0);
    $sheet->setCellValue('S' . $row, $promo1);
    $sheet->setCellValue('AH' . $row, $hourly);
    $sheet->setCellValue('AM' . $row, $net);
    $row++;
}

// ── 시간제보험 탭 ── 종합탭 AH 의 원본 근거. 있으면 정산 반영 시 공제 항목으로 잡힌다.
$ins = $book->createSheet();
$ins->setTitle('시간제보험');
$ins->setCellValue('A1', '발생일자');
$ins->setCellValue('B1', '성함');
$ins->setCellValue('C1', '금액');
$r = 2;
foreach ($riders as [$lic, $name, $cnt, $pickup, $delivery, $area, $distCnt, $dist, $promo1]) {
    $gross  = $pickup + $delivery + $area + $dist + $promo1;
    $hourly = (int) round($gross * 0.019);
    $ins->setCellValue('A' . $r, $date);
    $ins->setCellValue('B' . $r, $name);
    $ins->setCellValue('C' . $r, $hourly);
    $r++;
}

// ── 차감내역 탭 ── 사고·오배달 등. 정산 반영 시 「차감내역」 공제로 잡힌다.
$ded = $book->createSheet();
$ded->setTitle('차감내역');
foreach (['A' => '주문일자', 'B' => '축약형ID', 'C' => '성함', 'D' => '구분', 'E' => '스토어명', 'F' => '배정시간', 'G' => '메뉴가', 'H' => '배달비', 'I' => '금액'] as $c => $label) {
    $ded->setCellValue($c . '1', $label);
}
$dedRows = [
    ['이인용9205', '다른음식 오배달', '온육집 숯불갈비 가좌점', 18_500, 3_800, 12_300],
    ['최진수8604', '배달미완료',      '청파냉면 본점',          14_200, 3_500, 9_600],
];
$r = 2;
foreach ($dedRows as [$name, $kind, $store, $menu, $fee, $amt]) {
    $ded->setCellValue('A' . $r, $date);
    $ded->setCellValue('B' . $r, 'ORD' . (1000 + $r));
    $ded->setCellValue('C' . $r, $name);
    $ded->setCellValue('D' . $r, $kind);
    $ded->setCellValue('E' . $r, $store);
    $ded->setCellValue('F' . $r, $date . ' 12:30');
    $ded->setCellValue('G' . $r, $menu);
    $ded->setCellValue('H' . $r, $fee);
    $ded->setCellValue('I' . $r, $amt);
    $r++;
}

$book->setActiveSheetIndex(0);
(new Xlsx($book))->save($out);

printf("✅ 생성: %s\n", $out);
printf("   정산일   %s\n", $date);
printf("   라이더   %d명 (마지막 1명은 미매칭 시연용 '한지훈0000')\n", count($riders));
printf("   실지급 합계 %s원\n", number_format($totalNet));
printf("   탭       종합 · 시간제보험 · 차감내역\n");
