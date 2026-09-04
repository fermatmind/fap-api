<?php

declare(strict_types=1);

return [
    'orchestrator_state' => 'DEPLOYED_DISABLED',
    'runtime_mode' => 'DETERMINISTIC_ROUTE_HOLD_ONLY',
    'mission_execution_enabled' => false,
    'mission_persistence_enabled' => (bool) env('SEO_COUNCIL_MISSION_PERSISTENCE_ENABLED', false),
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
