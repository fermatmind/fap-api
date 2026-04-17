<?php

declare(strict_types=1);

namespace App\Domain\Career\Operations;

use App\Domain\Career\Publish\CareerFullReleaseLedgerService;
use App\DTO\Career\CareerCrosswalkBacklogConvergenceMember;
use App\DTO\Career\CareerCrosswalkBacklogConvergenceSnapshot;

final class CareerCrosswalkBacklogConvergenceService
{
    public const AUTHORITY_KIND = 'career_crosswalk_backlog_convergence';

    public const AUTHORITY_VERSION = 'career.crosswalk_convergence.v1';

    public const SCOPE = 'career_all_342';

    public const STATE_STILL_UNRESOLVED = 'still_unresolved';

    public const STATE_RESOLVED_BY_APPROVED_PATCH = 'resolved_by_approved_patch';

    public const STATE_FAMILY_HANDOFF = 'family_handoff';

    public const STATE_REVIEW_NEEDED = 'review_needed';

    public const STATE_BLOCKED = 'blocked';

    public const AGING_METRIC_BASIS = 'latest_unresolved_patch_created_at';

    /**
     * @var array<string, true>
     */
    private const UNRESOLVED_MODES = [
        'local_heavy_interpretation' => true,
        'family_proxy' => true,
        'unmapped' => true,
        'functional_equivalent' => true,
    ];

    public function __construct(
        private readonly CareerFullReleaseLedgerService $fullReleaseLedgerService,
        private readonly CareerCrosswalkReviewQueueService $reviewQueueService,
        private readonly CareerEditorialPatchAuthorityService $patchAuthorityService,
        private readonly CareerCrosswalkOverrideResolver $overrideResolver,
    ) {}

    public function build(): CareerCrosswalkBacklogConvergenceSnapshot
    {
        return $this->buildFromReleaseLedger($this->fullReleaseLedgerService->build()->toArray());
    }

    /**
     * @param  array<string, mixed>  $ledger
     */
    public function buildFromReleaseLedger(array $ledger): CareerCrosswalkBacklogConvergenceSnapshot
    {
        $trackedBySlug = $this->mapBySlug((array) ($ledger['members'] ?? []), 'canonical_slug');

        $patches = (array) (($this->patchAuthorityService->build(self::SCOPE)->toArray())['patches'] ?? []);
        $latestPatchBySlug = $this->latestPatchBySlug($patches);
        $approvedPatchBySlug = $this->approvedPatchBySlug($patches);

        $subjects = $this->buildQueueSubjects($trackedBySlug);
        $queue = $this->reviewQueueService->build(
            subjects: $subjects,
            approvedPatchesBySlug: $approvedPatchBySlug,
            batchContextBySlug: $this->batchContextBySlug($trackedBySlug),
            scope: self::SCOPE,
        )->toArray();
        $queueBySlug = $this->mapBySlug((array) ($queue['items'] ?? []), 'subject_slug');

        $resolved = $this->overrideResolver->resolve($subjects, $approvedPatchBySlug);
        $resolvedBySlug = $this->mapBySlug((array) ($resolved['resolved'] ?? []), 'subject_slug');

        $unresolvedByMode = $this->unresolvedByMode($queueBySlug, $approvedPatchBySlug);

        $stateCounts = [
            self::STATE_STILL_UNRESOLVED => 0,
            self::STATE_RESOLVED_BY_APPROVED_PATCH => 0,
            self::STATE_FAMILY_HANDOFF => 0,
            self::STATE_REVIEW_NEEDED => 0,
            self::STATE_BLOCKED => 0,
        ];

        $members = [];
        foreach ($trackedBySlug as $slug => $ledgerMember) {
            $queueRow = $queueBySlug[$slug] ?? null;
            $resolvedRow = $resolvedBySlug[$slug] ?? null;
            $latestPatch = $latestPatchBySlug[$slug] ?? null;
            $hasApprovedPatch = isset($approvedPatchBySlug[$slug]);

            $convergenceState = $this->resolveConvergenceState(
                ledgerMember: $ledgerMember,
                queueRow: $queueRow,
                resolvedRow: $resolvedRow,
                hasApprovedPatch: $hasApprovedPatch,
            );
            if ($convergenceState === null) {
                continue;
            }

            $stateCounts[$convergenceState]++;
            $members[] = new CareerCrosswalkBacklogConvergenceMember(
                canonicalSlug: $slug,
                currentCrosswalkMode: $this->nullableString($ledgerMember['current_crosswalk_mode'] ?? null),
                convergenceState: $convergenceState,
                queueReason: $this->normalizeStringList($queueRow['queue_reason'] ?? []),
                agingDays: $this->resolveAgingDays($convergenceState, $latestPatch),
                latestPatchStatus: is_array($latestPatch)
                    ? $this->nullableString($latestPatch['patch_status'] ?? null)
                    : null,
                overrideApplied: is_array($resolvedRow) && (bool) ($resolvedRow['override_applied'] ?? false),
                resolvedTargetKind: is_array($resolvedRow)
                    ? $this->nullableString($resolvedRow['resolved_target_kind'] ?? null)
                    : null,
                resolvedTargetSlug: is_array($resolvedRow)
                    ? $this->nullableString($resolvedRow['resolved_target_slug'] ?? null)
                    : null,
                evidenceRefs: [
                    'release_ledger_kind' => (string) ($ledger['ledger_kind'] ?? ''),
                    'release_cohort' => $this->nullableString($ledgerMember['release_cohort'] ?? null),
                    'blocking_flags' => $this->normalizeStringList($queueRow['blocking_flags'] ?? []),
                    'candidate_target_kind' => is_array($queueRow)
                        ? $this->nullableString($queueRow['candidate_target_kind'] ?? null)
                        : null,
                    'candidate_target_slug' => is_array($queueRow)
                        ? $this->nullableString($queueRow['candidate_target_slug'] ?? null)
                        : null,
                    'latest_unresolved_patch_created_at' => $convergenceState === self::STATE_STILL_UNRESOLVED
                        ? $this->nullableString(is_array($latestPatch) ? ($latestPatch['created_at'] ?? null) : null)
                        : null,
                ],
            );
        }

        usort($members, static fn (CareerCrosswalkBacklogConvergenceMember $left, CareerCrosswalkBacklogConvergenceMember $right): int => strcmp(
            $left->canonicalSlug,
            $right->canonicalSlug,
        ));

        $aging = $this->buildAgingSummary($members);

        $trackingCounts = (array) ($ledger['tracking_counts'] ?? []);
        $trackedTotal = (int) ($trackingCounts['tracked_total_occupations'] ?? count($trackedBySlug));

        $counts = [
            'unresolved_local_heavy_interpretation' => $unresolvedByMode['local_heavy_interpretation'],
            'unresolved_family_proxy' => $unresolvedByMode['family_proxy'],
            'unresolved_unmapped' => $unresolvedByMode['unmapped'],
            'unresolved_functional_equivalent' => $unresolvedByMode['functional_equivalent'],
            'resolved_by_approved_patch' => $stateCounts[self::STATE_RESOLVED_BY_APPROVED_PATCH],
            'resolved_by_override' => $stateCounts[self::STATE_RESOLVED_BY_APPROVED_PATCH],
            'family_handoff' => $stateCounts[self::STATE_FAMILY_HANDOFF],
            'review_needed' => $stateCounts[self::STATE_REVIEW_NEEDED],
            'blocked' => $stateCounts[self::STATE_BLOCKED],
            'still_unresolved' => $stateCounts[self::STATE_STILL_UNRESOLVED],
        ];

        $patchCoverage = $this->buildPatchCoverage(
            patches: $patches,
            trackedBySlug: $trackedBySlug,
            unresolvedByMode: $unresolvedByMode,
            approvedPatchBySlug: $approvedPatchBySlug,
        );

        return new CareerCrosswalkBacklogConvergenceSnapshot(
            authorityKind: self::AUTHORITY_KIND,
            authorityVersion: self::AUTHORITY_VERSION,
            scope: self::SCOPE,
            trackingCounts: array_merge($trackingCounts, [
                'members_in_convergence_scope' => count($members),
                'members_outside_convergence_scope' => max(0, $trackedTotal - count($members)),
            ]),
            counts: $counts,
            aging: $aging,
            patchCoverage: $patchCoverage,
            members: $members,
            convergencePolicy: [
                'convergence_states' => [
                    self::STATE_STILL_UNRESOLVED,
                    self::STATE_RESOLVED_BY_APPROVED_PATCH,
                    self::STATE_FAMILY_HANDOFF,
                    self::STATE_REVIEW_NEEDED,
                    self::STATE_BLOCKED,
                ],
                'unresolved_modes' => array_keys(self::UNRESOLVED_MODES),
                'aging_metric_basis' => self::AGING_METRIC_BASIS,
                'queue_open_timestamp_authority_present' => false,
            ],
        );
    }

    /**
     * @param  array<string, array<string, mixed>>  $trackedBySlug
     * @return list<array<string, mixed>>
     */
    private function buildQueueSubjects(array $trackedBySlug): array
    {
        $subjects = [];

        foreach ($trackedBySlug as $slug => $member) {
            $publicIndexState = strtolower(trim((string) ($member['public_index_state'] ?? '')));
            $readinessStatus = $publicIndexState === 'indexable'
                ? 'publish_ready'
                : 'blocked_override_eligible';

            $subjects[] = [
                'canonical_slug' => $slug,
                'crosswalk_mode' => $this->nullableString($member['current_crosswalk_mode'] ?? null) ?? 'unmapped',
                'readiness_status' => $readinessStatus,
                'blocked_governance_status' => $this->blockedGovernanceStatus($member),
            ];
        }

        return $subjects;
    }

    /**
     * @param  array<string, array<string, mixed>>  $trackedBySlug
     * @return array<string, array{batch_origin:?string,publish_track:?string,family_slug:?string}>
     */
    private function batchContextBySlug(array $trackedBySlug): array
    {
        $context = [];
        foreach ($trackedBySlug as $slug => $member) {
            $context[$slug] = [
                'batch_origin' => $this->nullableString($member['batch_origin'] ?? null),
                'publish_track' => null,
                'family_slug' => null,
            ];
        }

        return $context;
    }

    /**
     * @param  list<array<string, mixed>>  $patches
     * @return array<string, array<string, mixed>>
     */
    private function latestPatchBySlug(array $patches): array
    {
        $grouped = [];
        foreach ($patches as $patch) {
            if (! is_array($patch)) {
                continue;
            }
            $slug = trim((string) ($patch['subject_slug'] ?? ''));
            if ($slug === '') {
                continue;
            }

            $grouped[$slug][] = $patch;
        }

        $latest = [];
        foreach ($grouped as $slug => $rows) {
            usort($rows, static function (array $left, array $right): int {
                $leftTime = strtotime((string) ($left['reviewed_at'] ?? $left['created_at'] ?? '')) ?: 0;
                $rightTime = strtotime((string) ($right['reviewed_at'] ?? $right['created_at'] ?? '')) ?: 0;
                if ($leftTime !== $rightTime) {
                    return $rightTime <=> $leftTime;
                }

                return strcmp((string) ($right['patch_version'] ?? ''), (string) ($left['patch_version'] ?? ''));
            });

            $latest[$slug] = $rows[0];
        }

        return $latest;
    }

    /**
     * @param  list<array<string, mixed>>  $patches
     * @return array<string, array<string, mixed>>
     */
    private function approvedPatchBySlug(array $patches): array
    {
        $approved = [];

        foreach ($patches as $patch) {
            if (! is_array($patch)) {
                continue;
            }

            $slug = trim((string) ($patch['subject_slug'] ?? ''));
            $status = strtolower(trim((string) ($patch['patch_status'] ?? '')));
            if ($slug === '' || $status !== 'approved') {
                continue;
            }

            $existing = $approved[$slug] ?? null;
            if (! is_array($existing)) {
                $approved[$slug] = $patch;

                continue;
            }

            $existingTime = strtotime((string) ($existing['reviewed_at'] ?? $existing['created_at'] ?? '')) ?: 0;
            $candidateTime = strtotime((string) ($patch['reviewed_at'] ?? $patch['created_at'] ?? '')) ?: 0;

            if ($candidateTime >= $existingTime) {
                $approved[$slug] = $patch;
            }
        }

        return $approved;
    }

    /**
     * @param  array<string, array<string, mixed>>  $queueBySlug
     * @param  array<string, array<string, mixed>>  $approvedPatchBySlug
     * @return array{local_heavy_interpretation:int,family_proxy:int,unmapped:int,functional_equivalent:int}
     */
    private function unresolvedByMode(array $queueBySlug, array $approvedPatchBySlug): array
    {
        $counts = [
            'local_heavy_interpretation' => 0,
            'family_proxy' => 0,
            'unmapped' => 0,
            'functional_equivalent' => 0,
        ];

        foreach ($queueBySlug as $slug => $queueRow) {
            if (! is_array($queueRow)) {
                continue;
            }
            if (isset($approvedPatchBySlug[$slug])) {
                continue;
            }

            $mode = strtolower(trim((string) ($queueRow['current_crosswalk_mode'] ?? '')));
            if (! isset(self::UNRESOLVED_MODES[$mode])) {
                continue;
            }

            $counts[$mode]++;
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $ledgerMember
     * @param  array<string, mixed>|null  $queueRow
     * @param  array<string, mixed>|null  $resolvedRow
     */
    private function resolveConvergenceState(
        array $ledgerMember,
        ?array $queueRow,
        ?array $resolvedRow,
        bool $hasApprovedPatch,
    ): ?string {
        if ($this->isBlocked($ledgerMember)) {
            return self::STATE_BLOCKED;
        }

        if ($queueRow !== null && ! $hasApprovedPatch) {
            return self::STATE_STILL_UNRESOLVED;
        }

        $releaseCohort = strtolower(trim((string) ($ledgerMember['release_cohort'] ?? '')));
        $overrideApplied = is_array($resolvedRow) && (bool) ($resolvedRow['override_applied'] ?? false);
        $resolvedTargetKind = strtolower(trim((string) ($resolvedRow['resolved_target_kind'] ?? '')));
        $candidateTargetKind = strtolower(trim((string) ($queueRow['candidate_target_kind'] ?? '')));

        if ($overrideApplied && $hasApprovedPatch) {
            if ($resolvedTargetKind === 'family' || $candidateTargetKind === 'family') {
                return self::STATE_FAMILY_HANDOFF;
            }

            return self::STATE_RESOLVED_BY_APPROVED_PATCH;
        }

        if ($releaseCohort === 'family_handoff' || $resolvedTargetKind === 'family') {
            return self::STATE_FAMILY_HANDOFF;
        }

        if ($queueRow !== null || $releaseCohort === 'review_needed') {
            return self::STATE_REVIEW_NEEDED;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $ledgerMember
     */
    private function isBlocked(array $ledgerMember): bool
    {
        $releaseCohort = strtolower(trim((string) ($ledgerMember['release_cohort'] ?? '')));
        if ($releaseCohort === 'blocked') {
            return true;
        }

        foreach ($this->normalizeStringList($ledgerMember['blocker_reasons'] ?? []) as $reason) {
            if ($reason === 'blocked_governance' || str_starts_with($reason, 'first_wave_readiness_blocked_')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>|null  $latestPatch
     */
    private function resolveAgingDays(string $convergenceState, ?array $latestPatch): ?int
    {
        if ($convergenceState !== self::STATE_STILL_UNRESOLVED || ! is_array($latestPatch)) {
            return null;
        }

        $createdAt = trim((string) ($latestPatch['created_at'] ?? ''));
        if ($createdAt === '') {
            return null;
        }

        $timestamp = strtotime($createdAt);
        if ($timestamp === false) {
            return null;
        }

        $seconds = now('UTC')->timestamp - $timestamp;

        return $seconds < 0 ? 0 : (int) floor($seconds / 86400);
    }

    /**
     * @param  list<CareerCrosswalkBacklogConvergenceMember>  $members
     * @return array<string, mixed>
     */
    private function buildAgingSummary(array $members): array
    {
        $known = [];
        $unresolvedTotal = 0;

        foreach ($members as $member) {
            if ($member->convergenceState !== self::STATE_STILL_UNRESOLVED) {
                continue;
            }

            $unresolvedTotal++;
            if ($member->agingDays !== null) {
                $known[] = $member->agingDays;
            }
        }

        sort($known);
        $count = count($known);
        $median = 0;
        if ($count > 0) {
            $middle = intdiv($count, 2);
            $median = $count % 2 === 1
                ? $known[$middle]
                : (int) floor(($known[$middle - 1] + $known[$middle]) / 2);
        }

        return [
            'metric_basis' => self::AGING_METRIC_BASIS,
            'max_days_open' => $count > 0 ? max($known) : 0,
            'median_days_open' => $median,
            'known_sample_size' => $count,
            'unknown_sample_size' => max(0, $unresolvedTotal - $count),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $patches
     * @param  array<string, array<string, mixed>>  $trackedBySlug
     * @param  array{local_heavy_interpretation:int,family_proxy:int,unmapped:int,functional_equivalent:int}  $unresolvedByMode
     * @param  array<string, array<string, mixed>>  $approvedPatchBySlug
     * @return array<string, mixed>
     */
    private function buildPatchCoverage(
        array $patches,
        array $trackedBySlug,
        array $unresolvedByMode,
        array $approvedPatchBySlug,
    ): array {
        $trackedSlugs = array_fill_keys(array_keys($trackedBySlug), true);

        $approvedCount = 0;
        $rejectedCount = 0;
        $supersededCount = 0;

        $approvedByMode = [
            'local_heavy_interpretation' => 0,
            'family_proxy' => 0,
            'unmapped' => 0,
            'functional_equivalent' => 0,
        ];

        foreach ($patches as $patch) {
            if (! is_array($patch)) {
                continue;
            }
            $slug = trim((string) ($patch['subject_slug'] ?? ''));
            if ($slug === '' || ! isset($trackedSlugs[$slug])) {
                continue;
            }

            $status = strtolower(trim((string) ($patch['patch_status'] ?? '')));
            if ($status === 'approved') {
                $approvedCount++;
            } elseif ($status === 'rejected') {
                $rejectedCount++;
            } elseif ($status === 'superseded') {
                $supersededCount++;
            }
        }

        foreach ($approvedPatchBySlug as $slug => $patch) {
            if (! is_array($patch) || ! isset($trackedBySlug[$slug])) {
                continue;
            }
            $mode = strtolower(trim((string) ($trackedBySlug[$slug]['current_crosswalk_mode'] ?? '')));
            if (isset($approvedByMode[$mode])) {
                $approvedByMode[$mode]++;
            }
        }

        return [
            'approved_count' => $approvedCount,
            'rejected_count' => $rejectedCount,
            'superseded_count' => $supersededCount,
            'unresolved_without_approved_patch' => array_sum($unresolvedByMode),
            'by_mode' => [
                'approved_count' => $approvedByMode,
                'unresolved_without_approved_patch' => $unresolvedByMode,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $member
     */
    private function blockedGovernanceStatus(array $member): ?string
    {
        foreach ($this->normalizeStringList($member['blocker_reasons'] ?? []) as $reason) {
            if ($reason === 'blocked_governance') {
                return 'blocked_governance';
            }
        }

        return null;
    }

    /**
     * @param  array<int|string, mixed>  $rows
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
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function normalizeStringList(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $normalized = trim($value);
            if ($normalized === '') {
                continue;
            }

            $result[] = $normalized;
        }

        return array_values(array_unique($result));
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }
}
