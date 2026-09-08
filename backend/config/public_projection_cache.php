<?php

declare(strict_types=1);

return [
    // Deployed hosts require prepared persistent state; local/test fixtures stay standalone.
    'phase' => in_array(env('APP_ENV', 'production'), ['production', 'staging'], true) ? 'activate' : 'prepare',
    'maxmemory_bytes' => 2147483648,
    'minimum_available_bytes' => 1073741824,
    'observation_seconds' => 7 * 86400,
];
