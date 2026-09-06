<?php

declare(strict_types=1);

/**
 * 역할(admin/operation/settlement)별 화면(area) 조회·쓰기 권한.
 * super 역할은 항상 전권이라 이 테이블에 포함하지 않는다.
 * 시스템관리(system/*)는 이 테이블과 무관하게 super 전용으로 코드에 고정되어 있다.
 */
final class RolePermission
{
    public const AREAS = [
        'dashboard'  => '대시보드',
        'settlement' => '정산 업로드',
        'deduction'  => '차감·수수료(대행수수료/미수금)',
        'promotion'  => '프로모션',
        'withdrawal' => '출금',
        // 'content'(콘텐츠)는 2026-09-06 갑 지시로 **본사 최고관리자·개발사 전용**이 되어
        // 역할별로 열고 닫을 값이 아니다. 여기 남겨두면 켜도 아무 일이 없는 스위치가 되므로
        // 뺀다(판정은 admin_can_access_route). 기존 role_permissions 행은 무시된다.
        'riders'     => '라이더',
    ];

    public const ROLES = ['admin', 'operation', 'settlement'];

    /** @var array<string, array<string, array{view: bool, write: bool}>>|null */
    private static ?array $cache = null;

    public static function tableReady(): bool
    {
        return db_table_exists('role_permissions');
    }

    /** @return array<string, array<string, array{view: bool, write: bool}>> */
    private static function load(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        self::$cache = [];
        if (!self::tableReady()) {
            return self::$cache;
        }

        foreach (db_rows('SELECT role, area, can_view, can_write FROM role_permissions') as $row) {
            self::$cache[(string) $row['role']][(string) $row['area']] = [
                'view'  => (bool) $row['can_view'],
                'write' => (bool) $row['can_write'],
            ];
        }

        return self::$cache;
    }

    public static function canView(string $role, string $area): bool
    {
        return (bool) (self::load()[$role][$area]['view'] ?? false);
    }

    public static function canWrite(string $role, string $area): bool
    {
        return (bool) (self::load()[$role][$area]['write'] ?? false);
    }

    /** @return array<string, array<string, array{view: bool, write: bool}>> 화면 표시용: role => area => {view,write} (없는 조합도 false로 채워 반환) */
    public static function all(): array
    {
        self::load();
        $out = [];
        foreach (self::ROLES as $role) {
            foreach (array_keys(self::AREAS) as $area) {
                $out[$role][$area] = [
                    'view'  => self::canView($role, $area),
                    'write' => self::canWrite($role, $area),
                ];
            }
        }

        return $out;
    }

    /**
     * @param list<array{role: string, area: string, view: bool, write: bool}> $rows
     */
    public static function save(array $rows): void
    {
        db_transaction(static function () use ($rows): void {
            foreach ($rows as $row) {
                $role = (string) $row['role'];
                $area = (string) $row['area'];
                if (!in_array($role, self::ROLES, true) || !array_key_exists($area, self::AREAS)) {
                    continue;
                }
                db_execute(
                    'INSERT INTO role_permissions (role, area, can_view, can_write)
                        VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE can_view = VALUES(can_view), can_write = VALUES(can_write)',
                    [$role, $area, $row['view'] ? 1 : 0, $row['write'] ? 1 : 0]
                );
            }
        });
        self::$cache = null;
    }
}
