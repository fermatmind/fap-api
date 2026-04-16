<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

use App\Domain\Career\IndexStateValue;
use App\DTO\Career\CareerFirstWaveIndexPolicyAuthority;
use App\DTO\Career\CareerFirstWaveIndexPolicyMember;
use App\Services\Career\Config\CareerThresholdExperimentAuthorityService;

final class CareerFirstWaveIndexPolicyEngine
{
    public const SCOPE = 'career_first_wave_10';

    public function __construct(
        private readonly CareerThresholdExperimentAuthorityService $runtimeConfigAuthority,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $subjects
     */
    public function build(array $subjects, string $scope = self::SCOPE): CareerFirstWaveIndexPolicyAuthority
    {
        $counts = [
            CareerIndexLifecycleState::NOINDEX => 0,
            CareerIndexLifecycleState::PROMOTION_CANDIDATE => 0,
            CareerIndexLifecycleState::INDEXED => 0,
            CareerIndexLifecycleState::DEMOTED => 0,
        ];
        $members = [];

        foreach ($subjects as $subject) {
            if (! is_array($subject)) {
                continue;
            }

            $slug = $this->normalizeNullableString($subject['canonical_slug'] ?? null);
            if ($slug === null) {
                continue;
            }

            $member = $this->evaluate($slug, $subject);
            $counts[$member->policyState]++;
            $members[] = $member;
        }

        return new CareerFirstWaveIndexPolicyAuthority(
            scope: $scope,
            counts: $counts,
            members: $members,
        );
    }

    /**
     * @param  array<string, mixed>  $subject
     */
    private function evaluate(string $canonicalSlug, array $subject): CareerFirstWaveIndexPolicyMember
    {
        $currentIndexState = $this->normalizePolicyState(
            (string) ($subject['current_index_state'] ?? $subject['lifecycle_state'] ?? ''),
        );
        $indexEligible = (bool) ($subject['index_eligible'] ?? false);
        $publicIndexState = $this->normalizePublicIndexState((string) ($subject['public_index_state'] ?? ''));
        $reviewerStatus = $this->normalizeNullableString($subject['reviewer_status'] ?? null);
        $crosswalkMode = $this->normalizeNullableString($subject['crosswalk_mode'] ?? null);
        $allowStrongClaim = (bool) ($subject['allow_strong_claim'] ?? false);
        $confidenceScore = is_numeric($subject['confidence_score'] ?? null) ? (int) round((float) $subject['confidence_score']) : null;
        $blockedGovernanceStatus = $this->normalizeNullableString($subject['blocked_governance_status'] ?? null);
        $nextStepLinksCount = is_numeric($subject['next_step_links_count'] ?? null) ? max(0, (int) $subject['next_step_links_count']) : 0;

        $trustFreshness = is_array($subject['trust_freshness'] ?? null) ? $subject['trust_freshness'] : [];
        $policyEvidence = [
            'confidence_score' => $confidenceScore,
            'reviewer_status' => $reviewerStatus,
            'crosswalk_mode' => $crosswalkMode,
            'allow_strong_claim' => $allowStrongClaim,
            'blocked_governance_status' => $blockedGovernanceStatus,
            'next_step_links_count' => $nextStepLinksCount,
            'trust_freshness' => [
                'review_due_known' => (bool) ($trustFreshness['review_due_known'] ?? false),
                'review_staleness_state' => $this->normalizeNullableString($trustFreshness['review_staleness_state'] ?? null) ?? 'unknown',
            ],
        ];

        $policyState = $this->resolvePolicyState($currentIndexState, $indexEligible, $publicIndexState);

        $policyReasons = [];
        $reviewApproved = in_array($reviewerStatus, ['approved', 'reviewed'], true);
        $policyReasons[] = $reviewApproved ? 'reviewer_approved' : 'reviewer_not_approved';

        if ($crosswalkMode !== null) {
            $policyReasons[] = match ($crosswalkMode) {
                'exact', 'trust_inheritance', 'direct_match' => 'safe_crosswalk',
                'unmapped' => 'crosswalk_unmapped',
                'family_proxy' => 'crosswalk_family_proxy',
                'local_heavy_interpretation' => 'crosswalk_local_heavy_interpretation',
                default => 'crosswalk_mode_candidate_only',
            };
        }

        $policyReasons[] = $allowStrongClaim ? 'strong_claim_allowed' : 'strong_claim_blocked';

        if ($blockedGovernanceStatus !== null) {
            $policyReasons[] = 'blocked_governance';
        }

        if ($nextStepLinksCount > 0) {
            $policyReasons[] = 'next_step_links_present';
        } else {
            $policyReasons[] = 'insufficient_next_step_links';
        }

        if ($confidenceScore !== null && $confidenceScore < $this->runtimeConfigAuthority->confidencePublishMin()) {
            $policyReasons[] = 'confidence_below_threshold';
        }

        if (! $indexEligible) {
            $policyReasons[] = 'not_index_eligible';
        }

        if ($publicIndexState === IndexStateValue::TRUST_LIMITED) {
            $policyReasons[] = 'trust_limited';
        }

        switch ($policyState) {
            case CareerIndexLifecycleState::INDEXED:
                $policyReasons[] = 'indexed_ready';
                break;
            case CareerIndexLifecycleState::PROMOTION_CANDIDATE:
                $policyReasons[] = 'publish_gate_candidate';
                break;
            case CareerIndexLifecycleState::DEMOTED:
                if (! $reviewApproved) {
                    $policyReasons[] = 'demoted_review_regression';
                }
                if (! $indexEligible || $publicIndexState !== IndexStateValue::INDEXABLE) {
                    $policyReasons[] = 'demoted_trust_regression';
                }
                break;
            default:
                $policyReasons[] = 'publish_gate_hold';
                break;
        }

        return new CareerFirstWaveIndexPolicyMember(
            canonicalSlug: $canonicalSlug,
            currentIndexState: $currentIndexState,
            publicIndexState: $publicIndexState,
            indexEligible: $indexEligible,
            policyState: $policyState,
            policyReasons: array_values(array_unique($policyReasons)),
            policyEvidence: $policyEvidence,
        );
    }

    private function resolvePolicyState(string $currentIndexState, bool $indexEligible, string $publicIndexState): string
    {
        if (in_array($currentIndexState, [
            CareerIndexLifecycleState::NOINDEX,
            CareerIndexLifecycleState::PROMOTION_CANDIDATE,
            CareerIndexLifecycleState::INDEXED,
            CareerIndexLifecycleState::DEMOTED,
        ], true)) {
            return $currentIndexState;
        }

        if ($indexEligible && $publicIndexState === IndexStateValue::INDEXABLE) {
            return CareerIndexLifecycleState::INDEXED;
        }

        return CareerIndexLifecycleState::NOINDEX;
    }

    private function normalizePolicyState(string $state): string
    {
        $normalized = strtolower(trim($state));

        return match ($normalized) {
            CareerIndexLifecycleState::NOINDEX,
            CareerIndexLifecycleState::PROMOTION_CANDIDATE,
            CareerIndexLifecycleState::INDEXED,
            CareerIndexLifecycleState::DEMOTED => $normalized,
            default => CareerIndexLifecycleState::NOINDEX,
        };
    }

    private function normalizePublicIndexState(string $state): string
    {
        $normalized = strtolower(trim($state));

        return match ($normalized) {
            IndexStateValue::INDEXABLE,
            IndexStateValue::TRUST_LIMITED,
            IndexStateValue::NOINDEX => $normalized,
            default => IndexStateValue::NOINDEX,
        };
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));

        return $normalized === '' ? null : $normalized;
    }
}
