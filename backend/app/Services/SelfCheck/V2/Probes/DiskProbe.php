<?php

declare(strict_types=1);

namespace App\Services\SelfCheck\V2\Probes;

use App\Services\SelfCheck\V2\Contracts\ProbeInterface;
use App\Services\SelfCheck\V2\DTO\ProbeResult;

final class DiskProbe implements ProbeInterface
{
    public function name(): string
    {
        return 'disk';
    }

    public function probe(bool $verbose = false): array
    {
        $path = base_path();
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);
        $ok = is_numeric($free) && is_numeric($total) && (float) $total > 0;

        return (new ProbeResult(
            $ok,
            $ok ? '' : 'DISK_PROBE_FAILED',
            $ok ? '' : 'disk probe failed',
            [
                'path' => $path,
                'free_bytes' => $ok ? (int) $free : null,
                'total_bytes' => $ok ? (int) $total : null,
            ],
        ))->toArray($verbose);
    }
}
