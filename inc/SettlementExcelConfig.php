<?php

declare(strict_types=1);

require_once __DIR__ . '/Crypto.php';

/**
 * 정산 엑셀 파일 열기 암호 (DB + 환경 변수)
 */
final class SettlementExcelConfig
{
    /** @var list<string> */
    private const PLATFORMS = ['baemin', 'coupang', 'other'];

    /**
     * 정산서 종류 — **열기 암호가 서로 다르다.**
     * (2026-08-22 배민 실파일 확인: 주간 `3060454741` / 일일 `siook00`)
     * 그래서 (플랫폼 × 종류)마다 따로 저장한다.
     *
     * @var list<string>
     */
    private const KINDS = ['daily', 'weekly'];

    public static function tableExists(): bool
    {
        return db_table_exists('settlement_excel_config');
    }

    /** @return list<string> */
    public static function platforms(): array
    {
        return self::PLATFORMS;
    }

    /** @return list<string> */
    public static function kinds(): array
    {
        return self::KINDS;
    }

    /** 화면 표기용 */
    public static function kindLabel(string $kind): string
    {
        return $kind === 'weekly' ? '주간' : '일일';
    }

    /** 알 수 없는 값은 기존 동작(일일)으로 떨어뜨린다. */
    public static function normalizeKind(?string $kind): string
    {
        return $kind === 'weekly' ? 'weekly' : 'daily';
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
            if (in_array($p, self::PLATFORMS, true) && (string) ($row['open_password'] ?? '') !== '') {
                $out[$p] = Crypto::decryptSafe((string) $row['open_password']);
            }
        }

        return $out;
    }

    /**
     * 종류별 저장 암호 — 관리 화면용. `platform|kind` 키로 돌려준다.
     *
     * @return array<string, string> "baemin|daily" => password
     */
    public static function allStoredByKind(?int $orgId = null): array
    {
        $out = [];
        foreach (self::PLATFORMS as $p) {
            foreach (self::KINDS as $k) {
                $out[$p . '|' . $k] = '';
            }
        }
        if (!self::tableExists()) {
            return $out;
        }
        $hasKind = in_array('kind', array_column(db_rows('SHOW COLUMNS FROM settlement_excel_config'), 'Field'), true);
        if (!$hasKind) {
            // 마이그레이션 전 DB — 기존 값은 전부 일일로 본다.
            foreach (self::allStored($orgId) as $p => $pw) {
                $out[$p . '|daily'] = $pw;
            }

            return $out;
        }

        $rows = ($orgId !== null && $orgId > 0)
            ? db_rows('SELECT platform, kind, open_password FROM settlement_excel_config WHERE org_id = ?', [$orgId])
            : db_rows('SELECT platform, kind, open_password FROM settlement_excel_config WHERE org_id IS NULL');
        foreach ($rows as $row) {
            $p = (string) ($row['platform'] ?? '');
            $k = self::normalizeKind((string) ($row['kind'] ?? 'daily'));
            if (in_array($p, self::PLATFORMS, true) && (string) ($row['open_password'] ?? '') !== '') {
                $out[$p . '|' . $k] = Crypto::decryptSafe((string) $row['open_password']);
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
                // "platform|kind" 키 — 화면이 일일/주간 칸을 따로 그린다.
                'passwords'   => self::allStoredByKind($id),
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

        $pw = self::normalizePassword(Crypto::decryptSafe((string) ($row['open_password'] ?? '')));

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
    public static function passwordsToTry(string $platform, ?string $uploadPassword = null, ?int $orgId = null, ?string $kind = null): array
    {
        $list = [];

        if ($uploadPassword !== null && $uploadPassword !== '') {
            $list[] = self::normalizePassword($uploadPassword);
        }

        // 대리점 저장값 → 전역 저장값 순 (복호화 폴백). $kind가 오면 그 종류를 먼저 시도한다.
        foreach (self::storedPasswordList($platform, $orgId, $kind) as $pw) {
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
    private static function storedPasswordList(string $platform, ?int $orgId, ?string $kind = null): array
    {
        if (!self::tableExists()) {
            return [];
        }

        // kind 컬럼이 아직 없는 DB(마이그레이션 전)에서도 동작해야 한다.
        $hasKind = in_array('kind', array_column(db_rows('SHOW COLUMNS FROM settlement_excel_config'), 'Field'), true);

        // 요청한 종류를 먼저, 그 다음 나머지 종류. 복호화는 순서대로 시도만 하면 되므로
        // 한쪽만 저장해둔 경우에도 다른 쪽 암호로 열릴 수 있다(사용자 실수에 관대하게).
        $kindOrder = $hasKind
            ? ($kind === null ? self::KINDS : array_merge([self::normalizeKind($kind)], array_diff(self::KINDS, [self::normalizeKind($kind)])))
            : [null];

        $out = [];
        $push = static function (?array $r) use (&$out): void {
            if ($r === null) {
                return;
            }
            $pw = self::normalizePassword(Crypto::decryptSafe((string) ($r['open_password'] ?? '')));
            if ($pw !== '' && !in_array($pw, $out, true)) {
                $out[] = $pw;
            }
        };

        // 대리점 행 → 전역 행 순
        foreach ([true, false] as $orgScoped) {
            if ($orgScoped && ($orgId === null || $orgId <= 0)) {
                continue;
            }
            foreach ($kindOrder as $k) {
                $where  = 'platform = ? AND ' . ($orgScoped ? 'org_id = ?' : 'org_id IS NULL');
                $params = $orgScoped ? [$platform, $orgId] : [$platform];
                if ($k !== null) {
                    $where   .= ' AND kind = ?';
                    $params[] = $k;
                }
                // 중복 행이 남아 있어도 암호가 있는 행을 우선 집는다(정렬 없이 LIMIT 1이면 빈 행을 집을 수 있다).
                $push(db_row(
                    "SELECT open_password FROM settlement_excel_config WHERE {$where}
                      ORDER BY (open_password <> '') DESC, id ASC LIMIT 1",
                    $params
                ));
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

        $hasOrg    = $orgId !== null && $orgId > 0;
        $updatedBy = ($adminId !== null && $adminId > 0) ? $adminId : null;
        $hasKind   = in_array('kind', array_column(db_rows('SHOW COLUMNS FROM settlement_excel_config'), 'Field'), true);

        foreach (self::PLATFORMS as $platform) {
            foreach (self::KINDS as $kind) {
                // 키는 "platform|kind"(신규)를 우선하고, 없으면 "platform"(구 형식)도 받는다.
                $key = $platform . '|' . $kind;
                if (array_key_exists($key, $passwords)) {
                    $raw = (string) $passwords[$key];
                } elseif ($kind === 'daily' && array_key_exists($platform, $passwords)) {
                    $raw = (string) $passwords[$platform];
                } else {
                    continue; // 안 보낸 항목은 건드리지 않는다(빈 값으로 덮어쓰지 않음)
                }
                $pw = self::normalizePassword($raw);

                $where  = 'platform = ? AND ' . ($hasOrg ? 'org_id = ?' : 'org_id IS NULL');
                $params = $hasOrg ? [$platform, $orgId] : [$platform];
                if ($hasKind) {
                    $where   .= ' AND kind = ?';
                    $params[] = $kind;
                }
                $exists = db_row(
                    "SELECT id FROM settlement_excel_config WHERE {$where} ORDER BY id ASC LIMIT 1",
                    $params
                );

                if ($exists) {
                    db_execute(
                        'UPDATE settlement_excel_config SET open_password = ?, updated_by = ? WHERE id = ?',
                        [Crypto::encrypt($pw), $updatedBy, (int) $exists['id']]
                    );
                } elseif ($hasKind) {
                    db_insert(
                        'INSERT INTO settlement_excel_config (platform, kind, org_id, open_password, updated_by) VALUES (?, ?, ?, ?, ?)',
                        [$platform, $kind, $hasOrg ? $orgId : null, Crypto::encrypt($pw), $updatedBy]
                    );
                } else {
                    db_insert(
                        'INSERT INTO settlement_excel_config (platform, org_id, open_password, updated_by) VALUES (?, ?, ?, ?)',
                        [$platform, $hasOrg ? $orgId : null, Crypto::encrypt($pw), $updatedBy]
                    );
                }

                if (!$hasKind) {
                    break; // 구 스키마는 종류 구분이 없다
                }
            }
        }

        return self::allStored($orgId);
    }
}
