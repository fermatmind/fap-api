<?php

declare(strict_types=1);

return [
    'questions' => [
        // First-stage, low-risk target: min sidecar should be <= 60% of full compiled questions file.
        'min_compiled_max_ratio' => 0.60,
        // Soft ceiling for sidecar size; checked in tests and CI logs.
        'min_compiled_max_bytes' => 300000,
    ],
];
