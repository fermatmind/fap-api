<?php

declare(strict_types=1);

return [
    'operation' => 'bounded_material_authority_backfill_and_public_closeout',
    'max_records' => 10000,
    'canary_size' => 10,
    'classifier_exact_path_only' => true,
    'search_submission_allowed' => false,
    'destructive_probe_allowed' => false,
];
