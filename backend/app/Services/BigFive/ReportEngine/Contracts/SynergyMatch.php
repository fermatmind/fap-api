<?php

declare(strict_types=1);

namespace App\Services\BigFive\ReportEngine\Contracts;

final class SynergyMatch
{
    /**
     * @param  array<string,mixed>  $copy
     * @param  list<array<string,mixed>>  $sectionTargets
     * @param  list<string>  $mutualExcludes
     */
    public function __construct(
        public readonly string $synergyId,
        public readonly string $title,
        public readonly float $priorityWeight,
        public readonly string $mutexGroup,
        public readonly array $mutualExcludes,
        public readonly int $maxShow,
        public readonly array $sectionTargets,
        public readonly array $copy,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'synergy_id' => $this->synergyId,
            'title' => $this->title,
            'priority_weight' => $this->priorityWeight,
            'mutex_group' => $this->mutexGroup,
            'section_targets' => $this->sectionTargets,
        ];
    }
}
