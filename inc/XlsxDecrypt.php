<?php

declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\IOFactory;

require_once __DIR__ . '/SettlementExcelConfig.php';

/**
 * 파일 열기 암호가 걸린 xlsx → 복호화 후 PhpSpreadsheet 파싱 가능한 경로 반환
 */
final class XlsxDecrypt
{
    /** @var list<string> */
    private static array $tempFiles = [];

    /** @var list<array{python: string, code: int, output: string}> */
    private static array $lastAttempts = [];

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

        if ($head === "\xD0\xCF\x11\xE0") {
            return true;
        }

        if ($head === "PK\x03\x04") {
            return false;
        }

        return true;
    }

    public static function isReadableXlsx(string $filePath): bool
    {
        return self::validateDecryptedFile($filePath) === 'ok';
    }

    /**
     * @return 'ok'|'not_zip'|'ole_xls'|'unreadable'|'zip_ext_missing'
     */
    public static function validateDecryptedFile(string $filePath): string
    {
        if (!is_readable($filePath) || filesize($filePath) < 4) {
            return 'unreadable';
        }

        $head = @file_get_contents($filePath, false, null, 0, 4);
        if ($head === false || strlen($head) < 4) {
            return 'unreadable';
        }

        if ($head === "\xD0\xCF\x11\xE0") {
            return 'ole_xls';
        }

        if ($head !== "PK\x03\x04") {
            return 'not_zip';
        }

        if (!extension_loaded('zip')) {
            return 'zip_ext_missing';
        }

        try {
            $reader = IOFactory::createReader('Xlsx');
            if ($reader->canRead($filePath)) {
                return 'ok';
            }
        } catch (Throwable) {
            // fall through to ZipArchive check
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            return 'unreadable';
        }

        $ok = $zip->locateName('_rels/.rels') !== false
            || $zip->locateName('xl/workbook.xml') !== false
            || $zip->locateName('[Content_Types].xml') !== false;
        $zip->close();

        return $ok ? 'ok' : 'unreadable';
    }

    /**
     * 웹(Apache/php-fpm)에서 Python·msoffcrypto 사용 가능 여부 진단
     *
     * @return array<string, mixed>
     */
    public static function diagnostics(): array
    {
        $result = [
            'exec_enabled'     => self::isExecEnabled(),
            'python_binaries'  => [],
            'msoffcrypto_cli'  => [],
            'recommended_bin'  => null,
            'script_path'      => ROOT_PATH . '/scripts/decrypt_xlsx.py',
            'script_exists'    => is_file(ROOT_PATH . '/scripts/decrypt_xlsx.py'),
            'settlement_python_env' => getenv('SETTLEMENT_PYTHON_BIN') ?: null,
            'path_env'         => getenv('PATH') ?: '',
            'php_user'         => self::phpProcessUser(),
            'zip_extension'    => extension_loaded('zip'),
            'hint'             => 'Apache 재시작은 pip 설치 후 보통 필요 없습니다. 웹 사용자(apache/www-data)와 동일한 python3에 msoffcrypto가 있어야 합니다.',
        ];

        foreach (self::pythonBinaries() as $py) {
            $info = self::probePython($py);
            $result['python_binaries'][] = $info;
            if ($result['recommended_bin'] === null && ($info['msoffcrypto_ok'] ?? false)) {
                $result['recommended_bin'] = $py;
            }
        }

        foreach (['msoffcrypto-tool', 'msoffcrypto'] as $bin) {
            $path = self::commandExists($bin);
            if ($path !== null) {
                $result['msoffcrypto_cli'][] = $path;
            }
        }

        return $result;
    }

    /**
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

        if (!self::isExecEnabled()) {
            throw new RuntimeException(
                'PHP에서 shell 명령(exec)이 비활성화되어 엑셀 암호를 해제할 수 없습니다. '
                . 'php.ini disable_functions 에서 exec, shell_exec 를 허용해 주세요.'
            );
        }

        if ($passwords === []) {
            throw new RuntimeException(
                '암호로 보호된 엑셀입니다. 정산 업로드 화면에서 「엑셀 열기 암호」를 등록하거나, '
                . '서버 환경 변수 SETTLEMENT_EXCEL_PASSWORD 를 설정해 주세요.'
            );
        }

        $lastError   = '등록된 비밀번호로 파일을 열 수 없습니다.';
        $sawOleXls   = false;
        $sawNotZip   = false;

        foreach ($passwords as $password) {
            self::$lastAttempts = [];
            $out = self::decryptToTemp($filePath, $password);
            if ($out === null) {
                if (self::allAttemptsMissingMsoffcrypto()) {
                    throw new RuntimeException(self::buildMsoffcryptoHelpMessage());
                }
                foreach (self::$lastAttempts as $a) {
                    if (str_contains($a['output'], 'decrypted_ole_xls')) {
                        $sawOleXls = true;
                    }
                    if (str_contains($a['output'], 'decrypted_invalid') || str_contains($a['output'], 'decrypted_empty')) {
                        $sawNotZip = true;
                    }
                }
                continue;
            }

            $valid = self::validateDecryptedFile($out);
            if ($valid === 'ok') {
                return $out;
            }

            self::unlinkTemp($out);

            if ($valid === 'ole_xls') {
                $sawOleXls = true;
            } elseif ($valid === 'not_zip') {
                $sawNotZip = true;
            } elseif ($valid === 'zip_ext_missing') {
                throw new RuntimeException(
                    'PHP zip 확장이 없어 복호화된 xlsx를 읽을 수 없습니다. php.ini 에서 extension=zip 을 활성화하세요.'
                );
            }
        }

        if ($sawOleXls) {
            throw new RuntimeException(
                '암호 해제 후 파일이 구형 Excel(.xls) 형식입니다. 배민에서 받은 파일이 .xlsx 인지 확인하거나, Excel에서 xlsx로 다시 저장해 주세요.'
            );
        }

        if ($sawNotZip) {
            throw new RuntimeException(
                '엑셀 열기 암호가 맞지 않거나 복호화 결과가 손상되었습니다. 등록된 암호·대소문자·공백을 확인해 주세요.'
            );
        }

        if (self::allAttemptsMissingMsoffcrypto()) {
            throw new RuntimeException(self::buildMsoffcryptoHelpMessage());
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
            $run = self::runCommand(sprintf(
                '%s %s %s %s %s 2>&1',
                escapeshellarg($py),
                escapeshellarg($script),
                escapeshellarg($input),
                escapeshellarg($output),
                escapeshellarg($password)
            ), $py);

            self::$lastAttempts[] = $run;

            if ($run['code'] === 0 && self::validateDecryptedFile($output) === 'ok') {
                return true;
            }
        }

        return false;
    }

    /** @return array{python: string, code: int, output: string} */
    private static function runCommand(string $cmd, string $pyLabel): array
    {
        $lines = [];
        $code  = 0;
        @exec($cmd, $lines, $code);

        return [
            'python' => $pyLabel,
            'code'   => $code,
            'output' => trim(implode("\n", $lines)),
        ];
    }

    private static function decryptWithMsoffcryptoCli(string $input, string $output, string $password): bool
    {
        foreach (['msoffcrypto-tool', 'msoffcrypto'] as $bin) {
            $which = self::commandExists($bin);
            if ($which === null) {
                continue;
            }

            $run = self::runCommand(sprintf(
                '%s -p %s %s %s 2>&1',
                escapeshellarg($which),
                escapeshellarg($password),
                escapeshellarg($input),
                escapeshellarg($output)
            ), $which);

            self::$lastAttempts[] = $run;

            if ($run['code'] === 0 && self::validateDecryptedFile($output) === 'ok') {
                return true;
            }

            // 일부 버전 CLI: 인자 순서가 다를 수 있음
            $runAlt = self::runCommand(sprintf(
                '%s %s %s -p %s 2>&1',
                escapeshellarg($which),
                escapeshellarg($input),
                escapeshellarg($output),
                escapeshellarg($password)
            ), $which . ' (alt)');
            self::$lastAttempts[] = $runAlt;

            if ($runAlt['code'] === 0 && self::validateDecryptedFile($output) === 'ok') {
                return true;
            }
        }

        return false;
    }

    private static function allAttemptsMissingMsoffcrypto(): bool
    {
        if (self::$lastAttempts === []) {
            return false;
        }

        foreach (self::$lastAttempts as $a) {
            if ($a['code'] !== 2) {
                return false;
            }
            if (!str_contains($a['output'], 'msoffcrypto')) {
                return false;
            }
        }

        return true;
    }

    private static function buildMsoffcryptoHelpMessage(): string
    {
        $diag = self::diagnostics();
        $lines = [
            '웹 서버(PHP)가 사용하는 Python에 msoffcrypto가 없습니다.',
            'SSH에서 pip 설치만 했다면 Apache/php-fpm 사용자(보통 www-data)용 Python에 다시 설치해야 합니다.',
            'Apache 재시작은 pip 설치 후 보통 필요 없습니다. (환경변수 SETTLEMENT_PYTHON_BIN 변경 시에만 php-fpm/Apache 재시작)',
        ];

        if (!$diag['exec_enabled']) {
            $lines[] = '※ PHP exec() 가 비활성화되어 있습니다.';
        }

        $phpUser = (string) ($diag['php_user'] ?? '');
        if ($phpUser !== '') {
            $lines[] = "PHP 실행 사용자: {$phpUser}";
        }

        foreach ($diag['python_binaries'] as $p) {
            $py = (string) ($p['path'] ?? '');
            $ver = (string) ($p['version'] ?? '');
            $ok  = !empty($p['msoffcrypto_ok']) ? 'OK' : '없음';
            $lines[] = "Python {$py} ({$ver}) → msoffcrypto: {$ok}";
        }

        $rec = $diag['recommended_bin'] ?? null;
        if (is_string($rec) && $rec !== '') {
            $lines[] = "권장: Apache/PHP-FPM SetEnv SETTLEMENT_PYTHON_BIN {$rec}";
        }

        $lines[] = '설치 예 (웹과 동일 사용자):';
        $targetPy = is_string($rec) && $rec !== '' ? $rec : '/usr/bin/python3';
        $lines[] = "  sudo -u {$phpUser} {$targetPy} -m pip install msoffcrypto-tool";
        $lines[] = '또는: sudo ' . $targetPy . ' -m pip install msoffcrypto-tool';
        $lines[] = '진단: ' . rtrim(ADMIN_BASE, '/') . '/api/settlement_excel_check.php (관리자 로그인 후)';

        if (self::$lastAttempts !== []) {
            $last = self::$lastAttempts[count(self::$lastAttempts) - 1];
            if ($last['output'] !== '') {
                $lines[] = '마지막 오류: ' . mb_substr($last['output'], 0, 200);
            }
        }

        return implode("\n", $lines);
    }

    /** @return array<string, mixed> */
    private static function probePython(string $py): array
    {
        $run = self::runCommand(
            escapeshellarg($py) . ' -c "import sys; print(sys.executable); print(sys.version)" 2>&1',
            $py
        );
        $verRun = self::runCommand(
            escapeshellarg($py) . ' -c "import msoffcrypto; print(msoffcrypto.__name__)" 2>&1',
            $py
        );

        return [
            'path'            => $py,
            'version'         => $run['output'],
            'msoffcrypto_ok'  => $verRun['code'] === 0,
            'msoffcrypto_err' => $verRun['code'] !== 0 ? $verRun['output'] : '',
        ];
    }

    /** @return list<string> */
    private static function pythonBinaries(): array
    {
        $list = [];

        foreach (self::envValues('SETTLEMENT_PYTHON_BIN') as $v) {
            if (is_executable($v) && !in_array($v, $list, true)) {
                $list[] = $v;
            }
        }

        $common = [
            '/usr/bin/python3',
            '/usr/local/bin/python3',
            '/bin/python3',
        ];
        if (PHP_OS_FAMILY === 'Windows') {
            $common = array_merge(
                [
                    'C:\\Python312\\python.exe',
                    'C:\\Python311\\python.exe',
                    'C:\\Python310\\python.exe',
                ],
                $common
            );
        }
        foreach ($common as $path) {
            if (is_executable($path) && !in_array($path, $list, true)) {
                $list[] = $path;
            }
        }

        foreach (['python3', 'python'] as $bin) {
            $path = self::commandExists($bin);
            if ($path !== null && !in_array($path, $list, true)) {
                $list[] = $path;
            }
        }

        $which = self::resolveWhich('python3') ?? self::resolveWhich('python');
        if ($which !== null && !in_array($which, $list, true)) {
            $list[] = $which;
        }

        return $list;
    }

    /** @return list<string> */
    private static function envValues(string $name): array
    {
        $vals = [];
        foreach ([getenv($name), $_SERVER[$name] ?? '', $_SERVER['REDIRECT_' . $name] ?? ''] as $v) {
            if (is_string($v) && $v !== '' && !in_array($v, $vals, true)) {
                $vals[] = $v;
            }
        }

        return $vals;
    }

    private static function resolveWhich(string $command): ?string
    {
        if (!self::isExecEnabled()) {
            return null;
        }

        $lines = [];
        @exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null', $lines, $code);
        if ($code === 0 && isset($lines[0]) && $lines[0] !== '' && is_executable($lines[0])) {
            return $lines[0];
        }

        if (PHP_OS_FAMILY === 'Windows') {
            @exec('where ' . escapeshellarg($command) . ' 2>nul', $lines, $code);
            if ($code === 0 && isset($lines[0]) && is_file($lines[0])) {
                return $lines[0];
            }
        }

        return null;
    }

    private static function commandExists(string $command): ?string
    {
        if (str_contains($command, '/') || str_contains($command, '\\')) {
            return is_executable($command) ? $command : null;
        }

        $which = self::resolveWhich($command);
        if ($which !== null) {
            return $which;
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

    private static function isExecEnabled(): bool
    {
        if (!function_exists('exec')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return !in_array('exec', $disabled, true);
    }

    private static function phpProcessUser(): string
    {
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $info = posix_getpwuid(posix_geteuid());

            return (string) ($info['name'] ?? '');
        }

        $run = self::runCommand('whoami 2>/dev/null', 'whoami');

        return $run['output'];
    }
}
