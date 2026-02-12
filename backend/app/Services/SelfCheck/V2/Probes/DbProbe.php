<?php

declare(strict_types=1);

namespace App\Services\SelfCheck\V2\Probes;

use App\Services\SelfCheck\V2\Contracts\ProbeInterface;
use App\Services\SelfCheck\V2\DTO\ProbeResult;
use Illuminate\Support\Facades\DB;

final class DbProbe implements ProbeInterface
{
    public function name(): string
    {
        return 'db';
    }

    public function probe(bool $verbose = false): array
    {
        $t0 = microtime(true);

        try {
            DB::select('select 1 as ok');
            $ms = (int) round((microtime(true) - $t0) * 1000);

            return (new ProbeResult(true, '', '', [
                'driver' => (string) config('database.default'),
                'latency_ms' => $ms,
            ]))->toArray($verbose);
        } catch (\Throwable $e) {
            $ms = (int) round((microtime(true) - $t0) * 1000);

            return (new ProbeResult(
                false,
                'DB_UNAVAILABLE',
                (string) $e->getMessage(),
                ['driver' => (string) config('database.default'), 'latency_ms' => $ms],
            ))->toArray($verbose);
        }
    }
}
