<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class FirstWaveReadinessSummary
{
    /**
     * @param  array<string, int>  $counts
     * @param  list<array<string, mixed>>  $occupations
     */
    public function __construct(
        public readonly string $waveName,
        public readonly string $summaryVersion,
        public readonly array $counts,
        public readonly array $occupations,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'summary_kind' => 'career_first_wave_readiness',
            'summary_version' => $this->summaryVersion,
            'wave_name' => $this->waveName,
            'counts' => $this->counts,
            'occupations' => $this->occupations,
        ];
    }
}
