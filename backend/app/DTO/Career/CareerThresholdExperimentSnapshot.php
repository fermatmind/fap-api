<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerThresholdExperimentSnapshot
{
    /**
     * @param  array<string, mixed>  $thresholds
     * @param  array<string, mixed>  $experiments
     */
    public function __construct(
        public readonly string $snapshotKey,
        public readonly array $thresholds,
        public readonly array $experiments,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'snapshot_key' => $this->snapshotKey,
            'thresholds' => $this->thresholds,
            'experiments' => $this->experiments,
        ];
    }
}
