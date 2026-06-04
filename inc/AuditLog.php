<?php

declare(strict_types=1);

/**
 * 감사 로그 (audit_logs — actor_type / target_table / before·after JSON)
 */
final class AuditLog
{
    /**
     * @param string $action   내부 코드 (auth.login, content.notice.save …) → DB action 으로 정규화
     * @param string $target   public_id·파일명·코드 등 (표시·after_value 보조)
     * @param string $detail   사람이 읽을 설명
     */
    public static function record(
        string $action,
        string $target = '',
        string $detail = '',
        ?int $adminId = null,
        ?string $actorLoginId = null
    ): void {
        try {
            if (!self::tableExists()) {
                return;
            }

            $actorType = 'admin';
            if ($adminId === null && $actorLoginId === null && function_exists('admin_is_logged_in') && admin_is_logged_in()) {
                $u = admin_user();
                if ($u) {
                    $adminId = (int) $u['id'];
                    $actorLoginId = (string) $u['login_id'];
                }
            }

            if ($adminId === null && $actorLoginId === null) {
                $actorType = 'system';
            }

            [$targetTable, $targetId] = self::resolveTarget($action, $target);

            $after = array_filter([
                'detail'    => $detail !== '' ? $detail : null,
                'ref'       => $target !== '' && ($targetId === null || !ctype_digit($target)) ? $target : null,
                'login_id'  => ($actorLoginId !== null && $actorLoginId !== '' && ($adminId === null || $adminId < 1))
                    ? $actorLoginId : null,
            ], static fn ($v) => $v !== null && $v !== '');

            self::insert([
                'actor_type'   => $actorType,
                'actor_id'     => ($adminId !== null && $adminId > 0) ? $adminId : null,
                'action'       => self::normalizeAction($action),
                'target_table' => $targetTable,
                'target_id'    => $targetId,
                'before_value' => null,
                'after_value'  => $after !== [] ? $after : null,
            ]);
        } catch (Throwable) {
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function write(array $data): void
    {
        try {
            if (!self::tableExists()) {
                return;
            }
            self::insert($data);
        } catch (Throwable) {
        }
    }

    /**
     * @param array<string, mixed> $filters q, actor, action_prefix, page, limit
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, limit: int, pages: int}
     */
    public static function list(array $filters = []): array
    {
        if (!self::tableExists()) {
            return ['rows' => [], 'total' => 0, 'page' => 1, 'limit' => 50, 'pages' => 1];
        }

        [$whereSql, $params] = self::buildWhere($filters);
        $page   = max(1, (int) ($filters['page'] ?? 1));
        $limit  = max(10, min(200, (int) ($filters['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;

        $total = (int) (db_row(
            "SELECT COUNT(*) AS c
             FROM audit_logs al
             LEFT JOIN admins a ON al.actor_type = 'admin' AND a.id = al.actor_id
             WHERE {$whereSql}",
            $params
        )['c'] ?? 0);

        $rows = db_rows(
            "SELECT al.id, al.actor_type, al.actor_id, al.action, al.target_table, al.target_id,
                    al.before_value, al.after_value, al.ip, al.created_at,
                    a.login_id AS admin_login_id
             FROM audit_logs al
             LEFT JOIN admins a ON al.actor_type = 'admin' AND a.id = al.actor_id
             WHERE {$whereSql}
             ORDER BY al.created_at DESC, al.id DESC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );

        return [
            'rows'  => array_map([self::class, 'mapRow'], $rows),
            'total' => $total,
            'page'  => $page,
            'limit' => $limit,
            'pages' => $total > 0 ? (int) ceil($total / $limit) : 1,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     */
    public static function csvContent(array $filters = [], int $maxRows = 5000): string
    {
        if (!self::tableExists()) {
            return "\xEF\xBB\xBF" . "일시,수행자,행동,대상,상세,IP\n";
        }

        [$whereSql, $params] = self::buildWhere($filters);
        $maxRows = max(1, min(10000, $maxRows));

        $rows = db_rows(
            "SELECT al.actor_type, al.actor_id, al.action, al.target_table, al.target_id,
                    al.after_value, al.ip, al.created_at, a.login_id AS admin_login_id
             FROM audit_logs al
             LEFT JOIN admins a ON al.actor_type = 'admin' AND a.id = al.actor_id
             WHERE {$whereSql}
             ORDER BY al.created_at DESC, al.id DESC
             LIMIT {$maxRows}",
            $params
        );

        $lines = ["\xEF\xBB\xBF" . '일시,수행자,행동,대상,상세,IP'];
        foreach ($rows as $r) {
            $mapped = self::mapRow($r);
            $lines[] = implode(',', [
                self::csvCell((string) $mapped['at']),
                self::csvCell((string) $mapped['actor']),
                self::csvCell((string) $mapped['action']),
                self::csvCell((string) $mapped['target']),
                self::csvCell((string) $mapped['detail']),
                self::csvCell((string) $mapped['ip']),
            ]);
        }

        return implode("\n", $lines);
    }

    public static function tableExists(): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        try {
            $cache = db_table_exists('audit_logs');
        } catch (Throwable) {
            $cache = false;
        }

        return $cache;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function insert(array $data): void
    {
        $beforeJson = self::jsonOrNull($data['before_value'] ?? null);
        $afterJson  = self::jsonOrNull($data['after_value'] ?? null);

        db_insert(
            'INSERT INTO audit_logs
                (actor_type, actor_id, action, target_table, target_id, before_value, after_value, ip, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                self::clip((string) ($data['actor_type'] ?? 'admin'), 10),
                isset($data['actor_id']) && (int) $data['actor_id'] > 0 ? (int) $data['actor_id'] : null,
                self::clip((string) ($data['action'] ?? ''), 80),
                self::clip((string) ($data['target_table'] ?? ''), 60),
                isset($data['target_id']) && (int) $data['target_id'] > 0 ? (int) $data['target_id'] : null,
                $beforeJson,
                $afterJson,
                self::clip(self::clientIp(), 45),
                self::clip((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 300),
            ]
        );
    }

    private static function normalizeAction(string $action): string
    {
        $a = strtolower(trim($action));

        return match (true) {
            str_contains($a, 'login.fail'), str_ends_with($a, '.fail') => 'LOGIN_FAIL',
            str_contains($a, 'logout') => 'LOGOUT',
            str_contains($a, 'login') => 'LOGIN',
            str_contains($a, '.delete') => 'DELETE',
            str_contains($a, '.export') => 'EXPORT',
            str_contains($a, '.upload'), str_contains($a, '.commit') => 'CREATE',
            str_contains($a, '.complete'), str_contains($a, '.reject'), str_contains($a, 'mark_downloaded') => 'UPDATE',
            str_contains($a, '.save') => 'UPDATE',
            default => self::clip(strtoupper(str_replace('.', '_', $a)), 80),
        };
    }

    /**
     * @return array{0: string, 1: ?int}
     */
    private static function resolveTarget(string $action, string $target): array
    {
        $table = match (true) {
            str_starts_with($action, 'content.notice') => 'content_notices',
            str_starts_with($action, 'content.banner') => 'content_banners',
            str_starts_with($action, 'settlement.upload') => 'settlement_uploads',
            str_starts_with($action, 'settlement.') => 'settlement_rider_cycles',
            str_starts_with($action, 'withdrawal.') => 'withdrawal_requests',
            str_starts_with($action, 'deduction.') => 'deduction_global_config',
            str_starts_with($action, 'rider.') => 'riders',
            str_starts_with($action, 'admin.') => 'admins',
            str_starts_with($action, 'codes.') => 'system_codes',
            default => '',
        };

        $targetId = null;
        if (ctype_digit(trim($target))) {
            $targetId = (int) $target;
        } elseif ($table !== '' && preg_match('/(\d+)/', $target, $m)) {
            $targetId = (int) $m[1];
        }

        return [$table, $targetId];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function mapRow(array $row): array
    {
        $ts = strtotime((string) ($row['created_at'] ?? ''));
        $after = self::decodeJson($row['after_value'] ?? null);

        $actor = (string) ($row['admin_login_id'] ?? '');
        if ($actor === '') {
            $actorType = (string) ($row['actor_type'] ?? '');
            if ($actorType === 'system') {
                $actor = 'system';
            } elseif ($actorType === 'rider' && !empty($row['actor_id'])) {
                $actor = 'rider#' . (int) $row['actor_id'];
            } elseif (!empty($after['login_id'])) {
                $actor = (string) $after['login_id'];
            } elseif (!empty($row['actor_id'])) {
                $actor = $actorType . '#' . (int) $row['actor_id'];
            } else {
                $actor = $actorType !== '' ? $actorType : '—';
            }
        }

        $targetTable = (string) ($row['target_table'] ?? '');
        $targetId    = isset($row['target_id']) ? (int) $row['target_id'] : 0;
        $target      = $targetTable;
        if ($targetId > 0) {
            $target = $targetTable !== '' ? "{$targetTable}#{$targetId}" : (string) $targetId;
        }
        if ($target === '' && !empty($after['ref'])) {
            $target = (string) $after['ref'];
        }

        $detail = (string) ($after['detail'] ?? '');
        if ($detail === '' && $after !== []) {
            $detail = json_encode($after, JSON_UNESCAPED_UNICODE);
        }

        return [
            'id'     => (int) ($row['id'] ?? 0),
            'at'     => $ts ? date('Y-m-d H:i', $ts) : '',
            'actor'  => $actor,
            'action' => (string) ($row['action'] ?? ''),
            'target' => $target,
            'detail' => $detail,
            'ip'     => (string) ($row['ip'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private static function buildWhere(array $filters): array
    {
        $where = ['1=1'];
        $params = [];

        $actor = trim((string) ($filters['actor'] ?? ''));
        if ($actor !== '') {
            $where[] = '(a.login_id LIKE ? OR al.after_value LIKE ? OR CAST(al.actor_id AS CHAR) LIKE ?)';
            $like = '%' . $actor . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $prefix = trim((string) ($filters['action_prefix'] ?? ''));
        if ($prefix !== '') {
            $where[] = '(al.action LIKE ? OR al.target_table LIKE ?)';
            $params[] = strtoupper($prefix) . '%';
            $params[] = $prefix . '%';
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(al.action LIKE ? OR al.target_table LIKE ? OR al.after_value LIKE ? OR a.login_id LIKE ? OR CAST(al.target_id AS CHAR) LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * @param mixed $value
     */
    private static function jsonOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return json_last_error() === JSON_ERROR_NONE ? $value : null;
        }
        if (!is_array($value)) {
            return null;
        }
        $json = json_encode($value, JSON_UNESCAPED_UNICODE);
        if ($json === false || $json === '[]' || $json === '{}') {
            return null;
        }

        return $json;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeJson(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function clientIp(): string
    {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        }

        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }

    private static function clip(string $s, int $max): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($s, 0, $max, 'UTF-8');
        }

        return substr($s, 0, $max);
    }

    private static function csvCell(string $v): string
    {
        $s = str_replace('"', '""', $v);
        if (preg_match('/[",\n\r]/', $s)) {
            return '"' . $s . '"';
        }

        return $s;
    }
}
