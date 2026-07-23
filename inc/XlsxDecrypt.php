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
        if (!is_file($filePath) || !is_readable($filePath)) {
            return 'unreadable';
        }

        $size = filesize($filePath);
        if ($size === false || $size < 4) {
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

        if (extension_loaded('zip')) {
            $zip = new ZipArchive();
            if ($zip->open($filePath) === true) {
                $ok = $zip->locateName('_rels/.rels') !== false
                    || $zip->locateName('xl/workbook.xml') !== false
                    || $zip->locateName('[Content_Types].xml') !== false;
                $zip->close();
                if ($ok) {
                    return 'ok';
                }
            }
        }

        try {
            $reader = IOFactory::createReader('Xlsx');
            if ($reader->canRead($filePath)) {
                return 'ok';
            }
        } catch (Throwable) {
            // fall through
        }

        try {
            $book = IOFactory::load($filePath);
            $book->disconnectWorksheets();
            unset($book);

            return 'ok';
        } catch (Throwable) {
            // fall through
        }

        if (!extension_loaded('zip')) {
            return 'zip_ext_missing';
        }

        return 'unreadable';
    }

    /** Python 복호화 성공 판정 — zip 확장 없어도 PK 헤더 xlsx면 성공 */
    private static function isDecryptedZipOutput(string $filePath): bool
    {
        $valid = self::validateDecryptedFile($filePath);

        return $valid === 'ok' || $valid === 'zip_ext_missing';
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
     * @return array<string, mixed>
     */
    public static function testDecrypt(string $filePath, array $passwords): array
    {
        $head = is_readable($filePath) ? @file_get_contents($filePath, false, null, 0, 8) : false;

        $result = [
            'file_size'     => is_file($filePath) ? filesize($filePath) : 0,
            'file_head_hex' => $head !== false ? bin2hex($head) : '',
            'is_encrypted'  => self::isEncrypted($filePath),
            'password_count'=> count($passwords),
            'attempts'      => [],
            'success'       => false,
            'diagnostics'   => self::diagnostics(),
        ];

        if ($passwords === [] || !self::isExecEnabled()) {
            return $result;
        }

        foreach ($passwords as $i => $password) {
            self::$lastAttempts = [];
            $out = self::decryptToTemp($filePath, $password);
            $valid = $out !== null ? self::validateDecryptedFile($out) : null;
            $attempt = [
                'index'        => $i + 1,
                'password_len' => strlen($password),
                'steps'        => self::$lastAttempts,
                'valid'        => $valid,
                'decrypt_ok'   => $out !== null && self::isDecryptedZipOutput($out),
            ];
            if ($out !== null && $valid === 'ok') {
                $result['success'] = true;
                $result['attempts'][] = $attempt;
                self::unlinkTemp($out);
                break;
            }
            if ($out !== null && $valid === 'zip_ext_missing') {
                $result['zip_extension_missing'] = true;
            }
            if ($out !== null) {
                self::unlinkTemp($out);
            }
            $result['attempts'][] = $attempt;
        }

        return $result;
    }

    /**
     * @param list<string> $passwords
     * @throws RuntimeException
     */
    public static function prepareForParsing(string $filePath, array $passwords = [], string $platform = 'baemin'): string
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

        $sawOleXls = false;
        $sawNotZip = false;
        $attempted = count($passwords);

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

        if (self::attemptsIndicateZipExtensionMissing()) {
            throw new RuntimeException(self::buildZipExtensionHelpMessage());
        }

        throw new RuntimeException(self::buildDecryptFailureMessage($attempted, $platform));
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

        $inputCopy = self::copyToTempReadable($inputPath);

        if (self::decryptWithPython($inputCopy, $outPath, $password)) {
            return $outPath;
        }

        // Python+msoffcrypto 가 있으면 CLI(버전 불일치 exit=0)는 건너뜀
        if (!self::hasPythonWithMsoffcrypto() && self::decryptWithMsoffcryptoCli($inputCopy, $outPath, $password)) {
            return $outPath;
        }

        self::unlinkTemp($outPath);

        return null;
    }

    private static function copyToTempReadable(string $inputPath): string
    {
        $base = tempnam(sys_get_temp_dir(), 'baedal_enc_');
        if ($base === false) {
            return $inputPath;
        }
        $copy = $base . '.xlsx';
        @unlink($base);
        if (!@copy($inputPath, $copy)) {
            @unlink($copy);

            return $inputPath;
        }
        @chmod($copy, 0600);
        self::$tempFiles[] = $copy;

        return $copy;
    }

    private static function writePasswordFile(string $password): ?string
    {
        $path = tempnam(sys_get_temp_dir(), 'baedal_pw_');
        if ($path === false) {
            return null;
        }
        if (file_put_contents($path, $password) === false) {
            @unlink($path);

            return null;
        }
        @chmod($path, 0600);
        self::$tempFiles[] = $path;

        return $path;
    }

    private static function decryptWithPython(string $input, string $output, string $password): bool
    {
        $script = ROOT_PATH . '/scripts/decrypt_xlsx.py';
        $wrapper = ROOT_PATH . '/scripts/run_decrypt.sh';

        if (!is_file($script)) {
            self::$lastAttempts[] = [
                'python' => '(script)',
                'code'   => 127,
                'output' => 'decrypt_xlsx.py 없음: ' . $script,
            ];

            return false;
        }

        $pwFile = self::writePasswordFile($password);
        if ($pwFile === null) {
            self::$lastAttempts[] = [
                'python' => '(password)',
                'code'   => 127,
                'output' => '암호 임시 파일 생성 실패: ' . sys_get_temp_dir(),
            ];

            return false;
        }

        $runners = self::pythonRunners();
        $withMso = [];
        foreach ($runners as $runner) {
            if ($runner['label'] === 'bash+run_decrypt.sh') {
                $withMso[] = $runner;
                continue;
            }
            $probe = self::probePython($runner['bin']);
            if (!empty($probe['msoffcrypto_ok'])) {
                $withMso[] = $runner;
            }
        }
        if ($withMso !== []) {
            $runners = $withMso;
        }

        if ($runners === []) {
            self::$lastAttempts[] = [
                'python' => '(none)',
                'code'   => 127,
                'output' => 'Python 실행 파일을 찾지 못했습니다. SETTLEMENT_PYTHON_BIN=/usr/bin/python3 설정',
            ];
        }

        try {
            foreach ($runners as $runner) {
                $argv = [
                    $runner['bin'],
                    ...$runner['args'],
                    $input,
                    $output,
                    '--password-file',
                    $pwFile,
                    '--verbose',
                ];
                $run = self::runProcess($argv, $runner['label']);
                $run = self::annotateAttempt($run, $output);
                self::$lastAttempts[] = $run;

                if ($run['code'] === 0 && self::isDecryptedZipOutput($output)) {
                    return true;
                }

                // 숫자-only 암호: argv 직접 전달도 시도
                if (preg_match('/^\d+$/', $password)) {
                    $argvDirect = [
                        $runner['bin'],
                        ...$runner['args'],
                        $input,
                        $output,
                        $password,
                        '--verbose',
                    ];
                    $runDirect = self::runProcess($argvDirect, $runner['label'] . ' (direct)');
                    $runDirect = self::annotateAttempt($runDirect, $output);
                    self::$lastAttempts[] = $runDirect;
                    if ($runDirect['code'] === 0 && self::isDecryptedZipOutput($output)) {
                        return true;
                    }
                }
            }
        } finally {
            self::unlinkTemp($pwFile);
        }

        return false;
    }

    /**
     * @return list<array{bin: string, args: list<string>, label: string}>
     */
    private static function pythonRunners(): array
    {
        $runners = [];
        $script  = ROOT_PATH . '/scripts/decrypt_xlsx.py';
        $scored  = [];

        foreach (self::pythonBinaries() as $py) {
            if (!self::isUsablePythonPath($py)) {
                continue;
            }
            $probe = self::probePython($py);
            if ($probe['version'] === '' && $probe['msoffcrypto_err'] !== '') {
                continue;
            }
            $scored[] = [
                'bin'   => $py,
                'args'  => [$script],
                'label' => $py,
                'score' => !empty($probe['msoffcrypto_ok']) ? 10 : 0,
            ];
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        foreach ($scored as $row) {
            unset($row['score']);
            $runners[] = $row;
        }

        $wrapper = ROOT_PATH . '/scripts/run_decrypt.sh';
        if (is_file($wrapper) && PHP_OS_FAMILY !== 'Windows') {
            array_unshift($runners, ['bin' => '/bin/bash', 'args' => [$wrapper], 'label' => 'bash+run_decrypt.sh']);
        }

        return $runners;
    }

    private static function hasPythonWithMsoffcrypto(): bool
    {
        foreach (self::pythonBinaries() as $py) {
            if (!self::isUsablePythonPath($py)) {
                continue;
            }
            $probe = self::probePython($py);
            if (!empty($probe['msoffcrypto_ok'])) {
                return true;
            }
        }

        return false;
    }

    private static function isUsablePythonPath(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        if (str_contains($path, DIRECTORY_SEPARATOR) || str_contains($path, '/')) {
            return is_file($path);
        }

        return self::commandExists($path) !== null;
    }

    /** @param array{python: string, code: int, output: string} $run */
    private static function annotateAttempt(array $run, string $outputPath): array
    {
        $extra = self::fileProbe($outputPath);
        if (is_file($outputPath)) {
            $valid = self::validateDecryptedFile($outputPath);
            if ($valid !== 'ok') {
                $extra .= ' validate=' . $valid;
            }
            if (self::isEncrypted($outputPath)) {
                $extra .= ' still_encrypted=1';
            }
        }
        $run['output'] = trim($run['output'] . ' | ' . $extra);

        return $run;
    }

    private static function fileProbe(string $path): string
    {
        if (!is_file($path)) {
            return 'output=missing';
        }

        $size = filesize($path);
        $head = @file_get_contents($path, false, null, 0, 4);
        $hex  = $head !== false ? bin2hex($head) : '????';

        return 'output_size=' . (string) $size . ' head=' . $hex;
    }

    /**
     * @param list<string> $argv
     * @return array{python: string, code: int, output: string}
     */
    private static function runProcess(array $argv, string $label): array
    {
        if (!function_exists('proc_open')) {
            return self::runCommandFallback($argv, $label);
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = @proc_open($argv, $descriptors, $pipes, null, self::processEnv());
        if (!is_resource($proc)) {
            return self::runCommandFallback($argv, $label);
        }

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        $output = trim($stderr !== '' ? $stderr : $stdout);

        return [
            'python' => $label,
            'code'   => $code,
            'output' => $output,
        ];
    }

    /**
     * @param list<string> $argv
     * @return array{python: string, code: int, output: string}
     */
    private static function runCommandFallback(array $argv, string $label): array
    {
        $parts = [];
        foreach ($argv as $arg) {
            $parts[] = escapeshellarg($arg);
        }

        return self::runCommand(implode(' ', $parts) . ' 2>&1', $label);
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

    /** @return array<string, string> */
    private static function processEnv(): array
    {
        $home = sys_get_temp_dir();

        return [
            'PATH'   => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'HOME'   => $home,
            'LANG'   => 'C.UTF-8',
            'LC_ALL' => 'C.UTF-8',
        ];
    }

    private static function buildDecryptFailureMessage(int $attempted, string $platform = 'baemin'): string
    {
        $meta = SettlementExcelConfig::storedPasswordMeta($platform);
        $diag = self::diagnostics();

        $lines = [
            '등록된 비밀번호로 파일을 열 수 없습니다.',
            "시도한 암호 후보: {$attempted}개 · DB 저장 암호 길이: {$meta['length']}자",
            'DB·암호 길이는 정상입니다. Python 실행 또는 msoffcrypto 복호화 단계에서 실패한 상태입니다.',
        ];

        $lines[] = 'exec: ' . ($diag['exec_enabled'] ? '사용 가능' : '비활성(disable_functions)');
        $lines[] = '스크립트: ' . ($diag['script_exists'] ? '있음' : '없음') . ' · PHP 사용자: ' . ($diag['php_user'] ?: '?');

        foreach ($diag['python_binaries'] as $p) {
            $lines[] = sprintf(
                '  %s → msoffcrypto %s',
                (string) ($p['path'] ?? '?'),
                !empty($p['msoffcrypto_ok']) ? 'OK' : '없음'
            );
        }

        if ($diag['python_binaries'] === []) {
            $lines[] = '  (웹에서 Python을 하나도 찾지 못함 — SETTLEMENT_PYTHON_BIN=/usr/bin/python3 설정 후 php-fpm reload)';
        }

        if (self::$lastAttempts === []) {
            $lines[] = '복호화 명령 로그 없음: 스크립트 경로·SELinux(httpd→python 실행 차단) 확인';
            $lines[] = 'CentOS/RHEL: ausearch -m avc -ts recent | grep httpd';
        } else {
            foreach (self::$lastAttempts as $a) {
                $lines[] = sprintf(
                    '[%s] exit=%d %s',
                    $a['python'],
                    $a['code'],
                    mb_substr($a['output'] !== '' ? $a['output'] : '(출력 없음)', 0, 400)
                );
            }
        }

        $lines[] = '같은 파일로 POST: ' . rtrim(ADMIN_BASE, '/') . '/api/settlement_excel_test.php';
        $lines[] = '임시 우회: Excel에서 암호 제거 후 xlsx 저장 → 업로드';

        return implode("\n", $lines);
    }

    private static function attemptsIndicateZipExtensionMissing(): bool
    {
        if (extension_loaded('zip') || self::$lastAttempts === []) {
            return false;
        }

        foreach (self::$lastAttempts as $a) {
            if ($a['code'] !== 0) {
                return false;
            }
            if (!str_contains($a['output'], '504b0304') && !str_contains($a['output'], 'validate=zip_ext_missing')) {
                return false;
            }
        }

        return true;
    }

    private static function buildZipExtensionHelpMessage(): string
    {
        $ini = php_ini_loaded_file() ?: 'php.ini';
        $ver = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

        return implode("\n", [
            '엑셀 암호 해제는 성공했지만, PHP zip 확장이 없어 xlsx를 읽을 수 없습니다.',
            '(복호화 로그: exit=0, output_head=504b0304 = 정상 xlsx)',
            '',
            'Amazon Linux / RHEL 예:',
            "  sudo dnf install -y php-zip || sudo yum install -y php-zip",
            "  # 버전별: sudo dnf install -y php{$ver}-zip",
            '  sudo systemctl restart httpd',
            '',
            "php.ini ({$ini}) 에 extension=zip 이 활성화돼 있는지 확인하세요.",
            '설치 후: php -m | grep zip',
        ]);
    }

    private static function decryptWithMsoffcryptoCli(string $input, string $output, string $password): bool
    {
        foreach (['msoffcrypto-tool', 'msoffcrypto'] as $bin) {
            $which = self::commandExists($bin);
            if ($which === null) {
                continue;
            }

            $run = self::runProcess([
                $which,
                '-p',
                $password,
                $input,
                $output,
            ], $which);
            $run = self::annotateAttempt($run, $output);
            self::$lastAttempts[] = $run;
            if ($run['code'] === 0 && !self::isEncrypted($output) && self::isDecryptedZipOutput($output)) {
                return true;
            }

            $runAlt = self::runProcess([
                $which,
                $input,
                $output,
                '-p',
                $password,
            ], $which . ' (alt)');
            $runAlt = self::annotateAttempt($runAlt, $output);
            self::$lastAttempts[] = $runAlt;
            if ($runAlt['code'] === 0 && !self::isEncrypted($output) && self::isDecryptedZipOutput($output)) {
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
            if (self::isUsablePythonPath($v) && !in_array($v, $list, true)) {
                $list[] = $v;
            }
        }

        if (PHP_OS_FAMILY === 'Windows') {
            foreach (self::discoverWindowsPython() as $path) {
                if (!in_array($path, $list, true)) {
                    $list[] = $path;
                }
            }

            return $list;
        }

        foreach (['/usr/bin/python3', '/bin/python3', '/usr/local/bin/python3'] as $path) {
            if (self::isUsablePythonPath($path) && !in_array($path, $list, true)) {
                $list[] = $path;
            }
        }

        foreach (['python3', 'python'] as $bin) {
            $path = self::commandExists($bin);
            if ($path !== null && self::isUsablePythonPath($path) && !in_array($path, $list, true)) {
                $list[] = $path;
            }
        }

        return $list;
    }

    /** @return list<string> */
    private static function discoverWindowsPython(): array
    {
        $found = [];
        foreach (['python', 'python3', 'py'] as $cmd) {
            $path = self::resolveWhich($cmd);
            if ($path !== null && !in_array($path, $found, true)) {
                $found[] = $path;
            }
        }

        $roots = [
            getenv('LOCALAPPDATA') . '\\Programs\\Python',
            getenv('LOCALAPPDATA') . '\\Python',
            'C:\\Python312',
            'C:\\Python311',
            'C:\\Python310',
        ];
        foreach ($roots as $root) {
            if ($root === '' || !is_dir($root)) {
                continue;
            }
            $glob = glob($root . '\\python.exe') ?: [];
            foreach ($glob as $exe) {
                if (!in_array($exe, $found, true)) {
                    $found[] = $exe;
                }
            }
            $glob = glob($root . '\\Python*\\python.exe') ?: [];
            foreach ($glob as $exe) {
                if (!in_array($exe, $found, true)) {
                    $found[] = $exe;
                }
            }
            // python.org / Windows Store: ...\Python\pythoncore-3.xx-64\python.exe
            $glob = glob($root . '\\pythoncore-*\\python.exe') ?: [];
            foreach ($glob as $exe) {
                if (!in_array($exe, $found, true)) {
                    $found[] = $exe;
                }
            }
            $bin = $root . '\\bin\\python.exe';
            if (is_file($bin) && !in_array($bin, $found, true)) {
                $found[] = $bin;
            }
        }

        // Apache가 SYSTEM으로 돌면 LOCALAPPDATA가 시스템 프로필이라, 사용자 설치본을 직접 탐색
        foreach (glob('C:\\Users\\*\\AppData\\Local\\Python\\pythoncore-*\\python.exe') ?: [] as $exe) {
            if (!in_array($exe, $found, true)) {
                $found[] = $exe;
            }
        }
        foreach (glob('C:\\Users\\*\\AppData\\Local\\Python\\bin\\python.exe') ?: [] as $exe) {
            if (!in_array($exe, $found, true)) {
                $found[] = $exe;
            }
        }

        return $found;
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
