<?php

declare(strict_types=1);

/**
 * 조직(총판·대리점) 삭제 — 안전 점검 후 (2026-09-04)
 *
 *   미리보기:   php tools/delete_org.php AG-01
 *   실제 삭제:   php tools/delete_org.php AG-01 --apply
 *   계정까지:    php tools/delete_org.php AG-01 --apply --with-accounts
 *
 * 관리자 화면에는 삭제 기능이 **일부러** 없다(비활성화만 가능) — 조직 id 는 지갑·원장·
 * 라이더·정산에 그대로 박혀 있어서 지우면 과거 기록의 소속을 잃는다. 그래서 이 도구는
 * **아직 아무 실적이 없는 조직**(seed 샘플, 잘못 만든 조직)만 지운다.
 *
 *  - 돈/실적이 1건이라도 있으면 → 삭제 거부하고 무엇이 걸렸는지 알려준다(비활성화 권장).
 *  - 설정성 부속 행(지갑·출금설정·수수료설정 등)만 함께 정리한다.
 *  - 하위 조직이 있으면 자식부터 지우라고 알려준다.
 *  - 본사(admin)·세무대리(tax_agent)는 시스템 기준 조직이라 삭제 불가.
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';

$args   = array_slice($argv, 1);
$apply  = in_array('--apply', $args, true);
$withAc = in_array('--with-accounts', $args, true);
$key    = '';
foreach ($args as $a) {
    if (!str_starts_with($a, '--')) { $key = $a; break; }
}

if ($key === '') {
    fwrite(STDERR, "사용법: php tools/delete_org.php <조직코드|id> [--apply] [--with-accounts]\n");
    exit(2);
}

$n   = static fn ($v): string => number_format((int) $v);
$org = ctype_digit($key)
    ? db_row('SELECT * FROM organizations WHERE id = ?', [(int) $key])
    : db_row('SELECT * FROM organizations WHERE code = ?', [$key]);

if ($org === null) {
    fwrite(STDERR, "조직을 찾을 수 없습니다: {$key}\n");
    exit(1);
}

$id    = (int) $org['id'];
$level = (string) $org['level'];

echo "조직 삭제 — DB: " . DB_NAME . "\n";
echo str_repeat('-', 74) . "\n";
printf("  대상: #%d [%s] %s (%s)%s\n", $id, (string) $org['code'], (string) $org['name'], $level, $apply ? '' : '  ※ 미리보기');
echo str_repeat('-', 74) . "\n";

$blocked = [];

if ($level === 'admin' || $level === 'tax_agent') {
    $blocked[] = "본사/세무대리는 시스템 기준 조직이라 삭제할 수 없습니다";
}

// ── 하위 조직 ──────────────────────────────────────────────────────────────
$children = db_rows('SELECT id, code, name, level FROM organizations WHERE parent_id = ?', [$id]);
if ($children !== []) {
    $names = [];
    foreach ($children as $c) { $names[] = "#{$c['id']} {$c['name']}"; }
    $blocked[] = '하위 조직 ' . count($children) . '개 — 자식부터 지우세요: ' . implode(', ', $names);
}

// ── 실적/돈 데이터 (1건이라도 있으면 삭제 불가) ─────────────────────────────
$guards = [
    'riders'                      => ['agency_id', '라이더'],
    'settlement_uploads'          => ['agency_id', '정산 업로드'],
    'settlement_weekly_riders'    => ['agency_id', '주간 정산'],
    'promotion_batches'           => ['agency_id', '프로모션 배치'],
    'withdrawal_requests'         => ['agency_id', '출금 요청'],
    'pg_payments'                 => ['agency_id', 'PG 결제'],
    'firm_transfers'              => ['agency_id', '펌뱅킹 이체'],
    'tax_withholding_collections' => ['agency_id', '원천세 수집'],
    'message_queue'               => ['agency_id', '메시지 발송'],
    'agency_wallet_ledger'        => ['agency_id', '지갑 원장'],
];
foreach ($guards as $table => [$col, $label]) {
    if (!db_table_exists($table)) { continue; }
    $c = (int) (db_row("SELECT COUNT(*) AS c FROM {$table} WHERE {$col} = ?", [$id])['c'] ?? 0);
    if ($c > 0) { $blocked[] = "{$label} {$n($c)}건 ({$table})"; }
}

// ── 지갑 잔액 ──────────────────────────────────────────────────────────────
$w = db_table_exists('agency_wallets')
    ? db_row('SELECT balance, withholding_reserve, insurance_reserve FROM agency_wallets WHERE agency_id = ?', [$id])
    : null;
if ($w !== null) {
    $sum = (int) $w['balance'] + (int) $w['withholding_reserve'] + (int) $w['insurance_reserve'];
    if ($sum !== 0) {
        $blocked[] = sprintf(
            '지갑에 돈이 남아 있음 — 잔액 %s / 원천세예수금 %s / 보험예수금 %s',
            $n($w['balance']), $n($w['withholding_reserve']), $n($w['insurance_reserve'])
        );
    }
}

// ── 관리자 계정 ────────────────────────────────────────────────────────────
$accounts = db_table_exists('admins')
    ? db_rows('SELECT id, login_id, name, role FROM admins WHERE org_id = ?', [$id])
    : [];
if ($accounts !== [] && !$withAc) {
    $ids = [];
    foreach ($accounts as $a) { $ids[] = "{$a['login_id']}({$a['name']})"; }
    $blocked[] = '소속 관리자 계정 ' . count($accounts) . '개 — 함께 지우려면 --with-accounts: ' . implode(', ', $ids);
}

if ($blocked !== []) {
    echo "  삭제할 수 없습니다:\n";
    foreach ($blocked as $b) { echo "    ✗ {$b}\n"; }
    echo "\n  → 위 항목을 먼저 정리하거나, 실적이 있는 조직이라면 지우지 말고 **비활성화**하세요.\n";
    echo "     관리자 > 시스템 > 조직관리 에서 활성 토글을 끄면 목록·선택에서 빠집니다.\n";
    exit(1);
}

// ── 함께 정리할 설정성 부속 행 ─────────────────────────────────────────────
$cascade = [
    'agency_wallets'          => 'agency_id',
    'agency_bank_accounts'    => 'agency_id',
    'agency_cards'            => 'agency_id',
    'withdrawal_config'       => 'org_id',
    'org_fee_config'          => 'org_id',
    'deduction_global_config' => 'org_id',
    'settlement_excel_config' => 'org_id',
    'content_banners'         => 'org_id',
    'content_notices'         => 'org_id',
];

echo "  함께 삭제될 부속 데이터:\n";
$plan = [];
foreach ($cascade as $table => $col) {
    if (!db_table_exists($table)) { continue; }
    $c = (int) (db_row("SELECT COUNT(*) AS c FROM {$table} WHERE {$col} = ?", [$id])['c'] ?? 0);
    if ($c > 0) { $plan[$table] = $col; printf("    - %-26s %s건\n", $table, $n($c)); }
}
foreach ($accounts as $a) {
    printf("    - %-26s %s (%s)\n", 'admins', (string) $a['login_id'], (string) $a['name']);
}
if ($plan === [] && $accounts === []) { echo "    (없음)\n"; }

if (!$apply) {
    echo "\n  실제로 지우려면:  php tools/delete_org.php {$key} --apply"
        . ($accounts !== [] ? ' --with-accounts' : '') . "\n";
    exit(0);
}

db_transaction(static function () use ($plan, $id, $withAc): void {
    foreach ($plan as $table => $col) {
        db_execute("DELETE FROM {$table} WHERE {$col} = ?", [$id]);
    }
    if ($withAc && db_table_exists('admins')) {
        db_execute('DELETE FROM admins WHERE org_id = ?', [$id]);
    }
    db_execute('DELETE FROM organizations WHERE id = ?', [$id]);
});

echo "\n  삭제 완료 — org#{$id} 및 부속 데이터를 제거했습니다.\n";
echo "  검증: php tools/audit_money.php\n";
