<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';

header('Content-Type: application/manifest+json; charset=utf-8');

$icon = web_asset('media/logos/favicon.ico');
$start = rider_url('login');

echo json_encode(
    [
        'name' => '도깨비 배달 라이더',
        'short_name' => '라이더',
        'description' => '도깨비 배달 라이더 앱 (목업)',
        'start_url' => $start,
        'scope' => rider_url(''),
        'display' => 'standalone',
        'background_color' => '#f5f8fa',
        'theme_color' => '#009ef7',
        'lang' => 'ko',
        'icons' => [
            [
                'src' => $icon,
                'sizes' => '48x48',
                'type' => 'image/x-icon',
                'purpose' => 'any',
            ],
        ],
    ],
    JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
);
