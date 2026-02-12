<?php

declare(strict_types=1);

namespace App\Services\SelfCheck\V2;

use App\Services\SelfCheck\V2\Contracts\ProbeInterface;

final class SelfCheckAggregator
{
    /**
     * @param array<int,ProbeInterface> $probes
     */
    public function __construct(private readonly array $probes)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function run(bool $verbose = false): array
    {
        $deps = [];

        foreach ($this->probes as $probe) {
            $deps[$probe->name()] = $probe->probe($verbose);
        }

        return $deps;
    }
}
