<?php

declare(strict_types=1);

namespace App\Support\Ops;

use Illuminate\Support\Facades\Log;

class OpsSecurityEvent
{
    public static function emit(string $type, array $data = []): void
    {
        Log::channel('stack')->warning('OPS_SECURITY_'.$type, [
            'timestamp' => now()->toISOString(),
            ...$data,
        ]);
    }
}
