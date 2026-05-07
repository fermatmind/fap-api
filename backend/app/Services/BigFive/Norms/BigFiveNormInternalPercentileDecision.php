<?php

declare(strict_types=1);

namespace App\Services\BigFive\Norms;

final class BigFiveNormInternalPercentileDecision
{
    /**
     * @param  array<string,int>|null  $percentiles
     * @param  array<string,mixed>  $metadata
     */
    public function __construct(
        public readonly bool $allowed,
        public readonly string $status,
        public readonly string $reason,
        public readonly ?array $percentiles,
        public readonly array $metadata,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'status' => $this->status,
            'reason' => $this->reason,
            'percentiles' => $this->percentiles,
            'metadata' => $this->metadata,
        ];
    }
}
