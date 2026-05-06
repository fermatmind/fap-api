<?php

declare(strict_types=1);

namespace App\Services\BigFive\Norms;

final class BigFiveNormAggregationResult
{
    /**
     * @param  array<string,mixed>  $summary
     * @param  list<array<string,mixed>>  $groups
     */
    public function __construct(
        public readonly array $summary,
        public readonly array $groups,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'summary' => $this->summary,
            'groups' => $this->groups,
        ];
    }
}
