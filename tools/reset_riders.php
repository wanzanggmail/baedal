<?php

declare(strict_types=1);

/**
 * 라이더 전체 삭제 — 다시 등록하기 위한 초기화 (2026-09-06)
 *
 *   미리보기(기본):  php tools/reset_riders.php
 *   대리점만:        php tools/reset_riders.php --agency=DKKB-0001
 *   실제 삭제:       php tools/reset_riders.php --apply --yes-delete-riders
 *
 * ⚠️ **`riders` 테이블만 지우면 안 된다.** 라이더 id 는 20개 넘는 테이블에 박혀 있고,
 * 그중 절반은 외래키가 없어서 DB 가 막아주지도 정리해주지도 않는다:
 *
 *  - **CASCADE 6개**(지갑·채무·차감·정산사이클·출금신청·플랫폼연결) — 말없이 함께 사라진다.
 *    지웠다는 사실조차 화면에 안 남는다.
 *  - **SET NULL 6개**(정산 원본·주문상세·지원금·주급공제·시간보험 등) — 행은 남고
 *    `rider_id` 만 NULL 이 된다. **누구 것인지 영영 알 수 없는 고아 데이터**가 된다.
 *  - **외래키 없음 7개**(pg_payments·rider_debt_entries·statement_links·firm_transfers·
 *    message_queue·message_send_logs·rider_carry_forward) — id 가 **그대로 남는다**.
 *    새 라이더가 같은 id 를 받으면 **남의 결제·명세서·문자가 그 사람에게 붙는다.**
 *    이게 가장 위험하다.
 *
 * 그래서 이 도구는 라이더에 딸린 것을 **자식부터 순서대로 전부** 지운다. 되돌릴 수 없으므로
 * 기본은 미리보기이고, 실제 삭제에는 `--apply` 와 `--yes-delete-riders` 를 **둘 다** 요구한다.
 *
 * 지우지 않는 것: 조직·관리자 계정·수수료/출금 설정·코드마스터·대리점 지갑(agency_wallets).
 * 정산 업로드 이력(settlement_uploads)은 `--with-uploads` 를 줄 때만 지운다.
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';

$args        = array_slice($argv, 1);
$apply       = in_array('--apply', $args, true);
$confirmed   = in_array('--yes-delete-riders', $args, true);
$withUploads = in_array('--with-uploads', $args, true);
$withUnmatch = in_array('--with-unmatched', $args, true);

$agencyKey = '';
foreach ($args as $a) {
    if (str_starts_with($a, '--agency=')) {
        $agencyKey = substr($a, 9);
    }
}

$n    = static fn ($v): string => number_format((int) $v);
$line = static function (): void { echo str_repeat('-', 74) . "\n"; };

// ── 대상 라이더 ───────────────────────────────────────────────────────────────
$scopeLabel = '전체 대리점';
$where      = '1';
$params     = [];

if ($agencyKey !== '') {
    $org = ctype_digit($agencyKey)
        ? db_row('SELECT * FROM organizations WHERE id = ?', [(int) $agencyKey])
        : db_row('SELECT * FROM organizations WHERE code = ?', [$agencyKey]);
    if ($org === null) {
        fwrite(STDERR, "대리점을 찾을 수 없습니다: {$agencyKey}\n");
        exit(1);
    }
    $where      = 'agency_id = ?';
    $params     = [(int) $org['id']];
    $scopeLabel = sprintf('#%d [%s] %s', (int) $org['id'], (string) $org['code'], (string) $org['name']);
}

$ids = array_map('intval', array_column(db_rows("SELECT id FROM riders WHERE {$where}", $params), 'id'));

echo "라이더 초기화 — DB: " . DB_NAME . "\n";
$line();
printf("  대상: %s · 라이더 %s명%s\n", $scopeLabel, $n(count($ids)), $apply ? '' : '   ※ 미리보기(아무것도 지우지 않음)');
$line();

if ($ids === []) {
    echo "  지울 라이더가 없습니다.\n";
    exit(0);
}

$in = implode(',', $ids); // 위에서 intval 로 정규화한 값만 들어간다

// ── 사라지는 돈 ───────────────────────────────────────────────────────────────
$wallet = db_row("SELECT COUNT(*) c, COALESCE(SUM(balance),0) b FROM rider_wallets WHERE rider_id IN ({$in})");
$debt   = db_row("SELECT COUNT(*) c, COALESCE(SUM(balance_amount),0) b FROM rider_debts WHERE rider_id IN ({$in}) AND balance_amount > 0");
$pg     = db_row("SELECT COUNT(*) c, COALESCE(SUM(total_charged),0) b FROM pg_payments WHERE rider_id IN ({$in}) AND status = 'success'");
$wd     = db_row("SELECT COUNT(*) c, COALESCE(SUM(amount),0) b FROM withdrawal_requests WHERE rider_id IN ({$in})");
$agWal  = db_row('SELECT COALESCE(SUM(balance),0) b FROM agency_wallets');

echo "  ■ 함께 사라지는 돈\n";
printf("     라이더 지갑 잔액   %3s개 · %14s원\n", $n($wallet['c']), $n($wallet['b']));
printf("     미상환 채무        %3s건 · %14s원\n", $n($debt['c']), $n($debt['b']));
printf("     PG 결제(성공)      %3s건 · %14s원\n", $n($pg['c']), $n($pg['b']));
printf("     출금 신청          %3s건 · %14s원\n", $n($wd['c']), $n($wd['b']));
echo "\n";
printf("     ※ 대리점 지갑(agency_wallets) %s원은 **그대로 남습니다.**\n", $n($agWal['b']));
echo "        라이더 지갑은 대리점이 라이더에게 갚을 돈이라, 라이더 쪽만 지우면\n";
echo "        원장이 어긋납니다. 실서버라면 지우기 전에 반드시 백업하세요.\n";
$line();

// ── 지울 순서 (자식 → 부모) ───────────────────────────────────────────────────
// 외래키가 CASCADE 든 SET NULL 이든 **명시적으로** 지운다. DB 규칙에 맡기면 SET NULL 쪽이
// 고아로 남고, 외래키 없는 테이블은 아예 손도 안 탄다.
$steps = [
    ['settlement_fee_items',         "cycle_id IN (SELECT id FROM settlement_rider_cycles WHERE rider_id IN ({$in}))"],
    ['withdrawal_request_cycles',    "cycle_id IN (SELECT id FROM settlement_rider_cycles WHERE rider_id IN ({$in}))"
                                     . " OR request_id IN (SELECT id FROM withdrawal_requests WHERE rider_id IN ({$in}))"],
    ['rider_debt_entries',           "rider_id IN ({$in}) OR debt_id IN (SELECT id FROM rider_debts WHERE rider_id IN ({$in}))"],
    ['rider_carry_forward',          "rider_id IN ({$in})"],
    ['settlement_rider_cycles',      "rider_id IN ({$in})"],
    ['withdrawal_requests',          "rider_id IN ({$in})"],
    ['rider_debts',                  "rider_id IN ({$in})"],
    ['rider_wallets',                "rider_id IN ({$in})"],
    ['rider_platforms',              "rider_id IN ({$in})"],
    ['deduction_entries',            "rider_id IN ({$in})"],
    ['promotion_entries',            "rider_id IN ({$in})"],
    ['settlement_support_amounts',   "rider_id IN ({$in})"],
    ['settlement_weekly_deductions', "rider_id IN ({$in})"],
    ['settlement_hourly_insurance',  "rider_id IN ({$in})"],
    ['settlement_order_details',     "rider_id IN ({$in})"],
    ['settlement_weekly_riders',     "rider_id IN ({$in})"],
    ['settlement_daily_riders',      "rider_id IN ({$in})"],
    // 외래키가 없어 DB 가 막아주지 않는 것들 — 남기면 새 라이더에게 붙는다
    ['pg_payments',                  "rider_id IN ({$in})"],
    ['firm_transfers',               "rider_id IN ({$in})"],
    ['message_queue',                "rider_id IN ({$in})"],
    ['message_send_logs',            "rider_id IN ({$in})"],
    ['statement_links',              "rider_id IN ({$in})"],
    ['riders',                       "id IN ({$in})"],
];

if ($withUploads) {
    // 라이더가 사라지면 업로드 행은 빈 껍데기가 된다. 요청할 때만 함께 지운다.
    array_splice($steps, -1, 0, [['settlement_uploads', '1']]);
}

echo "  ■ 지울 행\n";
$total = 0;
$plan  = [];
foreach ($steps as [$table, $cond]) {
    if (!db_table_exists($table)) {
        continue;
    }
    $c = (int) db_row("SELECT COUNT(*) c FROM `{$table}` WHERE {$cond}")['c'];
    $plan[] = [$table, $cond, $c];
    $total += $c;
    if ($c > 0) {
        printf("     %-30s %8s 행\n", $table, $n($c));
    }
}
printf("     %-30s %8s 행\n", '(합계)', $n($total));

// 미매칭 행 — 업로드했지만 어느 라이더에도 붙지 못한 정산 원본. 어차피 라이더가 사라지면
// 이것만 덩그러니 남아 통계에 잡힌다. 지울지는 따로 물어본다(정산 원본이라 함부로 안 지운다).
$unmatched = [];
foreach (['settlement_order_details', 'settlement_daily_riders', 'settlement_hourly_insurance', 'pg_payments'] as $t) {
    if (!db_table_exists($t)) {
        continue;
    }
    $c = (int) db_row("SELECT COUNT(*) c FROM `{$t}` WHERE rider_id IS NULL")['c'];
    if ($c > 0) {
        $unmatched[$t] = $c;
    }
}
if ($withUnmatch && $agencyKey !== '') {
    // 미매칭 행은 어느 대리점 것인지 알 수 없다(그래서 미매칭이다). 대리점을 한정한 채
    // 지우면 다른 대리점 것까지 날아간다.
    fwrite(STDERR, "  --with-unmatched 는 --agency 와 같이 쓸 수 없습니다(미매칭 행은 소속을 모릅니다).\n");
    exit(2);
}
if ($withUnmatch && $unmatched !== []) {
    echo "\n  ■ 「미매칭」 행도 함께 지웁니다\n";
    foreach ($unmatched as $t => $c) {
        $plan[] = [$t, 'rider_id IS NULL', $c];
        $total += $c;
        printf("     %-30s %8s 행\n", $t, $n($c));
    }
    printf("     %-30s %8s 행\n", '(총합계)', $n($total));
} elseif ($unmatched !== []) {
    echo "\n  ■ 라이더가 안 붙은 「미매칭」 행 — 위 삭제 대상이 아니라 그대로 남습니다\n";
    foreach ($unmatched as $t => $c) {
        printf("     %-30s %8s 행\n", $t, $n($c));
    }
    echo "     이것까지 비우려면 --with-unmatched 를 함께 주세요.\n";
}
$line();

if (!$apply) {
    echo "  미리보기입니다. 실제로 지우려면:\n";
    echo "     php tools/reset_riders.php" . ($agencyKey !== '' ? " --agency={$agencyKey}" : '')
        . ($withUploads ? ' --with-uploads' : '') . ($withUnmatch ? ' --with-unmatched' : '')
        . " --apply --yes-delete-riders\n";
    exit(0);
}

if (!$confirmed) {
    fwrite(STDERR, "  --apply 만으로는 실행하지 않습니다. --yes-delete-riders 를 함께 주세요.\n");
    exit(2);
}

// ── 실행 ──────────────────────────────────────────────────────────────────────
echo "  삭제 중...\n";
db_execute('START TRANSACTION');
try {
    foreach ($plan as [$table, $cond, $expected]) {
        if ($expected === 0) {
            continue;
        }
        db_execute("DELETE FROM `{$table}` WHERE {$cond}");
        printf("     %-30s %8s 행 삭제\n", $table, $n($expected));
    }
    db_execute('COMMIT');
} catch (Throwable $e) {
    db_execute('ROLLBACK');
    fwrite(STDERR, "\n  실패 — 롤백했습니다: " . $e->getMessage() . "\n");
    exit(1);
}

$line();
printf("  완료 — 라이더 %s명과 딸린 %s행을 지웠습니다.\n", $n(count($ids)), $n($total));
echo "  남은 라이더: " . $n(db_row('SELECT COUNT(*) c FROM riders')['c']) . "명\n";
echo "\n  ※ 라이더 id 는 이어서 발급됩니다(AUTO_INCREMENT 를 되돌리지 않음).\n";
echo "     혹시 남아 있을지 모를 옛 id 참조가 새 라이더에게 붙지 않게 하려는 것입니다.\n";
echo "     이제 「라이더 일괄등록」으로 새로 올리시면 됩니다.\n";
