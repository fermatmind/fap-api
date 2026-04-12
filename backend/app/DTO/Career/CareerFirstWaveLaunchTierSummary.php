<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerFirstWaveLaunchTierSummary
{
    /**
     * @param  array<string, int>  $counts
     * @param  list<array<string, mixed>>  $occupations
     */
    public function __construct(
        public readonly string $summaryVersion,
        public readonly string $scope,
        public readonly array $counts,
        public readonly array $occupations,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'summary_kind' => 'career_first_wave_launch_tier',
            'summary_version' => $this->summaryVersion,
            'scope' => $this->scope,
            'counts' => $this->counts,
            'occupations' => $this->occupations,
        ];
    }
}
