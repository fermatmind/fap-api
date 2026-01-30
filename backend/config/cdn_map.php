<?php

declare(strict_types=1);

$appUrl = rtrim((string) env('APP_URL', 'http://localhost'), '/');
$localAssets = $appUrl . '/storage/content_assets';

return [
    'default_region' => env('FAP_DEFAULT_REGION', 'CN_MAINLAND'),

    'map' => [
        'CN_MAINLAND' => [
            'assets_base_url' => env('FAP_CDN_CN_MAINLAND_ASSETS_BASE_URL', $localAssets),
        ],
        'US' => [
            'assets_base_url' => env('FAP_CDN_US_ASSETS_BASE_URL', 'https://cdn-us.example.com/content_assets'),
        ],
        'EU' => [
            'assets_base_url' => env('FAP_CDN_EU_ASSETS_BASE_URL', 'https://cdn-eu.example.com/content_assets'),
        ],
    ],
];
