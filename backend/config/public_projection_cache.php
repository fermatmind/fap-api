<?php

declare(strict_types=1);

return [
    // The first release installs compatibility. A subsequent release activates.
    'phase' => 'prepare',
    'maxmemory_bytes' => 2147483648,
    'minimum_available_bytes' => 1073741824,
    'observation_seconds' => 7 * 86400,
];
