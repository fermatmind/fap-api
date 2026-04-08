<?php

declare(strict_types=1);

namespace App\Domain\Career\Scoring;

final class CareerScoreResult
{
    /**
     * @param  array<int,string>  $criticalMissingFields
     * @param  array<string,mixed>  $componentBreakdown
     * @param  array<int,array<string,mixed>>  $penalties
     */
    public function __construct(
        public readonly int $value,
        public readonly string $integrityState,
        public readonly array $criticalMissingFields,
        public readonly int $confidenceCap,
        public readonly string $formulaRef,
        public readonly array $componentBreakdown,
        public readonly array $penalties,
        public readonly float $degradationFactor,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'integrity_state' => $this->integrityState,
            'critical_missing_fields' => array_values($this->criticalMissingFields),
            'confidence_cap' => $this->confidenceCap,
            'formula_ref' => $this->formulaRef,
            'component_breakdown' => $this->componentBreakdown,
            'penalties' => array_values($this->penalties),
            'degradation_factor' => round($this->degradationFactor, 4),
        ];
    }
}
