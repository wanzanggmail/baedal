<?php

declare(strict_types=1);

/**
 * 매뉴얼 검색(관리자) — GET ?q=검색어
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/ManualDocs.php';

header('Content-Type: application/json; charset=utf-8');

if (!admin_is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => '인증이 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
$results = ManualDocs::search($q, 'admin');

echo json_encode(['ok' => true, 'query' => $q, 'results' => $results], JSON_UNESCAPED_UNICODE);
