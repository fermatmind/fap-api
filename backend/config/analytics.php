<?php

declare(strict_types=1);

return [
    'smoke_attempt_exclusion' => [
        /*
         * Production smoke attempts are operational probes, not growth signals.
         * Keep the default empty so deploy/runtime config can provide exact ids
         * without hardcoding live attempt identifiers in the repository.
         */
        'attempt_ids' => array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            explode(',', (string) env('ANALYTICS_SMOKE_EXCLUDED_ATTEMPT_IDS', ''))
        ))),

        'anon_id_prefixes' => [
            'codex_probe_',
        ],

        'traffic_quality_labels' => [
            'smoke',
            'probe',
            'codex_probe',
            'internal_smoke',
            'production_smoke',
        ],
    ],
];
