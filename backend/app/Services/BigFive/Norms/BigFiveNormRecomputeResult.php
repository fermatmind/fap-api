<?php

declare(strict_types=1);

namespace App\Services\BigFive\Norms;

final class BigFiveNormRecomputeResult
{
    /**
     * @param  array<string,mixed>  $summary
     * @param  array<string,mixed>  $metrics
     * @param  array<string,mixed>  $internalPercentiles
     */
    public function __construct(
        public readonly array $summary,
        public readonly array $metrics,
        public readonly array $internalPercentiles,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'summary' => $this->summary,
            'metrics' => $this->metrics,
            'internal_percentiles' => $this->internalPercentiles,
        ];
    }
}
