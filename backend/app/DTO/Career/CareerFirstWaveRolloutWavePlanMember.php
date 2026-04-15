<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerFirstWaveRolloutWavePlanMember
{
    /**
     * @param  array{family_hub:bool,next_step_links_count:int}  $supportingRoutes
     * @param  array{review_due_known:bool,review_staleness_state:string}  $trustFreshness
     * @param  list<string>  $deferReasons
     */
    public function __construct(
        public readonly string $canonicalSlug,
        public readonly string $rolloutCohort,
        public readonly string $launchTier,
        public readonly string $readinessStatus,
        public readonly string $lifecycleState,
        public readonly string $publicIndexState,
        public readonly array $supportingRoutes,
        public readonly array $trustFreshness,
        public readonly array $deferReasons,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'member_kind' => 'career_job_detail',
            'canonical_slug' => $this->canonicalSlug,
            'rollout_cohort' => $this->rolloutCohort,
            'launch_tier' => $this->launchTier,
            'readiness_status' => $this->readinessStatus,
            'lifecycle_state' => $this->lifecycleState,
            'public_index_state' => $this->publicIndexState,
            'supporting_routes' => $this->supportingRoutes,
            'trust_freshness' => $this->trustFreshness,
            'defer_reasons' => $this->deferReasons,
        ];
    }
}
