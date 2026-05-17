<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Collectors;

final class SogouFoundationCollector extends DomesticSearchFoundationCollector
{
    public function engine(): string
    {
        return 'sogou';
    }

    public function sourceEngine(): string
    {
        return 'sogou';
    }

    public function collectorName(): string
    {
        return 'sogou_foundation';
    }
}
