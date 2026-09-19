<?php

declare(strict_types=1);

/**
 * 광고 배너 클릭 → 로그 남기고 랜딩 URL 로 보낸다 (2026-09-19 갑).
 * GET ?b=<public_id>
 *
 * 이동 주소는 **DB 에 저장된 랜딩 URL 뿐**이다(요청 값으로 목적지를 정하지 않는다) —
 * 열린 리다이렉트로 쓰일 여지를 아예 없앤다.
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/BannerClick.php';

$pid = trim((string) ($_GET['b'] ?? ''));
if ($pid === '' || !preg_match('#^[a-zA-Z0-9_-]{1,32}$#', $pid)) {
    http_response_code(400);
    exit;
}

$banner = db_row('SELECT id, title, link_url FROM content_banners WHERE public_id = ? LIMIT 1', [$pid]);
$link   = (string) ($banner['link_url'] ?? '');
if ($banner === null || !preg_match('#^https?://#i', $link)) {
    http_response_code(404);
    exit;
}

BannerClick::record(
    $banner,
    function_exists('rider_current_user') && rider_current_user() !== null ? (int) rider_current_user()['id'] : null,
    function_exists('rider_current_agency_id') ? rider_current_agency_id() : null
);

header('Cache-Control: no-store');
header('Location: ' . $link, true, 302);
exit;
