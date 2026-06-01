<?php

declare(strict_types=1);

/**
 * 라이더 앱에서 /assets/ 파일 제공 (문서 루트가 rider/ 만 열릴 때 배너 이미지용)
 * GET ?f=media/banners/demo-insurance.svg
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';

$rel = trim(str_replace('\\', '/', (string) ($_GET['f'] ?? '')));
if ($rel === '' || str_contains($rel, '..') || !preg_match('#^[a-zA-Z0-9_./-]+$#', $rel)) {
    http_response_code(400);
    exit;
}

$full = ROOT_PATH . '/assets/' . $rel;
$real = realpath($full);
$base = realpath(ROOT_PATH . '/assets');
if ($real === false || $base === false || !str_starts_with($real, $base)) {
    http_response_code(404);
    exit;
}

$ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
$types = [
    'svg'  => 'image/svg+xml',
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
];
$mime = $types[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=86400');
readfile($real);
