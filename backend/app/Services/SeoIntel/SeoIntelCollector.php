<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

interface SeoIntelCollector
{
    public function name(): string;

    /**
     * @param  array<string, mixed>  $options
     */
    public function collect(array $options = []): SeoIntelCollectorResult;
}
