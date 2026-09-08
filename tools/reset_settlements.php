<?php

declare(strict_types=1);

/**
 * 정산 내역 초기화 — 라이더·조직은 그대로 두고 (2026-09-08)
 *
 *   미리보기:  php tools/reset_settlements.php
 *   실제 삭제: php tools/reset_settlements.php --apply --yes-reset-settlements
 *
 * 갑: "실서버에 라이더 조직 내용 제외하고 정산 내역에 대해서만 초기화 시키고 싶은데"
 *
 * ── 지우는 것 ────────────────────────────────────────────────────────────────
 *   정산 원본·결과 · 차감 · 미수금(계약까지) · 출금 · PG 결제 · 지갑 원장
 *   지갑 잔액은 0 으로, 원천세 예수금도 0 으로.
 *
 * ── 남기는 것 ────────────────────────────────────────────────────────────────
 *   라이더(riders·rider_platforms·계좌·설정) · 조직 · 관리자 계정
 *   수수료/출금/공제 **설정**(withdrawal_config·deduction_global_config·org_fee_config)
 *   코드마스터 · 공지·배너 · 알림톡 템플릿 · 감사 로그
 *
 * ⚠️ **되돌릴 수 없다.** 실서버라면 반드시 DB 백업 후 실행할 것.
 *    기본은 미리보기이고, 실제 삭제에는 `--apply` 와 `--yes-reset-settlements` 를 둘 다 요구한다.
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once INC_PATH . '/AuditLog.php';

$args      = array_slice($argv, 1);
$apply     = in_array('--apply', $args, true);
$confirmed = in_array('--yes-reset-settlements', $args, true);

$n    = static fn ($v): string => number_format((int) $v);
$line = static function (): void { echo str_repeat('-', 78) . "\n"; };

echo "정산 내역 초기화 — DB: " . DB_NAME . "\n";
$line();
printf("  라이더·조직·설정은 그대로 둡니다.%s\n", $apply ? '' : '   ※ 미리보기(아무것도 지우지 않음)');
$line();

// ── 지금 상태 ────────────────────────────────────────────────────────────────
$riders = (int) db_row('SELECT COUNT(*) c FROM riders')['c'];
$orgs   = (int) db_row('SELECT COUNT(*) c FROM organizations')['c'];
printf("  ■ 남는 것: 라이더 %s명 · 조직 %s개 · 수수료/공제 설정 · 코드마스터 · 감사 로그\n\n", $n($riders), $n($orgs));

$rw = db_row('SELECT COALESCE(SUM(balance),0) b FROM rider_wallets');
$aw = db_row('SELECT COALESCE(SUM(balance),0) b, COALESCE(SUM(withholding_reserve),0) r FROM agency_wallets');
$db = db_row('SELECT COUNT(*) c, COALESCE(SUM(balance_amount),0) b FROM rider_debts');
$pg = db_row("SELECT COUNT(*) c, COALESCE(SUM(total_charged),0) s FROM pg_payments WHERE status = 'success'");

echo "  ■ 사라지는 돈\n";
printf("     라이더 지갑 잔액    %16s원 → 0\n", $n($rw['b']));
printf("     대리점 지갑 잔액    %16s원 → 0\n", $n($aw['b']));
printf("     원천세 예수금       %16s원 → 0\n", $n($aw['r']));
printf("     미수금 잔액         %16s원 (%s건, 계약까지 삭제)\n", $n($db['b']), $n($db['c']));
printf("     PG 결제(성공)       %16s원 (%s건)\n", $n($pg['s']), $n($pg['c']));
$line();

// ── 지울 순서 (자식 → 부모) ──────────────────────────────────────────────────
// 외래키가 CASCADE 든 SET NULL 이든 **명시적으로** 지운다. DB 규칙에 맡기면 순서가 꼬이고,
// 외래키 없는 테이블은 아예 손도 안 탄다.
$deletes = [
    // 정산 결과에 딸린 것부터
    ['settlement_fee_items',         '정산 공제 항목'],
    ['withdrawal_request_cycles',    '출금↔정산 연결'],
    ['rider_carry_forward',          '차감 이월'],
    ['rider_debt_entries',           '미수금 상환 이력'],
    ['deduction_entries',            '차감 내역'],
    ['settlement_rider_cycles',      '정산 사이클'],
    // 출금·지급
    ['withdrawal_requests',          '출금 신청'],
    ['firm_transfers',               '펌뱅킹 이체'],
    // 자금조달
    ['pg_payments',                  'PG 결제'],
    // 미수금 계약
    ['rider_debts',                  '미수금 계약'],
    // 정산 원본
    ['settlement_order_details',     '오더별 상세'],
    ['settlement_support_amounts',   '지원금'],
    ['settlement_hourly_insurance',  '시간제 보험'],
    ['settlement_weekly_deductions', '주급 공제'],
    ['settlement_weekly_riders',     '주급 라이더'],
    ['settlement_daily_riders',      '일자별 라이더'],
    ['settlement_uploads',           '업로드 이력'],
    // 지갑 원장·부속
    ['agency_wallet_ledger',         '지갑 입출금 원장'],
    ['statement_links',              '명세서 공개 링크'],
    ['tax_insurance_collections',    '세무대리 수집'],
    ['promotion_entries',            '프로모션 지급'],
    ['promotion_batches',            '프로모션 배치'],
    ['message_queue',                '문자·알림톡 큐'],
    ['message_send_logs',            '문자·알림톡 로그'],
];

echo "  ■ 지울 행\n";
$plan  = [];
$total = 0;
foreach ($deletes as [$table, $label]) {
    if (!db_table_exists($table)) {
        continue;
    }
    $c = (int) db_row("SELECT COUNT(*) c FROM `{$table}`")['c'];
    $plan[] = [$table, $label, $c];
    $total += $c;
    if ($c > 0) {
        printf("     %-30s %-20s %8s 행\n", $table, $label, $n($c));
    }
}
printf("     %-51s %8s 행\n", '(합계)', $n($total));
echo "\n     그 밖에: rider_wallets 잔액·적립일수 0 · agency_wallets 잔액·예수금 0 으로 초기화\n";
$line();

if (!$apply) {
    echo "  미리보기입니다. 실제로 초기화하려면:\n";
    echo "     php tools/reset_settlements.php --apply --yes-reset-settlements\n\n";
    echo "  ⚠️ 되돌릴 수 없습니다. 실서버라면 먼저 DB 를 백업하세요.\n";
    exit(0);
}

if (!$confirmed) {
    fwrite(STDERR, "  --apply 만으로는 실행하지 않습니다. --yes-reset-settlements 를 함께 주세요.\n");
    exit(2);
}

// ── 실행 ─────────────────────────────────────────────────────────────────────
echo "  초기화 중...\n";
db_execute('START TRANSACTION');
try {
    foreach ($plan as [$table, $label, $expected]) {
        if ($expected === 0) {
            continue;
        }
        db_execute("DELETE FROM `{$table}`");
        printf("     %-30s %8s 행 삭제\n", $table, $n($expected));
    }

    // 지갑은 행을 지우지 않는다 — 라이더·조직에 딸린 계정이라 **비우기만** 한다.
    $cols = array_column(db_rows('SHOW COLUMNS FROM rider_wallets'), 'Field');
    $set  = 'balance = 0';
    if (in_array('accrued_days', $cols, true)) {
        $set .= ', accrued_days = 0';
    }
    db_execute("UPDATE rider_wallets SET {$set}, updated_at = NOW()");
    echo "     rider_wallets                  잔액·적립일수 0 으로\n";

    $aCols = array_column(db_rows('SHOW COLUMNS FROM agency_wallets'), 'Field');
    $aSet  = 'balance = 0';
    foreach (['withholding_reserve', 'insurance_reserve'] as $c) {
        if (in_array($c, $aCols, true)) {
            $aSet .= ", {$c} = 0";
        }
    }
    db_execute("UPDATE agency_wallets SET {$aSet}, updated_at = NOW()");
    echo "     agency_wallets                 잔액·예수금 0 으로\n";

    AuditLog::record(
        'system.reset_settlements',
        'settlement',
        sprintf(
            '정산 내역 초기화 — %s행 삭제 · 라이더 지갑 %s원·대리점 지갑 %s원·예수금 %s원 → 0 · 미수금 %s건 삭제',
            $n($total), $n($rw['b']), $n($aw['b']), $n($aw['r']), $n($db['c'])
        )
    );

    db_execute('COMMIT');
} catch (Throwable $e) {
    db_execute('ROLLBACK');
    fwrite(STDERR, "\n  실패 — 롤백했습니다: " . $e->getMessage() . "\n");
    exit(1);
}

$line();
printf("  완료 — %s행 삭제, 지갑 초기화\n\n", $n($total));

// ── 검증 ─────────────────────────────────────────────────────────────────────
echo "  ■ 확인\n";
printf("     라이더           %s명 (그대로)\n", $n(db_row('SELECT COUNT(*) c FROM riders')['c']));
printf("     조직             %s개 (그대로)\n", $n(db_row('SELECT COUNT(*) c FROM organizations')['c']));
printf("     정산 사이클      %s행\n", $n(db_row('SELECT COUNT(*) c FROM settlement_rider_cycles')['c']));
printf("     라이더 지갑 잔액 %s원\n", $n(db_row('SELECT COALESCE(SUM(balance),0) b FROM rider_wallets')['b']));
printf("     대리점 지갑 잔액 %s원\n", $n(db_row('SELECT COALESCE(SUM(balance),0) b FROM agency_wallets')['b']));
echo "\n  이제 정산 엑셀을 처음부터 다시 업로드하시면 됩니다.\n";
