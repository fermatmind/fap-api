<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

use App\Domain\Career\Operations\CareerCrosswalkBacklogConvergenceService;
use App\DTO\Career\CareerLaunchGovernanceClosure;
use App\DTO\Career\CareerLaunchGovernanceClosureMember;
use App\Services\Analytics\CareerConversionClosureBuilder;

final class CareerLaunchGovernanceClosureService
{
    public const GOVERNANCE_KIND = 'career_launch_governance_closure';

    public const GOVERNANCE_VERSION = 'career.governance.v1';

    public const SCOPE = 'career_all_342';

    public const GOVERNANCE_MATURE_PUBLIC_LAUNCH = 'mature_public_launch';

    public const GOVERNANCE_PUBLIC_BUT_CONSERVATIVE = 'public_but_conservative';

    public const GOVERNANCE_NOT_YET_MATURE = 'not_yet_mature';

    private const OPERATIONS_READY = 'strong_operations_ready';

    private const OPERATIONS_NOT_READY = 'not_strong_operations_ready';

    public function __construct(
        private readonly CareerFullReleaseLedgerService $fullReleaseLedgerService,
        private readonly CareerStrongIndexEligibilityService $strongIndexEligibilityService,
        private readonly CareerCrosswalkBacklogConvergenceService $crosswalkBacklogConvergenceService,
        private readonly CareerLifecycleOperationalSummaryService $lifecycleOperationalSummaryService,
        private readonly CareerConversionClosureBuilder $conversionClosureBuilder,
    ) {}

    public function build(): CareerLaunchGovernanceClosure
    {
        $releaseLedger = $this->fullReleaseLedgerService->build()->toArray();
        $strongIndexSnapshot = $this->strongIndexEligibilityService->buildFromReleaseLedger($releaseLedger)->toArray();
        $backlogConvergence = $this->crosswalkBacklogConvergenceService->buildFromReleaseLedger($releaseLedger)->toArray();
        $trackedSlugs = $this->trackedSlugsFromReleaseLedger($releaseLedger);
        $lifecycleSummary = $this->lifecycleOperationalSummaryService->buildForTrackedSlugs($trackedSlugs)->toArray();
        $conversionClosure = $this->conversionClosureBuilder->build()->toArray();

        $strongIndexBySlug = $this->mapBySlug((array) ($strongIndexSnapshot['members'] ?? []), 'canonical_slug');
        $convergenceBySlug = $this->mapBySlug((array) ($backlogConvergence['members'] ?? []), 'canonical_slug');
        $lifecycleBySlug = $this->mapBySlug((array) ($lifecycleSummary['members'] ?? []), 'canonical_slug');

        $globalConversionReady = (bool) data_get($conversionClosure, 'readiness.closure_ready', false);

        $summary = [
            'mature_public_launch_count' => 0,
            'public_but_conservative_count' => 0,
            'strong_index_ready_count' => 0,
            'strong_operations_ready_count' => 0,
            'not_yet_ready_count' => 0,
        ];

        $members = [];
        foreach ((array) ($releaseLedger['members'] ?? []) as $releaseMember) {
            if (! is_array($releaseMember)) {
                continue;
            }

            $slug = trim((string) ($releaseMember['canonical_slug'] ?? ''));
            if ($slug === '') {
                continue;
            }

            $releaseState = $this->normalizeState($releaseMember['release_cohort'] ?? null) ?? 'blocked';
            $strongIndexState = $this->normalizeState(data_get($strongIndexBySlug, $slug.'.strong_index_decision'))
                ?? CareerStrongIndexEligibilityService::DECISION_NOT_ELIGIBLE;

            $strongIndexReady = $strongIndexState === CareerStrongIndexEligibilityService::DECISION_STRONG_INDEX_READY;
            if ($strongIndexReady) {
                $summary['strong_index_ready_count']++;
            }

            $operationsState = $this->resolveOperationsState(
                releaseMember: $releaseMember,
                convergenceMember: $convergenceBySlug[$slug] ?? null,
                lifecycleMember: $lifecycleBySlug[$slug] ?? null,
                globalConversionReady: $globalConversionReady,
            );
            $strongOperationsReady = $operationsState === self::OPERATIONS_READY;
            if ($strongOperationsReady) {
                $summary['strong_operations_ready_count']++;
            }

            $governanceState = $this->resolveGovernanceState(
                releaseState: $releaseState,
                strongIndexReady: $strongIndexReady,
                strongOperationsReady: $strongOperationsReady,
            );

            if ($governanceState === self::GOVERNANCE_MATURE_PUBLIC_LAUNCH) {
                $summary['mature_public_launch_count']++;
            } elseif ($governanceState === self::GOVERNANCE_PUBLIC_BUT_CONSERVATIVE) {
                $summary['public_but_conservative_count']++;
            } else {
                $summary['not_yet_ready_count']++;
            }

            $members[] = new CareerLaunchGovernanceClosureMember(
                memberKind: 'career_tracked_occupation',
                canonicalSlug: $slug,
                releaseState: $releaseState,
                strongIndexState: $strongIndexState,
                operationsState: $operationsState,
                governanceState: $governanceState,
                strongIndexReady: $strongIndexReady,
                strongOperationsReady: $strongOperationsReady,
                blockingReasons: $this->buildBlockingReasons(
                    releaseMember: $releaseMember,
                    strongIndexMember: $strongIndexBySlug[$slug] ?? null,
                    convergenceMember: $convergenceBySlug[$slug] ?? null,
                    lifecycleMember: $lifecycleBySlug[$slug] ?? null,
                    globalConversionReady: $globalConversionReady,
                    governanceState: $governanceState,
                ),
                evidenceRefs: [
                    'release_ledger_kind' => $this->normalizeState($releaseLedger['ledger_kind'] ?? null),
                    'release_ledger_version' => $this->normalizeState($releaseLedger['ledger_version'] ?? null),
                    'strong_index_snapshot_kind' => $this->normalizeState($strongIndexSnapshot['snapshot_kind'] ?? null),
                    'strong_index_snapshot_version' => $this->normalizeState($strongIndexSnapshot['snapshot_version'] ?? null),
                    'convergence_authority_kind' => $this->normalizeState($backlogConvergence['authority_kind'] ?? null),
                    'convergence_authority_version' => $this->normalizeState($backlogConvergence['authority_version'] ?? null),
                    'lifecycle_summary_kind' => $this->normalizeState($lifecycleSummary['summary_kind'] ?? null),
                    'lifecycle_summary_version' => $this->normalizeState($lifecycleSummary['summary_version'] ?? null),
                    'conversion_summary_kind' => $this->normalizeState($conversionClosure['summary_kind'] ?? null),
                    'conversion_summary_version' => $this->normalizeState($conversionClosure['summary_version'] ?? null),
                    'release_blockers' => $this->normalizeStringList($releaseMember['blocker_reasons'] ?? []),
                    'strong_index_reasons' => $this->normalizeStringList(data_get($strongIndexBySlug, $slug.'.decision_reasons', [])),
                    'convergence_state' => $this->normalizeState(data_get($convergenceBySlug, $slug.'.convergence_state')),
                    'lifecycle_closure_state' => $this->normalizeState(data_get($lifecycleBySlug, $slug.'.closure_state')),
                    'global_conversion_closure_ready' => $globalConversionReady,
                ],
            );
        }

        usort($members, static fn (CareerLaunchGovernanceClosureMember $left, CareerLaunchGovernanceClosureMember $right): int => strcmp(
            $left->canonicalSlug,
            $right->canonicalSlug,
        ));

        $trackingCounts = [
            'expected_total_occupations' => (int) data_get($releaseLedger, 'counts.tracking_counts.expected_total_occupations', 0),
            'tracked_total_occupations' => (int) data_get($releaseLedger, 'counts.tracking_counts.tracked_total_occupations', count($members)),
            'tracking_complete' => (bool) data_get($releaseLedger, 'counts.tracking_counts.tracking_complete', false),
        ];
        $trackedTotal = max(0, (int) $trackingCounts['tracked_total_occupations']);

        $publicStatement = $this->buildPublicStatement($summary, $trackedTotal);

        return new CareerLaunchGovernanceClosure(
            governanceKind: self::GOVERNANCE_KIND,
            governanceVersion: self::GOVERNANCE_VERSION,
            scope: self::SCOPE,
            trackingCounts: $trackingCounts,
            summary: $summary,
            members: $members,
            publicStatement: $publicStatement,
        );
    }

    /**
     * @param  array<string, mixed>  $releaseMember
     * @param  array<string, mixed>|null  $convergenceMember
     * @param  array<string, mixed>|null  $lifecycleMember
     */
    private function resolveOperationsState(
        array $releaseMember,
        ?array $convergenceMember,
        ?array $lifecycleMember,
        bool $globalConversionReady,
    ): string {
        $releaseState = $this->normalizeState($releaseMember['release_cohort'] ?? null) ?? 'blocked';
        $convergenceState = $this->normalizeState($convergenceMember['convergence_state'] ?? null);
        $closureState = $this->normalizeState($lifecycleMember['closure_state'] ?? null) ?? 'baseline_only';

        $queueConverged = match ($convergenceState) {
            CareerCrosswalkBacklogConvergenceService::STATE_STILL_UNRESOLVED,
            CareerCrosswalkBacklogConvergenceService::STATE_REVIEW_NEEDED,
            CareerCrosswalkBacklogConvergenceService::STATE_BLOCKED,
            CareerCrosswalkBacklogConvergenceService::STATE_FAMILY_HANDOFF => false,
            CareerCrosswalkBacklogConvergenceService::STATE_RESOLVED_BY_APPROVED_PATCH => true,
            default => ! in_array($releaseState, ['family_handoff', 'review_needed', 'blocked'], true),
        };

        $lifecycleOperational = in_array($closureState, ['feedback_active', 'timeline_active', 'conversion_ready'], true);

        if ($queueConverged && $lifecycleOperational && $globalConversionReady) {
            return self::OPERATIONS_READY;
        }

        return self::OPERATIONS_NOT_READY;
    }

    private function resolveGovernanceState(
        string $releaseState,
        bool $strongIndexReady,
        bool $strongOperationsReady,
    ): string {
        if ($releaseState === 'public_detail_indexable' && $strongIndexReady && $strongOperationsReady) {
            return self::GOVERNANCE_MATURE_PUBLIC_LAUNCH;
        }

        if (in_array($releaseState, ['public_detail_indexable', 'public_detail_conservative'], true)) {
            return self::GOVERNANCE_PUBLIC_BUT_CONSERVATIVE;
        }

        return self::GOVERNANCE_NOT_YET_MATURE;
    }

    /**
     * @param  array<string, mixed>  $releaseMember
     * @param  array<string, mixed>|null  $strongIndexMember
     * @param  array<string, mixed>|null  $convergenceMember
     * @param  array<string, mixed>|null  $lifecycleMember
     * @return list<string>
     */
    private function buildBlockingReasons(
        array $releaseMember,
        ?array $strongIndexMember,
        ?array $convergenceMember,
        ?array $lifecycleMember,
        bool $globalConversionReady,
        string $governanceState,
    ): array {
        $reasons = [];

        $releaseState = $this->normalizeState($releaseMember['release_cohort'] ?? null);
        if ($releaseState !== null && ! in_array($releaseState, ['public_detail_indexable', 'public_detail_conservative'], true)) {
            $reasons[] = 'release_state_not_public_detail';
        }

        $strongIndexState = $this->normalizeState($strongIndexMember['strong_index_decision'] ?? null);
        if ($strongIndexState !== CareerStrongIndexEligibilityService::DECISION_STRONG_INDEX_READY) {
            $reasons[] = 'strong_index_not_ready';
        }

        $convergenceState = $this->normalizeState($convergenceMember['convergence_state'] ?? null);
        if ($convergenceState !== null && in_array($convergenceState, [
            CareerCrosswalkBacklogConvergenceService::STATE_STILL_UNRESOLVED,
            CareerCrosswalkBacklogConvergenceService::STATE_REVIEW_NEEDED,
            CareerCrosswalkBacklogConvergenceService::STATE_BLOCKED,
            CareerCrosswalkBacklogConvergenceService::STATE_FAMILY_HANDOFF,
        ], true)) {
            $reasons[] = 'crosswalk_backlog_not_converged';
        }

        $closureState = $this->normalizeState($lifecycleMember['closure_state'] ?? null) ?? 'baseline_only';
        if (! in_array($closureState, ['feedback_active', 'timeline_active', 'conversion_ready'], true)) {
            $reasons[] = 'lifecycle_operational_state_not_ready';
        }

        if (! $globalConversionReady) {
            $reasons[] = 'conversion_closure_global_not_ready';
        }

        if ($governanceState === self::GOVERNANCE_MATURE_PUBLIC_LAUNCH) {
            return [];
        }

        return array_values(array_unique(array_merge(
            $reasons,
            $this->normalizeStringList($releaseMember['blocker_reasons'] ?? []),
            $this->normalizeStringList($strongIndexMember['decision_reasons'] ?? []),
        )));
    }

    /**
     * @param  array<string, int>  $summary
     * @return array<string, bool|string>
     */
    private function buildPublicStatement(array $summary, int $trackedTotal): array
    {
        $canClaimMaturePublicLaunch = $trackedTotal > 0
            && (int) ($summary['mature_public_launch_count'] ?? 0) === $trackedTotal;
        $canClaimStrongIndexReady = $trackedTotal > 0
            && (int) ($summary['strong_index_ready_count'] ?? 0) === $trackedTotal;
        $canClaimStrongOperationsReady = $trackedTotal > 0
            && (int) ($summary['strong_operations_ready_count'] ?? 0) === $trackedTotal;

        $allowedExternalStatement = sprintf(
            'Career governance closure currently supports cohort-qualified external statement only: mature_public_launch=%d/%d, strong_index_ready=%d/%d, strong_operations_ready=%d/%d.',
            (int) ($summary['mature_public_launch_count'] ?? 0),
            $trackedTotal,
            (int) ($summary['strong_index_ready_count'] ?? 0),
            $trackedTotal,
            (int) ($summary['strong_operations_ready_count'] ?? 0),
            $trackedTotal,
        );

        if ($canClaimMaturePublicLaunch && $canClaimStrongIndexReady && $canClaimStrongOperationsReady) {
            $allowedExternalStatement = sprintf(
                'Career governance closure confirms %d/%d tracked occupations meet mature_public_launch, strong_index_ready, and strong_operations_ready.',
                $trackedTotal,
                $trackedTotal,
            );
        }

        return [
            'can_claim_mature_public_launch' => $canClaimMaturePublicLaunch,
            'can_claim_strong_index_ready' => $canClaimStrongIndexReady,
            'can_claim_strong_operations_ready' => $canClaimStrongOperationsReady,
            'allowed_external_statement' => $allowedExternalStatement,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function mapBySlug(array $rows, string $key): array
    {
        $mapped = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $slug = trim((string) ($row[$key] ?? ''));
            if ($slug === '') {
                continue;
            }

            $mapped[$slug] = $row;
        }

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $releaseLedger
     * @return list<string>
     */
    private function trackedSlugsFromReleaseLedger(array $releaseLedger): array
    {
        $slugs = [];
        foreach ((array) ($releaseLedger['members'] ?? []) as $member) {
            if (! is_array($member)) {
                continue;
            }

            $slug = trim(strtolower((string) ($member['canonical_slug'] ?? '')));
            if ($slug === '') {
                continue;
            }

            $slugs[$slug] = true;
        }

        return array_keys($slugs);
    }

    private function normalizeState(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }

            $normalized = trim($item);
            if ($normalized === '') {
                continue;
            }

            $out[$normalized] = true;
        }

        return array_keys($out);
    }
}
