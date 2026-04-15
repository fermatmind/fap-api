<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

use App\DTO\Career\CareerFirstWaveLaunchManifestArtifact;
use App\DTO\Career\CareerFirstWaveSmokeMatrixArtifact;

final class CareerFirstWaveReleaseArtifactProjectionService
{
    public function __construct(
        private readonly CareerFirstWaveLaunchManifestService $launchManifestService,
    ) {}

    /**
     * @return array{
     *   career-launch-manifest.json: CareerFirstWaveLaunchManifestArtifact,
     *   career-smoke-matrix.json: CareerFirstWaveSmokeMatrixArtifact
     * }
     */
    public function build(): array
    {
        $manifest = $this->launchManifestService->build()->toArray();

        return [
            'career-launch-manifest.json' => $this->buildLaunchManifestArtifact($manifest),
            'career-smoke-matrix.json' => $this->buildSmokeMatrixArtifact($manifest),
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function buildLaunchManifestArtifact(array $manifest): CareerFirstWaveLaunchManifestArtifact
    {
        $members = array_map(function (array $member): array {
            $trustFreshness = null;
            if (is_array($member['trust_freshness'] ?? null)) {
                $trustFreshness = [
                    'review_due_known' => (bool) data_get($member, 'trust_freshness.review_due_known', false),
                    'review_staleness_state' => data_get($member, 'trust_freshness.review_staleness_state'),
                ];
            }

            return [
                'canonical_slug' => (string) ($member['canonical_slug'] ?? ''),
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
        }, (array) ($manifest['members'] ?? []));

        return new CareerFirstWaveLaunchManifestArtifact(
            scope: (string) ($manifest['scope'] ?? ''),
            counts: (array) ($manifest['counts'] ?? []),
            groups: (array) ($manifest['groups'] ?? []),
            members: $members,
        );
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function buildSmokeMatrixArtifact(array $manifest): CareerFirstWaveSmokeMatrixArtifact
    {
        $members = array_map(function (array $member): array {
            return [
                'canonical_slug' => (string) ($member['canonical_slug'] ?? ''),
                'smoke_matrix' => [
                    'job_detail_route_known' => (bool) data_get($member, 'smoke_matrix.job_detail_route_known', false),
                    'discoverable_route_known' => (bool) data_get($member, 'smoke_matrix.discoverable_route_known', false),
                    'seo_contract_present' => (bool) data_get($member, 'smoke_matrix.seo_contract_present', false),
                    'structured_data_authority_present' => (bool) data_get($member, 'smoke_matrix.structured_data_authority_present', false),
                    'trust_freshness_present' => (bool) data_get($member, 'smoke_matrix.trust_freshness_present', false),
                    'family_support_route_present' => (bool) data_get($member, 'smoke_matrix.family_support_route_present', false),
                    'next_step_support_present' => (bool) data_get($member, 'smoke_matrix.next_step_support_present', false),
                ],
            ];
        }, (array) ($manifest['members'] ?? []));

        return new CareerFirstWaveSmokeMatrixArtifact(
            scope: (string) ($manifest['scope'] ?? ''),
            members: $members,
        );
    }
}
