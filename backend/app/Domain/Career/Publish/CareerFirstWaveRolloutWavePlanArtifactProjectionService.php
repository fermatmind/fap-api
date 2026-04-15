<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

use App\DTO\Career\CareerFirstWaveRolloutWavePlanArtifact;

final class CareerFirstWaveRolloutWavePlanArtifactProjectionService
{
    public function __construct(
        private readonly CareerFirstWaveRolloutWavePlanService $rolloutWavePlanService,
    ) {}

    public function build(): CareerFirstWaveRolloutWavePlanArtifact
    {
        $plan = $this->rolloutWavePlanService->build()->toArray();

        $members = array_map(function (array $member): array {
            $trustFreshness = null;
            if (is_array($member['trust_freshness'] ?? null)) {
                $trustFreshness = [
                    'review_due_known' => (bool) data_get($member, 'trust_freshness.review_due_known', false),
                    'review_staleness_state' => (string) data_get($member, 'trust_freshness.review_staleness_state', 'unknown_due_date'),
                ];
            }

            return [
                'canonical_slug' => (string) ($member['canonical_slug'] ?? ''),
                'rollout_cohort' => (string) ($member['rollout_cohort'] ?? ''),
                'launch_tier' => (string) ($member['launch_tier'] ?? ''),
                'readiness_status' => (string) ($member['readiness_status'] ?? ''),
                'lifecycle_state' => (string) ($member['lifecycle_state'] ?? ''),
                'public_index_state' => (string) ($member['public_index_state'] ?? ''),
                'supporting_routes' => [
                    'family_hub' => (bool) data_get($member, 'supporting_routes.family_hub', false),
                    'next_step_links_count' => (int) data_get($member, 'supporting_routes.next_step_links_count', 0),
                ],
                'trust_freshness' => $trustFreshness,
            ];
        }, (array) ($plan['members'] ?? []));

        return new CareerFirstWaveRolloutWavePlanArtifact(
            scope: (string) ($plan['scope'] ?? ''),
            counts: [
                'stable' => (int) data_get($plan, 'counts.stable', 0),
                'candidate' => (int) data_get($plan, 'counts.candidate', 0),
                'hold' => (int) data_get($plan, 'counts.hold', 0),
                'blocked' => (int) data_get($plan, 'counts.blocked', 0),
                'manual_review_needed' => (int) data_get($plan, 'counts.manual_review_needed', 0),
            ],
            cohorts: [
                'stable' => array_values((array) data_get($plan, 'cohorts.stable', [])),
                'candidate' => array_values((array) data_get($plan, 'cohorts.candidate', [])),
                'hold' => array_values((array) data_get($plan, 'cohorts.hold', [])),
                'blocked' => array_values((array) data_get($plan, 'cohorts.blocked', [])),
            ],
            advisory: [
                'manual_review_needed' => array_values((array) data_get($plan, 'cohorts.manual_review_needed', [])),
            ],
            members: $members,
        );
    }
}
