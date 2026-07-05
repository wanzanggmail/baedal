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
    /** 조직 계정에 부여 가능한 기능 역할 (super는 플랫폼 루트 전용이라 제외) */
    public const ACCOUNT_ROLES = ['operation', 'settlement', 'admin'];

    /** @return array<string,string> */
    public static function accountRoleLabels(): array
    {
        return [
            'operation'  => '운영',
            'settlement' => '정산',
            'admin'      => '조회 전용',
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
                    o.contact_name, o.contact_phone, o.is_active, o.created_at,
                    p.name AS parent_name,
                    (SELECT COUNT(*) FROM admins  a WHERE a.org_id    = o.id) AS account_count,
                    (SELECT COUNT(*) FROM riders  r WHERE r.agency_id = o.id) AS rider_count,
                    (SELECT a2.login_id FROM admins a2 WHERE a2.org_id = o.id ORDER BY a2.id ASC LIMIT 1) AS primary_login
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
                    o.contact_name, o.contact_phone, o.is_active, o.created_at,
                    p.name AS parent_name,
                    (SELECT COUNT(*) FROM admins  a WHERE a.org_id    = o.id) AS account_count,
                    (SELECT COUNT(*) FROM riders  r WHERE r.agency_id = o.id) AS rider_count,
                    (SELECT a2.login_id FROM admins a2 WHERE a2.org_id = o.id ORDER BY a2.id ASC LIMIT 1) AS primary_login
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
        if (!in_array($creatorLevel, [Org::LEVEL_ADMIN, Org::LEVEL_DISTRIBUTOR], true)) {
            throw new InvalidArgumentException('조직을 생성할 권한이 없습니다.');
        }

        $level = trim((string) ($data['level'] ?? ''));

        // 레벨·상위 결정
        if ($creatorLevel === Org::LEVEL_DISTRIBUTOR) {
            $level    = Org::LEVEL_AGENCY;     // 총판은 대리점만 생성
            $parentId = $creatorOrgId;
        } else { // 본사
            if ($level === Org::LEVEL_DISTRIBUTOR) {
                $parentId = $creatorOrgId;     // 루트 하위
            } elseif ($level === Org::LEVEL_AGENCY) {
                $parentId = (int) ($data['parent_id'] ?? 0);
                $parent   = Org::find($parentId);
                if ($parent === null || (string) $parent['level'] !== Org::LEVEL_DISTRIBUTOR) {
                    throw new InvalidArgumentException('대리점의 상위 총판을 선택하세요.');
                }
            } else {
                throw new InvalidArgumentException('생성할 조직 유형(총판/대리점)을 선택하세요.');
            }
        }

        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        $name = trim((string) ($data['name'] ?? ''));
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
     * 조직 기본 정보 수정 (이름·연락처). 레벨·상위·코드는 불변.
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

        db_execute(
            'UPDATE organizations SET name = ?, contact_name = ?, contact_phone = ?, updated_at = NOW() WHERE id = ?',
            [$name, $contactName, $contactPhone, $id]
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

        return [
            'id'            => (int) ($row['id'] ?? 0),
            'parent_id'     => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            'parent_name'   => (string) ($row['parent_name'] ?? ''),
            'level'         => $level,
            'level_label'   => Org::levelLabel($level),
            'code'          => (string) ($row['code'] ?? ''),
            'name'          => (string) ($row['name'] ?? ''),
            'contact_name'  => (string) ($row['contact_name'] ?? ''),
            'contact_phone' => (string) ($row['contact_phone'] ?? ''),
            'active'        => (int) ($row['is_active'] ?? 0) === 1,
            'account_count' => (int) ($row['account_count'] ?? 0),
            'rider_count'   => (int) ($row['rider_count'] ?? 0),
            'primary_login' => (string) ($row['primary_login'] ?? ''),
            'created_at'    => $created ? date('Y-m-d', strtotime((string) $created)) : '',
        ];
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
