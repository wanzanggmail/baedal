<?php

declare(strict_types=1);

/**
 * 배민 정산금액 «구성 합 == 배달처리비» 대조 (2026-09-25).
 *
 *   php tools/diag_baemin_compose.php
 *
 * 업로드 상세의 「정산금액 구성」이 배민만 비어 있다 — 일자 요약에 총액만 넣고 구성 컬럼을
 * 0으로 두기 때문이다. 채우려면 **구성 합이 배달처리비와 정확히 같아야** 한다.
 * `SettlementAmounts::exVat()` 가 «구성 합이 있으면 그걸 정산 기준액으로» 쓰기 때문에,
 * 둘이 다르면 구성을 채우는 순간 **정산액이 조용히 달라진다.**
 *
 * **읽기 전용.**
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';

$n = static fn ($v): string => number_format((int) $v);

echo '배민 구성 합 대조 — DB: ' . DB_NAME . ' · ' . date('Y-m-d H:i') . "\n";

// 오더별 상세에 저장된 배민 구성: fee_delivery(기본단가)·fee_area(지역)·fee_weather(기상)
// ·fee_promo1(피크)·fee_promo2(추가)·fee_promo3(대량), net_amount(배달처리비)
$rows = db_rows(
    "SELECT u.id AS upload_id, u.original_filename, od.settlement_date, od.rider_id,
            COUNT(*) AS orders,
            COALESCE(SUM(od.net_amount), 0) AS payout,
            COALESCE(SUM(od.fee_delivery + od.fee_area + od.fee_weather
                       + od.fee_promo1 + od.fee_promo2 + od.fee_promo3), 0) AS compose
       FROM settlement_order_details od
       JOIN settlement_uploads u ON u.id = od.upload_id
      WHERE u.platform = 'baemin'
      GROUP BY u.id, u.original_filename, od.settlement_date, od.rider_id
      ORDER BY od.settlement_date DESC, od.rider_id"
);

if ($rows === []) {
    echo "\n  배민 오더 상세가 없다 — 대조할 데이터 없음.\n";
    exit(0);
}

$ok = 0;
$bad = [];
foreach ($rows as $r) {
    if ((int) $r['payout'] === (int) $r['compose']) {
        $ok++;
        continue;
    }
    $bad[] = $r;
}

printf("\n  라이더·일자 묶음 %d건 — 일치 %d · 불일치 %d\n", count($rows), $ok, count($bad));

foreach (array_slice($bad, 0, 20) as $r) {
    $rider = db_row('SELECT name, rider_code FROM riders WHERE id = ? LIMIT 1', [(int) $r['rider_id']]) ?? [];
    printf(
        "    %s %s(%s) %d건 — 배달처리비 %s / 구성 합 %s · 차이 %s\n",
        (string) $r['settlement_date'],
        (string) ($rider['name'] ?? ('#' . (int) $r['rider_id'])),
        (string) ($rider['rider_code'] ?? '?'),
        (int) $r['orders'],
        $n($r['payout']),
        $n($r['compose']),
        $n((int) $r['compose'] - (int) $r['payout'])
    );
}
if (count($bad) > 20) {
    printf("    … 외 %d건\n", count($bad) - 20);
}

echo "\n";
if ($bad === []) {
    echo "  ✅ 전부 일치 — 일자 요약에 구성을 채워도 정산 기준액이 바뀌지 않는다.\n";
} else {
    echo "  ⚠️ 불일치가 있다. 구성을 그대로 채우면 그만큼 정산 기준액이 달라진다.\n";
    echo "     원인 후보: 배달처리비 0원 처리건(라이더 귀책)·헛걸음 보상·우리가 안 읽는 할증 열.\n";
    echo "     → 구성은 «표시용»으로만 채우고 정산 기준액은 배달처리비를 유지하는 쪽이 안전하다.\n";

    // ── ② 건별 차이 분포 — «어떤 금액이 몇 건에서» 비는지 보면 빠진 열의 정체가 드러난다.
    echo "\n  건별 차이 분포(배달처리비 − 구성 합):\n";
    foreach (db_rows(
        "SELECT (od.net_amount - (od.fee_delivery + od.fee_area + od.fee_weather
                                + od.fee_promo1 + od.fee_promo2 + od.fee_promo3)) AS gap,
                COUNT(*) AS c
           FROM settlement_order_details od
           JOIN settlement_uploads u ON u.id = od.upload_id
          WHERE u.platform = 'baemin'
          GROUP BY gap ORDER BY c DESC LIMIT 15"
    ) as $g) {
        printf("    %+8s원 × %d건%s\n", $n($g['gap']), (int) $g['c'], (int) $g['gap'] === 0 ? '  (정상)' : '');
    }
    echo "     같은 금액이 여러 건에 반복되면 그게 **우리가 안 읽는 할증 열**이다.\n";
    echo "     배민 정산서 헤더에서 그 이름을 찾아 XlsxParser::parseBaeminOrders() 에 추가하면 된다.\n";
}
