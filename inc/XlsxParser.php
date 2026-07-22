<?php

declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * 쿠팡이츠 일별 정산서(.xlsx) 파서 — PhpSpreadsheet 사용
 */
class XlsxParser
{
    private ?Spreadsheet $spreadsheet = null;
    /** @var list<string> */
    private array $sheetNames = [];

    /** @throws RuntimeException */
    public static function assertRequirements(): void
    {
        $missing = [];
        if (!extension_loaded('zip')) {
            $missing[] = 'zip';
        }
        if (!extension_loaded('fileinfo')) {
            $missing[] = 'fileinfo';
        }

        if ($missing !== []) {
            $ini = php_ini_loaded_file() ?: 'phpinfo()에서 Loaded Configuration File 확인';
            throw new RuntimeException(
                'XLSX 업로드를 위해 PHP 확장이 필요합니다: ' . implode(', ', $missing) . '. '
                . "php.ini ({$ini}) 에서 extension=zip, extension=fileinfo 를 활성화한 뒤 Apache/PHP-FPM을 재시작하세요."
            );
        }

        if (!class_exists(IOFactory::class)) {
            throw new RuntimeException(
                'PhpSpreadsheet가 설치되지 않았습니다. 프로젝트 루트에서 composer install 을 실행하세요.'
            );
        }
    }

    public function open(string $filePath): void
    {
        self::assertRequirements();

        $this->spreadsheet = IOFactory::load($filePath);
        $this->sheetNames  = [];
        foreach ($this->spreadsheet->getAllSheets() as $sheet) {
            $this->sheetNames[] = $sheet->getTitle();
        }
    }

    public function close(): void
    {
        $this->spreadsheet = null;
        $this->sheetNames  = [];
    }

    /** @return list<string> */
    public function getSheetNames(): array
    {
        return $this->sheetNames;
    }

    /**
     * @return array<int, array<string, mixed>>  행 번호(1-based) => [컬럼 => 값]
     */
    public function readSheet(int|string $sheetIndex = 0, int $startRow = 1, int $maxRows = 0): array
    {
        $worksheet = $this->resolveWorksheet($sheetIndex);
        if ($worksheet === null) {
            return [];
        }

        $raw = $worksheet->toArray(null, true, false, false);
        $result = [];

        foreach ($raw as $i => $row) {
            $rowNum = $i + 1;
            if ($rowNum < $startRow) {
                continue;
            }
            if ($maxRows > 0 && $rowNum >= $startRow + $maxRows) {
                break;
            }

            $rowData = [];
            foreach ($row as $colIdx => $val) {
                $col = Coordinate::stringFromColumnIndex((int) $colIdx + 1);
                if ($val === null || $val === '') {
                    continue;
                }
                $rowData[$col] = is_string($val) ? $val : (is_float($val) ? $val : $val);
            }
            if ($rowData !== []) {
                $result[$rowNum] = $rowData;
            }
        }

        return $result;
    }

    /**
     * @return array{date: string, rows: list<array<string, mixed>>}
     */
    public function parseDailySheet(string $settlementDate): array
    {
        $allRows = $this->readSheet(0, 1, 0);
        if ($allRows === []) {
            return ['date' => $settlementDate, 'rows' => []];
        }

        $headerRow    = 0;
        $dataStartRow = 0;
        foreach ($allRows as $rowNum => $cols) {
            $vals = array_values(array_filter(array_map('strval', array_filter((array) $cols, static fn ($v) => $v !== null && $v !== ''))));
            foreach ($vals as $v) {
                if (str_contains($v, '라이선스') || str_contains($v, '성함') || str_contains($v, '이름')) {
                    $headerRow    = $rowNum;
                    $dataStartRow = $rowNum + 2;
                    break 2;
                }
            }
        }

        if ($headerRow === 0) {
            return ['date' => $settlementDate, 'rows' => []];
        }

        $h1     = $allRows[$headerRow] ?? [];
        $h2     = $allRows[$headerRow + 1] ?? [];
        $colMap = $this->buildColumnMap($h1, $h2);

        $rows = [];
        foreach ($allRows as $rowNum => $cols) {
            if ($rowNum < $dataStartRow) {
                continue;
            }

            $licenseId = $cols['B'] ?? null;
            if ($licenseId === null || $licenseId === '') {
                continue;
            }
            if (!is_numeric($licenseId)) {
                continue;
            }

            $name = (string) ($cols[$colMap['name'] ?? 'C'] ?? '');
            if ($name === '') {
                continue;
            }

            $nameClean  = preg_replace('/\d+$/', '', $name);
            $nameSuffix = preg_replace('/^[^0-9]+/', '', $name);

            $row = [
                'license_id'       => (string) $licenseId,
                'name_raw'         => $name,
                'name'             => $nameClean ?: $name,
                'name_suffix'      => $nameSuffix,
                'settlement_date'  => $settlementDate,
                'order_count'      => (int) ($cols[$colMap['order_count'] ?? 'F'] ?? 0),
                'gross_amount'     => (int) round((float) ($cols[$colMap['gross'] ?? 'E'] ?? 0)),
                'fee_pickup'       => (int) ($cols[$colMap['fee_pickup'] ?? 'G'] ?? 0),
                'fee_delivery'     => (int) ($cols[$colMap['fee_delivery'] ?? 'H'] ?? 0),
                'fee_area'         => (int) ($cols[$colMap['fee_area'] ?? 'I'] ?? 0),
                'fee_dist_cnt'     => (int) ($cols[$colMap['dist_cnt'] ?? 'J'] ?? 0),
                'fee_dist_surge'   => (int) ($cols[$colMap['dist_surge'] ?? 'K'] ?? 0),
                'fee_pickup_cnt'   => (int) ($cols[$colMap['pickup_cnt'] ?? 'L'] ?? 0),
                'fee_pickup_surge' => (int) ($cols[$colMap['pickup_surge'] ?? 'M'] ?? 0),
                'fee_dest_cnt'     => (int) ($cols[$colMap['dest_cnt'] ?? 'N'] ?? 0),
                'fee_dest_surge'   => (int) ($cols[$colMap['dest_surge'] ?? 'O'] ?? 0),
                'fee_weather_cnt'  => (int) ($cols[$colMap['weather_cnt'] ?? 'P'] ?? 0),
                'fee_weather'      => (int) ($cols[$colMap['weather'] ?? 'Q'] ?? 0),
                'fee_promo1'       => (int) ($cols[$colMap['promo1'] ?? 'S'] ?? 0),
                'fee_promo2'       => (int) ($cols[$colMap['promo2'] ?? 'U'] ?? 0),
                'fee_promo3'       => (int) ($cols[$colMap['promo3'] ?? 'W'] ?? 0),
                'fee_promo4'       => (int) ($cols[$colMap['promo4'] ?? 'Y'] ?? 0),
                'payout_amount'    => self::moneyCell($cols, $colMap['payout'] ?? 'AM'),
            ];

            // 라이선스 ID·금액 검증 (폴백으로 B열 ID가 들어가는 경우 방지)
            if (!self::isValidLicenseId($row['license_id'])) {
                continue;
            }
            if (abs($row['payout_amount']) > 50_000_000 || abs($row['gross_amount']) > 50_000_000) {
                continue;
            }

            $rows[] = $row;
        }

        return ['date' => $settlementDate, 'rows' => $rows];
    }

    /**
     * 차감내역 탭 — 주문일자 | 축약형ID | 성함 | 구분 | 스토어명 | 배정시간 | 메뉴가 | 배달비 | 금액.
     * ⚠️ 2026-07-23 정정: 예전 버전은 D열부터 한 칸씩 밀려 읽어 실제 차감액(J열, 금액)이 아니라
     * 배달비(I열)를 저장하던 버그가 있었음 — 실 정산서로 검증 후 헤더 기반 매핑으로 재작성.
     *
     * @return list<array<string, mixed>>
     */
    public function parseDeductionSheet(): array
    {
        $sheetIdx = $this->findSheetIndex('차감');
        if ($sheetIdx === null) {
            return [];
        }

        $allRows   = $this->readSheet($sheetIdx, 1, 0);
        $headerRow = $this->findHeaderRow($allRows, ['주문일자']);
        if ($headerRow === null) {
            return [];
        }

        $map = $this->mapHeaderColumns($allRows[$headerRow] ?? [], [
            'order_date'   => ['주문일자'],
            'order_no'     => ['축약형 ID', '축약형ID'],
            'name'         => ['성함'],
            'type'         => ['구분'],
            'store_name'   => ['스토어명'],
            'assigned_at'  => ['배정시간'],
            'menu_price'   => ['메뉴가'],
            'delivery_fee' => ['배달비'],
            'amount'       => ['금액'],
        ]);

        $rows = [];
        foreach ($allRows as $rowNum => $cols) {
            if ($rowNum <= $headerRow) {
                continue;
            }
            if (!array_filter((array) $cols, static fn ($v) => $v !== null && $v !== '')) {
                continue;
            }

            $name = (string) ($cols[$map['name'] ?? ''] ?? '');
            $rows[] = [
                'order_date'   => (string) ($cols[$map['order_date'] ?? ''] ?? ''),
                'order_no'     => (string) ($cols[$map['order_no'] ?? ''] ?? ''),
                'name_raw'     => $name,
                'name'         => self::cleanName($name),
                'type'         => (string) ($cols[$map['type'] ?? ''] ?? ''),
                'store_name'   => (string) ($cols[$map['store_name'] ?? ''] ?? ''),
                'assigned_at'  => self::excelDateTime($cols[$map['assigned_at'] ?? ''] ?? null),
                'menu_price'   => (int) round((float) ($cols[$map['menu_price'] ?? ''] ?? 0)),
                'delivery_fee' => (int) round((float) ($cols[$map['delivery_fee'] ?? ''] ?? 0)),
                'amount'       => (int) round((float) ($cols[$map['amount'] ?? ''] ?? 0)),
            ];
        }

        return $rows;
    }

    /**
     * 오더별 상세 내역서 탭 — 주문 1건 = 1행(성함/주문번호/스토어/픽업·배달지역/배정·수락·배달시간/
     * 거리/할증 세부/정산금액). 실 배달시각을 담아 향후 age-bucket 계산 등에 재사용 가능.
     *
     * @return list<array<string, mixed>>
     */
    public function parseOrderDetailSheet(): array
    {
        $sheetIdx = $this->findSheetIndex('오더별');
        if ($sheetIdx === null) {
            return [];
        }

        $allRows   = $this->readSheet($sheetIdx, 1, 0);
        $headerRow = $this->findHeaderRow($allRows, ['축약형 주문번호']);
        if ($headerRow === null) {
            return [];
        }

        $map = $this->mapHeaderColumns($allRows[$headerRow] ?? [], [
            'name'             => ['성함'],
            'order_no'         => ['축약형 주문번호'],
            'store_name'       => ['스토어명'],
            'pickup_area'      => ['픽업지역'],
            'delivery_area'    => ['배달지역'],
            'assigned_at'      => ['배정시간'],
            'accepted_at'      => ['수락시간'],
            'delivered_at'     => ['배달시간'],
            'duration'         => ['배달소요시간'],
            'peak_time'        => ['피크타임'],
            'distance'         => ['배달거리(m)'],
            'delivery_type'    => ['배달타입'],
            'fee_pickup'       => ['픽업 비용'],
            'fee_delivery'     => ['배달 비용'],
            'fee_area'         => ['지역 단가'],
            'fee_dist_surge'   => ['배달거리 할증'],
            'fee_pickup_surge' => ['픽업지 할증'],
            'fee_dest_surge'   => ['도착지 할증'],
            'fee_weather'      => ['기상 할증'],
            'fee_promo1'       => ['프로모션1'],
            'fee_promo2'       => ['프로모션2'],
            'fee_promo3'       => ['프로모션3'],
            'fee_promo4'       => ['프로모션4'],
            'net_amount'       => ['정산금액'],
        ]);

        $rows = [];
        foreach ($allRows as $rowNum => $cols) {
            if ($rowNum <= $headerRow) {
                continue;
            }
            if (!array_filter((array) $cols, static fn ($v) => $v !== null && $v !== '')) {
                continue;
            }

            $name = (string) ($cols[$map['name'] ?? ''] ?? '');
            if ($name === '') {
                continue;
            }

            $durationDays = (float) ($cols[$map['duration'] ?? ''] ?? 0);

            $rows[] = [
                'name_raw'         => $name,
                'name'             => self::cleanName($name),
                'order_no'         => (string) ($cols[$map['order_no'] ?? ''] ?? ''),
                'store_name'       => (string) ($cols[$map['store_name'] ?? ''] ?? ''),
                'pickup_area'      => (string) ($cols[$map['pickup_area'] ?? ''] ?? ''),
                'delivery_area'    => (string) ($cols[$map['delivery_area'] ?? ''] ?? ''),
                'assigned_at'      => self::excelDateTime($cols[$map['assigned_at'] ?? ''] ?? null),
                'accepted_at'      => self::excelDateTime($cols[$map['accepted_at'] ?? ''] ?? null),
                'delivered_at'     => self::excelDateTime($cols[$map['delivered_at'] ?? ''] ?? null),
                'duration_minutes' => round($durationDays * 24 * 60, 1),
                'peak_time'        => (string) ($cols[$map['peak_time'] ?? ''] ?? ''),
                'distance_m'       => (int) round((float) ($cols[$map['distance'] ?? ''] ?? 0)),
                'delivery_type'    => (string) ($cols[$map['delivery_type'] ?? ''] ?? ''),
                'fee_pickup'       => (int) round((float) ($cols[$map['fee_pickup'] ?? ''] ?? 0)),
                'fee_delivery'     => (int) round((float) ($cols[$map['fee_delivery'] ?? ''] ?? 0)),
                'fee_area'         => (int) round((float) ($cols[$map['fee_area'] ?? ''] ?? 0)),
                'fee_dist_surge'   => (int) round((float) ($cols[$map['fee_dist_surge'] ?? ''] ?? 0)),
                'fee_pickup_surge' => (int) round((float) ($cols[$map['fee_pickup_surge'] ?? ''] ?? 0)),
                'fee_dest_surge'   => (int) round((float) ($cols[$map['fee_dest_surge'] ?? ''] ?? 0)),
                'fee_weather'      => (int) round((float) ($cols[$map['fee_weather'] ?? ''] ?? 0)),
                'fee_promo1'       => (int) round((float) ($cols[$map['fee_promo1'] ?? ''] ?? 0)),
                'fee_promo2'       => (int) round((float) ($cols[$map['fee_promo2'] ?? ''] ?? 0)),
                'fee_promo3'       => (int) round((float) ($cols[$map['fee_promo3'] ?? ''] ?? 0)),
                'fee_promo4'       => (int) round((float) ($cols[$map['fee_promo4'] ?? ''] ?? 0)),
                'net_amount'       => (int) round((float) ($cols[$map['net_amount'] ?? ''] ?? 0)),
            ];
        }

        return $rows;
    }

    /**
     * 시간제보험 탭 — 발생일자 | 성함 | 금액 (라이더·일자별 1행, 종합탭 AH합계의 원본 근거).
     *
     * @return list<array<string, mixed>>
     */
    public function parseHourlyInsuranceSheet(): array
    {
        $sheetIdx = $this->findSheetIndex('시간제보험');
        if ($sheetIdx === null) {
            return [];
        }

        $allRows   = $this->readSheet($sheetIdx, 1, 0);
        $headerRow = $this->findHeaderRow($allRows, ['발생일자']);
        if ($headerRow === null) {
            return [];
        }

        $map = $this->mapHeaderColumns($allRows[$headerRow] ?? [], [
            'occurred_date' => ['발생일자'],
            'name'          => ['성함'],
            'amount'        => ['금액'],
        ]);

        $rows = [];
        foreach ($allRows as $rowNum => $cols) {
            if ($rowNum <= $headerRow) {
                continue;
            }
            if (!array_filter((array) $cols, static fn ($v) => $v !== null && $v !== '')) {
                continue;
            }

            $name = (string) ($cols[$map['name'] ?? ''] ?? '');
            if ($name === '') {
                continue;
            }

            $rows[] = [
                'occurred_date' => self::excelDateOnly($cols[$map['occurred_date'] ?? ''] ?? null),
                'name_raw'      => $name,
                'name'          => self::cleanName($name),
                'amount'        => abs((int) round((float) ($cols[$map['amount'] ?? ''] ?? 0))),
            ];
        }

        return $rows;
    }

    /**
     * 헤더 행 탐색 — 각 행의 셀 값 중 하나라도 키워드를 포함하면 그 행을 헤더로 판정.
     *
     * @param array<int, array<string, mixed>> $allRows
     * @param list<string> $keywords
     */
    private function findHeaderRow(array $allRows, array $keywords): ?int
    {
        foreach ($allRows as $rowNum => $cols) {
            foreach ((array) $cols as $val) {
                if ($val === null || $val === '') {
                    continue;
                }
                $s = (string) $val;
                foreach ($keywords as $kw) {
                    if (str_contains($s, $kw)) {
                        return $rowNum;
                    }
                }
            }
        }

        return null;
    }

    /**
     * 헤더 행의 컬럼(A,B,C…)을 키워드 매칭으로 논리 컬럼명에 매핑.
     * 컬럼 순서대로 훑으며, 이미 배정된 키는 건너뛴다(첫 매칭 우선).
     *
     * @param array<string, mixed> $headerRow
     * @param array<string, list<string>> $patterns key => [keyword, ...]
     * @return array<string, string> key => 컬럼 문자
     */
    private function mapHeaderColumns(array $headerRow, array $patterns): array
    {
        $map = [];
        foreach ($headerRow as $col => $label) {
            $label = trim((string) $label);
            if ($label === '') {
                continue;
            }
            foreach ($patterns as $key => $keywords) {
                if (isset($map[$key])) {
                    continue;
                }
                foreach ($keywords as $kw) {
                    if (str_contains($label, $kw)) {
                        $map[$key] = (string) $col;
                        break;
                    }
                }
            }
        }

        return $map;
    }

    /** 이름 뒤 붙는 숫자 접미사 제거 (예: "박성준1682" → "박성준") */
    private static function cleanName(string $name): string
    {
        return preg_replace('/\d+$/', '', $name) ?: $name;
    }

    /** 엑셀 시리얼(날짜+시간) → "Y-m-d H:i:s" 문자열. 실패 시 null. */
    private static function excelDateTime(mixed $val): ?string
    {
        if ($val === null || $val === '') {
            return null;
        }
        if (is_numeric($val)) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $val)->format('Y-m-d H:i:s');
            } catch (Throwable) {
                return null;
            }
        }
        $ts = strtotime((string) $val);

        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }

    /** 엑셀 시리얼(날짜) → "Y-m-d" 문자열. 실패 시 null. */
    private static function excelDateOnly(mixed $val): ?string
    {
        $dt = self::excelDateTime($val);

        return $dt !== null ? substr($dt, 0, 10) : null;
    }

    private function resolveWorksheet(int|string $sheetIndex): ?Worksheet
    {
        if ($this->spreadsheet === null) {
            return null;
        }

        if (is_int($sheetIndex)) {
            return $this->spreadsheet->getSheet($sheetIndex);
        }

        return $this->spreadsheet->getSheetByName($sheetIndex);
    }

    private function findSheetIndex(string $keyword): ?int
    {
        foreach ($this->sheetNames as $i => $name) {
            if (str_contains($name, $keyword)) {
                return $i;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $h1 @param array<string, mixed> $h2 @return array<string, string> */
    private function buildColumnMap(array $h1, array $h2): array
    {
        $map  = [];
        $allH = [];

        foreach ([
            'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z',
            'AA', 'AB', 'AC', 'AD', 'AE', 'AF', 'AG', 'AH', 'AI', 'AJ', 'AK', 'AL', 'AM', 'AN', 'AO', 'AP',
        ] as $col) {
            $allH[$col] = trim((string) ($h1[$col] ?? '') . ' ' . (string) ($h2[$col] ?? ''));
        }

        foreach ($allH as $col => $label) {
            if (str_contains($label, '라이선스')) {
                $map['license_col'] = $col;
            } elseif (str_contains($label, '성함') || ($col === 'C' && $label === '')) {
                $map['name'] = $col;
            } elseif (str_contains($label, '오더수') && !isset($map['order_count'])) {
                $map['order_count'] = $col;
            } elseif (str_contains($label, '총 정산') && str_contains($label, '금액') && !isset($map['gross'])) {
                $map['gross'] = $col;
            } elseif (str_contains($label, '픽업 비용')) {
                $map['fee_pickup'] = $col;
            } elseif (str_contains($label, '배달 비용')) {
                $map['fee_delivery'] = $col;
            } elseif (str_contains($label, '지역 단가')) {
                $map['fee_area'] = $col;
            } elseif (str_contains($label, '배달거리 할증') && str_contains($label, '건')) {
                $map['dist_cnt'] = $col;
            } elseif (str_contains($label, '배달거리 할증')) {
                $map['dist_surge'] = $col;
            } elseif (str_contains($label, '픽업지 할증') && str_contains($label, '건')) {
                $map['pickup_cnt'] = $col;
            } elseif (str_contains($label, '픽업지 할증')) {
                $map['pickup_surge'] = $col;
            } elseif ((str_contains($label, '도착지 할증') || str_contains($label, '도착지할증')) && str_contains($label, '건')) {
                $map['dest_cnt'] = $col;
            } elseif (str_contains($label, '도착지 할증') || str_contains($label, '도착지할증')) {
                $map['dest_surge'] = $col;
            } elseif (str_contains($label, '기상') && str_contains($label, '건')) {
                $map['weather_cnt'] = $col;
            } elseif (str_contains($label, '기상')) {
                $map['weather'] = $col;
            } elseif (str_contains($label, '프로모션1') || str_contains($label, '프로모1')) {
                $map['promo1'] = $col;
            } elseif (str_contains($label, '프로모션2') || str_contains($label, '프로모2')) {
                $map['promo2'] = $col;
            } elseif (str_contains($label, '프로모션3') || str_contains($label, '프로모3')) {
                $map['promo3'] = $col;
            } elseif (str_contains($label, '프로모션4') || str_contains($label, '프로모4')) {
                $map['promo4'] = $col;
            } elseif (str_contains($label, '보수액') && !str_contains($label, '사업주')) {
                $map['payout'] = $col;
            } elseif (str_contains($label, '실지급') || (str_contains($label, '지급액') && !str_contains($label, '예정'))) {
                $map['payout'] = $col;
            }
        }

        return $map;
    }

    /** @param array<string, mixed> $cols */
    private static function moneyCell(array $cols, string $col): int
    {
        if (!isset($cols[$col]) || !is_numeric($cols[$col])) {
            return 0;
        }

        return (int) round((float) $cols[$col]);
    }

    private static function isValidLicenseId(string $id): bool
    {
        if ($id === '' || !ctype_digit($id)) {
            return false;
        }

        $len = strlen($id);

        return $len >= 9 && $len <= 15;
    }
}
