<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Collectors;

final class So360FoundationCollector extends DomesticSearchFoundationCollector
{
    public function engine(): string
    {
        return 'so360';
    }

    public function sourceEngine(): string
    {
        return 'so360';
    }

    public function collectorName(): string
    {
        return 'so360_foundation';
    }
}
