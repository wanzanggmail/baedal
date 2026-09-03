<?php

declare(strict_types=1);

/**
 * 지갑 원장 보정 (2026-09-04)
 *
 *   미리보기:  php tools/reconcile_ledger.php
 *   실제 반영:  php tools/reconcile_ledger.php --apply
 *
 * 과거 `AgencyWallet::move()` 가 비원자적이던 시절(잔액 UPDATE 와 원장 INSERT 가 따로 실행)
 * 잔액은 바뀌었는데 원장 행이 안 남은 구간이 있다. 그 차액을 `ledger_fix` 원장 행으로
 * **사후 기록**해 `balance == 원장합` 불변식을 복구한다.
 *
 * ⚠️ **잔액(balance)은 절대 건드리지 않는다** — 돈은 그대로 두고 감사추적만 채운다.
 * 멱등: 반영 후 차액이 0이 되므로 다시 돌려도 아무 일도 하지 않는다.
 * 검증은 `php tools/audit_money.php` 로.
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once INC_PATH . '/AgencyWallet.php';

$apply = in_array('--apply', $argv, true);
$n     = static fn ($v): string => number_format((int) $v);

echo "지갑 원장 보정 — DB: " . DB_NAME . " · " . ($apply ? '실제 반영' : '미리보기(반영 안 함)') . "\n";
echo str_repeat('-', 74) . "\n";

$rows = db_rows(
    "SELECT w.agency_id AS aid, o.name, o.level, w.balance AS bal,
            COALESCE((SELECT SUM(CASE WHEN l.direction='credit' THEN l.amount ELSE -l.amount END)
                        FROM agency_wallet_ledger l WHERE l.agency_id = w.agency_id),0) AS led
       FROM agency_wallets w
       LEFT JOIN organizations o ON o.id = w.agency_id"
);

$targets = [];
foreach ($rows as $r) {
    $diff = (int) $r['bal'] - (int) $r['led'];
    if ($diff !== 0) {
        $targets[] = $r + ['diff' => $diff];
    }
}

if ($targets === []) {
    echo "  보정할 대상이 없습니다 (전 조직 balance == 원장합).\n";
    exit(0);
}

$total = 0;
foreach ($targets as $t) {
    $dir = $t['diff'] > 0 ? 'credit' : 'debit';
    printf(
        "  org#%-3d %-16s 잔액 %14s / 원장합 %14s → 보정 %s%s (%s)\n",
        $t['aid'],
        (string) ($t['name'] ?? '?'),
        $n($t['bal']),
        $n($t['led']),
        $t['diff'] > 0 ? '+' : '-',
        $n(abs($t['diff'])),
        $dir
    );
    $total += abs($t['diff']);
}
echo str_repeat('-', 74) . "\n";
echo '  대상 ' . count($targets) . "개 조직 · 보정 총액 {$n($total)}원 (잔액 변동 없음)\n";

if (!$apply) {
    echo "\n  반영하려면:  php tools/reconcile_ledger.php --apply\n";
    exit(0);
}

// 잔액은 그대로 두고 원장 행만 삽입한다 — AgencyWallet::move() 는 잔액을 바꾸므로 쓰지 않는다.
db_transaction(static function () use ($targets): void {
    foreach ($targets as $t) {
        $aid  = (int) $t['aid'];
        $diff = (int) $t['diff'];
        db_insert(
            'INSERT INTO agency_wallet_ledger
                (agency_id, direction, reason, amount, balance_after, ref_id, note, created_by, created_at)
             VALUES (?, ?, \'ledger_fix\', ?, ?, NULL, ?, NULL, NOW())',
            [
                $aid,
                $diff > 0 ? 'credit' : 'debit',
                abs($diff),
                (int) $t['bal'],   // 보정 후 잔액 = 현재 잔액(변동 없음)
                '원장 보정 — 과거 move() 비원자성으로 누락된 원장분 사후 기록(잔액 불변)',
            ]
        );
    }
});

echo "\n  반영 완료. 검증: php tools/audit_money.php\n";
