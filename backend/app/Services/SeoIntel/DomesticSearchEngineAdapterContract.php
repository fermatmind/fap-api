<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

interface DomesticSearchEngineAdapterContract
{
    public function engine(): string;

    public function sourceEngine(): string;

    public function collectorName(): string;

    public function liveApiEnabled(): bool;
}
