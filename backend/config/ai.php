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

    'narrative' => [
        'enabled' => env('AI_NARRATIVE_ENABLED', false),
        'provider' => env('AI_NARRATIVE_PROVIDER', env('AI_PROVIDER', 'mock')),
        'model' => env('AI_NARRATIVE_MODEL', env('AI_MODEL', 'mock-model')),
        'prompt_version' => env('AI_NARRATIVE_PROMPT_VERSION', env('AI_PROMPT_VERSION', 'v1.0.0')),
        'fail_open_mode' => env('AI_NARRATIVE_FAIL_OPEN_MODE', 'deterministic'),
    ],

    'eq_agent' => [
        'llm_enabled' => env('EQ_AGENT_LLM_ENABLED', false),
        'provider' => env('EQ_AGENT_LLM_PROVIDER', 'openai'),
        'staging_only' => env('EQ_AGENT_LLM_STAGING_ONLY', true),
        'model' => env('EQ_AGENT_LLM_MODEL', env('AI_MODEL', '')),
        'fail_open_mode' => env('EQ_AGENT_LLM_FAIL_OPEN_MODE', 'deterministic'),
        'openai' => [
            'base_url' => rtrim((string) env('EQ_AGENT_OPENAI_BASE_URL', env('OPENAI_BASE_URL', 'https://api.openai.com/v1')), '/'),
            'api_key' => env('EQ_AGENT_OPENAI_API_KEY', env('OPENAI_API_KEY', '')),
            'model' => env('EQ_AGENT_OPENAI_MODEL', env('EQ_AGENT_LLM_MODEL', env('AI_MODEL', ''))),
            'connect_timeout_seconds' => (int) env('EQ_AGENT_OPENAI_CONNECT_TIMEOUT', 5),
            'request_timeout_seconds' => (int) env('EQ_AGENT_OPENAI_REQUEST_TIMEOUT', 30),
            'max_retries' => (int) env('EQ_AGENT_OPENAI_MAX_RETRIES', 0),
            'retry_sleep_milliseconds' => (int) env('EQ_AGENT_OPENAI_RETRY_SLEEP_MS', 250),
            'max_output_tokens' => (int) env('EQ_AGENT_OPENAI_MAX_OUTPUT_TOKENS', 900),
        ],
    ],
];
