<?php

declare(strict_types=1);

/**
 * 정산수수료 배분 — 최저 금액에 미달한 대리점을 전역 기본값으로 맞춘다 (2026-09-07)
 *
 *   미리보기:  php tools/fix_fee_share_minimum.php
 *   실제 적용: php tools/fix_fee_share_minimum.php --apply
 *
 * 갑: "지금 대행수수료 최저 금액에 맞지 않는 대리점들은 그거에 맞춰 조정해줘"
 *
 * **하한이 걸리는 대상**은 「본사 몫」 = `본사 + 세무대리 + 개발사` 합계다(2026-09-06 갑).
 * 최저 금액은 `deduction_global_config.agency_fee_min_short/long` 에 있다.
 *
 * ⚠️ 원래 이 시스템은 **하한을 올려도 이미 낮게 설정된 대리점을 말없이 바꾸지 않는다**
 *    (남의 요율을 임의로 손대지 않기 위해). 이 도구는 갑이 명시적으로 지시했을 때만 쓴다.
 *
 * 맞추는 값은 **전역 기본값 그대로**다 — 본사 몫만 하한까지 끌어올리면 세무대리·개발사
 * 몫이 0으로 남아 그쪽이 못 받게 된다. 배분 비율 자체를 전역과 같게 복사한다.
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once INC_PATH . '/AgencyFeeConfig.php';
require_once INC_PATH . '/AuditLog.php';

$apply = in_array('--apply', array_slice($argv, 1), true);
$n     = static fn ($v): string => number_format((int) $v);
$line  = static function (): void { echo str_repeat('-', 78) . "\n"; };

$COLS = [
    'hq_fee_short', 'tax_fee_short', 'dev_fee_short', 'dist_fee_short',
    'hq_fee_long',  'tax_fee_long',  'dev_fee_long',  'dist_fee_long',
];

echo "정산수수료 배분 하한 조정 — DB: " . DB_NAME . "\n";
$line();

$min = AgencyFeeConfig::minimums();
printf("  최저 금액: 기준미만 %s원 · 기준이상 %s원%s\n",
    $n($min['fee_per_tx_short']), $n($min['fee_per_tx_long']), $apply ? '' : '   ※ 미리보기');

if ($min['fee_per_tx_short'] <= 0 && $min['fee_per_tx_long'] <= 0) {
    echo "  최저 금액이 0(하한 없음)이라 조정할 대상이 없습니다.\n";
    exit(0);
}

$global = db_row('SELECT * FROM withdrawal_config WHERE org_id IS NULL ORDER BY id ASC LIMIT 1');
if ($global === null) {
    fwrite(STDERR, "  전역 기본값 행이 없습니다. php migrate.php 를 먼저 실행하세요.\n");
    exit(1);
}

$gShort = (int) $global['hq_fee_short'] + (int) $global['tax_fee_short'] + (int) $global['dev_fee_short'];
$gLong  = (int) $global['hq_fee_long'] + (int) $global['tax_fee_long'] + (int) $global['dev_fee_long'];
printf("  전역 기본값: 미만 %d+%d+%d = %s원 · 이상 %d+%d+%d = %s원\n",
    $global['hq_fee_short'], $global['tax_fee_short'], $global['dev_fee_short'], $n($gShort),
    $global['hq_fee_long'], $global['tax_fee_long'], $global['dev_fee_long'], $n($gLong));

// 전역값 자체가 하한 미달이면 그걸로 맞춰봐야 여전히 미달이다 — 먼저 알려주고 멈춘다.
if (($min['fee_per_tx_short'] > 0 && $gShort < $min['fee_per_tx_short'])
    || ($min['fee_per_tx_long'] > 0 && $gLong < $min['fee_per_tx_long'])) {
    fwrite(STDERR, "\n  ⚠️ 전역 기본값이 최저 금액보다 낮습니다. 이 값으로 맞추면 여전히 미달입니다.\n");
    fwrite(STDERR, "     「수수료 설정(관리)」에서 전역 기본값부터 올린 뒤 다시 실행하세요.\n");
    exit(2);
}
$line();

$rows = db_rows(
    'SELECT w.id, w.org_id, o.name,
            w.hq_fee_short, w.tax_fee_short, w.dev_fee_short, w.dist_fee_short,
            w.hq_fee_long,  w.tax_fee_long,  w.dev_fee_long,  w.dist_fee_long
       FROM withdrawal_config w
       JOIN organizations o ON o.id = w.org_id
      WHERE w.org_id IS NOT NULL
      ORDER BY o.name ASC'
);

$targets = [];
foreach ($rows as $r) {
    $s = (int) $r['hq_fee_short'] + (int) $r['tax_fee_short'] + (int) $r['dev_fee_short'];
    $l = (int) $r['hq_fee_long'] + (int) $r['tax_fee_long'] + (int) $r['dev_fee_long'];
    $below = ($min['fee_per_tx_short'] > 0 && $s < $min['fee_per_tx_short'])
          || ($min['fee_per_tx_long'] > 0 && $l < $min['fee_per_tx_long']);
    if ($below) {
        $targets[] = $r + ['sum_short' => $s, 'sum_long' => $l];
    }
}

if ($targets === []) {
    echo "  미달한 대리점이 없습니다.\n";
    exit(0);
}

printf("  ■ 미달 대리점 %d곳 — 전역 기본값으로 맞춥니다\n\n", count($targets));
printf("     %-16s %-22s %-22s\n", '대리점', '기준미만 (현재 → 변경)', '기준이상 (현재 → 변경)');
foreach ($targets as $t) {
    printf("     %-16s %3s → %-18s %3s → %s\n",
        mb_substr((string) $t['name'], 0, 8),
        $n($t['sum_short']), $n($gShort) . sprintf(' (%d+%d+%d)', $global['hq_fee_short'], $global['tax_fee_short'], $global['dev_fee_short']),
        $n($t['sum_long']), $n($gLong) . sprintf(' (%d+%d+%d)', $global['hq_fee_long'], $global['tax_fee_long'], $global['dev_fee_long']));
}
$line();

if (!$apply) {
    echo "  미리보기입니다. 실제로 바꾸려면:\n";
    echo "     php tools/fix_fee_share_minimum.php --apply\n";
    exit(0);
}

$set    = implode(' = ?, ', $COLS) . ' = ?';
$values = [];
foreach ($COLS as $c) {
    $values[] = (int) $global[$c];
}

$done = 0;
db_execute('START TRANSACTION');
try {
    foreach ($targets as $t) {
        db_execute(
            "UPDATE withdrawal_config SET {$set}, updated_at = NOW() WHERE id = ?",
            array_merge($values, [(int) $t['id']])
        );
        // 남의 요율을 바꾸는 일이라 누가 언제 무엇을 바꿨는지 반드시 남긴다.
        AuditLog::record(
            'withdrawal.fee_share.min_fix',
            'withdrawal_config',
            sprintf(
                '[%s] 최저 금액 미달 조정 — 본사몫 미만 %d→%d원 / 이상 %d→%d원 (전역 기본값으로)',
                (string) $t['name'], $t['sum_short'], $gShort, $t['sum_long'], $gLong
            )
        );
        $done++;
    }
    db_execute('COMMIT');
} catch (Throwable $e) {
    db_execute('ROLLBACK');
    fwrite(STDERR, "\n  실패 — 롤백했습니다: " . $e->getMessage() . "\n");
    exit(1);
}

printf("  완료 — %d곳 조정\n\n", $done);

// ── 검증: 정말 다 맞았는지 다시 읽는다 ──
$still = 0;
foreach (db_rows(
    'SELECT o.name,
            (w.hq_fee_short + w.tax_fee_short + w.dev_fee_short) s,
            (w.hq_fee_long  + w.tax_fee_long  + w.dev_fee_long)  l
       FROM withdrawal_config w JOIN organizations o ON o.id = w.org_id
      WHERE w.org_id IS NOT NULL'
) as $r) {
    if (($min['fee_per_tx_short'] > 0 && (int) $r['s'] < $min['fee_per_tx_short'])
        || ($min['fee_per_tx_long'] > 0 && (int) $r['l'] < $min['fee_per_tx_long'])) {
        printf("  [남음] %s — 미만 %s / 이상 %s\n", $r['name'], $n($r['s']), $n($r['l']));
        $still++;
    }
}
echo $still === 0 ? "  검증: 미달 대리점 0곳\n" : "  검증: 아직 {$still}곳 미달\n";
