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

        // ⚡ **데이터 전용으로 읽는다.** 기본 로더는 수식을 계산하는데, 배민 주간정산서(을지)에는
        // `SUM(D20:INDEX(D:D,...))` 처럼 **열 전체(1,048,576행)를 참조하는 수식**이 있어 메모리
        // 128MB를 넘기고 타임아웃까지 났다. readDataOnly는 파일에 저장된 **계산 결과(캐시값)**를
        // 그대로 읽으므로 결과는 같고 훨씬 빠르다. 이 파서는 서식·스타일을 안 쓴다(전부 값만 읽음).
        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $this->spreadsheet = $reader->load($filePath);
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
     * @param bool $calculateFormulas 수식 셀을 계산할지. `false`면 수식 문자열이 그대로 온다.
     *   ⚠️ 배민 주간정산서(을지)에는 `SUM(D20:INDEX(D:D,…))`처럼 **열 전체(약 105만 행)를
     *      참조하는 합계 수식**이 있어 계산하면 메모리가 터진다(512MB로도 부족). 그 시트는
     *      정작 데이터 칸이 전부 리터럴 값이라 계산이 필요 없으므로 `false`로 읽는다.
     *      쿠팡 등 기존 경로는 계산이 필요할 수 있어 기본값을 `true`로 둔다.
     *
     * @return array<int, array<string, mixed>>  행 번호(1-based) => [컬럼 => 값]
     */
    public function readSheet(int|string $sheetIndex = 0, int $startRow = 1, int $maxRows = 0, bool $calculateFormulas = true): array
    {
        $worksheet = $this->resolveWorksheet($sheetIndex);
        if ($worksheet === null) {
            return [];
        }

        $raw = $worksheet->toArray(null, $calculateFormulas, false, false);
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

        $allRows = $this->readSheet($sheetIdx, 1, 0);
        // 일일정산서는 「발생일자 / 성함 / 금액」, 주정산서(「시간제보험(차감)」)는 「일자 / 이름 / 금액」으로
        // 라벨이 다르다. 둘 다 받는다 — 안 그러면 주정산서에서 0건으로 읽혀 조용히 누락된다.
        $headerRow = $this->findHeaderRow($allRows, ['발생일자', '일자']);
        if ($headerRow === null) {
            return [];
        }

        $map = $this->mapHeaderColumns($allRows[$headerRow] ?? [], [
            'occurred_date' => ['발생일자', '일자'],
            'name'          => ['성함', '이름'],
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
     * 지원금 탭 — 주문일자·축약형ID·성함·스토어명·픽업/배달지역·배정/수락/배달시간·
     * 배달소요시간·피크타임·지원금(주문 단위). 정산금액과 별개로 존재하며 최종 지급액에
     * **가산**되는 항목(parser.py 확인). "추가지원금" 시트와 이름이 겹치므로(부분일치) 배제한다.
     *
     * @return list<array<string, mixed>>
     */
    public function parseSupportSheet(): array
    {
        $sheetIdx = $this->findSheetIndexExact('지원금', ['추가지원금']);
        if ($sheetIdx === null) {
            return [];
        }

        // ⚠️ '지원금'을 헤더 판정 키워드에 넣으면 시트 제목("지원금 상세 내역서")에도
        // 포함돼 있어 제목 행을 헤더로 오판한다(실 파일로 발견). '주문일자'만 사용.
        $allRows   = $this->readSheet($sheetIdx, 1, 0);
        $headerRow = $this->findHeaderRow($allRows, ['주문일자']);
        if ($headerRow === null) {
            return [];
        }

        $map = $this->mapHeaderColumns($allRows[$headerRow] ?? [], [
            'order_date'    => ['주문일자'],
            'order_no'      => ['축약형 ID', '축약형ID'],
            'name'          => ['성함', '이름'],
            'store_name'    => ['스토어명'],
            'pickup_area'   => ['픽업지역'],
            'delivery_area' => ['배달지역'],
            'assigned_at'   => ['배정시간'],
            'accepted_at'   => ['수락시간'],
            'delivered_at'  => ['배달시간'],
            'duration'      => ['배달소요시간'],
            'peak_time'     => ['피크타임'],
            'amount'        => ['지원금'],
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
                'order_date'       => self::excelDateOnly($cols[$map['order_date'] ?? ''] ?? null),
                'order_no'         => (string) ($cols[$map['order_no'] ?? ''] ?? ''),
                'name_raw'         => $name,
                'name'             => self::cleanName($name),
                'store_name'       => (string) ($cols[$map['store_name'] ?? ''] ?? ''),
                'pickup_area'      => (string) ($cols[$map['pickup_area'] ?? ''] ?? ''),
                'delivery_area'    => (string) ($cols[$map['delivery_area'] ?? ''] ?? ''),
                'assigned_at'      => self::excelDateTime($cols[$map['assigned_at'] ?? ''] ?? null),
                'accepted_at'      => self::excelDateTime($cols[$map['accepted_at'] ?? ''] ?? null),
                'delivered_at'     => self::excelDateTime($cols[$map['delivered_at'] ?? ''] ?? null),
                'duration_minutes' => round($durationDays * 24 * 60, 1),
                'peak_time'        => (string) ($cols[$map['peak_time'] ?? ''] ?? ''),
                'amount'           => (int) round((float) ($cols[$map['amount'] ?? ''] ?? 0)),
            ];
        }

        return $rows;
    }

    /**
     * 추가지원금 탭 — 주문일자·축약형ID·성함·구분·금액. 지원금과 마찬가지로 지급액에 가산.
     *
     * @return list<array<string, mixed>>
     */
    public function parseAddSupportSheet(): array
    {
        $sheetIdx = $this->findSheetIndex('추가지원금');
        if ($sheetIdx === null) {
            return [];
        }

        $allRows   = $this->readSheet($sheetIdx, 1, 0);
        $headerRow = $this->findHeaderRow($allRows, ['주문일자']);
        if ($headerRow === null) {
            return [];
        }

        $map = $this->mapHeaderColumns($allRows[$headerRow] ?? [], [
            'order_date' => ['주문일자'],
            'order_no'   => ['축약형 ID', '축약형ID'],
            'name'       => ['성함', '이름'],
            'category'   => ['구분'],
            'amount'     => ['금액'],
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
                'order_date' => self::excelDateOnly($cols[$map['order_date'] ?? ''] ?? null),
                'order_no'   => (string) ($cols[$map['order_no'] ?? ''] ?? ''),
                'name_raw'   => $name,
                'name'       => self::cleanName($name),
                'category'   => (string) ($cols[$map['category'] ?? ''] ?? ''),
                'amount'     => (int) round((float) ($cols[$map['amount'] ?? ''] ?? 0)),
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

    /**
     * 배달의민족 정산서 파서 — 시트 1개("배달 내역 상세"), 주문(배달건) 단위 원본.
     * 쿠팡과 달리 라이더 요약 탭이 없어 주문을 반환하고, 상위(업로드 API)에서 라이더·운행일별로 집계한다.
     *
     * @return list<array<string,mixed>>
     */
    public function parseBaeminOrders(): array
    {
        // 배민은 대개 첫 시트. "배달" 키워드로 시트 탐색(폴백 0번).
        $sheetIdx = $this->findSheetIndex('배달');
        if ($sheetIdx === null) {
            $sheetIdx = 0;
        }

        $allRows   = $this->readSheet($sheetIdx, 1, 0);
        $headerRow = $this->findHeaderRow($allRows, ['배달번호', '배달처리비']);
        if ($headerRow === null) {
            return [];
        }

        $map = $this->mapHeaderColumns($allRows[$headerRow] ?? [], [
            'run_date'      => ['운행일'],
            'order_no'      => ['배달번호'],
            'status'        => ['배달상태'],
            'service_type'  => ['서비스타입'],
            'delivery_type' => ['배달방식'],
            'rider_id'      => ['라이더ID'],
            'user_id'       => ['User ID', 'UserID'],
            'rider_name'    => ['라이더명'],
            'store_name'    => ['가게이름'],
            'product_price' => ['상품가격'],
            'pickup_area'   => ['픽업 주소', '픽업주소'],
            'delivery_area' => ['전달지 주소', '전달지주소'],
            'order_time'    => ['주문시간'],
            'assigned_at'   => ['배차완료'],
            'store_at'      => ['가게도착'],
            'picked_at'     => ['픽업완료'],
            'delivered_at'  => ['전달완료'],
            'distance'      => ['거리'],
            'fee_base'      => ['기본단가'],
            'fee_weather'   => ['기상할증'],
            'fee_extra'     => ['추가할증'],
            'fee_peak'      => ['피크할증'],
            'fee_area'      => ['지역 할증', '지역할증'],
            'fee_bulk'      => ['대량 할증', '대량할증'],
            'payout'        => ['배달처리비'],
        ]);

        $get = static fn (array $c, ?string $col): mixed => ($col !== null && isset($c[$col])) ? $c[$col] : null;
        $money = static fn (array $c, ?string $col): int => (int) round((float) (self::numOrZero($get($c, $col))));

        $rows = [];
        foreach ($allRows as $rowNum => $cols) {
            if ($rowNum <= $headerRow) {
                continue;
            }
            $orderNo = (string) ($get($cols, $map['order_no'] ?? null) ?? '');
            if ($orderNo === '') {
                continue;
            }

            $riderId  = (string) ($get($cols, $map['rider_id'] ?? null) ?? '');
            $userId   = (string) ($get($cols, $map['user_id'] ?? null) ?? '');
            $nameRaw  = (string) ($get($cols, $map['rider_name'] ?? null) ?? '');
            if ($riderId === '' && $userId === '' && $nameRaw === '') {
                continue;
            }

            $rows[] = [
                'settlement_date' => self::yyyymmdd((string) ($get($cols, $map['run_date'] ?? null) ?? '')),
                'order_no'        => $orderNo,
                'status'          => (string) ($get($cols, $map['status'] ?? null) ?? ''),
                'rider_id'        => $riderId,
                'user_id'         => $userId,
                'name_raw'        => $nameRaw,
                'name'            => self::cleanName($nameRaw),
                'store_name'      => (string) ($get($cols, $map['store_name'] ?? null) ?? ''),
                'pickup_area'     => (string) ($get($cols, $map['pickup_area'] ?? null) ?? ''),
                'delivery_area'   => (string) ($get($cols, $map['delivery_area'] ?? null) ?? ''),
                'assigned_at'     => self::excelDateTime($get($cols, $map['assigned_at'] ?? null)),
                'accepted_at'     => self::excelDateTime($get($cols, $map['picked_at'] ?? null)),
                'delivered_at'    => self::excelDateTime($get($cols, $map['delivered_at'] ?? null)),
                'distance_m'      => $money($cols, $map['distance'] ?? null),
                'delivery_type'   => (string) ($get($cols, $map['delivery_type'] ?? null) ?? ''),
                // 배민 수수료 구성(참고): 기본단가·각종 할증 → 배달처리비(payout)
                'fee_base'        => $money($cols, $map['fee_base'] ?? null),
                'fee_weather'     => $money($cols, $map['fee_weather'] ?? null),
                'fee_extra'       => $money($cols, $map['fee_extra'] ?? null),
                'fee_peak'        => $money($cols, $map['fee_peak'] ?? null),
                'fee_area'        => $money($cols, $map['fee_area'] ?? null),
                'fee_bulk'        => $money($cols, $map['fee_bulk'] ?? null),
                'payout'          => $money($cols, $map['payout'] ?? null),
            ];

            // 🔢 **처리건수에 셀지 여부** — 배민 주간정산서(을지) 「처리건수」와 맞추기 위한 규칙.
            //
            // 2026-08-22 실 정산서(20260812~18) 대조로 확인: 배민은 **금액과 건수를 다른 기준**으로 센다.
            //   · 배달료   = 배달취소 건도 포함해 배달처리비 전액 합산
            //   · 처리건수 = **픽업완료했고 배달처리비가 0원이 아닌 건**만
            // 그래서 아래 두 종류는 돈은 받지만 건수에는 안 들어간다:
            //   · 가게까지 갔다가 픽업 못 한 취소건 → 헛걸음 보상 700원 (기본단가 700, 픽업완료 없음)
            //   · 라이더 귀책으로 0원 처리된 건 (전달완료여도 배달처리비 0)
            // 이 규칙을 안 지키면 우리 집계가 6건 더 많아지고(실측), 건당 정산수수료와
            // 프로모션 건수 구간이 그만큼 어긋난다.
            $last = array_key_last($rows);
            $rows[$last]['counted'] = $rows[$last]['accepted_at'] !== null
                && $rows[$last]['accepted_at'] !== ''
                && $rows[$last]['payout'] > 0;
        }

        return $rows;
    }

    /**
     * 배민 정산서에서 **협력사·사업자 정보**를 뽑는다 — 팀명·지역명 자동 채움용.
     *
     * 쿠팡은 파일명이 `팀_지역_날짜.xlsx` 라 파일명에서 팀/지역을 얻지만, 배민 파일명은
     * `배달처리비_표준서울강서C더블유플러스1_20260812_20260812.xlsx`(일일) /
     * `20260812~20260818_더블유플러스_표준서울강서C더블유플러스1_….xlsx`(주간) 형식이라
     * 같은 규칙을 쓰면 팀명이 "배달처리비"·"20260812~20260818" 처럼 엉뚱하게 잡힌다.
     * 배민은 **파일 안에 제대로 된 값**이 있으므로 그걸 쓴다.
     *
     * @return array{company:string, partner:string}  company=사업자명, partner=협력사명
     */
    public function parseBaeminPartnerInfo(): array
    {
        $out = ['company' => '', 'partner' => ''];

        // 일일: 「배달 내역 상세」 헤더에 협력사명/사업자명 컬럼이 있고 데이터 행마다 값이 들어있다.
        $idx = $this->findSheetIndex('배달');
        if ($idx !== null) {
            $rows = $this->readSheet($idx, 1, 3, false);
            $hdr  = null;
            foreach ($rows as $rowNum => $cols) {
                foreach ((array) $cols as $v) {
                    if (str_contains((string) $v, '협력사명')) {
                        $hdr = $rowNum;
                        break 2;
                    }
                }
            }
            if ($hdr !== null) {
                $map  = $this->mapHeaderColumns($rows[$hdr] ?? [], [
                    'partner' => ['협력사명'],
                    'company' => ['사업자명'],
                ]);
                $data = $this->readSheet($idx, $hdr + 1, 1, false);
                $first = $data[array_key_first($data) ?? 0] ?? [];
                $out['partner'] = trim((string) ($first[$map['partner'] ?? ''] ?? ''));
                $out['company'] = trim((string) ($first[$map['company'] ?? ''] ?? ''));
            }
        }

        // 주간: 갑지의 「1.협력사 정보」 블록 — 라벨과 값이 좌우로 붙어 있다.
        if ($out['partner'] === '') {
            $idx = $this->findSheetIndex('갑지');
            if ($idx !== null) {
                foreach ($this->readSheet($idx, 1, 40, false) as $cols) {
                    $prev = null;
                    foreach ((array) $cols as $v) {
                        $s = trim((string) $v);
                        if ($prev === '협력사명' && $s !== '') {
                            $out['partner'] = $s;
                        }
                        if ($prev === '사업자명' && $s !== '') {
                            $out['company'] = $s;
                        }
                        $prev = $s;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * 배민 **주간정산서 을지**(협력사 소속 라이더 정산 확인용) 파서 — 라이더별 주간 정산 결과.
     *
     * 일일정산서(배달 내역 상세)에는 **배달료와 주문 상세만** 있다. 프로모션·시간제보험료·
     * 고용/산재보험·원천세·최종 지급액은 **주간정산서에만** 있어서, 이걸 안 읽으면 라이더에게
     * 줄 정확한 금액이 나오지 않는다(2026-08-22 실파일 기준 프로모션만 237만원).
     *
     * 시트 구조(2026-08 기준): 안내문 → 행18 헤더 → 행20부터 데이터.
     * 헤더가 2줄(행18 항목명 + 행19 설명)이라 항목명 행을 찾아 매핑한다.
     *
     * @return list<array<string,mixed>>
     */
    public function parseBaeminWeeklyRiders(): array
    {
        $sheetIdx = $this->findSheetIndex('을지');
        if ($sheetIdx === null) {
            $sheetIdx = $this->findSheetIndex('라이더');
        }
        if ($sheetIdx === null) {
            return [];
        }

        // 수식 계산 끄기 — 위 readSheet() 주석 참고(전체열 SUM 때문에 계산하면 메모리가 터진다).
        $allRows = $this->readSheet($sheetIdx, 1, 0, false);
        // ⚠️ 키워드를 「배달료」로 잡으면 안 된다 — 헤더 위 안내문("정산 주간 발생한 배달료 항목")에
        //    걸려 안내문 행을 헤더로 오인한다. 「처리건수」는 헤더에만 나오는 단어라 이걸 쓴다.
        $headerRow = $this->findHeaderRow($allRows, ['처리건수']);
        if ($headerRow === null) {
            return [];
        }

        $map = $this->mapHeaderColumns($allRows[$headerRow] ?? [], [
            'user_id'      => ['User ID', 'UserID'],
            'rider_name'   => ['라이더명', '이름'],
            'order_count'  => ['처리건수'],
            'delivery_fee' => ['배달료'],
            'extra_pay'    => ['추가지급'],
            'total_fee'    => ['총 배달료', '총배달료'],
            'hourly_ins'   => ['시간제보험료'],
            'expense'      => ['필요경비'],
            'reward'       => ['보수액'],
            'emp_ins_rider'  => ['라이더부담'],       // 고용보험(라이더)
            'acc_ins_rider'  => ['라이더부담'],       // 산재보험(라이더) — 같은 이름이라 아래에서 위치로 보정
            'settle_amount'  => ['라이더별'],          // 라이더별 정산금액
            'income_tax'     => ['소득세'],
            'resident_tax'   => ['주민세'],
            'withholding'    => ['원천징수세액'],
            'payout'         => ['라이더별'],          // 라이더별 지급금액 — 위와 동명, 아래에서 보정
        ]);

        // ⚠️ 「라이더부담」·「라이더별」은 같은 라벨이 2번 나온다(고용/산재, 정산금액/지급금액).
        //    mapHeaderColumns가 첫 번째만 잡으므로, 헤더 행을 직접 훑어 2번째 위치를 찾아준다.
        $hdr = $allRows[$headerRow] ?? [];
        $findAll = static function (array $hdr, string $needle): array {
            $hits = [];
            foreach ($hdr as $col => $v) {
                if (str_contains(preg_replace('/\s+/u', '', (string) $v) ?? '', $needle)) {
                    $hits[] = $col;
                }
            }

            return $hits;
        };
        $riderBurden = $findAll($hdr, '라이더부담');
        if (count($riderBurden) >= 2) {
            $map['emp_ins_rider'] = $riderBurden[0];
            $map['acc_ins_rider'] = $riderBurden[1];
        }
        $riderCols = $findAll($hdr, '라이더별');
        if (count($riderCols) >= 2) {
            $map['settle_amount'] = $riderCols[0];
            $map['payout']        = $riderCols[1];
        }

        $get   = static fn (array $c, ?string $col): mixed => ($col !== null && isset($c[$col])) ? $c[$col] : null;
        $money = static fn (array $c, ?string $col): int => (int) round((float) (self::numOrZero($get($c, $col))));

        $rows = [];
        foreach ($allRows as $rowNum => $cols) {
            if ($rowNum <= $headerRow) {
                continue;
            }
            $userId = trim((string) ($get($cols, $map['user_id'] ?? null) ?? ''));
            $name   = trim((string) ($get($cols, $map['rider_name'] ?? null) ?? ''));
            if ($userId === '' && $name === '') {
                continue;
            }
            // 합계행 방어 — User ID 없이 이름만 「합계」류인 행은 건너뛴다.
            if ($userId === '' && preg_match('/합계|소계|총계/u', $name)) {
                continue;
            }

            $rows[] = [
                'user_id'       => $userId,
                'name_raw'      => $name,
                'name'          => self::cleanName($name),
                'order_count'   => $money($cols, $map['order_count'] ?? null),
                'delivery_fee'  => $money($cols, $map['delivery_fee'] ?? null),
                'extra_pay'     => $money($cols, $map['extra_pay'] ?? null),
                'total_fee'     => $money($cols, $map['total_fee'] ?? null),
                'hourly_ins'    => $money($cols, $map['hourly_ins'] ?? null),
                'expense'       => $money($cols, $map['expense'] ?? null),
                'reward'        => $money($cols, $map['reward'] ?? null),
                'emp_ins_rider' => $money($cols, $map['emp_ins_rider'] ?? null),
                'acc_ins_rider' => $money($cols, $map['acc_ins_rider'] ?? null),
                'settle_amount' => $money($cols, $map['settle_amount'] ?? null),
                'income_tax'    => $money($cols, $map['income_tax'] ?? null),
                'resident_tax'  => $money($cols, $map['resident_tax'] ?? null),
                'withholding'   => $money($cols, $map['withholding'] ?? null),
                'payout'        => $money($cols, $map['payout'] ?? null),
            ];
        }

        return $rows;
    }

    /** "YYYYMMDD" → "Y-m-d". 실패 시 오늘. */
    private static function yyyymmdd(string $s): string
    {
        $s = trim($s);
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $s, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }
        $ts = strtotime($s);

        return $ts ? date('Y-m-d', $ts) : date('Y-m-d');
    }

    private static function numOrZero(mixed $v): float
    {
        return is_numeric($v) ? (float) $v : 0.0;
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

    /**
     * 부분일치 키워드가 겹치는 시트명을 구분할 때 사용(예: "지원금" vs "추가지원금").
     *
     * @param list<string> $excludeKeywords 이 키워드를 포함하면 후보에서 제외
     */
    private function findSheetIndexExact(string $keyword, array $excludeKeywords = []): ?int
    {
        foreach ($this->sheetNames as $i => $name) {
            if (!str_contains($name, $keyword)) {
                continue;
            }
            $excluded = false;
            foreach ($excludeKeywords as $ex) {
                if (str_contains($name, $ex)) {
                    $excluded = true;
                    break;
                }
            }
            if (!$excluded) {
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
