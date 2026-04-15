<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerFirstWaveLaunchManifestMember
{
    /**
     * @param  list<string>  $blockers
     * @param  array<string, mixed>  $trustFreshness
     * @param  array{family_hub:bool,next_step_links_count:int}  $supportingRoutes
     * @param  array{job_detail_route_known:bool,discoverable_route_known:bool,seo_contract_present:bool,structured_data_authority_present:bool,trust_freshness_present:bool,family_support_route_present:bool,next_step_support_present:bool}  $smokeMatrix
     * @param  array<string, array<string, string>>  $evidenceRefs
     */
    public function __construct(
        public readonly string $canonicalSlug,
        public readonly string $canonicalTitleEn,
        public readonly string $launchTier,
        public readonly string $readinessStatus,
        public readonly string $lifecycleState,
        public readonly string $publicIndexState,
        public readonly array $blockers,
        public readonly array $trustFreshness,
        public readonly array $supportingRoutes,
        public readonly array $smokeMatrix,
        public readonly array $evidenceRefs,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'member_kind' => 'career_job_detail',
            'canonical_slug' => $this->canonicalSlug,
            'canonical_title_en' => $this->canonicalTitleEn,
            'launch_tier' => $this->launchTier,
            'readiness_status' => $this->readinessStatus,
            'lifecycle_state' => $this->lifecycleState,
            'public_index_state' => $this->publicIndexState,
            'blockers' => $this->blockers,
            'trust_freshness' => $this->trustFreshness,
            'supporting_routes' => $this->supportingRoutes,
            'smoke_matrix' => $this->smokeMatrix,
            'evidence_refs' => $this->evidenceRefs,
        ];
    }
}
