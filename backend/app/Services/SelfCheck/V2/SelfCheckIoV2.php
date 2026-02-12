<?php

declare(strict_types=1);

namespace App\Services\SelfCheck\V2;

use App\Services\SelfCheck\V2\Probes\CacheDirProbe;
use App\Services\SelfCheck\V2\Probes\ContentPackagesProbe;
use App\Services\SelfCheck\V2\Probes\DbProbe;
use App\Services\SelfCheck\V2\Probes\DiskProbe;
use App\Services\SelfCheck\V2\Probes\QueueProbe;
use App\Services\SelfCheck\V2\Probes\RedisProbe;

class SelfCheckIoV2
{
    /**
     * @return array<string,mixed>
     */
    public function collectDeps(string $region, string $locale, bool $verbose = false): array
    {
        $aggregator = new SelfCheckAggregator([
            new DbProbe(),
            new RedisProbe(),
            new QueueProbe(),
            new CacheDirProbe(),
            new ContentPackagesProbe($region, $locale),
            new DiskProbe(),
        ]);

        return $aggregator->run($verbose);
    }
}
