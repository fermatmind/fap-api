<?php

declare(strict_types=1);

namespace App\Services\BigFive\Norms;

final class BigFiveNormSegmentedAggregationResult
{
    /**
     * @param  array<string,mixed>  $summary
     * @param  list<array<string,mixed>>  $segments
     */
    public function __construct(
        public readonly array $summary,
        public readonly array $segments,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'summary' => $this->summary,
            'segments' => $this->segments,
        ];
    }
}
