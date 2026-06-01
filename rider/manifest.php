<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';

header('Content-Type: application/manifest+json; charset=utf-8');

$start = web_absolute_url(rider_url('login'));
$scope = web_absolute_url(rider_url(''));
$icons = array_map(static function (array $icon): array {
    $icon['src'] = web_absolute_url($icon['src']);

    return $icon;
}, web_pwa_icons_from_favicon_dir());

echo json_encode(
    [
        'name' => '도깨비 배달',
        'short_name' => '도깨비',
        'description' => '도깨비 배달 라이더 앱 (목업)',
        'start_url' => $start,
        'scope' => $scope,
        'display' => 'standalone',
        'orientation' => 'portrait-primary',
        'background_color' => '#f5f8fa',
        'theme_color' => '#009ef7',
        'lang' => 'ko',
        'dir' => 'ltr',
        'icons' => $icons,
    ],
    JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
);
