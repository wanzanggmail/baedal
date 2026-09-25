<?php

declare(strict_types=1);

/**
 * 펌뱅킹 미확정 이체 보정 조회 — **크론용 CLI**.
 *
 * 왜 필요한가: 바움은 처리결과 통보를 1분 간격 최대 10회 보내고 그만둔다. 그 10분 사이에
 * 우리 서버가 내려가 있었거나 통보 IP 가 막혀 있었으면 결과를 영영 못 받고, 출금은
 * `transferring` 에 갇힌다(라이더는 재신청도 못 한다). 관리자 화면의 「보정 조회」 버튼만으로는
 * **사람이 눌러야만** 풀리므로, 실서버에서는 크론으로 돌린다.
 *
 * 크론 예시 (10분마다):
 *   ‌*\/10 * * * * /usr/bin/php /var/www/baedal/scripts/firm_reconcile.php >> /var/log/firm_reconcile.log 2>&1
 *
 * 실 연동이 꺼져 있으면 아무것도 하지 않는다(모의 게이트웨이 환경에서 그냥 종료).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once INC_PATH . '/FirmReconciler.php';

$minAge = max(0, min(1440, (int) ($argv[1] ?? 5)));

// 크론이 겹쳐 돌면 같은 건을 두 번 조회한다 — 확정은 멱등이지만 API 를 헛되이 두드린다.
$lock = 'firm_reconcile_' . DB_NAME;
if ((int) (db_row('SELECT GET_LOCK(?, 5) AS g', [$lock])['g'] ?? 0) !== 1) {
    echo date('Y-m-d H:i:s') . " 이미 실행 중입니다 — 건너뜁니다.\n";
    exit(0);
}

try {
    $r = FirmReconciler::run($minAge);
    printf(
        "%s 조회 %d · 확정 %d · 재확정 %d · 진행중 %d · 오류 %d\n",
        date('Y-m-d H:i:s'),
        $r['checked'],
        $r['finalized'],
        $r['repaired'] ?? 0,
        $r['still_pending'],
        $r['errors']
    );
    foreach ($r['details'] as $line) {
        echo '  ' . $line . "\n";
    }
    // 확정이나 오류가 있으면 로그에서 눈에 띄게 남긴다.
    if ($r['finalized'] > 0 || ($r['repaired'] ?? 0) > 0 || $r['errors'] > 0) {
        error_log(sprintf(
            '[firm_reconcile] 확정 %d건 · 재확정 %d건 · 오류 %d건',
            $r['finalized'],
            $r['repaired'] ?? 0,
            $r['errors']
        ));
    }
} finally {
    db_row('SELECT RELEASE_LOCK(?) AS r', [$lock]);
}
