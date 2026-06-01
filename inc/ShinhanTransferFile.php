<?php

declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * 신한은행 BizBank 자금이체 — 파일불러오기 양식
 *
 * 공식 안내(대량이체 PDF) 필드 순서:
 *   A 입금은행(은행코드 3자리) → B 입금계좌 → C 고객관리성명 → D 입금액
 * 은행명 한글 불가, 계좌·금액은 숫자만.
 *
 * @see http://img.shinhan.com/cib/ko/data/biz_idx_061013.pdf
 */
final class ShinhanTransferFile
{
    /** @var array<string, string> 은행명 → 3자리 금융기관코드 */
    private const BANK_NAME_TO_CODE = [
        '국민'     => '004',
        '국민은행' => '004',
        'kb'       => '004',
        '신한'     => '088',
        '신한은행' => '088',
        '우리'     => '020',
        '우리은행' => '020',
        '농협'     => '011',
        'nh'       => '011',
        '카카오'   => '090',
        '카카오뱅크' => '090',
        '하나'     => '081',
        '하나은행' => '081',
        '기업'     => '003',
        'ibk'      => '003',
        'ibk기업'  => '003',
        'ibk기업은행' => '003',
        '토스'     => '092',
        '토스뱅크' => '092',
        'sc'       => '023',
        'sc제일'   => '023',
        '씨티'     => '027',
        '대구'     => '031',
        '아이엠'   => '031',
        '부산'     => '032',
        '광주'     => '034',
        '제주'     => '035',
        '전북'     => '037',
        '경남'     => '039',
        '새마을'   => '045',
        '신협'     => '048',
        '우체국'   => '071',
        '케이'     => '089',
        '케이뱅크' => '089',
    ];

    /** @return list<string> */
    public static function columnHeaders(): array
    {
        return ['입금은행', '입금계좌', '고객관리성명', '입금액'];
    }

    /**
     * @param list<array<string, mixed>> $withdrawals Withdrawal::mapRow 형태
     * @return array{rows: list<list<string|int>>, errors: list<string>}
     */
    public static function buildDataRows(array $withdrawals): array
    {
        $rows   = [];
        $errors = [];

        foreach ($withdrawals as $i => $w) {
            $line = $i + 1;
            $bankCode = self::normalizeBankCode(
                (string) ($w['bank_code'] ?? ''),
                (string) ($w['bank'] ?? '')
            );
            if ($bankCode === '') {
                $errors[] = "{$line}행: 입금은행 코드 없음 ({$w['rider_name']} / {$w['id']})";
                continue;
            }

            $account = self::normalizeAccount((string) ($w['account'] ?? ''));
            if ($account === '') {
                $errors[] = "{$line}행: 입금계좌 없음 ({$w['rider_name']})";
                continue;
            }

            $name = self::normalizeName((string) ($w['holder'] ?? $w['rider_name'] ?? ''));
            if ($name === '') {
                $errors[] = "{$line}행: 고객관리성명 없음 ({$w['id']})";
                continue;
            }

            $amount = (int) ($w['amount'] ?? 0);
            if ($amount <= 0) {
                $errors[] = "{$line}행: 입금액 0원 ({$w['id']})";
                continue;
            }

            $rows[] = [$bankCode, $account, $name, $amount];
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    public static function normalizeBankCode(string $code, string $bankLabel = ''): string
    {
        $digits = preg_replace('/\D/', '', $code) ?? '';
        if ($digits !== '') {
            if (strlen($digits) > 3) {
                $digits = substr($digits, -3);
            }

            return str_pad($digits, 3, '0', STR_PAD_LEFT);
        }

        $label = mb_strtolower(trim($bankLabel), 'UTF-8');
        if ($label === '') {
            return '';
        }

        foreach (self::BANK_NAME_TO_CODE as $key => $mapped) {
            if ($label !== '' && str_contains($label, mb_strtolower($key, 'UTF-8'))) {
                return $mapped;
            }
        }

        return '';
    }

    public static function normalizeAccount(string $account): string
    {
        return preg_replace('/\D/', '', $account) ?? '';
    }

    public static function normalizeName(string $name): string
    {
        $name = preg_replace('/\s+/u', '', trim($name)) ?? '';

        return mb_substr($name, 0, 20, 'UTF-8');
    }

    /**
     * BizBank 텍스트/CSV — 탭 구분, 데이터만(헤더 없음)
     *
     * @param list<list<string|int>> $dataRows
     */
    public static function toTabText(array $dataRows): string
    {
        $lines = [];
        foreach ($dataRows as $row) {
            $lines[] = implode("\t", [
                (string) $row[0],
                (string) $row[1],
                (string) $row[2],
                (string) $row[3],
            ]);
        }

        return implode("\r\n", $lines) . ($lines !== [] ? "\r\n" : '');
    }

    /**
     * @param list<list<string|int>> $dataRows
     */
    public static function toCsvUtf8(array $dataRows, bool $includeHeader = true): string
    {
        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            return '';
        }

        if ($includeHeader) {
            fputcsv($out, self::columnHeaders());
        }
        foreach ($dataRows as $row) {
            fputcsv($out, [
                self::csvTextCell((string) $row[0]),
                self::csvTextCell((string) $row[1]),
                (string) $row[2],
                (string) $row[3],
            ]);
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return "\xEF\xBB\xBF" . ($csv !== false ? $csv : '');
    }

    /**
     * @param list<list<string|int>> $dataRows
     */
    public static function toXlsxBinary(array $dataRows, bool $includeHeader = true): string
    {
        $prevHandler = set_error_handler(static function (int $severity, string $message): bool {
            if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
                return true;
            }

            return false;
        });

        try {
            $sheet = new Spreadsheet();
            $ws    = $sheet->getActiveSheet();
            $ws->setTitle('자금이체');

            $startRow = 1;
            if ($includeHeader) {
                foreach (self::columnHeaders() as $colIdx => $header) {
                    self::setTextCell($ws, self::colLetter($colIdx), 1, $header);
                }
                $startRow = 2;
            }

            foreach ($dataRows as $i => $row) {
                $r = $startRow + $i;
                self::setTextCell($ws, 'A', $r, (string) $row[0]);
                self::setTextCell($ws, 'B', $r, (string) $row[1]);
                self::setTextCell($ws, 'C', $r, (string) $row[2]);
                $ws->setCellValue('D' . $r, (int) $row[3]);
            }

            $lastRow = max($startRow, $startRow + count($dataRows) - 1);
            if ($lastRow >= 1) {
                $ws->getStyle('A1:C' . $lastRow)
                    ->getNumberFormat()
                    ->setFormatCode(NumberFormat::FORMAT_TEXT);
            }

            foreach (range('A', 'D') as $col) {
                $ws->getColumnDimension($col)->setAutoSize(true);
            }

            $tmp = tempnam(sys_get_temp_dir(), 'shinhan_wd_');
            if ($tmp === false) {
                throw new RuntimeException('임시 파일을 만들 수 없습니다.');
            }

            try {
                $writer = new Xlsx($sheet);
                $writer->save($tmp);
                $bin = file_get_contents($tmp);
                if ($bin === false) {
                    throw new RuntimeException('엑셀 파일을 읽을 수 없습니다.');
                }

                return $bin;
            } finally {
                @unlink($tmp);
                $sheet->disconnectWorksheets();
            }
        } finally {
            if ($prevHandler !== null) {
                set_error_handler($prevHandler);
            } else {
                restore_error_handler();
            }
        }
    }

    /** Excel/CSV에서 선행 0·긴 계좌번호가 숫자로 깨지지 않게 */
    private static function csvTextCell(string $value): string
    {
        return "\t" . $value;
    }

    private static function colLetter(int $zeroBasedIndex): string
    {
        return chr(ord('A') + $zeroBasedIndex);
    }

    private static function setTextCell(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $ws, string $col, int $row, string $value): void
    {
        $addr = $col . $row;
        $ws->setCellValueExplicit($addr, $value, DataType::TYPE_STRING);
        $ws->getStyle($addr)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
    }

    public static function suggestFilename(string $ext, ?string $payoutDate = null): string
    {
        $date = $payoutDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $payoutDate)
            ? str_replace('-', '', $payoutDate)
            : date('Ymd');
        $ts = date('His');

        return "shinhan_bizbank_{$date}_{$ts}.{$ext}";
    }
}
