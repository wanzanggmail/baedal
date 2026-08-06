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
    public static function allStored(?int $orgId = null): array
    {
        $out = array_fill_keys(self::PLATFORMS, '');
        if (!self::tableExists()) {
            return $out;
        }

        // 표시용은 스코프 정확히 (대리점 행만 또는 전역 NULL 행만)
        $rows = ($orgId !== null && $orgId > 0)
            ? db_rows('SELECT platform, open_password FROM settlement_excel_config WHERE org_id = ?', [$orgId])
            : db_rows('SELECT platform, open_password FROM settlement_excel_config WHERE org_id IS NULL');
        foreach ($rows as $row) {
            $p = (string) ($row['platform'] ?? '');
            if (in_array($p, self::PLATFORMS, true)) {
                $out[$p] = (string) ($row['open_password'] ?? '');
            }
        }

        return $out;
    }

    /**
     * 본사·총판용 — 조회 권한 범위(Org::agencyScopeClause) 내 대리점 목록 + 각자 저장된 암호.
     *
     * @return list<array{id:int, name:string, code:string, parent_name:?string, passwords:array<string,string>}>
     */
    public static function listAgencyRows(): array
    {
        if (!db_table_exists('organizations')) {
            return [];
        }
        require_once __DIR__ . '/Org.php';

        [$where, $params] = Org::agencyScopeClause('o.id');
        $sql = "SELECT o.id, o.name, o.code, p.name AS parent_name
                  FROM organizations o
                  LEFT JOIN organizations p ON p.id = o.parent_id
                 WHERE o.level = 'agency'" . ($where !== '' ? " AND {$where}" : '') . '
                 ORDER BY p.name ASC, o.name ASC';

        $out = [];
        foreach (db_rows($sql, $params) as $r) {
            $id = (int) $r['id'];
            $out[] = [
                'id'          => $id,
                'name'        => (string) $r['name'],
                'code'        => (string) $r['code'],
                'parent_name' => $r['parent_name'] !== null ? (string) $r['parent_name'] : null,
                'passwords'   => self::allStored($id),
            ];
        }

        return $out;
    }

    /** @return array{length: int, configured: bool} */
    public static function storedPasswordMeta(string $platform, ?int $orgId = null): array
    {
        if (!self::tableExists()) {
            return ['length' => 0, 'configured' => false];
        }

        $row = ($orgId !== null && $orgId > 0)
            ? db_row('SELECT open_password FROM settlement_excel_config WHERE platform = ? AND org_id = ? LIMIT 1', [$platform, $orgId])
            : db_row('SELECT open_password FROM settlement_excel_config WHERE platform = ? AND org_id IS NULL LIMIT 1', [$platform]);
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
    public static function passwordsToTry(string $platform, ?string $uploadPassword = null, ?int $orgId = null): array
    {
        $list = [];

        if ($uploadPassword !== null && $uploadPassword !== '') {
            $list[] = self::normalizePassword($uploadPassword);
        }

        // 대리점 저장값 → 전역 저장값 순 (복호화 폴백)
        foreach (self::storedPasswordList($platform, $orgId) as $pw) {
            $list[] = $pw;
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

        if ($platform !== 'baemin') {
            foreach (self::storedPasswordList('baemin', $orgId) as $pw) {
                $list[] = $pw;
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

    /**
     * 플랫폼 미지정·자동 감지 시 모든 플랫폼 암호를 순서대로 시도
     *
     * @return list<string>
     */
    public static function allPasswordsToTry(?string $uploadPassword = null, ?int $orgId = null): array
    {
        $unique = [];
        foreach (self::PLATFORMS as $platform) {
            foreach (self::passwordsToTry($platform, $uploadPassword, $orgId) as $pw) {
                if ($pw !== '' && !in_array($pw, $unique, true)) {
                    $unique[] = $pw;
                }
            }
        }

        return $unique;
    }

    /**
     * 복호화 시도용 저장 비밀번호: 대리점(org) 행 → 전역(NULL) 행 순 (빈 값 제외).
     *
     * @return list<string>
     */
    private static function storedPasswordList(string $platform, ?int $orgId): array
    {
        if (!self::tableExists()) {
            return [];
        }
        $out = [];
        if ($orgId !== null && $orgId > 0) {
            $r = db_row('SELECT open_password FROM settlement_excel_config WHERE platform = ? AND org_id = ? LIMIT 1', [$platform, $orgId]);
            if ($r !== null) {
                $pw = self::normalizePassword((string) ($r['open_password'] ?? ''));
                if ($pw !== '') {
                    $out[] = $pw;
                }
            }
        }
        $r = db_row('SELECT open_password FROM settlement_excel_config WHERE platform = ? AND org_id IS NULL LIMIT 1', [$platform]);
        if ($r !== null) {
            $pw = self::normalizePassword((string) ($r['open_password'] ?? ''));
            if ($pw !== '') {
                $out[] = $pw;
            }
        }

        return $out;
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
    public static function save(array $passwords, ?int $orgId = null, ?int $adminId = null): array
    {
        if (!self::tableExists()) {
            throw new RuntimeException('settlement_excel_config 테이블이 없습니다. php migrate.php 를 실행하세요.');
        }

        $hasOrg   = $orgId !== null && $orgId > 0;
        $updatedBy = ($adminId !== null && $adminId > 0) ? $adminId : null;

        foreach (self::PLATFORMS as $platform) {
            $pw = self::normalizePassword((string) ($passwords[$platform] ?? ''));
            $exists = $hasOrg
                ? db_row('SELECT id FROM settlement_excel_config WHERE platform = ? AND org_id = ? LIMIT 1', [$platform, $orgId])
                : db_row('SELECT id FROM settlement_excel_config WHERE platform = ? AND org_id IS NULL LIMIT 1', [$platform]);
            if ($exists) {
                db_execute(
                    'UPDATE settlement_excel_config SET open_password = ?, updated_by = ? WHERE id = ?',
                    [$pw, $updatedBy, (int) $exists['id']]
                );
            } else {
                db_insert(
                    'INSERT INTO settlement_excel_config (platform, org_id, open_password, updated_by) VALUES (?, ?, ?, ?)',
                    [$platform, $hasOrg ? $orgId : null, $pw, $updatedBy]
                );
            }
        }

        return self::allStored($orgId);
    }
}
