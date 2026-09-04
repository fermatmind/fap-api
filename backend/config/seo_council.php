<?php

declare(strict_types=1);

return [
    'orchestrator_state' => 'DEPLOYED_DISABLED',
    'runtime_mode' => 'DETERMINISTIC_ROUTE_HOLD_ONLY',
    'mission_execution_enabled' => false,
    'mission_persistence_enabled' => (bool) env('SEO_COUNCIL_MISSION_PERSISTENCE_ENABLED', false),
    'mission_persistence_runtime_state' => 'DISABLED',
    'read_only_runtime_test_enabled' => false,
    'read_only_runtime_state' => 'OFFLINE_EVAL',
    'read_only_runtime_expected_version_vector' => [],
    'model_provider' => 'disabled',
    'model_runtime_enabled' => false,
    'tool_broker_enabled' => false,
    'model_http' => [
        'endpoint' => env('SEO_COUNCIL_MODEL_HTTP_ENDPOINT', ''),
        'secret' => env('SEO_COUNCIL_MODEL_HTTP_SECRET', ''),
        'connect_timeout_seconds' => 3,
        'timeout_seconds' => 15,
    ],
    'model_missions' => [
        'bounded_review' => [
            'model' => 'seo-council-readonly-v1',
            'max_calls' => 2,
            'max_input_tokens' => 4096,
            'max_output_tokens' => 768,
            'max_cost_microusd' => 250000,
            'deadline_ms' => 15000,
            'max_response_bytes' => 65536,
        ],
    ],
    'scheduler_enabled' => (bool) env('SEO_COUNCIL_SCHEDULER_ENABLED', false),
    'scheduler_lease_ttl_seconds' => 120,
    'scheduler_max_lease_ttl_seconds' => 300,
    'scheduler_max_clock_drift_seconds' => 30,
    'scheduler_queue_limit' => 64,
    'career_runtime_enabled' => false,
    'connection' => env('SEO_COUNCIL_DB_CONNECTION', env('SEO_INTEL_DB_CONNECTION', 'seo_intel')),
    'baseline_sha' => '90f44e3d64747e9e8550a57d3a52b0b3a36e678a',
    'scheduled_request' => resource_path('seo-agent/council/scheduled/weekly-opportunity.v1.json'),
];
