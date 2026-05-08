<?php

declare(strict_types=1);

namespace App\Domain\Career\Expansion;

final class CanonicalExpansionManifestDTO
{
    /**
     * @param  list<string>  $slugs
     * @param  list<string>  $locales
     * @param  list<string>  $rollbackGroup
     */
    public function __construct(
        public readonly string $batchId,
        public readonly int $batchSize,
        public readonly array $slugs,
        public readonly array $locales,
        public readonly string $projectionState,
        public readonly bool $releaseGateRequired,
        public readonly bool $surfaceEqualityRequired,
        public readonly array $rollbackGroup,
        public readonly string $rolloutState,
        public readonly string $candidateRouteSemantics = 'expected_pre_route',
        public readonly string $candidateReleaseGateApplicability = 'not_applicable_before_promotion',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'batch_id' => $this->batchId,
            'batch_size' => $this->batchSize,
            'slugs' => $this->slugs,
            'locales' => $this->locales,
            'projection_state' => $this->projectionState,
            'release_gate_required' => $this->releaseGateRequired,
            'surface_equality_required' => $this->surfaceEqualityRequired,
            'rollback_group' => $this->rollbackGroup,
            'rollout_state' => $this->rolloutState,
            'candidate_route_semantics' => $this->candidateRouteSemantics,
            'candidate_release_gate_applicability' => $this->candidateReleaseGateApplicability,
        ];
    }
}
