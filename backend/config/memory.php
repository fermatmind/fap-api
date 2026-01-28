<?php

return [
    'enabled' => env('MEMORY_ENABLED', true),
    'max_confirmed_per_user' => (int) env('MEMORY_MAX_CONFIRMED', 200),
    'max_proposed_per_user' => (int) env('MEMORY_MAX_PROPOSED', 500),
    'default_namespace' => env('MEMORY_NAMESPACE', 'memory'),
    'redaction_enabled' => env('MEMORY_REDACTION_ENABLED', true),
];
