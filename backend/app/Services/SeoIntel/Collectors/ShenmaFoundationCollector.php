<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Collectors;

final class ShenmaFoundationCollector extends DomesticSearchFoundationCollector
{
    public function engine(): string
    {
        return 'shenma';
    }

    public function sourceEngine(): string
    {
        return 'shenma';
    }

    public function collectorName(): string
    {
        return 'shenma_foundation';
    }
}
