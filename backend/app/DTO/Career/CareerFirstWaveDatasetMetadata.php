<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerFirstWaveDatasetMetadata
{
    public function __construct(
        public readonly CareerFirstWaveDatasetCoverage $coverage,
        public readonly CareerFirstWaveDatasetProvenance $provenance,
        public readonly CareerFirstWaveDatasetFreshness $freshness,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'coverage' => $this->coverage->toArray(),
            'provenance' => $this->provenance->toArray(),
            'freshness' => $this->freshness->toArray(),
        ];
    }
}
