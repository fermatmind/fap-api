<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

use App\DTO\Career\CareerFirstWaveLaunchReadinessAudit;
use App\DTO\Career\CareerFirstWaveLaunchReadinessAuditMember;
use App\DTO\Career\CareerTrustFreshnessMember;

final class CareerFirstWaveLaunchReadinessAuditV2Service
{
    public const SUMMARY_VERSION = 'career.launch_readiness.audit.v2';

    public function __construct(
        private readonly CareerFirstWaveLaunchReadinessAuditService $launchReadinessAuditService,
        private readonly CareerTrustFreshnessAuthorityService $trustFreshnessAuthorityService,
    ) {}

    public function build(): CareerFirstWaveLaunchReadinessAudit
    {
        $audit = $this->launchReadinessAuditService->build();
        $freshnessAuthority = $this->trustFreshnessAuthorityService->build();

        $freshnessBySlug = [];
        foreach ($freshnessAuthority->members as $freshnessMember) {
            $freshnessBySlug[$freshnessMember->canonicalSlug] = $this->toEvidence($freshnessMember);
        }

        $members = array_map(function (CareerFirstWaveLaunchReadinessAuditMember $member) use ($freshnessBySlug): CareerFirstWaveLaunchReadinessAuditMember {
            return new CareerFirstWaveLaunchReadinessAuditMember(
                occupationUuid: $member->occupationUuid,
                canonicalSlug: $member->canonicalSlug,
                canonicalTitleEn: $member->canonicalTitleEn,
                launchTier: $member->launchTier,
                readinessStatus: $member->readinessStatus,
                lifecycleState: $member->lifecycleState,
                publicIndexState: $member->publicIndexState,
                indexEligible: $member->indexEligible,
                reviewerStatus: $member->reviewerStatus,
                crosswalkMode: $member->crosswalkMode,
                allowStrongClaim: $member->allowStrongClaim,
                confidenceScore: $member->confidenceScore,
                blockedGovernanceStatus: $member->blockedGovernanceStatus,
                nextStepLinksCount: $member->nextStepLinksCount,
                familyHubSupportingRoute: $member->familyHubSupportingRoute,
                blockers: $member->blockers,
                evidenceRefs: $member->evidenceRefs,
                trustFreshness: $freshnessBySlug[$member->canonicalSlug] ?? null,
            );
        }, $audit->members);

        return new CareerFirstWaveLaunchReadinessAudit(
            summaryVersion: self::SUMMARY_VERSION,
            scope: $audit->scope,
            counts: $audit->counts,
            members: $members,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function toEvidence(CareerTrustFreshnessMember $member): array
    {
        return [
            'reviewed_at' => $member->reviewedAt,
            'next_review_due_at' => $member->nextReviewDueAt,
            'review_due_known' => $member->reviewDueKnown,
            'review_staleness_state' => $member->reviewStalenessState,
            'review_freshness_basis' => $member->reviewFreshnessBasis,
        ];
    }
}
