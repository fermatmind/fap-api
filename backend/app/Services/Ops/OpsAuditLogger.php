<?php

declare(strict_types=1);

namespace App\Services\Ops;

use Illuminate\Support\Facades\Log;

class OpsAuditLogger
{
    public static function log(string $event, array $data = []): void
    {
        Log::channel('stack')->info('OPS_AUDIT_'.$event, [
            'ts' => now()->toISOString(),
            ...$data,
        ]);
    }
}
