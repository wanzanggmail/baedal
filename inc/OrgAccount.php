<?php

declare(strict_types=1);

require_once __DIR__ . '/Org.php';

/**
 * 조직 서브계정 관리 (대표계정이 자기 조직 범위 내 계정을 CRUD) — LOGIC §2 · §7 #14.
 *
 * 대표계정 = 조직의 최초(가장 낮은 id) 계정. 대표만 서브계정을 만들고 권한을 부여한다.
 * 부여 가능 역할: operation / settlement / admin (super는 테넌트 격리를 깨므로 금지).
 */
final class OrgAccount
{
    /** 서브계정에 부여 가능한 역할 (super 제외). manager=자기 조직 내 전체 화면 조회·쓰기(시스템관리 제외) */
    public const ASSIGNABLE_ROLES = ['operation', 'settlement', 'admin', 'manager'];

    /** @return array<string,string> */
    public static function roleLabels(): array
    {
        return ['operation' => '운영', 'settlement' => '정산', 'admin' => '조회 전용', 'manager' => '총괄 관리자'];
    }

    /** 조직의 대표계정 id (최초 계정). 없으면 0. */
    public static function primaryId(int $orgId): int
    {
        if ($orgId < 1) {
            return 0;
        }
        $row = db_row('SELECT id FROM admins WHERE org_id = ? ORDER BY id ASC LIMIT 1', [$orgId]);

        return $row !== null ? (int) $row['id'] : 0;
    }

    /** 현재 로그인 계정이 자기 조직의 대표인지 (총판·대리점 한정). */
    public static function currentIsPrimary(): bool
    {
        $level = admin_org_level();
        if (!in_array($level, [Org::LEVEL_AGENCY, Org::LEVEL_DISTRIBUTOR], true)) {
            return false;
        }
        $orgId = admin_org_id();
        $me    = admin_user();

        return $orgId > 0 && $me !== null && self::primaryId($orgId) === (int) $me['id'];
    }

    /**
     * 조직 소속 계정 목록 (대표 표시 포함).
     *
     * @return list<array<string,mixed>>
     */
    public static function listForOrg(int $orgId): array
    {
        if ($orgId < 1) {
            return [];
        }
        $primary = self::primaryId($orgId);
        $rows = db_rows(
            'SELECT id, login_id, name, email, role, is_active, last_login_at, created_at
               FROM admins WHERE org_id = ? ORDER BY id ASC',
            [$orgId]
        );
        $labels = self::roleLabels();

        return array_map(static function (array $r) use ($primary, $labels): array {
            $role = (string) $r['role'];

            return [
                'id'            => (int) $r['id'],
                'login_id'      => (string) $r['login_id'],
                'name'          => (string) $r['name'],
                'email'         => (string) ($r['email'] ?? ''),
                'role'          => $role,
                'role_label'    => $labels[$role] ?? $role,
                'active'        => (int) $r['is_active'] === 1,
                'is_primary'    => (int) $r['id'] === $primary,
                'last_login_at' => $r['last_login_at'] ? date('Y-m-d H:i', strtotime((string) $r['last_login_at'])) : '',
                'created_at'    => $r['created_at'] ? date('Y-m-d', strtotime((string) $r['created_at'])) : '',
            ];
        }, $rows);
    }

    /**
     * 서브계정 생성 (org_id = 대표의 조직).
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function create(int $orgId, array $data): array
    {
        self::assertPrimary($orgId);

        $loginId  = trim((string) ($data['login_id'] ?? ''));
        $name     = trim((string) ($data['name'] ?? ''));
        $email    = trim((string) ($data['email'] ?? ''));
        $role     = trim((string) ($data['role'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        self::validateLoginId($loginId);
        if ($name === '') {
            throw new InvalidArgumentException('이름을 입력하세요.');
        }
        if (!in_array($role, self::ASSIGNABLE_ROLES, true)) {
            throw new InvalidArgumentException('부여할 역할을 선택하세요.');
        }
        if (strlen($password) < 8) {
            throw new InvalidArgumentException('비밀번호는 8자 이상이어야 합니다.');
        }
        if (db_row('SELECT id FROM admins WHERE login_id = ? LIMIT 1', [$loginId]) !== null) {
            throw new InvalidArgumentException('이미 사용 중인 로그인 ID입니다.');
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $id = db_insert(
            'INSERT INTO admins (login_id, password_hash, name, email, role, org_id, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 1)',
            [$loginId, $hash, $name, $email !== '' ? $email : null, $role, $orgId]
        );

        return self::findInOrg($id, $orgId);
    }

    /**
     * 서브계정 수정 (이름·역할·비밀번호). 대표계정 자신의 역할은 변경 불가.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function update(int $orgId, int $id, array $data): array
    {
        self::assertPrimary($orgId);
        $target = self::assertTargetInOrg($id, $orgId);

        $name     = trim((string) ($data['name'] ?? ''));
        $email    = trim((string) ($data['email'] ?? ''));
        $role     = trim((string) ($data['role'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        if ($name === '') {
            throw new InvalidArgumentException('이름을 입력하세요.');
        }
        if (!in_array($role, self::ASSIGNABLE_ROLES, true)) {
            throw new InvalidArgumentException('역할을 선택하세요.');
        }
        // 대표계정의 역할은 변경 불가(권한 상실 방지)
        if ((int) $id === self::primaryId($orgId) && $role !== (string) $target['role']) {
            throw new InvalidArgumentException('대표계정의 역할은 변경할 수 없습니다.');
        }

        $params = [$name, $email !== '' ? $email : null, $role];
        $sets   = 'name = ?, email = ?, role = ?, updated_at = NOW()';
        if ($password !== '') {
            if (strlen($password) < 8) {
                throw new InvalidArgumentException('비밀번호는 8자 이상이어야 합니다.');
            }
            $sets    .= ', password_hash = ?';
            $params[] = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        }
        $params[] = $id;
        db_execute("UPDATE admins SET {$sets} WHERE id = ?", $params);

        return self::findInOrg($id, $orgId);
    }

    /** @return array<string,mixed> */
    public static function setActive(int $orgId, int $id, bool $active): array
    {
        self::assertPrimary($orgId);
        self::assertTargetInOrg($id, $orgId);

        if ($id === self::primaryId($orgId)) {
            throw new InvalidArgumentException('대표계정은 비활성화할 수 없습니다.');
        }
        db_execute('UPDATE admins SET is_active = ?, updated_at = NOW() WHERE id = ?', [$active ? 1 : 0, $id]);

        return self::findInOrg($id, $orgId);
    }

    /** @return array<string,mixed> */
    private static function findInOrg(int $id, int $orgId): array
    {
        $rows = self::listForOrg($orgId);
        foreach ($rows as $r) {
            if ((int) $r['id'] === $id) {
                return $r;
            }
        }
        throw new RuntimeException('계정을 불러올 수 없습니다.');
    }

    private static function assertPrimary(int $orgId): void
    {
        $me = admin_user();
        if ($me === null || admin_org_id() !== $orgId || self::primaryId($orgId) !== (int) $me['id']) {
            throw new InvalidArgumentException('대표계정만 계정을 관리할 수 있습니다.');
        }
    }

    /** @return array<string,mixed> */
    private static function assertTargetInOrg(int $id, int $orgId): array
    {
        $row = db_row('SELECT id, role, org_id FROM admins WHERE id = ? LIMIT 1', [$id]);
        if ($row === null || (int) $row['org_id'] !== $orgId) {
            throw new InvalidArgumentException('내 조직 소속 계정이 아닙니다.');
        }
        if ((string) $row['role'] === 'super') {
            throw new InvalidArgumentException('최고 관리자 계정은 관리할 수 없습니다.');
        }

        return $row;
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
