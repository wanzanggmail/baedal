<?php

declare(strict_types=1);

require_once INC_PATH . '/Org.php';

/**
 * 조직(총판/대리점) 관리 — 생성 시 로그인 계정을 함께 발급(통합).
 *
 * 권한: 본사(admin)는 총판·대리점 전체, 총판(distributor)은 자기 하위 대리점만.
 * 스코프 계산은 Org 클래스에 위임.
 */
final class Organization
{
    /** 조직 계정에 부여 가능한 기능 역할 (super는 플랫폼 루트 전용이라 제외). manager=자기 조직 내 전체 화면 조회·쓰기(시스템관리 제외) */
    public const ACCOUNT_ROLES = ['operation', 'settlement', 'admin', 'manager'];

    /** @return array<string,string> */
    public static function accountRoleLabels(): array
    {
        return [
            'operation'  => '운영',
            'settlement' => '정산',
            'admin'      => '조회 전용',
            'manager'    => '총괄 관리자',
        ];
    }

    /**
     * 현재 계정이 관리할 수 있는 조직 id 집합.
     *  - 본사: null (admin 레벨 제외 전체)
     *  - 총판: 자기 하위 조직(자신 제외)
     *  - 그 외: [] (관리 불가)
     *
     * @return list<int>|null
     */
    public static function manageableOrgIds(): ?array
    {
        $org = Org::current();
        if ($org === null) {
            return admin_has_role('super') ? null : [];
        }
        $level = (string) $org['level'];
        if ($level === Org::LEVEL_ADMIN) {
            return null; // 전체(admin 레벨은 SQL에서 제외)
        }
        if ($level === Org::LEVEL_DISTRIBUTOR) {
            return array_values(array_filter(
                Org::subtreeOrgIds((int) $org['id']),
                static fn (int $id): bool => $id !== (int) $org['id']
            ));
        }

        return [];
    }

    /**
     * 관리 가능한 조직 목록 (총판·대리점). 계정 수·라이더 수 포함.
     *
     * @return list<array<string,mixed>>
     */
    public static function listManageable(): array
    {
        $ids = self::manageableOrgIds();

        $where  = ["o.level <> 'admin'"];
        $params = [];
        if ($ids === null) {
            // 전체
        } elseif ($ids === []) {
            $where[] = '1=0';
        } else {
            $where[] = 'o.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $params  = array_values($ids);
        }
        $whereStr = implode(' AND ', $where);

        $rows = db_rows(
            "SELECT o.id, o.parent_id, o.level, o.code, o.name,
                    o.contact_name, o.contact_phone, o.memo, o.is_active, o.created_at,
                    p.name AS parent_name,
                    (SELECT COUNT(*) FROM admins  a WHERE a.org_id    = o.id) AS account_count,
                    (SELECT COUNT(*) FROM admins  a4 WHERE a4.org_id = o.id AND a4.is_active = 1) AS active_account_count,
                    (SELECT COUNT(*) FROM riders  r WHERE r.agency_id = o.id) AS rider_count,
                    (SELECT COUNT(*) FROM riders  r2 WHERE r2.agency_id = o.id AND r2.status = 'active') AS active_rider_count,
                    (SELECT a2.login_id FROM admins a2 WHERE a2.org_id = o.id ORDER BY a2.id ASC LIMIT 1) AS primary_login,
                    (SELECT a3.role FROM admins a3 WHERE a3.org_id = o.id ORDER BY a3.id ASC LIMIT 1) AS primary_role
               FROM organizations o
               LEFT JOIN organizations p ON p.id = o.parent_id
              WHERE {$whereStr}
              ORDER BY o.level ASC, o.id ASC",
            $params
        );

        return array_map([self::class, 'mapRow'], $rows);
    }

    /** 총판 선택지 (대리점 상위 지정용) — 관리 범위 내 distributor */
    /** @return list<array{id:int,name:string,code:string}> */
    public static function distributorOptions(): array
    {
        $ids = self::manageableOrgIds();
        $where  = ["level = 'distributor'"];
        $params = [];
        if ($ids === null) {
            // 전체 distributor
        } elseif ($ids === []) {
            return [];
        } else {
            $where[] = 'id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $params  = array_values($ids);
        }
        $rows = db_rows(
            'SELECT id, name, code FROM organizations WHERE ' . implode(' AND ', $where) . ' ORDER BY name ASC',
            $params
        );

        return array_map(
            static fn (array $r): array => [
                'id'   => (int) $r['id'],
                'name' => (string) $r['name'],
                'code' => (string) $r['code'],
            ],
            $rows
        );
    }

    /**
     * 현재 계정이 라이더를 배정할 수 있는 대리점 선택지 (스코프 내 활성 agency).
     *
     * @return list<array{id:int,name:string,code:string}>
     */
    public static function agencyOptions(): array
    {
        $ids = Org::scopeAgencyIds();
        $where  = ["level = 'agency'", 'is_active = 1'];
        $params = [];
        if ($ids === null) {
            // 전체
        } elseif ($ids === []) {
            return [];
        } else {
            $where[] = 'id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $params  = array_values($ids);
        }
        $rows = db_rows(
            'SELECT id, name, code FROM organizations WHERE ' . implode(' AND ', $where) . ' ORDER BY name ASC',
            $params
        );

        return array_map(
            static fn (array $r): array => [
                'id'   => (int) $r['id'],
                'name' => (string) $r['name'],
                'code' => (string) $r['code'],
            ],
            $rows
        );
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        $row = db_row(
            "SELECT o.id, o.parent_id, o.level, o.code, o.name,
                    o.contact_name, o.contact_phone, o.memo, o.is_active, o.created_at,
                    p.name AS parent_name,
                    (SELECT COUNT(*) FROM admins  a WHERE a.org_id    = o.id) AS account_count,
                    (SELECT COUNT(*) FROM admins  a4 WHERE a4.org_id = o.id AND a4.is_active = 1) AS active_account_count,
                    (SELECT COUNT(*) FROM riders  r WHERE r.agency_id = o.id) AS rider_count,
                    (SELECT COUNT(*) FROM riders  r2 WHERE r2.agency_id = o.id AND r2.status = 'active') AS active_rider_count,
                    (SELECT a2.login_id FROM admins a2 WHERE a2.org_id = o.id ORDER BY a2.id ASC LIMIT 1) AS primary_login,
                    (SELECT a3.role FROM admins a3 WHERE a3.org_id = o.id ORDER BY a3.id ASC LIMIT 1) AS primary_role
               FROM organizations o
               LEFT JOIN organizations p ON p.id = o.parent_id
              WHERE o.id = ? LIMIT 1",
            [$id]
        );

        return $row !== null ? self::mapRow($row) : null;
    }

    /**
     * 조직 생성 + 로그인 계정 발급 (트랜잭션).
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function create(array $data): array
    {
        $creatorLevel = admin_org_level();
        $creatorOrgId = admin_org_id();
        // 2026-07 재설계: 조직 생성은 본사(admin 레벨)만. 총판의 생성 권한은 회수됨.
        if ($creatorLevel !== Org::LEVEL_ADMIN) {
            throw new InvalidArgumentException('조직 생성은 본사만 가능합니다.');
        }

        $level = trim((string) ($data['level'] ?? ''));

        // 레벨·상위 결정 (본사가 총판 또는 대리점 생성)
        if ($level === Org::LEVEL_DISTRIBUTOR) {
            $parentId = $creatorOrgId;         // 본사 루트 하위
        } elseif ($level === Org::LEVEL_AGENCY) {
            $parentId = (int) ($data['parent_id'] ?? 0);
            $parent   = Org::find($parentId);
            if ($parent === null || (string) $parent['level'] !== Org::LEVEL_DISTRIBUTOR) {
                throw new InvalidArgumentException('대리점의 상위 총판을 선택하세요.');
            }
        } else {
            throw new InvalidArgumentException('생성할 조직 유형(총판/대리점)을 선택하세요.');
        }

        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        $name = trim((string) ($data['name'] ?? ''));
        // 코드 미입력 시 자동 생성 (레벨별 접두 + 순번, 중복 회피)
        if ($code === '') {
            $code = self::suggestCode($level);
        }
        self::validateCode($code);
        if ($name === '') {
            throw new InvalidArgumentException('조직 이름을 입력하세요.');
        }
        if (db_row('SELECT id FROM organizations WHERE code = ? LIMIT 1', [$code]) !== null) {
            throw new InvalidArgumentException('이미 사용 중인 조직 코드입니다.');
        }

        // 계정 정보
        $loginId  = trim((string) ($data['login_id'] ?? ''));
        $accName  = trim((string) ($data['account_name'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $role     = trim((string) ($data['role'] ?? 'operation'));
        self::validateLoginId($loginId);
        if ($accName === '') {
            $accName = $name . ' 담당자';
        }
        if (!in_array($role, self::ACCOUNT_ROLES, true)) {
            throw new InvalidArgumentException('계정 역할을 선택하세요.');
        }
        if (strlen($password) < 8) {
            throw new InvalidArgumentException('계정 비밀번호는 8자 이상이어야 합니다.');
        }
        if (db_row('SELECT id FROM admins WHERE login_id = ? LIMIT 1', [$loginId]) !== null) {
            throw new InvalidArgumentException('이미 사용 중인 로그인 ID입니다.');
        }

        $contactName  = trim((string) ($data['contact_name'] ?? ''));
        $contactPhone = trim((string) ($data['contact_phone'] ?? ''));

        $newId = db_transaction(static function () use (
            $parentId, $level, $code, $name, $contactName, $contactPhone,
            $loginId, $password, $accName, $role
        ): int {
            $orgId = db_insert(
                'INSERT INTO organizations (parent_id, level, code, name, contact_name, contact_phone)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$parentId, $level, $code, $name, $contactName, $contactPhone]
            );

            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            db_insert(
                'INSERT INTO admins (login_id, password_hash, name, role, org_id, is_active)
                 VALUES (?, ?, ?, ?, ?, 1)',
                [$loginId, $hash, $accName, $role, $orgId]
            );

            // 신규 조직 기본값 시드 — 플랫폼 수수료 요율 1%(모든 조직), 대리점은 지갑·정산수수료 기본
            // 1.00 은 자리값이다(2026-08-08 갑 확정): 분배비율은 계약마다 달라 조직 등록 시
            // 매번 직접 입력하므로 초기값 자체는 의미가 없다. PgFeeConfig::DEFAULT_PCT 와 같은 값.
            if (db_table_exists('org_fee_config')) {
                db_execute('INSERT IGNORE INTO org_fee_config (org_id, pg_service_fee_pct) VALUES (?, 1.00)', [$orgId]);
            }
            if ($level === Org::LEVEL_AGENCY) {
                if (db_table_exists('agency_wallets')) {
                    db_execute('INSERT IGNORE INTO agency_wallets (agency_id, balance, withholding_reserve) VALUES (?, 0, 0)', [$orgId]);
                }
                // 정산수수료 기본값(기준일수 7일·미만 80원·이상 40원)을 대리점 전용 행으로 세팅
                if (db_table_exists('withdrawal_config')) {
                    db_execute(
                        'INSERT IGNORE INTO withdrawal_config (org_id, reserve_amount, fee_day_threshold, fee_per_tx_short, fee_per_tx_long)
                         VALUES (?, 50000, 7, 80, 40)',
                        [$orgId]
                    );
                }
            }

            return $orgId;
        });

        Org::clearCache();
        $row = self::find($newId);
        if ($row === null) {
            throw new RuntimeException('생성된 조직을 불러올 수 없습니다.');
        }

        return $row;
    }

    /**
     * 조직 기본 정보 수정 (이름·연락처·메모). 레벨·상위·코드는 불변. 계정은 addAccount/updateAccount로.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function update(int $id, array $data): array
    {
        self::assertManageable($id);

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('조직 이름을 입력하세요.');
        }
        $contactName  = trim((string) ($data['contact_name'] ?? ''));
        $contactPhone = trim((string) ($data['contact_phone'] ?? ''));
        $memo         = mb_substr(trim((string) ($data['memo'] ?? '')), 0, 500);

        db_execute(
            'UPDATE organizations SET name = ?, contact_name = ?, contact_phone = ?, memo = ?, updated_at = NOW() WHERE id = ?',
            [$name, $contactName, $contactPhone, $memo, $id]
        );

        Org::clearCache();
        $row = self::find($id);
        if ($row === null) {
            throw new RuntimeException('수정된 조직을 불러올 수 없습니다.');
        }

        return $row;
    }

    /** @return array<string,mixed> */
    public static function setActive(int $id, bool $active): array
    {
        self::assertManageable($id);

        db_execute(
            'UPDATE organizations SET is_active = ?, updated_at = NOW() WHERE id = ?',
            [$active ? 1 : 0, $id]
        );
        // 조직 비활성화 시 소속 계정도 함께 비활성화(로그인 차단)
        db_execute('UPDATE admins SET is_active = ?, updated_at = NOW() WHERE org_id = ?', [$active ? 1 : 0, $id]);

        Org::clearCache();
        $row = self::find($id);
        if ($row === null) {
            throw new RuntimeException('상태 변경 후 조직을 불러올 수 없습니다.');
        }

        return $row;
    }

    /**
     * 조직 소속 계정 1건 반환 (관리 화면용). @return array<string,mixed>
     */
    private static function accountRow(int $orgId, int $accountId): array
    {
        $a = db_row(
            'SELECT id, login_id, name, email, role, is_active, last_login_at FROM admins WHERE id = ? AND org_id = ? LIMIT 1',
            [$accountId, $orgId]
        );
        if ($a === null) {
            throw new InvalidArgumentException('이 조직 소속 계정이 아닙니다.');
        }
        $primaryId  = self::primaryAccountId($orgId);
        $roleLabels = self::accountRoleLabels();
        $role       = (string) $a['role'];

        return [
            'id'            => (int) $a['id'],
            'login_id'      => (string) $a['login_id'],
            'name'          => (string) $a['name'],
            'email'         => (string) ($a['email'] ?? ''),
            'role'          => $role,
            'role_label'    => $roleLabels[$role] ?? $role,
            'active'        => (int) $a['is_active'] === 1,
            'is_primary'    => (int) $a['id'] === $primaryId,
            'last_login_at' => $a['last_login_at'] ? date('Y-m-d H:i', strtotime((string) $a['last_login_at'])) : '',
        ];
    }

    private static function primaryAccountId(int $orgId): int
    {
        $row = db_row('SELECT id FROM admins WHERE org_id = ? ORDER BY id ASC LIMIT 1', [$orgId]);

        return $row !== null ? (int) $row['id'] : 0;
    }

    /**
     * 조직에 역할별 계정 추가 (본사가 특정 총판·대리점에 서브계정 발급).
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function addAccount(int $orgId, array $data): array
    {
        self::assertManageable($orgId);
        $org = Org::find($orgId);
        if ($org === null || (string) $org['level'] === Org::LEVEL_ADMIN) {
            throw new InvalidArgumentException('총판·대리점 조직만 계정을 추가할 수 있습니다.');
        }

        $loginId  = trim((string) ($data['login_id'] ?? ''));
        $accName  = trim((string) ($data['name'] ?? ''));
        $email    = trim((string) ($data['email'] ?? ''));
        $role     = trim((string) ($data['role'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        self::validateLoginId($loginId);
        if ($accName === '') {
            $accName = (string) $org['name'] . ' 담당자';
        }
        if (!in_array($role, self::ACCOUNT_ROLES, true)) {
            throw new InvalidArgumentException('부여할 역할을 선택하세요.');
        }
        if (strlen($password) < 8) {
            throw new InvalidArgumentException('비밀번호는 8자 이상이어야 합니다.');
        }
        if (db_row('SELECT id FROM admins WHERE login_id = ? LIMIT 1', [$loginId]) !== null) {
            throw new InvalidArgumentException('이미 사용 중인 로그인 ID입니다.');
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $newId = db_insert(
            'INSERT INTO admins (login_id, password_hash, name, email, role, org_id, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 1)',
            [$loginId, $hash, $accName, $email !== '' ? $email : null, $role, $orgId]
        );

        return self::accountRow($orgId, $newId);
    }

    /**
     * 조직 계정 수정 (이름·역할·이메일·비밀번호 재설정). super 계정은 불가.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function updateAccount(int $orgId, int $accountId, array $data): array
    {
        self::assertManageable($orgId);
        $target = db_row('SELECT id, role FROM admins WHERE id = ? AND org_id = ? LIMIT 1', [$accountId, $orgId]);
        if ($target === null) {
            throw new InvalidArgumentException('이 조직 소속 계정이 아닙니다.');
        }
        if ((string) $target['role'] === 'super') {
            throw new InvalidArgumentException('최고 관리자 계정은 여기서 수정할 수 없습니다.');
        }

        $name     = trim((string) ($data['name'] ?? ''));
        $email    = trim((string) ($data['email'] ?? ''));
        $role     = trim((string) ($data['role'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        if ($name === '') {
            throw new InvalidArgumentException('이름을 입력하세요.');
        }
        if (!in_array($role, self::ACCOUNT_ROLES, true)) {
            throw new InvalidArgumentException('역할을 선택하세요.');
        }

        $sets   = 'name = ?, email = ?, role = ?, updated_at = NOW()';
        $params = [$name, $email !== '' ? $email : null, $role];
        if ($password !== '') {
            if (strlen($password) < 8) {
                throw new InvalidArgumentException('비밀번호는 8자 이상이어야 합니다.');
            }
            $sets    .= ', password_hash = ?';
            $params[] = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        }
        $params[] = $accountId;
        db_execute("UPDATE admins SET {$sets} WHERE id = ?", $params);

        return self::accountRow($orgId, $accountId);
    }

    /**
     * 조직 계정 활성/비활성. 마지막 활성 계정은 비활성화 불가(로그인 보장).
     *
     * @return array<string,mixed>
     */
    public static function setAccountActive(int $orgId, int $accountId, bool $active): array
    {
        self::assertManageable($orgId);
        $target = db_row('SELECT id, role, is_active FROM admins WHERE id = ? AND org_id = ? LIMIT 1', [$accountId, $orgId]);
        if ($target === null) {
            throw new InvalidArgumentException('이 조직 소속 계정이 아닙니다.');
        }
        if ((string) $target['role'] === 'super') {
            throw new InvalidArgumentException('최고 관리자 계정은 변경할 수 없습니다.');
        }
        if (!$active) {
            $activeCount = (int) (db_row('SELECT COUNT(*) AS c FROM admins WHERE org_id = ? AND is_active = 1', [$orgId])['c'] ?? 0);
            if ($activeCount <= 1 && (int) $target['is_active'] === 1) {
                throw new InvalidArgumentException('마지막 활성 계정은 비활성화할 수 없습니다. (조직 로그인이 불가능해집니다)');
            }
        }
        db_execute('UPDATE admins SET is_active = ?, updated_at = NOW() WHERE id = ?', [$active ? 1 : 0, $accountId]);

        return self::accountRow($orgId, $accountId);
    }

    private static function assertManageable(int $id): void
    {
        $ids = self::manageableOrgIds();
        if ($ids === null) {
            $org = Org::find($id);
            if ($org === null || (string) $org['level'] === Org::LEVEL_ADMIN) {
                throw new InvalidArgumentException('대상 조직을 찾을 수 없습니다.');
            }
            return;
        }
        if (!in_array($id, $ids, true)) {
            throw new InvalidArgumentException('이 조직을 관리할 권한이 없습니다.');
        }
    }

    /** @param array<string,mixed> $row */
    private static function mapRow(array $row): array
    {
        $level   = (string) ($row['level'] ?? '');
        $created = $row['created_at'] ?? null;

        $primaryRole = (string) ($row['primary_role'] ?? '');
        $roleLabels  = self::accountRoleLabels();

        return [
            'id'                   => (int) ($row['id'] ?? 0),
            'parent_id'            => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            'parent_name'          => (string) ($row['parent_name'] ?? ''),
            'level'                => $level,
            'level_label'          => Org::levelLabel($level),
            'code'                 => (string) ($row['code'] ?? ''),
            'name'                 => (string) ($row['name'] ?? ''),
            'contact_name'         => (string) ($row['contact_name'] ?? ''),
            'contact_phone'        => (string) ($row['contact_phone'] ?? ''),
            'memo'                 => (string) ($row['memo'] ?? ''),
            'active'               => (int) ($row['is_active'] ?? 0) === 1,
            'account_count'        => (int) ($row['account_count'] ?? 0),
            'active_account_count' => (int) ($row['active_account_count'] ?? 0),
            'rider_count'          => (int) ($row['rider_count'] ?? 0),
            'active_rider_count'   => (int) ($row['active_rider_count'] ?? 0),
            'primary_login'        => (string) ($row['primary_login'] ?? ''),
            'primary_role'         => $primaryRole,
            'primary_role_label'   => $primaryRole !== '' ? ($roleLabels[$primaryRole] ?? $primaryRole) : '',
            'created_at'           => $created ? date('Y-m-d', strtotime((string) $created)) : '',
        ];
    }

    /**
     * 레벨별 조직 코드 자동 생성 — 접두(DIST/AG) + 4자리 순번, 중복 회피.
     */
    public static function suggestCode(string $level): string
    {
        $prefix = $level === Org::LEVEL_DISTRIBUTOR ? 'DIST' : 'AG';

        // 같은 접두를 가진 기존 코드의 최대 순번 +1에서 시작
        $rows = db_rows(
            "SELECT code FROM organizations WHERE code LIKE ?",
            [$prefix . '-%']
        );
        $max = 0;
        foreach ($rows as $r) {
            if (preg_match('/^' . $prefix . '-(\d+)$/', (string) $r['code'], $m)) {
                $max = max($max, (int) $m[1]);
            }
        }
        $seq = $max + 1;

        // 만일을 대비한 중복 회피 루프
        do {
            $code   = sprintf('%s-%04d', $prefix, $seq);
            $exists = db_row('SELECT id FROM organizations WHERE code = ? LIMIT 1', [$code]);
            $seq++;
        } while ($exists !== null && $seq < $max + 1000);

        return $code;
    }

    /**
     * 조직 상세 — 기본정보 + 소속 계정 목록 + (대리점) 라이더/지갑/설정 요약, (총판) 하위 대리점.
     *
     * @return array<string,mixed>
     */
    public static function detail(int $id): array
    {
        self::assertManageable($id);

        $org = self::find($id);
        if ($org === null) {
            throw new InvalidArgumentException('조직을 찾을 수 없습니다.');
        }

        // 소속 계정
        $primaryId  = 0;
        $roleLabels = self::accountRoleLabels();
        $accRows    = db_rows(
            'SELECT id, login_id, name, email, role, is_active, last_login_at, created_at
               FROM admins WHERE org_id = ? ORDER BY id ASC',
            [$id]
        );
        $accounts = [];
        foreach ($accRows as $i => $a) {
            if ($i === 0) {
                $primaryId = (int) $a['id'];
            }
            $role = (string) $a['role'];
            $accounts[] = [
                'id'            => (int) $a['id'],
                'login_id'      => (string) $a['login_id'],
                'name'          => (string) $a['name'],
                'email'         => (string) ($a['email'] ?? ''),
                'role'          => $role,
                'role_label'    => $roleLabels[$role] ?? $role,
                'active'        => (int) $a['is_active'] === 1,
                'is_primary'    => (int) $a['id'] === $primaryId,
                'last_login_at' => $a['last_login_at'] ? date('Y-m-d H:i', strtotime((string) $a['last_login_at'])) : '',
            ];
        }

        $detail = [
            'org'      => $org,
            'accounts' => $accounts,
        ];

        if ($org['level'] === Org::LEVEL_AGENCY) {
            // 라이더 상태 분해
            $statusRows = db_rows(
                "SELECT status, COUNT(*) AS c FROM riders WHERE agency_id = ? GROUP BY status",
                [$id]
            );
            $riderStatus = ['active' => 0, 'suspended' => 0, 'leave_request' => 0, 'offboarded' => 0];
            foreach ($statusRows as $s) {
                $riderStatus[(string) $s['status']] = (int) $s['c'];
            }

            // 지갑 · 설정 요약 (있으면)
            $wallet = null;
            if (class_exists('AgencyWallet') || is_file(INC_PATH . '/AgencyWallet.php')) {
                require_once INC_PATH . '/AgencyWallet.php';
                if (AgencyWallet::tableExists()) {
                    $wallet = AgencyWallet::withdrawable($id);
                }
            }

            $feeCfg = null;
            if (is_file(INC_PATH . '/WithdrawalConfig.php')) {
                require_once INC_PATH . '/WithdrawalConfig.php';
                $feeCfg = WithdrawalConfig::get($id);
            }

            $pgFee = null;
            if (is_file(INC_PATH . '/PgFeeConfig.php')) {
                require_once INC_PATH . '/PgFeeConfig.php';
                $pgFee = PgFeeConfig::breakdownForAgency($id);
            }

            $cardCount = db_table_exists('agency_cards')
                ? (int) (db_row('SELECT COUNT(*) AS c FROM agency_cards WHERE agency_id = ? AND is_active = 1', [$id])['c'] ?? 0)
                : 0;
            $hasBank = db_table_exists('agency_bank_accounts')
                && db_row('SELECT agency_id FROM agency_bank_accounts WHERE agency_id = ? LIMIT 1', [$id]) !== null;
            $uploadCount = db_table_exists('settlement_uploads')
                ? (int) (db_row('SELECT COUNT(*) AS c FROM settlement_uploads WHERE agency_id = ?', [$id])['c'] ?? 0)
                : 0;
            $withholdingRiders = (int) (db_row('SELECT COUNT(*) AS c FROM riders WHERE agency_id = ? AND withholding_tax_enabled = 1', [$id])['c'] ?? 0);

            $detail['agency'] = [
                'rider_status'        => $riderStatus,
                'wallet'              => $wallet,
                'fee_config'          => $feeCfg,
                'pg_fee'              => $pgFee,
                'card_count'          => $cardCount,
                'has_bank_account'    => $hasBank,
                'upload_count'        => $uploadCount,
                'withholding_riders'  => $withholdingRiders,
            ];
        } elseif ($org['level'] === Org::LEVEL_DISTRIBUTOR) {
            $children = db_rows(
                "SELECT o.id, o.code, o.name, o.is_active,
                        (SELECT COUNT(*) FROM riders r WHERE r.agency_id = o.id) AS rider_count,
                        (SELECT COUNT(*) FROM riders r2 WHERE r2.agency_id = o.id AND r2.status = 'active') AS active_rider_count
                   FROM organizations o
                  WHERE o.parent_id = ? AND o.level = 'agency'
                  ORDER BY o.name ASC",
                [$id]
            );
            $childList = array_map(static fn (array $c): array => [
                'id'                 => (int) $c['id'],
                'code'               => (string) $c['code'],
                'name'               => (string) $c['name'],
                'active'             => (int) $c['is_active'] === 1,
                'rider_count'        => (int) $c['rider_count'],
                'active_rider_count' => (int) $c['active_rider_count'],
            ], $children);

            $detail['distributor'] = [
                'agency_count'     => count($childList),
                'total_riders'     => array_sum(array_column($childList, 'rider_count')),
                'children'         => $childList,
            ];
        }

        return $detail;
    }

    private static function validateCode(string $code): void
    {
        if ($code === '' || strlen($code) > 40) {
            throw new InvalidArgumentException('조직 코드를 확인하세요. (1~40자)');
        }
        if (!preg_match('/^[A-Z0-9_-]+$/', $code)) {
            throw new InvalidArgumentException('조직 코드는 영문 대문자·숫자·_·- 만 사용할 수 있습니다.');
        }
    }

    private static function validateLoginId(string $loginId): void
    {
        if ($loginId === '' || strlen($loginId) > 60) {
            throw new InvalidArgumentException('로그인 ID를 확인하세요.');
        }
        if (!preg_match('/^[a-zA-Z0-9@._-]+$/', $loginId)) {
            throw new InvalidArgumentException('로그인 ID는 영문, 숫자, @ . _ - 만 사용할 수 있습니다.');
        }
    }
}
