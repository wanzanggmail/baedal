<?php

declare(strict_types=1);

/**
 * 정산 엑셀 파일 열기 암호 (DB + 환경 변수)
 */
final class SettlementExcelConfig
{
    /** @var list<string> */
    private const PLATFORMS = ['baemin', 'coupang', 'other'];

    public static function tableExists(): bool
    {
        return db_table_exists('settlement_excel_config');
    }

    /** @return list<string> */
    public static function platforms(): array
    {
        return self::PLATFORMS;
    }

    /**
     * 플랫폼별 저장 비밀번호 (관리 화면용, 빈 값 포함)
     *
     * @return array<string, string> platform => password
     */
    public static function allStored(): array
    {
        $out = array_fill_keys(self::PLATFORMS, '');
        if (!self::tableExists()) {
            return $out;
        }

        $rows = db_rows('SELECT platform, open_password FROM settlement_excel_config');
        foreach ($rows as $row) {
            $p = (string) ($row['platform'] ?? '');
            if (in_array($p, self::PLATFORMS, true)) {
                $out[$p] = (string) ($row['open_password'] ?? '');
            }
        }

        return $out;
    }

    /** @return array{length: int, configured: bool} */
    public static function storedPasswordMeta(string $platform): array
    {
        if (!self::tableExists()) {
            return ['length' => 0, 'configured' => false];
        }

        $row = db_row(
            'SELECT open_password FROM settlement_excel_config WHERE platform = ? LIMIT 1',
            [$platform]
        );
        if ($row === null) {
            return ['length' => 0, 'configured' => false];
        }

        $pw = self::normalizePassword((string) ($row['open_password'] ?? ''));

        return [
            'length'      => strlen($pw),
            'configured'  => $pw !== '',
        ];
    }

    public static function normalizePassword(string $password): string
    {
        $password = trim($password);
        if (str_starts_with($password, "\xEF\xBB\xBF")) {
            $password = substr($password, 3);
        }
        if (class_exists('Normalizer')) {
            $normalized = \Normalizer::normalize($password, \Normalizer::FORM_C);
            if (is_string($normalized)) {
                $password = $normalized;
            }
        }

        return trim($password, "\r\n\x00\t ");
    }

    /**
     * 업로드 시 시도할 비밀번호 목록 (중복 제거, 빈 값 제외)
     *
     * @return list<string>
     */
    public static function passwordsToTry(string $platform, ?string $uploadPassword = null): array
    {
        $list = [];

        if ($uploadPassword !== null && $uploadPassword !== '') {
            $list[] = self::normalizePassword($uploadPassword);
        }

        if (self::tableExists()) {
            $row = db_row(
                'SELECT open_password FROM settlement_excel_config WHERE platform = ? LIMIT 1',
                [$platform]
            );
            if ($row !== null) {
                $pw = self::normalizePassword((string) ($row['open_password'] ?? ''));
                if ($pw !== '') {
                    $list[] = $pw;
                }
            }
        }

        foreach (self::envValues('SETTLEMENT_EXCEL_PASSWORD_' . strtoupper($platform)) as $v) {
            $pw = self::normalizePassword($v);
            if ($pw !== '') {
                $list[] = $pw;
            }
        }

        foreach (self::envValues('SETTLEMENT_EXCEL_PASSWORD') as $v) {
            $pw = self::normalizePassword($v);
            if ($pw !== '') {
                $list[] = $pw;
            }
        }

        if ($platform !== 'baemin' && self::tableExists()) {
            $baemin = db_row(
                'SELECT open_password FROM settlement_excel_config WHERE platform = ? LIMIT 1',
                ['baemin']
            );
            if ($baemin !== null) {
                $pw = self::normalizePassword((string) ($baemin['open_password'] ?? ''));
                if ($pw !== '') {
                    $list[] = $pw;
                }
            }
        }

        $unique = [];
        foreach ($list as $pw) {
            if ($pw !== '' && !in_array($pw, $unique, true)) {
                $unique[] = $pw;
            }
        }

        return $unique;
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

    /**
     * @param array<string, string> $passwords platform => open_password
     * @return array<string, string>
     */
    public static function save(array $passwords, ?int $adminId = null): array
    {
        if (!self::tableExists()) {
            throw new RuntimeException('settlement_excel_config 테이블이 없습니다. migrate_settlement_excel_config.php 를 실행하세요.');
        }

        foreach (self::PLATFORMS as $platform) {
            $pw = self::normalizePassword((string) ($passwords[$platform] ?? ''));
            $exists = db_row(
                'SELECT platform FROM settlement_excel_config WHERE platform = ? LIMIT 1',
                [$platform]
            );
            if ($exists) {
                db_execute(
                    'UPDATE settlement_excel_config SET open_password = ?, updated_by = ? WHERE platform = ?',
                    [$pw, ($adminId !== null && $adminId > 0) ? $adminId : null, $platform]
                );
            } else {
                db_insert(
                    'INSERT INTO settlement_excel_config (platform, open_password, updated_by) VALUES (?, ?, ?)',
                    [$platform, $pw, ($adminId !== null && $adminId > 0) ? $adminId : null]
                );
            }
        }

        return self::allStored();
    }
}
