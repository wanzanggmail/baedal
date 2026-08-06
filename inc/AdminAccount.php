<?php

declare(strict_types=1);

/**
 * 관리자 계정 (admins 테이블)
 */
final class AdminAccount
{
    /** @var list<string> */
    public const ROLES = ['super', 'admin', 'operation', 'settlement', 'manager'];

    /** @return array<string, string> */
    public static function roleLabels(): array
    {
        return [
            'super'      => '최고 관리자',
            'admin'      => '조회 전용',
            'operation'  => '운영',
            'settlement' => '정산',
            'manager'    => '총괄 관리자',
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function listAll(): array
    {
        $rows = db_rows(
            'SELECT id, login_id, name, email, role, is_active, last_login_at, created_at
             FROM admins
             ORDER BY id ASC'
        );

        return array_map([self::class, 'mapRow'], $rows);
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        $row = db_row(
            'SELECT id, login_id, name, email, role, is_active, last_login_at, created_at
             FROM admins WHERE id = ? LIMIT 1',
            [$id]
        );

        return $row !== null ? self::mapRow($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function create(array $data): array
    {
        $loginId  = trim((string) ($data['login_id'] ?? ''));
        $name     = trim((string) ($data['name'] ?? ''));
        $email    = trim((string) ($data['email'] ?? ''));
        $role     = trim((string) ($data['role'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        self::validateLoginId($loginId);
        if ($name === '') {
            throw new InvalidArgumentException('이름을 입력하세요.');
        }
        self::validateRole($role);
        if (strlen($password) < 8) {
            throw new InvalidArgumentException('비밀번호는 8자 이상이어야 합니다.');
        }
        if (db_row('SELECT id FROM admins WHERE login_id = ? LIMIT 1', [$loginId]) !== null) {
            throw new InvalidArgumentException('이미 사용 중인 로그인 ID입니다.');
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $id = db_insert(
            'INSERT INTO admins (login_id, password_hash, name, email, role, is_active)
             VALUES (?, ?, ?, ?, ?, 1)',
            [
                $loginId,
                $hash,
                $name,
                $email !== '' ? $email : null,
                $role,
            ]
        );

        $row = self::find($id);
        if ($row === null) {
            throw new RuntimeException('생성된 관리자를 불러올 수 없습니다.');
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function update(int $id, array $data, int $actorId): array
    {
        $existing = db_row(
            'SELECT id, login_id, role, is_active FROM admins WHERE id = ? LIMIT 1',
            [$id]
        );
        if ($existing === null) {
            throw new InvalidArgumentException('관리자를 찾을 수 없습니다.');
        }

        $name     = trim((string) ($data['name'] ?? ''));
        $email    = trim((string) ($data['email'] ?? ''));
        $role     = trim((string) ($data['role'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        if ($name === '') {
            throw new InvalidArgumentException('이름을 입력하세요.');
        }
        self::validateRole($role);

        if ((string) $existing['role'] === 'super' && $role !== 'super') {
            self::ensureSuperRemains($id);
        }

        $params = [$name, $email !== '' ? $email : null, $role];
        $sets   = 'name = ?, email = ?, role = ?, updated_at = NOW()';

        if ($password !== '') {
            if (strlen($password) < 8) {
                throw new InvalidArgumentException('비밀번호는 8자 이상이어야 합니다.');
            }
            $sets .= ', password_hash = ?';
            $params[] = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        }

        $params[] = $id;
        db_execute("UPDATE admins SET {$sets} WHERE id = ?", $params);

        if ($id === $actorId) {
            $_SESSION['admin_name'] = $name;
            $_SESSION['admin_role'] = $role;
        }

        $row = self::find($id);
        if ($row === null) {
            throw new RuntimeException('수정된 관리자를 불러올 수 없습니다.');
        }

        return $row;
    }

    /** @return array<string, mixed> */
    public static function setActive(int $id, bool $active, int $actorId): array
    {
        if ($id === $actorId && !$active) {
            throw new InvalidArgumentException('본인 계정은 비활성화할 수 없습니다.');
        }

        $existing = db_row(
            'SELECT id, role FROM admins WHERE id = ? LIMIT 1',
            [$id]
        );
        if ($existing === null) {
            throw new InvalidArgumentException('관리자를 찾을 수 없습니다.');
        }

        if ((string) $existing['role'] === 'super' && !$active) {
            self::ensureSuperRemains($id);
        }

        db_execute(
            'UPDATE admins SET is_active = ?, updated_at = NOW() WHERE id = ?',
            [$active ? 1 : 0, $id]
        );

        $row = self::find($id);
        if ($row === null) {
            throw new RuntimeException('상태 변경 후 관리자를 불러올 수 없습니다.');
        }

        return $row;
    }

    /** @param array<string, mixed> $row */
    private static function mapRow(array $row): array
    {
        $labels = self::roleLabels();
        $role   = (string) ($row['role'] ?? '');
        $last   = $row['last_login_at'] ?? null;
        $created = $row['created_at'] ?? null;

        return [
            'id'            => (int) ($row['id'] ?? 0),
            'login_id'      => (string) ($row['login_id'] ?? ''),
            'name'          => (string) ($row['name'] ?? ''),
            'email'         => (string) ($row['email'] ?? ''),
            'role'          => $role,
            'role_label'    => $labels[$role] ?? $role,
            'active'        => (int) ($row['is_active'] ?? 0) === 1,
            'last_login_at' => $last ? date('Y-m-d H:i', strtotime((string) $last)) : '',
            'created_at'    => $created ? date('Y-m-d', strtotime((string) $created)) : '',
        ];
    }

    private static function ensureSuperRemains(int $excludingId): void
    {
        $cnt = (int) (db_row(
            'SELECT COUNT(*) AS c FROM admins
             WHERE role = ? AND is_active = 1 AND id != ?',
            ['super', $excludingId]
        )['c'] ?? 0);

        if ($cnt < 1) {
            throw new InvalidArgumentException('최소 한 명의 활성 최고 관리자가 필요합니다.');
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

    private static function validateRole(string $role): void
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new InvalidArgumentException('역할을 선택하세요.');
        }
    }
}
