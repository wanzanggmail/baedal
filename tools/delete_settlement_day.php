<?php

declare(strict_types=1);

/**
 * 정산 하루치 되돌리기 — 라이더 1명의 특정 정산일을 **반영 이전 상태로** 돌린다.
 *
 *   미리보기: php tools/delete_settlement_day.php --rider=244 --date=2026-09-21
 *   실행:     php tools/delete_settlement_day.php --rider=244 --date=2026-09-21 --apply
 *   (플랫폼/업로드를 좁히려면 --platform=baemin --upload=22)
 *
 * 단순 DELETE 가 아니다. 정산 반영은 지갑·예수금·차감까지 건드리므로 **그 순서를 거꾸로** 푼다:
 *   ① 라이더 지갑에서 net 회수          ② 대리점 원천세 예수금 회수
 *   ③ 수수료 항목(fee_items) 삭제        ④ 이 사이클이 먹은 차감의 소비 표시 해제(다시 걷히게)
 *   ⑤ 사이클 삭제                        ⑥ 오더별 상세 삭제
 *   ⑦ 업로드 원본행(daily_riders) 삭제
 *
 * ⛔ **출금이 걸려 있으면 거부한다** — 돈이 이미 나간 건은 되돌릴 수 없다.
 * ⛔ **이월(carry forward)이 걸려 있어도 거부한다** — 다른 정산으로 넘어간 금액이라
 *    여기서 되돌리면 그쪽 장부가 어긋난다. 그 경우는 사람이 판단해야 한다.
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once INC_PATH . '/RiderWallet.php';
require_once INC_PATH . '/AgencyWallet.php';

$arg = static function (string $k) use ($argv): ?string {
    foreach ($argv as $a) {
        if (str_starts_with($a, "--{$k}=")) {
            return substr($a, strlen($k) + 3);
        }
    }

    return null;
};
$apply    = in_array('--apply', $argv, true);
$riderId  = (int) ($arg('rider') ?? 0);
$date     = (string) ($arg('date') ?? '');
$platform = $arg('platform');
$uploadId = (int) ($arg('upload') ?? 0);
$n        = static fn ($v): string => number_format((int) $v);

if ($riderId < 1 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    fwrite(STDERR, "사용법: php tools/delete_settlement_day.php --rider=<id> --date=YYYY-MM-DD [--platform=baemin] [--upload=N] [--apply]\n");
    exit(2);
}

$rider = db_row('SELECT id, name, rider_code, agency_id FROM riders WHERE id = ? LIMIT 1', [$riderId]);
if ($rider === null) {
    fwrite(STDERR, "라이더 #{$riderId} 없음\n");
    exit(2);
}

$where  = 'rider_id = ? AND settlement_date = ?';
$params = [$riderId, $date];
if ($platform !== null && $platform !== '') {
    $where   .= ' AND platform = ?';
    $params[] = $platform;
}
if ($uploadId > 0) {
    $where   .= ' AND upload_id = ?';
    $params[] = $uploadId;
}
$cycles = db_rows("SELECT * FROM settlement_rider_cycles WHERE {$where} ORDER BY id", $params);

printf(
    "정산 하루치 되돌리기 %s — %s(%s) · %s%s\n\n",
    $apply ? '[실행]' : '[미리보기]',
    (string) $rider['name'],
    (string) $rider['rider_code'],
    $date,
    $platform !== null ? " · {$platform}" : ''
);

if ($cycles === []) {
    echo "  해당 정산일의 사이클이 없다 — 되돌릴 것이 없음.\n";
    exit(0);
}

// ── 안전 점검 ────────────────────────────────────────────────────────────────
$blocked = [];
$netTotal = 0;
$taxTotal = 0;
foreach ($cycles as $c) {
    $cid  = (int) $c['id'];
    $net  = (int) $c['net_amount'];
    $netTotal += $net;

    $wd = db_rows('SELECT request_id, amount FROM withdrawal_request_cycles WHERE cycle_id = ?', [$cid]);
    if ($wd !== [] || (int) $c['withdrawn_amount'] > 0) {
        $blocked[] = sprintf('사이클#%d — 출금에 연결됨(%s) · 소진 %s원', $cid,
            $wd ? 'wd#' . implode(', wd#', array_column($wd, 'request_id')) : '연결기록 없음',
            $n($c['withdrawn_amount']));
    }
    if (db_table_exists('rider_carry_forward')) {
        $cf = (int) (db_row(
            'SELECT COUNT(*) c FROM rider_carry_forward WHERE origin_cycle_id = ? OR collected_cycle_id = ?',
            [$cid, $cid]
        )['c'] ?? 0);
        if ($cf > 0) {
            $blocked[] = sprintf('사이클#%d — 차감 이월 %d건이 걸려 있음', $cid, $cf);
        }
    }
    $tax = (int) (db_row(
        "SELECT COALESCE(SUM(amount),0) s FROM settlement_fee_items WHERE cycle_id = ? AND fee_code = 'withholding'",
        [$cid]
    )['s'] ?? 0);
    $taxTotal += $tax;

    $ded = db_rows('SELECT id, kind, amount FROM deduction_entries WHERE consumed_cycle_id = ?', [$cid]);
    $od  = (int) (db_row(
        'SELECT COUNT(*) c FROM settlement_order_details WHERE upload_id = ? AND rider_id = ? AND settlement_date = ?',
        [(int) $c['upload_id'], $riderId, $date]
    )['c'] ?? 0);

    printf(
        "  사이클#%d (업로드#%d) %s %d건 · 정산 %s − 공제 %s = net %s\n",
        $cid, (int) $c['upload_id'], (string) $c['platform'], (int) $c['order_count'],
        $n($c['gross_amount']), $n($c['total_fee_amount']), $n($net)
    );
    printf("     원천세 %s원 · 오더상세 %d건 · 되돌릴 차감 %d건%s\n",
        $n($tax), $od, count($ded),
        $ded ? ' (' . implode(', ', array_map(static fn (array $d): string => '#' . $d['id'] . ' ' . $d['kind'] . ' ' . number_format((int) $d['amount']), $ded)) . ')' : '');
}

$wallet = (int) (db_row('SELECT balance FROM rider_wallets WHERE rider_id = ? LIMIT 1', [$riderId])['balance'] ?? 0);
printf("\n  라이더 지갑 %s원 → %s원 (−%s)\n", $n($wallet), $n($wallet - $netTotal), $n($netTotal));
if ($taxTotal > 0) {
    printf("  대리점 원천세 예수금 −%s원\n", $n($taxTotal));
}

if ($blocked !== []) {
    echo "\n⛔ 되돌릴 수 없다:\n";
    foreach ($blocked as $b) {
        echo "   · {$b}\n";
    }
    echo "\n   출금이 나갔거나 이월로 넘어간 금액은 여기서 풀 수 없다 —\n";
    echo "   「정산/잔액 수동 조정」(본사 전용)으로 처리할 것.\n";
    exit(1);
}
if ($wallet - $netTotal < 0) {
    printf("\n⛔ 지갑이 음수가 된다(%s원) — 이미 다른 경로로 빠져나간 돈이 있다. 사람이 확인할 것.\n", $n($wallet - $netTotal));
    exit(1);
}

if (!$apply) {
    echo "\n  ※ 미리보기다. 실제로 되돌리려면 --apply 를 붙인다.\n";
    exit(0);
}

// ── 실행 ────────────────────────────────────────────────────────────────────
db_transaction(static function () use ($cycles, $riderId, $date, $netTotal, $taxTotal, $rider): void {
    foreach ($cycles as $c) {
        $cid = (int) $c['id'];
        db_execute('UPDATE deduction_entries SET consumed_cycle_id = NULL WHERE consumed_cycle_id = ?', [$cid]);
        db_execute('DELETE FROM settlement_fee_items WHERE cycle_id = ?', [$cid]);
        db_execute(
            'DELETE FROM settlement_order_details WHERE upload_id = ? AND rider_id = ? AND settlement_date = ?',
            [(int) $c['upload_id'], $riderId, $date]
        );
        if ((int) ($c['daily_rider_id'] ?? 0) > 0) {
            db_execute('DELETE FROM settlement_daily_riders WHERE id = ?', [(int) $c['daily_rider_id']]);
        } else {
            db_execute(
                'DELETE FROM settlement_daily_riders WHERE upload_id = ? AND rider_id = ? AND settlement_date = ?',
                [(int) $c['upload_id'], $riderId, $date]
            );
        }
        db_execute('DELETE FROM settlement_rider_cycles WHERE id = ?', [$cid]);
    }
    // 지갑은 음수로 떨어지지 않게(위에서 이미 검사했지만 동시성 대비).
    db_execute(
        'UPDATE rider_wallets SET balance = GREATEST(0, balance - ?), updated_at = NOW() WHERE rider_id = ?',
        [$netTotal, $riderId]
    );
    $agencyId = (int) ($rider['agency_id'] ?? 0);
    if ($taxTotal > 0 && $agencyId > 0) {
        db_execute(
            'UPDATE agency_wallets SET withholding_reserve = GREATEST(0, withholding_reserve - ?) WHERE agency_id = ?',
            [$taxTotal, $agencyId]
        );
    }
});

require_once INC_PATH . '/AuditLog.php';
AuditLog::record(
    'settlement.rollback_day',
    (string) $rider['rider_code'],
    sprintf('정산 되돌리기 · %s · 사이클 %d개 · 지갑 −%s원 · 원천세 예수금 −%s원',
        $date, count($cycles), $n($netTotal), $n($taxTotal))
);

printf("\n✅ 되돌렸다. 지갑 %s원\n", $n((int) (db_row('SELECT balance FROM rider_wallets WHERE rider_id = ? LIMIT 1', [$riderId])['balance'] ?? 0)));
echo "   같은 날짜를 다시 올릴 수 있다(중복 검사도 함께 풀렸다).\n";
