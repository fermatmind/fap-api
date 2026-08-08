<?php

declare(strict_types=1);

return [
    // Apply remains disabled unless the target environment explicitly opts in.
    'import_enabled' => env('GREENFIELD_BASELINE_IMPORT_ENABLED', false),
];
