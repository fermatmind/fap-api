<?php

declare(strict_types=1);

namespace App\Services\BigFive\Norms;

final class BigFiveNormDriftResult
{
    /**
     * @param  array<string,mixed>  $summary
     * @param  list<array<string,mixed>>  $alerts
     * @param  array<string,mixed>  $rebuildEvidence
     */
    public function __construct(
        public readonly array $summary,
        public readonly array $alerts,
        public readonly array $rebuildEvidence,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'summary' => $this->summary,
            'alerts' => $this->alerts,
            'rebuild_evidence' => $this->rebuildEvidence,
        ];
    }
}
