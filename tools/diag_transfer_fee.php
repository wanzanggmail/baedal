<?php

declare(strict_types=1);

/**
 * 이체수수료 부담 주체 진단 (2026-09-24)
 *
 *   사용:  php tools/diag_transfer_fee.php          최근 10건
 *          php tools/diag_transfer_fee.php --id=123 한 건만
 *
 * "설정은 라이더 부담인데 대리점 지갑에서 수수료가 빠졌다"를 가릴 때 쓴다.
 * 출금 건에 박힌 부담 주체 · 지금 대리점 설정값 · 지갑 원장 차감 줄을 나란히 찍는다.
 * **읽기 전용.**
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';

$n  = static fn ($v): string => number_format((int) $v);
$id = 0;
foreach ($argv as $a) {
    if (preg_match('/^--id=(\d+)$/', $a, $m)) {
        $id = (int) $m[1];
    }
}

$rows = db_rows(
    'SELECT wr.id, wr.kind, wr.status, wr.rider_id, COALESCE(wr.agency_id, r.agency_id) AS agency_id,
            wr.gross_amount, wr.amount, wr.withhold_min_retain, wr.withhold_other, wr.withhold_transfer_fee,
            wr.settle_fee_payer, wr.transfer_fee_payer, wr.requested_at,
            r.name AS rider_name, o.name AS agency_name,
            o.agency_fee_payer AS org_settle_payer, o.transfer_fee_payer AS org_transfer_payer
       FROM withdrawal_requests wr
       LEFT JOIN riders r ON r.id = wr.rider_id
       LEFT JOIN organizations o ON o.id = COALESCE(wr.agency_id, r.agency_id)'
    . ($id > 0 ? ' WHERE wr.id = ?' : '')
    . ' ORDER BY wr.id DESC LIMIT ' . ($id > 0 ? '1' : '10'),
    $id > 0 ? [$id] : []
);

echo "이체수수료 부담 주체 진단 — DB: " . DB_NAME . " · " . date('Y-m-d H:i') . "\n";

foreach ($rows as $w) {
    $wid = (int) $w['id'];
    printf(
        "\n#%d %s [%s] %s · %s\n  잔액 %s → 지급 %s · 보증금 %s · 정산수수료 %s · 이체수수료 %s\n",
        $wid,
        (string) $w['kind'],
        (string) $w['status'],
        (string) ($w['rider_name'] ?? '?'),
        (string) ($w['agency_name'] ?? '?'),
        $n($w['gross_amount']), $n($w['amount']), $n($w['withhold_min_retain']),
        $n($w['withhold_other']), $n($w['withhold_transfer_fee'])
    );

    // 출금에 박힌 값 vs 지금 대리점 설정 — 다르면 신청 후 설정이 바뀐 것이다.
    foreach ([['정산수수료', 'settle_fee_payer', 'org_settle_payer'], ['이체수수료', 'transfer_fee_payer', 'org_transfer_payer']] as [$label, $k, $ok]) {
        printf(
            "  %s 부담: 출금기록=%s · 현재설정=%s%s\n",
            $label,
            (string) $w[$k],
            (string) ($w[$ok] ?? '(컬럼없음)'),
            (string) $w[$k] !== (string) ($w[$ok] ?? '') ? '  ← 불일치' : ''
        );
    }

    // 지급액이 수수료를 뺀 값인지 역산 — 라이더 부담이면 지급 = 잔액 − 보증금 − 수수료 2종.
    $riderFee   = (string) $w['settle_fee_payer']   === 'agency' ? 0 : (int) $w['withhold_other'];
    $riderTrans = (string) $w['transfer_fee_payer'] === 'agency' ? 0 : (int) $w['withhold_transfer_fee'];
    printf("  검산: 지급 %s / 라이더 부담분 정산 %s + 이체 %s\n", $n($w['amount']), $n($riderFee), $n($riderTrans));

    if (db_table_exists('agency_wallet_ledger')) {
        foreach (db_rows(
            'SELECT direction, reason, amount, balance_after, note FROM agency_wallet_ledger
              WHERE ref_id = ? AND agency_id = ? ORDER BY id ASC',
            [$wid, (int) $w['agency_id']]
        ) as $l) {
            printf(
                "    대리점원장 %s %s %s → 잔액 %s · %s\n",
                (string) $l['direction'] === 'debit' ? '-' : '+',
                $n($l['amount']),
                (string) $l['reason'],
                $n($l['balance_after']),
                (string) $l['note']
            );
        }
    }
}

echo "\n";
