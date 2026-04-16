<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

use App\DTO\Career\CareerFirstWaveLaunchManifest;
use App\DTO\Career\CareerFirstWaveLaunchManifestMember;
use App\DTO\Career\CareerFirstWaveLaunchReadinessAuditMember;
use App\DTO\Career\CareerFirstWaveIndexPolicyMember;
use App\Services\Career\Bundles\CareerFamilyHubBundleBuilder;
use App\Services\Career\Bundles\CareerJobDetailBundleBuilder;
use App\Services\Career\StructuredData\CareerStructuredDataBuilder;

final class CareerFirstWaveLaunchManifestService
{
    public function __construct(
        private readonly CareerFirstWaveLaunchReadinessAuditV2Service $launchReadinessAuditV2Service,
        private readonly CareerFirstWaveDiscoverabilityManifestService $discoverabilityManifestService,
        private readonly CareerFirstWaveNextStepLinksService $nextStepLinksService,
        private readonly CareerJobDetailBundleBuilder $jobDetailBundleBuilder,
        private readonly CareerFamilyHubBundleBuilder $familyHubBundleBuilder,
        private readonly CareerStructuredDataBuilder $structuredDataBuilder,
        private readonly CareerFirstWaveIndexPolicyEngine $indexPolicyEngine,
    ) {}

    public function build(): CareerFirstWaveLaunchManifest
    {
        $audit = $this->launchReadinessAuditV2Service->build();
        $discoverabilityManifest = $this->discoverabilityManifestService->build()->toArray();

        $jobRoutesBySlug = [];
        foreach ((array) ($discoverabilityManifest['routes'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            if (($row['route_kind'] ?? null) !== 'career_job_detail') {
                continue;
            }

            $slug = trim((string) ($row['canonical_slug'] ?? ''));
            if ($slug !== '') {
                $jobRoutesBySlug[$slug] = $row;
            }
        }

        $counts = [
            'total' => 0,
            'stable' => 0,
            'candidate' => 0,
            'hold' => 0,
            'blocked' => 0,
        ];
        $groups = [
            'stable' => [],
            'candidate' => [],
            'hold' => [],
            'blocked' => [],
        ];
        $policyAuthority = $this->indexPolicyEngine->build(array_map(
            function (CareerFirstWaveLaunchReadinessAuditMember $member): array {
                return [
                    'canonical_slug' => $member->canonicalSlug,
                    'current_index_state' => $member->lifecycleState,
                    'public_index_state' => $member->publicIndexState,
                    'index_eligible' => $member->indexEligible,
                    'reviewer_status' => $member->reviewerStatus,
                    'crosswalk_mode' => $member->crosswalkMode,
                    'allow_strong_claim' => $member->allowStrongClaim,
                    'confidence_score' => $member->confidenceScore,
                    'blocked_governance_status' => $member->blockedGovernanceStatus,
                    'next_step_links_count' => $member->nextStepLinksCount,
                    'trust_freshness' => $member->trustFreshness ?? [],
                ];
            },
            $audit->members,
        ), $audit->scope);
        $policyBySlug = [];
        foreach ($policyAuthority->members as $policyMember) {
            $policyBySlug[$policyMember->canonicalSlug] = $policyMember;
        }

        $members = [];

        foreach ($audit->members as $auditMember) {
            $policyMember = $policyBySlug[$auditMember->canonicalSlug] ?? null;
            $group = $this->classifyGroup($auditMember, $policyMember);
            $supportingRoute = $this->buildSupportingRoutes($auditMember);
            $smokeMatrix = $this->buildSmokeMatrix(
                auditMember: $auditMember,
                discoverabilityRow: $jobRoutesBySlug[$auditMember->canonicalSlug] ?? null,
                supportingRoutes: $supportingRoute,
            );

            $counts['total']++;
            $counts[$group]++;
            $groups[$group][] = $auditMember->canonicalSlug;

            $members[] = new CareerFirstWaveLaunchManifestMember(
                canonicalSlug: $auditMember->canonicalSlug,
                canonicalTitleEn: $auditMember->canonicalTitleEn,
                launchTier: $auditMember->launchTier,
                readinessStatus: $auditMember->readinessStatus,
                lifecycleState: $policyMember?->policyState ?? $auditMember->lifecycleState,
                publicIndexState: $policyMember?->publicIndexState ?? $auditMember->publicIndexState,
                blockers: $auditMember->blockers,
                trustFreshness: is_array($auditMember->trustFreshness) ? $auditMember->trustFreshness : [],
                supportingRoutes: $supportingRoute,
                smokeMatrix: $smokeMatrix,
                evidenceRefs: $auditMember->evidenceRefs,
            );
        }

        return new CareerFirstWaveLaunchManifest(
            scope: $audit->scope,
            counts: $counts,
            groups: $groups,
            members: $members,
        );
    }

    /**
     * @return array{family_hub:bool,next_step_links_count:int}
     */
    private function buildSupportingRoutes(CareerFirstWaveLaunchReadinessAuditMember $member): array
    {
        return [
            'family_hub' => $member->familyHubSupportingRoute,
            'next_step_links_count' => $member->nextStepLinksCount,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $discoverabilityRow
     * @param  array{family_hub:bool,next_step_links_count:int}  $supportingRoutes
     * @return array{job_detail_route_known:bool,discoverable_route_known:bool,seo_contract_present:bool,structured_data_authority_present:bool,trust_freshness_present:bool,family_support_route_present:bool,next_step_support_present:bool}
     */
    private function buildSmokeMatrix(
        CareerFirstWaveLaunchReadinessAuditMember $auditMember,
        ?array $discoverabilityRow,
        array $supportingRoutes,
    ): array {
        $jobBundle = $this->jobDetailBundleBuilder->buildBySlug($auditMember->canonicalSlug);
        $seoContractPresent = $jobBundle !== null
            && is_array($jobBundle->seoContract)
            && $jobBundle->seoContract !== [];
        $structuredDataAuthorityPresent = $jobBundle !== null
            && is_array($this->structuredDataBuilder->build('career_job_detail', $jobBundle));

        $familySupportRoutePresent = false;
        if ($supportingRoutes['family_hub'] === true) {
            $nextStepLinks = $this->nextStepLinksService->buildBySlug($auditMember->canonicalSlug)?->toArray();
            $familyHubSlug = null;

            foreach ((array) ($nextStepLinks['next_step_links'] ?? []) as $link) {
                if (! is_array($link) || ($link['route_kind'] ?? null) !== 'career_family_hub') {
                    continue;
                }

                $candidateSlug = trim((string) ($link['canonical_slug'] ?? ''));
                if ($candidateSlug !== '') {
                    $familyHubSlug = $candidateSlug;
                    break;
                }
            }

            if ($familyHubSlug !== null) {
                $familyBundle = $this->familyHubBundleBuilder->buildBySlug($familyHubSlug);
                $familySupportRoutePresent = $familyBundle !== null
                    && is_array($familyBundle->seoContract)
                    && $familyBundle->seoContract !== [];
            }
        }

        return [
            'job_detail_route_known' => $jobBundle !== null,
            'discoverable_route_known' => is_array($discoverabilityRow)
                && is_string($discoverabilityRow['discoverability_state'] ?? null)
                && trim((string) $discoverabilityRow['discoverability_state']) !== '',
            'seo_contract_present' => $seoContractPresent,
            'structured_data_authority_present' => $structuredDataAuthorityPresent,
            'trust_freshness_present' => is_array($auditMember->trustFreshness) && $auditMember->trustFreshness !== [],
            'family_support_route_present' => $familySupportRoutePresent,
            'next_step_support_present' => $supportingRoutes['next_step_links_count'] > 0,
        ];
    }

    private function classifyGroup(
        CareerFirstWaveLaunchReadinessAuditMember $member,
        ?CareerFirstWaveIndexPolicyMember $policyMember = null,
    ): string
    {
        if (
            $member->blockedGovernanceStatus !== null
            || in_array($member->readinessStatus, ['blocked_override_eligible', 'blocked_not_safely_remediable'], true)
        ) {
            return 'blocked';
        }

        if ($member->launchTier === WaveClassification::STABLE && $member->readinessStatus === 'publish_ready') {
            return 'stable';
        }

        if (
            $member->launchTier === WaveClassification::CANDIDATE
            || ($policyMember?->policyState ?? $member->lifecycleState) === CareerIndexLifecycleState::PROMOTION_CANDIDATE
        ) {
            return 'candidate';
        }

        return 'hold';
    }
}
