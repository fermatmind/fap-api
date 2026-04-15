<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

use App\DTO\Career\CareerFirstWaveRolloutBundleArtifact;
use App\DTO\Career\CareerFirstWaveRolloutCohortListArtifact;

final class CareerFirstWaveRolloutBundleProjectionService
{
    public const BUNDLE_FILENAME = 'career-rollout-bundle.json';

    public const STABLE_LIST_FILENAME = 'career-stable-whitelist.json';

    public const CANDIDATE_LIST_FILENAME = 'career-candidate-whitelist.json';

    public const HOLD_LIST_FILENAME = 'career-hold-list.json';

    public const BLOCKED_LIST_FILENAME = 'career-blocked-list.json';

    public function __construct(
        private readonly CareerFirstWaveRolloutWavePlanArtifactProjectionService $rolloutArtifactProjectionService,
    ) {}

    /**
     * @return array{
     *   career-rollout-bundle.json: CareerFirstWaveRolloutBundleArtifact,
     *   career-stable-whitelist.json: CareerFirstWaveRolloutCohortListArtifact,
     *   career-candidate-whitelist.json: CareerFirstWaveRolloutCohortListArtifact,
     *   career-hold-list.json: CareerFirstWaveRolloutCohortListArtifact,
     *   career-blocked-list.json: CareerFirstWaveRolloutCohortListArtifact
     * }
     */
    public function build(): array
    {
        $rolloutArtifact = $this->rolloutArtifactProjectionService->build()->toArray();

        $cohorts = [
            'stable' => array_values((array) data_get($rolloutArtifact, 'cohorts.stable', [])),
            'candidate' => array_values((array) data_get($rolloutArtifact, 'cohorts.candidate', [])),
            'hold' => array_values((array) data_get($rolloutArtifact, 'cohorts.hold', [])),
            'blocked' => array_values((array) data_get($rolloutArtifact, 'cohorts.blocked', [])),
        ];

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
        }, (array) ($rolloutArtifact['members'] ?? []));

        $scope = (string) ($rolloutArtifact['scope'] ?? '');

        return [
            self::BUNDLE_FILENAME => new CareerFirstWaveRolloutBundleArtifact(
                scope: $scope,
                counts: [
                    'stable' => (int) data_get($rolloutArtifact, 'counts.stable', 0),
                    'candidate' => (int) data_get($rolloutArtifact, 'counts.candidate', 0),
                    'hold' => (int) data_get($rolloutArtifact, 'counts.hold', 0),
                    'blocked' => (int) data_get($rolloutArtifact, 'counts.blocked', 0),
                    'manual_review_needed' => (int) data_get($rolloutArtifact, 'counts.manual_review_needed', 0),
                ],
                cohorts: $cohorts,
                advisory: [
                    'manual_review_needed' => array_values((array) data_get($rolloutArtifact, 'advisory.manual_review_needed', [])),
                ],
                members: $members,
            ),
            self::STABLE_LIST_FILENAME => new CareerFirstWaveRolloutCohortListArtifact(
                scope: $scope,
                cohort: 'stable',
                members: $cohorts['stable'],
            ),
            self::CANDIDATE_LIST_FILENAME => new CareerFirstWaveRolloutCohortListArtifact(
                scope: $scope,
                cohort: 'candidate',
                members: $cohorts['candidate'],
            ),
            self::HOLD_LIST_FILENAME => new CareerFirstWaveRolloutCohortListArtifact(
                scope: $scope,
                cohort: 'hold',
                members: $cohorts['hold'],
            ),
            self::BLOCKED_LIST_FILENAME => new CareerFirstWaveRolloutCohortListArtifact(
                scope: $scope,
                cohort: 'blocked',
                members: $cohorts['blocked'],
            ),
        ];
    }
}
