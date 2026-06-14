<?php

declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
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

    /** @return list<array<string, mixed>> */
    public function parseDeductionSheet(): array
    {
        $sheetIdx = $this->findSheetIndex('차감');
        if ($sheetIdx === null) {
            return [];
        }

        $allRows      = $this->readSheet($sheetIdx, 1, 0);
        $headerRow    = 0;
        $dataStartRow = 0;

        foreach ($allRows as $rowNum => $cols) {
            $vals = array_values(array_filter(array_map('strval', (array) $cols)));
            foreach ($vals as $v) {
                if (str_contains($v, '주문일자') || str_contains($v, '축약형')) {
                    $headerRow    = $rowNum;
                    $dataStartRow = $rowNum + 1;
                    break 2;
                }
            }
        }

        if ($headerRow === 0) {
            return [];
        }

        $rows = [];
        foreach ($allRows as $rowNum => $cols) {
            if ($rowNum < $dataStartRow) {
                continue;
            }
            if ($cols === [] || !array_filter((array) $cols, static fn ($v) => $v !== null && $v !== '')) {
                continue;
            }

            $rows[] = [
                'order_date'   => (string) ($cols['B'] ?? ''),
                'order_no'     => (string) ($cols['C'] ?? ''),
                'type'         => (string) ($cols['D'] ?? ''),
                'store_name'   => (string) ($cols['E'] ?? ''),
                'assigned_at'  => (string) ($cols['F'] ?? ''),
                'menu_price'   => (int) ($cols['G'] ?? 0),
                'delivery_fee' => (int) ($cols['H'] ?? 0),
                'amount'       => (int) ($cols['I'] ?? 0),
            ];
        }

        return $rows;
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
