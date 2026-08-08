<?php

/**
 * 운영 데이터 초기화
 * 최고 관리자(super)와 그 소속 본사 조직, 시스템 코드·권한·전역 설정만 남기고
 * 총판·대리점·다른 관리자·라이더·정산·출금·콘텐츠를 삭제합니다.
 *
 * 사용:
 *   php scripts/reset_operational_data.php           # 미리보기
 *   php scripts/reset_operational_data.php --execute # 실제 삭제
 *
 * !! 되돌릴 수 없습니다. 실행 전 DB 백업을 확인하세요. !!
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';

$execute = in_array('--execute', $argv, true);

const KEEP_ALL = [
    'system_codes',
    'role_permissions',
];

function reset_log(string $msg): void
{
    echo $msg . PHP_EOL;
}

function reset_table_names(): array
{
    $names = [];
    foreach (db_rows('SHOW TABLES') as $row) {
        $names[] = (string) array_values($row)[0];
    }
    sort($names);

    return $names;
}

function reset_has_column(string $table, string $column): bool
{
    return db_row(
        'SELECT 1 AS ok FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
         LIMIT 1',
        [$table, $column]
    ) !== null;
}

function reset_count(string $table, string $where = '', array $params = []): int
{
    $sql = "SELECT COUNT(*) AS c FROM `{$table}`";
    if ($where !== '') {
        $sql .= ' WHERE ' . $where;
    }
    $row = db_row($sql, $params);

    return (int) ($row['c'] ?? 0);
}

$supers = db_rows("SELECT id, login_id, name, role, org_id FROM admins WHERE role = 'super' ORDER BY id");
if ($supers === []) {
    fwrite(STDERR, "ERROR: super 계정이 없습니다. 중단합니다.\n");
    exit(1);
}

$keepAdminIds = array_map(static fn (array $r): int => (int) $r['id'], $supers);
$keepOrgIds = [];
foreach ($supers as $super) {
    if ($super['org_id'] === null || $super['org_id'] === '') {
        fwrite(STDERR, "ERROR: super 계정 {$super['login_id']} 에 org_id 가 없습니다.\n");
        exit(1);
    }
    $keepOrgIds[(int) $super['org_id']] = true;
}
$keepOrgIds = array_keys($keepOrgIds);

$orgs = db_rows(
    'SELECT id, level, code, name FROM organizations WHERE id IN (' . db_in($keepOrgIds) . ') ORDER BY id',
    $keepOrgIds
);
if (count($orgs) !== count($keepOrgIds)) {
    fwrite(STDERR, "ERROR: super 소속 조직을 찾을 수 없습니다.\n");
    exit(1);
}

reset_log('DB_HOST=' . DB_HOST);
reset_log('DB_NAME=' . DB_NAME);
reset_log('MODE=' . ($execute ? 'EXECUTE' : 'DRY-RUN'));
reset_log('');
reset_log('[KEEP admins]');
foreach ($supers as $super) {
    reset_log(sprintf(
        '  id=%s login_id=%s name=%s org_id=%s',
        $super['id'],
        $super['login_id'],
        $super['name'],
        $super['org_id']
    ));
}
reset_log('[KEEP organizations]');
foreach ($orgs as $org) {
    reset_log(sprintf(
        '  id=%s level=%s code=%s name=%s',
        $org['id'],
        $org['level'],
        $org['code'],
        $org['name']
    ));
}

$adminPlaceholders = db_in($keepAdminIds);
$orgPlaceholders = db_in($keepOrgIds);

$plans = [];
foreach (reset_table_names() as $table) {
    if (in_array($table, KEEP_ALL, true)) {
        $plans[] = [
            'table'  => $table,
            'action' => 'keep_all',
            'before' => reset_count($table),
            'delete' => 0,
            'sql'    => null,
            'params' => [],
        ];
        continue;
    }

    if ($table === 'admins') {
        $delete = reset_count('admins', "id NOT IN ({$adminPlaceholders})", $keepAdminIds);
        $plans[] = [
            'table'  => $table,
            'action' => 'keep_super',
            'before' => reset_count($table),
            'delete' => $delete,
            'sql'    => "DELETE FROM admins WHERE id NOT IN ({$adminPlaceholders})",
            'params' => $keepAdminIds,
        ];
        continue;
    }

    if ($table === 'organizations') {
        $delete = reset_count('organizations', "id NOT IN ({$orgPlaceholders})", $keepOrgIds);
        $plans[] = [
            'table'  => $table,
            'action' => 'keep_hq',
            'before' => reset_count($table),
            'delete' => $delete,
            'sql'    => "DELETE FROM organizations WHERE id NOT IN ({$orgPlaceholders})",
            'params' => $keepOrgIds,
        ];
        continue;
    }

    if ($table === 'org_fee_config' && reset_has_column($table, 'org_id')) {
        $delete = reset_count($table, "org_id NOT IN ({$orgPlaceholders})", $keepOrgIds);
        $plans[] = [
            'table'  => $table,
            'action' => 'keep_hq_config',
            'before' => reset_count($table),
            'delete' => $delete,
            'sql'    => "DELETE FROM `{$table}` WHERE org_id NOT IN ({$orgPlaceholders})",
            'params' => $keepOrgIds,
        ];
        continue;
    }

    if (in_array($table, ['deduction_global_config', 'withdrawal_config', 'settlement_excel_config'], true)
        && reset_has_column($table, 'org_id')
    ) {
        $delete = reset_count(
            $table,
            "org_id IS NOT NULL AND org_id NOT IN ({$orgPlaceholders})",
            $keepOrgIds
        );
        $plans[] = [
            'table'  => $table,
            'action' => 'keep_global_or_hq',
            'before' => reset_count($table),
            'delete' => $delete,
            'sql'    => "DELETE FROM `{$table}` WHERE org_id IS NOT NULL AND org_id NOT IN ({$orgPlaceholders})",
            'params' => $keepOrgIds,
        ];
        continue;
    }

    $before = reset_count($table);
    $plans[] = [
        'table'  => $table,
        'action' => 'delete_all',
        'before' => $before,
        'delete' => $before,
        'sql'    => "DELETE FROM `{$table}`",
        'params' => [],
    ];
}

reset_log('');
reset_log(sprintf('%-32s %-18s %8s %8s', 'table', 'action', 'before', 'delete'));
reset_log(str_repeat('-', 70));
$totalDelete = 0;
foreach ($plans as $plan) {
    $totalDelete += $plan['delete'];
    reset_log(sprintf(
        '%-32s %-18s %8d %8d',
        $plan['table'],
        $plan['action'],
        $plan['before'],
        $plan['delete']
    ));
}
reset_log(str_repeat('-', 70));
reset_log('TOTAL_DELETE_ROWS=' . $totalDelete);

if (!$execute) {
    reset_log('');
    reset_log('미리보기만 수행했습니다. 실제 삭제하려면:');
    reset_log('  php scripts/reset_operational_data.php --execute');
    exit(0);
}

$pdo = db();
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
try {
    foreach ($plans as $plan) {
        if ($plan['sql'] === null) {
            continue;
        }
        if ($plan['delete'] === 0 && $plan['action'] !== 'delete_all') {
            continue;
        }
        db_execute($plan['sql'], $plan['params']);
    }
} finally {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

foreach ($plans as $plan) {
    if ($plan['action'] !== 'delete_all') {
        continue;
    }
    try {
        $pdo->exec("ALTER TABLE `{$plan['table']}` AUTO_INCREMENT = 1");
    } catch (\Throwable $e) {
        reset_log('WARN AUTO_INCREMENT ' . $plan['table'] . ': ' . $e->getMessage());
    }
}

reset_log('');
reset_log('[AFTER]');
reset_log('admins=' . reset_count('admins'));
reset_log('organizations=' . reset_count('organizations'));
reset_log('riders=' . (db_table_exists('riders') ? reset_count('riders') : 0));
reset_log('settlement_uploads=' . (db_table_exists('settlement_uploads') ? reset_count('settlement_uploads') : 0));
reset_log('withdrawal_requests=' . (db_table_exists('withdrawal_requests') ? reset_count('withdrawal_requests') : 0));
reset_log('audit_logs=' . (db_table_exists('audit_logs') ? reset_count('audit_logs') : 0));
reset_log('system_codes=' . reset_count('system_codes'));
reset_log('role_permissions=' . reset_count('role_permissions'));

$leftAdmins = db_rows('SELECT id, login_id, role, org_id FROM admins ORDER BY id');
foreach ($leftAdmins as $admin) {
    if (($admin['role'] ?? '') !== 'super') {
        fwrite(STDERR, "ERROR: super 이외 계정이 남아 있습니다: {$admin['login_id']}\n");
        exit(1);
    }
}
if (db_table_exists('riders') && reset_count('riders') > 0) {
    fwrite(STDERR, "ERROR: riders 데이터가 남아 있습니다.\n");
    exit(1);
}

reset_log('');
reset_log('OK 운영 데이터 초기화 완료. 최고 관리자로 다시 로그인하세요.');
