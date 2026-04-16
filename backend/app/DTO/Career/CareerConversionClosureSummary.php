<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerConversionClosureSummary
{
    /**
     * @param  array<string, int>  $counts
     * @param  array<string, bool>  $readiness
     */
    public function __construct(
        public readonly string $summaryKind,
        public readonly string $summaryVersion,
        public readonly string $scope,
        public readonly array $counts,
        public readonly array $readiness,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'summary_kind' => $this->summaryKind,
            'summary_version' => $this->summaryVersion,
            'scope' => $this->scope,
            'counts' => $this->counts,
            'readiness' => $this->readiness,
        ];
    }
}
