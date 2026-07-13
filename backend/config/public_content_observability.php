<?php

declare(strict_types=1);

return [
    'enabled' => env('PUBLIC_CONTENT_RUNTIME_METRICS_ENABLED', true),
    'cache_store' => env('PUBLIC_CONTENT_RUNTIME_METRICS_STORE', 'redis'),
    'minute_retention_days' => 7,
    'daily_retention_days' => 90,
    'rollup_lag_minutes' => 2,
    'query_max_minutes' => 90 * 24 * 60,
    'allowed_locales' => ['en', 'zh-CN', 'unknown'],
    'duration_buckets_ms' => [25, 50, 100, 250, 500, 1000, 2000, 5000, 10000, 30000],

    // Exact parameterized route templates are the authority boundary. Never add
    // private attempt, report, order, payment, shortlist, or internal routes here.
    'routes' => [
        'api/v0.5/personality' => ['family' => 'mbti', 'priority' => 'L1'],
        'api/v0.5/personality/{type}' => ['family' => 'mbti', 'priority' => 'L1'],
        'api/v0.5/personality/{type}/seo' => ['family' => 'mbti', 'priority' => 'L1'],
        'api/v0.5/personality/comparisons' => ['family' => 'mbti', 'priority' => 'L1'],
        'api/v0.5/personality/comparisons/{comparison}' => ['family' => 'mbti', 'priority' => 'L1'],
        'api/v0.5/personality-content-assets' => ['family' => 'personality_assets', 'priority' => 'L2'],
        'api/v0.5/personality-content-assets/{framework}/{slug}' => ['framework_family' => true],
        'api/v0.5/personality-content-assets/{framework}/{entityType}/{code}' => ['framework_family' => true],
        'api/v0.5/articles' => ['family' => 'articles', 'priority' => 'L3'],
        'api/v0.5/articles/{slug}' => ['family' => 'articles', 'priority' => 'L3'],
        'api/v0.5/articles/{slug}/seo' => ['family' => 'articles', 'priority' => 'L3'],
        'api/v0.5/research' => ['family' => 'research', 'priority' => 'L3'],
        'api/v0.5/research/{slug}' => ['family' => 'research', 'priority' => 'L3'],
        'api/v0.5/support/articles' => ['family' => 'support', 'priority' => 'L3'],
        'api/v0.5/support/articles/{slug}' => ['family' => 'support', 'priority' => 'L3'],
        'api/v0.5/support/guides' => ['family' => 'support', 'priority' => 'L3'],
        'api/v0.5/support/guides/{slug}' => ['family' => 'support', 'priority' => 'L3'],
        'api/v0.5/support/interpretation-guides' => ['family' => 'support', 'priority' => 'L3'],
        'api/v0.5/support/interpretation-guides/{slug}' => ['family' => 'support', 'priority' => 'L3'],
        'api/v0.5/content-pages/{slug}' => ['family' => 'content_pages', 'priority' => 'L3'],
        'api/v0.5/landing-surfaces/{surfaceKey}' => ['family' => 'landing_surfaces', 'priority' => 'L3'],
        'api/v0.5/career-guides' => ['family' => 'career_guides', 'priority' => 'L3'],
        'api/v0.5/career-guides/{slug}' => ['family' => 'career_guides', 'priority' => 'L3'],
        'api/v0.5/career-guides/{slug}/seo' => ['family' => 'career_guides', 'priority' => 'L3'],
        'api/v0.5/career-jobs' => ['family' => 'career_jobs', 'priority' => 'L3'],
        'api/v0.5/career-jobs/{slug}' => ['family' => 'career_jobs', 'priority' => 'L3'],
        'api/v0.5/career-jobs/{slug}/seo' => ['family' => 'career_jobs', 'priority' => 'L3'],
        'api/v0.5/career/recommendations/mbti' => ['family' => 'career_recommendations', 'priority' => 'L3'],
        'api/v0.5/career/recommendations/mbti/{type}' => ['family' => 'career_recommendations', 'priority' => 'L3'],
        'api/v0.5/career/directory' => ['family' => 'career_directory', 'priority' => 'L3'],
        'api/v0.5/career/jobs' => ['family' => 'career_jobs', 'priority' => 'L3'],
        'api/v0.5/career/jobs/{slug}' => ['family' => 'career_jobs', 'priority' => 'L3'],
        'api/v0.5/topics' => ['family' => 'topics', 'priority' => 'L3'],
        'api/v0.5/topics/{slug}' => ['family' => 'topics', 'priority' => 'L3'],
        'api/v0.5/topics/{slug}/seo' => ['family' => 'topics', 'priority' => 'L3'],
    ],

    'framework_families' => [
        'big-five' => ['family' => 'big_five', 'priority' => 'L2'],
        'big_five' => ['family' => 'big_five', 'priority' => 'L2'],
        'enneagram' => ['family' => 'enneagram', 'priority' => 'L3'],
    ],
];
