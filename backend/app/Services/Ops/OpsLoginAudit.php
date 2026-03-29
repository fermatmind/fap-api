<?php

declare(strict_types=1);

namespace App\Services\Ops;

use Illuminate\Support\Facades\Log;

class OpsLoginAudit
{
    /**
     * @param  array<string, mixed>  $trace
     * @param  array<string, mixed>  $extra
     */
    public static function fail(array $trace, string $reason, array $extra = []): void
    {
        Log::channel('stack')->warning('OPS_LOGIN_FAIL', [
            ...$trace,
            'reason' => $reason,
            ...$extra,
        ]);
    }
}
