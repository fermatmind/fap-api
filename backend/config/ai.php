<?php

return [
    'enabled' => env('AI_ENABLED', true),
    'insights_enabled' => env('AI_INSIGHTS_ENABLED', true),

    'provider' => env('AI_PROVIDER', 'mock'),
    'model' => env('AI_MODEL', 'mock-model'),
    'prompt_version' => env('AI_PROMPT_VERSION', 'v1.0.0'),

    'timeouts' => [
        'connect_seconds' => (int) env('AI_TIMEOUT_CONNECT', 5),
        'request_seconds' => (int) env('AI_TIMEOUT_REQUEST', 30),
    ],

    'budgets' => [
        'daily_usd' => (float) env('AI_DAILY_USD', 5.0),
        'monthly_usd' => (float) env('AI_MONTHLY_USD', 50.0),
        'daily_tokens' => (int) env('AI_DAILY_TOKENS', 25000),
        'monthly_tokens' => (int) env('AI_MONTHLY_TOKENS', 250000),
    ],

    'breaker_enabled' => env('AI_BREAKER_ENABLED', true),
    'fail_open_when_redis_down' => env('AI_FAIL_OPEN_WHEN_REDIS_DOWN', false),
    'redis_prefix' => env('AI_REDIS_PREFIX', 'ai:budget'),
    'queue_name' => env('AI_INSIGHTS_QUEUE', 'insights'),
    'cost_per_1k_tokens_usd' => (float) env('AI_COST_PER_1K_TOKENS_USD', 0.002),
    'dev_allow_anon_header' => env('AI_DEV_ALLOW_ANON_HEADER', false),
];
