<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

use App\Domain\Career\IndexStateValue;
use App\DTO\Career\CareerStrongIndexEligibilityMember;
use App\DTO\Career\CareerStrongIndexEligibilitySnapshot;
use App\Services\Career\Config\CareerThresholdExperimentAuthorityService;

final class CareerStrongIndexEligibilityService
{
    public const SNAPSHOT_KIND = 'career_strong_index_eligibility';

    public const SNAPSHOT_VERSION = 'career.strong_index.v1';

    public const SCOPE = 'career_all_342';

    public const DECISION_STRONG_INDEX_READY = 'strong_index_ready';

    public const DECISION_INDEXABLE_BUT_NOT_STRONG_READY = 'indexable_but_not_strong_ready';

    public const DECISION_MANUAL_ONLY = 'manual_only';

    public const DECISION_NOT_ELIGIBLE = 'not_eligible';

    /**
     * @var array<string, true>
     */
    private const STRONG_READY_CROSSWALK_MODES = [
        'exact' => true,
        'trust_inheritance' => true,
    ];

    /**
     * @var array<string, true>
     */
    private const HARD_INELIGIBLE_CROSSWALK_MODES = [
        'local_heavy_interpretation' => true,
        'family_proxy' => true,
        'unmapped' => true,
    ];

    public function __construct(
        private readonly CareerFullReleaseLedgerService $fullReleaseLedgerService,
        private readonly CareerFirstWaveLaunchReadinessAuditV2Service $launchReadinessAuditV2Service,
        private readonly CareerThresholdExperimentAuthorityService $thresholdAuthorityService,
    ) {}

    public function build(): CareerStrongIndexEligibilitySnapshot
    {
        return $this->buildFromReleaseLedger($this->fullReleaseLedgerService->build()->toArray());
    }

    /**
     * @param  array<string, mixed>  $ledger
     */
    public function buildFromReleaseLedger(array $ledger): CareerStrongIndexEligibilitySnapshot
    {
        $firstWaveAuditBySlug = $this->safeFirstWaveAuditBySlug();

        $stableConfidenceThreshold = $this->thresholdAuthorityService->confidenceStableMin();
        $nextStepLinksMinimum = $this->thresholdAuthorityService->promotionNextStepLinksMin();

        $counts = [
            self::DECISION_STRONG_INDEX_READY => 0,
            self::DECISION_INDEXABLE_BUT_NOT_STRONG_READY => 0,
            self::DECISION_MANUAL_ONLY => 0,
            self::DECISION_NOT_ELIGIBLE => 0,
        ];

        $members = [];
        foreach ((array) ($ledger['members'] ?? []) as $ledgerMember) {
            if (! is_array($ledgerMember)) {
                continue;
            }

            $slug = trim((string) ($ledgerMember['canonical_slug'] ?? ''));
            if ($slug === '') {
                continue;
            }

            $auditRow = $firstWaveAuditBySlug[$slug] ?? [];
            $evidence = $this->buildEvidence($ledgerMember, $auditRow);

            [$decision, $decisionReasons] = $this->resolveDecision(
                evidence: $evidence,
                stableConfidenceThreshold: $stableConfidenceThreshold,
                nextStepLinksMinimum: $nextStepLinksMinimum,
            );

            $counts[$decision]++;
            $members[] = new CareerStrongIndexEligibilityMember(
                memberKind: 'career_tracked_occupation',
                canonicalSlug: $slug,
                strongIndexDecision: $decision,
                decisionReasons: $decisionReasons,
                decisionEvidence: $evidence,
            );
        }

        usort($members, static fn (CareerStrongIndexEligibilityMember $left, CareerStrongIndexEligibilityMember $right): int => strcmp(
            $left->canonicalSlug,
            $right->canonicalSlug,
        ));

        return new CareerStrongIndexEligibilitySnapshot(
            snapshotKind: self::SNAPSHOT_KIND,
            snapshotVersion: self::SNAPSHOT_VERSION,
            scope: self::SCOPE,
            counts: $counts,
            members: $members,
            decisionPolicy: [
                'stable_confidence_threshold' => $stableConfidenceThreshold,
                'next_step_links_minimum' => $nextStepLinksMinimum,
                'strong_ready_crosswalk_modes' => array_keys(self::STRONG_READY_CROSSWALK_MODES),
                'hard_ineligible_crosswalk_modes' => array_keys(self::HARD_INELIGIBLE_CROSSWALK_MODES),
                'trust_freshness_manual_states' => ['review_due', 'review_unreviewed'],
                'weak_signals_deferred' => ['demand_signal', 'novelty_score', 'canonical_conflict'],
                'derived_signal_gate_state' => 'deferred',
            ],
            weakSignalGateDeferred: true,
            derivedSignalGateState: 'deferred',
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function safeFirstWaveAuditBySlug(): array
    {
        try {
            $payload = $this->launchReadinessAuditV2Service->build()->toArray();
        } catch (\Throwable) {
            return [];
        }

        $mapped = [];
        foreach ((array) ($payload['members'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $slug = trim((string) ($row['canonical_slug'] ?? ''));
            if ($slug === '') {
                continue;
            }

            $mapped[$slug] = $row;
        }

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $ledgerMember
     * @param  array<string, mixed>  $auditRow
     * @return array<string, mixed>
     */
    private function buildEvidence(array $ledgerMember, array $auditRow): array
    {
        $trustFreshness = is_array($auditRow['trust_freshness'] ?? null)
            ? $auditRow['trust_freshness']
            : [];

        return [
            'current_index_state' => $this->normalizeIndexState((string) ($ledgerMember['current_index_state'] ?? '')),
            'public_index_state' => $this->normalizePublicIndexState((string) ($ledgerMember['public_index_state'] ?? '')),
            'index_eligible' => (bool) ($ledgerMember['index_eligible'] ?? false),
            'reviewer_status' => $this->normalizeNullableString($auditRow['reviewer_status'] ?? null),
            'confidence_score' => $this->normalizeNullableInt($auditRow['confidence_score'] ?? null),
            'allow_strong_claim' => $this->normalizeNullableBool($auditRow['allow_strong_claim'] ?? null),
            'crosswalk_mode' => $this->normalizeNullableString($ledgerMember['current_crosswalk_mode'] ?? null),
            'blocked_governance_status' => $this->normalizeNullableString($auditRow['blocked_governance_status'] ?? null),
            'next_step_links_count' => $this->normalizeNullableInt($auditRow['next_step_links_count'] ?? null),
            'trust_freshness' => [
                'review_due_known' => (bool) ($trustFreshness['review_due_known'] ?? false),
                'review_staleness_state' => $this->normalizeNullableString($trustFreshness['review_staleness_state'] ?? null) ?? 'unknown_due_date',
            ],
            'release_cohort' => $this->normalizeNullableString($ledgerMember['release_cohort'] ?? null),
            'review_queue_status' => $this->normalizeNullableString($ledgerMember['review_queue_status'] ?? null),
            'override_applied' => $this->normalizeNullableBool($ledgerMember['override_applied'] ?? null),
            'resolved_target_kind' => $this->normalizeNullableString($ledgerMember['resolved_target_kind'] ?? null),
            'derived_signal_gate_state' => 'deferred',
        ];
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array{string, list<string>}
     */
    private function resolveDecision(
        array $evidence,
        int $stableConfidenceThreshold,
        int $nextStepLinksMinimum,
    ): array {
        $decisionReasons = [];

        $publicIndexState = (string) ($evidence['public_index_state'] ?? IndexStateValue::NOINDEX);
        $indexEligible = (bool) ($evidence['index_eligible'] ?? false);
        $reviewerStatus = $this->normalizeNullableString($evidence['reviewer_status'] ?? null);
        $confidenceScore = $this->normalizeNullableInt($evidence['confidence_score'] ?? null);
        $allowStrongClaim = $this->normalizeNullableBool($evidence['allow_strong_claim'] ?? null);
        $crosswalkMode = $this->normalizeNullableString($evidence['crosswalk_mode'] ?? null);
        $blockedGovernanceStatus = $this->normalizeNullableString($evidence['blocked_governance_status'] ?? null);
        $nextStepLinksCount = $this->normalizeNullableInt($evidence['next_step_links_count'] ?? null);
        $reviewQueueStatus = $this->normalizeNullableString($evidence['review_queue_status'] ?? null);
        $overrideApplied = $this->normalizeNullableBool($evidence['override_applied'] ?? null);
        $releaseCohort = $this->normalizeNullableString($evidence['release_cohort'] ?? null);
        $resolvedTargetKind = $this->normalizeNullableString($evidence['resolved_target_kind'] ?? null);
        $trustStalenessState = $this->normalizeNullableString(data_get($evidence, 'trust_freshness.review_staleness_state')) ?? 'unknown_due_date';

        $familyHandoff = $releaseCohort === 'family_handoff'
            || $resolvedTargetKind === 'family'
            || $crosswalkMode === 'family_proxy';

        $manualSignals = [];
        if (in_array($trustStalenessState, ['review_due', 'review_unreviewed'], true)) {
            $manualSignals[] = 'trust_freshness_manual_review';
        }
        if ($crosswalkMode === 'functional_equivalent') {
            $manualSignals[] = 'functional_equivalent_manual_review';
        }
        if ($reviewQueueStatus === 'queued' && $overrideApplied !== true) {
            $manualSignals[] = 'review_queue_unresolved_manual_review';
        }

        $hardIneligibleReasons = [];
        if ($familyHandoff) {
            $hardIneligibleReasons[] = 'family_handoff_not_strong_index_subject';
        }
        if ($blockedGovernanceStatus !== null) {
            $hardIneligibleReasons[] = 'governance_blocked';
        }
        if (! $indexEligible) {
            $hardIneligibleReasons[] = 'index_eligible_false';
        }
        if ($crosswalkMode !== null && isset(self::HARD_INELIGIBLE_CROSSWALK_MODES[$crosswalkMode])) {
            $hardIneligibleReasons[] = 'crosswalk_mode_not_eligible_for_strong_index';
        }

        if ($hardIneligibleReasons !== []) {
            $decisionReasons = array_values(array_unique(array_merge($hardIneligibleReasons, $manualSignals)));

            return [self::DECISION_NOT_ELIGIBLE, $decisionReasons];
        }

        $strongReady = $publicIndexState === IndexStateValue::INDEXABLE
            && $indexEligible
            && $confidenceScore !== null
            && $confidenceScore >= $stableConfidenceThreshold
            && $reviewerStatus === 'approved'
            && $allowStrongClaim === true
            && $blockedGovernanceStatus === null
            && $nextStepLinksCount !== null
            && $nextStepLinksCount >= $nextStepLinksMinimum
            && $crosswalkMode !== null
            && isset(self::STRONG_READY_CROSSWALK_MODES[$crosswalkMode])
            && ! in_array($trustStalenessState, ['review_due', 'review_unreviewed'], true)
            && ! $familyHandoff;

        if ($strongReady) {
            return [
                self::DECISION_STRONG_INDEX_READY,
                [
                    'public_index_state_indexable',
                    'index_eligible_true',
                    'confidence_meets_stable_threshold',
                    'reviewer_approved',
                    'strong_claim_allowed',
                    'next_step_links_sufficient',
                    'crosswalk_mode_strong_safe',
                    'trust_freshness_not_manual_state',
                ],
            ];
        }

        if ($manualSignals !== []) {
            return [self::DECISION_MANUAL_ONLY, array_values(array_unique($manualSignals))];
        }

        if ($publicIndexState === IndexStateValue::INDEXABLE) {
            if ($confidenceScore === null) {
                $decisionReasons[] = 'confidence_score_unknown';
            } elseif ($confidenceScore < $stableConfidenceThreshold) {
                $decisionReasons[] = 'confidence_below_stable_threshold';
            }

            if ($reviewerStatus !== 'approved') {
                $decisionReasons[] = 'reviewer_not_approved';
            }
            if ($allowStrongClaim !== true) {
                $decisionReasons[] = 'strong_claim_not_allowed';
            }
            if ($nextStepLinksCount === null || $nextStepLinksCount < $nextStepLinksMinimum) {
                $decisionReasons[] = 'next_step_links_below_minimum';
            }
            if ($crosswalkMode === null || ! isset(self::STRONG_READY_CROSSWALK_MODES[$crosswalkMode])) {
                $decisionReasons[] = 'crosswalk_mode_not_strong_safe';
            }
            if ($trustStalenessState === 'unknown_due_date') {
                $decisionReasons[] = 'trust_freshness_unknown_due_date';
            }

            if ($decisionReasons === []) {
                $decisionReasons[] = 'indexable_without_full_strong_gate_alignment';
            }

            return [self::DECISION_INDEXABLE_BUT_NOT_STRONG_READY, array_values(array_unique($decisionReasons))];
        }

        $decisionReasons[] = 'public_index_state_not_indexable';

        return [self::DECISION_NOT_ELIGIBLE, array_values(array_unique($decisionReasons))];
    }

    private function normalizeIndexState(string $state): string
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
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = strtolower(trim((string) $value));

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeNullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) round((float) $value) : null;
    }

    private function normalizeNullableBool(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }
}
