<?php

/**
 * seed_riders_from_settlement 중복 생성 정리 (이름당 1명, baemin 연동 유지)
 * 실행: php fix_seed_rider_dupes.php
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

$dupes = db_rows(
    'SELECT name, GROUP_CONCAT(id ORDER BY id) AS ids
       FROM riders
      WHERE admin_memo LIKE \'%정산 업로드%\'
      GROUP BY name
      HAVING COUNT(*) > 1'
);

$removed = 0;

foreach ($dupes as $d) {
    $ids = array_map('intval', explode(',', (string) $d['ids']));
    $keepId = null;

    foreach ($ids as $id) {
        if (db_row('SELECT id FROM rider_platforms WHERE rider_id = ? AND platform = \'baemin\'', [$id])) {
            $keepId = $id;
            break;
        }
    }
    $keepId ??= $ids[0];

    foreach ($ids as $id) {
        if ($id === $keepId) {
            continue;
        }
        db_execute('UPDATE settlement_daily_riders SET rider_id = ? WHERE rider_id = ?', [$keepId, $id]);
        db_execute('DELETE FROM rider_platforms WHERE rider_id = ?', [$id]);
        db_execute('DELETE FROM riders WHERE id = ?', [$id]);
        echo "DEL   {$d['name']} rider_id={$id} → 유지 {$keepId}\n";
        $removed++;
    }
}

// baemin만 있는데 platform이 coupang인 연동 → baemin으로 통일
foreach (db_rows(
    'SELECT rp.id, rp.rider_id, rp.external_id, r.name
       FROM rider_platforms rp
       JOIN riders r ON r.id = rp.rider_id
      WHERE r.admin_memo LIKE \'%정산 업로드%\'
        AND rp.platform = \'coupang\''
) as $row) {
    $exists = db_row(
        'SELECT id FROM rider_platforms WHERE rider_id = ? AND platform = \'baemin\' AND external_id = ?',
        [$row['rider_id'], $row['external_id']]
    );
    if ($exists) {
        db_execute('DELETE FROM rider_platforms WHERE id = ?', [$row['id']]);
        echo "DEL   coupang 중복 연동 rider_id={$row['rider_id']} ({$row['name']})\n";
    } else {
        db_execute('UPDATE rider_platforms SET platform = \'baemin\' WHERE id = ?', [$row['id']]);
        echo "FIX   coupang→baemin rider_id={$row['rider_id']} ({$row['name']})\n";
    }
}

$linked = db_execute(
    'UPDATE settlement_daily_riders dr
        INNER JOIN rider_platforms rp
            ON rp.external_id = dr.license_id AND rp.platform = \'baemin\'
       SET dr.rider_id = rp.rider_id
     WHERE dr.platform = \'baemin\''
);

echo "\n중복 라이더 삭제: {$removed}명\n";
echo "baemin 정산 행 rider_id 재연결: {$linked}건\n";
