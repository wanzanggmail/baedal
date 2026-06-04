<?php

declare(strict_types=1);

require_once __DIR__ . '/SettlementExcelConfig.php';

/**
 * 파일 열기 암호가 걸린 xlsx → 복호화 후 PhpSpreadsheet 파싱 가능한 경로 반환
 */
final class XlsxDecrypt
{
    /** @var list<string> */
    private static array $tempFiles = [];

    /**
     * OLE(암호화) 또는 ZIP(xlsx) 여부 판별
     */
    public static function isEncrypted(string $filePath): bool
    {
        if (!is_readable($filePath)) {
            return false;
        }

        $head = @file_get_contents($filePath, false, null, 0, 4);
        if ($head === false || strlen($head) < 4) {
            return false;
        }

        // 암호화된 Office = OLE compound (D0 CF 11 E0)
        if ($head === "\xD0\xCF\x11\xE0") {
            return true;
        }

        // 일반 xlsx = ZIP (PK..)
        if ($head === "PK\x03\x04") {
            return false;
        }

        return true;
    }

    public static function isReadableXlsx(string $filePath): bool
    {
        if (!is_readable($filePath)) {
            return false;
        }

        if (!extension_loaded('zip')) {
            return false;
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            return false;
        }

        $ok = $zip->locateName('_rels/.rels') !== false;
        $zip->close();

        return $ok;
    }

    /**
     * 파싱에 사용할 파일 경로 (필요 시 복호화 임시 파일)
     *
     * @param list<string> $passwords
     * @throws RuntimeException
     */
    public static function prepareForParsing(string $filePath, array $passwords = []): string
    {
        if (!is_file($filePath)) {
            throw new RuntimeException('업로드 파일을 찾을 수 없습니다.');
        }

        if (!self::isEncrypted($filePath)) {
            if (!self::isReadableXlsx($filePath)) {
                throw new RuntimeException('유효한 xlsx 파일이 아닙니다.');
            }

            return $filePath;
        }

        if ($passwords === []) {
            throw new RuntimeException(
                '암호로 보호된 엑셀입니다. 정산 업로드 화면에서 「엑셀 열기 암호」를 등록하거나, '
                . '서버 환경 변수 SETTLEMENT_EXCEL_PASSWORD 를 설정해 주세요.'
            );
        }

        $lastError = '등록된 비밀번호로 파일을 열 수 없습니다.';

        foreach ($passwords as $password) {
            $out = self::decryptToTemp($filePath, $password);
            if ($out === null) {
                continue;
            }
            if (self::isReadableXlsx($out)) {
                return $out;
            }
            self::unlinkTemp($out);
            $lastError = '복호화는 되었으나 xlsx 구조가 올바르지 않습니다. 비밀번호를 확인해 주세요.';
        }

        throw new RuntimeException($lastError);
    }

    public static function cleanupTemps(): void
    {
        foreach (self::$tempFiles as $path) {
            self::unlinkTemp($path);
        }
        self::$tempFiles = [];
    }

    private static function unlinkTemp(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
        $idx = array_search($path, self::$tempFiles, true);
        if ($idx !== false) {
            unset(self::$tempFiles[$idx]);
            self::$tempFiles = array_values(self::$tempFiles);
        }
    }

    private static function decryptToTemp(string $inputPath, string $password): ?string
    {
        $out = tempnam(sys_get_temp_dir(), 'baedal_xlsx_');
        if ($out === false) {
            return null;
        }
        $outPath = $out . '.xlsx';
        @unlink($out);
        self::$tempFiles[] = $outPath;

        if (self::decryptWithPython($inputPath, $outPath, $password)) {
            return $outPath;
        }

        if (self::decryptWithMsoffcryptoCli($inputPath, $outPath, $password)) {
            return $outPath;
        }

        self::unlinkTemp($outPath);

        return null;
    }

    private static function decryptWithPython(string $input, string $output, string $password): bool
    {
        $script = ROOT_PATH . '/scripts/decrypt_xlsx.py';
        if (!is_file($script)) {
            return false;
        }

        foreach (self::pythonBinaries() as $py) {
            $cmd = sprintf(
                '%s %s %s %s %s 2>&1',
                escapeshellarg($py),
                escapeshellarg($script),
                escapeshellarg($input),
                escapeshellarg($output),
                escapeshellarg($password)
            );

            $lines = [];
            $code  = 0;
            @exec($cmd, $lines, $code);

            if ($code === 0 && is_file($output) && filesize($output) > 0) {
                return true;
            }

            if ($code === 2) {
                throw new RuntimeException(
                    '서버에 msoffcrypto-tool 이 없습니다. pip install -r requirements-settlement.txt 를 실행하세요.'
                );
            }
        }

        return false;
    }

    private static function decryptWithMsoffcryptoCli(string $input, string $output, string $password): bool
    {
        foreach (['msoffcrypto-tool', 'msoffcrypto'] as $bin) {
            $which = self::commandExists($bin);
            if ($which === null) {
                continue;
            }

            $cmd = sprintf(
                '%s -p %s %s %s 2>&1',
                escapeshellarg($which),
                escapeshellarg($password),
                escapeshellarg($input),
                escapeshellarg($output)
            );

            $lines = [];
            $code  = 0;
            @exec($cmd, $lines, $code);

            if ($code === 0 && is_file($output) && filesize($output) > 0) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function pythonBinaries(): array
    {
        $list = [];
        $env  = getenv('SETTLEMENT_PYTHON_BIN');
        if (is_string($env) && $env !== '') {
            $list[] = $env;
        }
        foreach (['python3', 'python'] as $bin) {
            $path = self::commandExists($bin);
            if ($path !== null && !in_array($path, $list, true)) {
                $list[] = $path;
            }
        }

        return $list;
    }

    private static function commandExists(string $command): ?string
    {
        if (str_contains($command, '/') || str_contains($command, '\\')) {
            return is_executable($command) ? $command : null;
        }

        $path = getenv('PATH') ?: '';
        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            $full = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $command;
            if (PHP_OS_FAMILY === 'Windows') {
                foreach (['', '.exe', '.cmd', '.bat'] as $ext) {
                    $try = $full . $ext;
                    if (is_file($try)) {
                        return $try;
                    }
                }
            } elseif (is_executable($full)) {
                return $full;
            }
        }

        return null;
    }
}
