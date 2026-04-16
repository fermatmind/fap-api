<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

use App\Domain\Career\IndexStateValue;
use App\DTO\Career\CareerFirstWavePromotionCandidateAuthority;
use App\DTO\Career\CareerFirstWavePromotionCandidateMember;
use App\Services\Career\Config\CareerThresholdExperimentAuthorityService;

final class CareerFirstWavePromotionCandidateEngine
{
    public const SCOPE = 'career_first_wave_10';

    public const DECISION_AUTO_NOMINATE = 'auto_nominate';

    public const DECISION_MANUAL_REVIEW_ONLY = 'manual_review_only';

    public const DECISION_NOT_ELIGIBLE = 'not_eligible';

    /**
     * @var array<string, true>
     */
    private const AUTO_CROSSWALK_MODES = [
        'exact' => true,
        'trust_inheritance' => true,
    ];

    /**
     * @var array<string, true>
     */
    private const NOT_ELIGIBLE_CROSSWALK_MODES = [
        'local_heavy_interpretation' => true,
        'family_proxy' => true,
        'unmapped' => true,
    ];

    public function __construct(
        private readonly CareerThresholdExperimentAuthorityService $runtimeConfigAuthority,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $subjects
     */
    public function build(array $subjects, string $scope = self::SCOPE): CareerFirstWavePromotionCandidateAuthority
    {
        $counts = [
            self::DECISION_AUTO_NOMINATE => 0,
            self::DECISION_MANUAL_REVIEW_ONLY => 0,
            self::DECISION_NOT_ELIGIBLE => 0,
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
            $counts[$member->engineDecision]++;
            $members[] = $member;
        }

        return new CareerFirstWavePromotionCandidateAuthority(
            scope: $scope,
            counts: $counts,
            members: $members,
        );
    }

    /**
     * @param  array<string, mixed>  $subject
     */
    private function evaluate(string $canonicalSlug, array $subject): CareerFirstWavePromotionCandidateMember
    {
        $currentIndexState = $this->normalizeLifecycleState(
            (string) ($subject['current_index_state'] ?? $subject['lifecycle_state'] ?? ''),
        );
        $publicIndexState = $this->normalizePublicIndexState((string) ($subject['public_index_state'] ?? ''));
        $indexEligible = (bool) ($subject['index_eligible'] ?? false);
        $confidenceScore = is_numeric($subject['confidence_score'] ?? null) ? (int) round((float) $subject['confidence_score']) : null;
        $reviewerStatus = $this->normalizeNullableString($subject['reviewer_status'] ?? null);
        $allowStrongClaim = (bool) ($subject['allow_strong_claim'] ?? false);
        $crosswalkMode = $this->normalizeNullableString($subject['crosswalk_mode'] ?? null);
        $blockedGovernanceStatus = $this->normalizeNullableString($subject['blocked_governance_status'] ?? null);
        $nextStepLinksCount = is_numeric($subject['next_step_links_count'] ?? null) ? max(0, (int) $subject['next_step_links_count']) : 0;
        $trustFreshness = is_array($subject['trust_freshness'] ?? null) ? $subject['trust_freshness'] : [];
        $trustStalenessState = $this->normalizeNullableString($trustFreshness['review_staleness_state'] ?? null) ?? 'unknown_due_date';

        $decisionEvidence = [
            'index_eligible' => $indexEligible,
            'confidence_score' => $confidenceScore,
            'reviewer_status' => $reviewerStatus,
            'allow_strong_claim' => $allowStrongClaim,
            'crosswalk_mode' => $crosswalkMode,
            'blocked_governance_status' => $blockedGovernanceStatus,
            'next_step_links_count' => $nextStepLinksCount,
            'trust_freshness' => [
                'review_due_known' => (bool) ($trustFreshness['review_due_known'] ?? false),
                'review_staleness_state' => $trustStalenessState,
            ],
        ];

        $isConfidenceEligible = $confidenceScore !== null
            && $confidenceScore >= $this->runtimeConfigAuthority->confidencePromotionCandidateMin();
        $isReviewerApproved = $reviewerStatus === 'approved';
        $isCrosswalkAutoSafe = $crosswalkMode !== null && isset(self::AUTO_CROSSWALK_MODES[$crosswalkMode]);
        $isNotEligibleCrosswalk = $crosswalkMode !== null && isset(self::NOT_ELIGIBLE_CROSSWALK_MODES[$crosswalkMode]);
        $strongClaimRequired = $this->runtimeConfigAuthority->promotionStrongClaimRequired();

        $decision = self::DECISION_MANUAL_REVIEW_ONLY;
        $decisionReasons = [];

        if (! $indexEligible) {
            $decision = self::DECISION_NOT_ELIGIBLE;
            $decisionReasons[] = 'index_eligible_false';
        } else {
            $decisionReasons[] = 'index_eligible_true';
        }

        if ($currentIndexState !== CareerIndexLifecycleState::NOINDEX) {
            $decision = self::DECISION_NOT_ELIGIBLE;
            $decisionReasons[] = 'currently_not_noindex';
        } else {
            $decisionReasons[] = 'currently_noindex';
        }

        if (! $isConfidenceEligible) {
            $decision = self::DECISION_NOT_ELIGIBLE;
            $decisionReasons[] = 'confidence_below_threshold';
        } else {
            $decisionReasons[] = 'confidence_at_or_above_threshold';
        }

        if ($strongClaimRequired && ! $allowStrongClaim) {
            $decision = self::DECISION_NOT_ELIGIBLE;
            $decisionReasons[] = 'strong_claim_blocked';
        } else {
            $decisionReasons[] = 'strong_claim_allowed';
        }

        if ($blockedGovernanceStatus !== null) {
            $decision = self::DECISION_NOT_ELIGIBLE;
            $decisionReasons[] = 'governance_blocked';
        } else {
            $decisionReasons[] = 'not_governance_blocked';
        }

        if ($nextStepLinksCount < $this->runtimeConfigAuthority->promotionNextStepLinksMin()) {
            $decision = self::DECISION_NOT_ELIGIBLE;
            $decisionReasons[] = 'next_step_links_insufficient';
        } else {
            $decisionReasons[] = 'next_step_links_sufficient';
        }

        if ($isNotEligibleCrosswalk) {
            $decision = self::DECISION_NOT_ELIGIBLE;
            $decisionReasons[] = 'crosswalk_not_supported';
        }

        if ($decision !== self::DECISION_NOT_ELIGIBLE) {
            if (! $isReviewerApproved) {
                $decisionReasons[] = 'reviewer_not_approved_manual_review';
                $decision = self::DECISION_MANUAL_REVIEW_ONLY;
            } elseif ($crosswalkMode === 'functional_equivalent') {
                $decisionReasons[] = 'functional_equivalent_requires_manual_review';
                $decision = self::DECISION_MANUAL_REVIEW_ONLY;
            } elseif ($trustStalenessState === 'review_due') {
                $decisionReasons[] = 'trust_review_due_manual_review';
                $decision = self::DECISION_MANUAL_REVIEW_ONLY;
            } elseif ($isCrosswalkAutoSafe) {
                $decisionReasons[] = 'reviewer_approved';
                $decisionReasons[] = 'safe_crosswalk';
                $decision = self::DECISION_AUTO_NOMINATE;
            } else {
                $decisionReasons[] = 'crosswalk_requires_manual_review';
                $decision = self::DECISION_MANUAL_REVIEW_ONLY;
            }
        }

        return new CareerFirstWavePromotionCandidateMember(
            canonicalSlug: $canonicalSlug,
            currentIndexState: $currentIndexState,
            publicIndexState: $publicIndexState,
            engineDecision: $decision,
            autoNominationEligible: $decision === self::DECISION_AUTO_NOMINATE,
            manualReviewOnly: $decision === self::DECISION_MANUAL_REVIEW_ONLY,
            decisionReasons: array_values(array_unique($decisionReasons)),
            decisionEvidence: $decisionEvidence,
        );
    }

    private function normalizeLifecycleState(string $state): string
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
