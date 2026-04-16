<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

use App\Domain\Career\IndexStateValue;
use App\Domain\Career\Operations\CareerCrosswalkOverrideResolver;
use App\Domain\Career\Operations\CareerCrosswalkReviewQueueService;
use App\Domain\Career\Operations\CareerEditorialPatchAuthorityService;
use App\Domain\Career\Production\CareerAssetBatchManifestBuilder;
use App\DTO\Career\CareerFullReleaseLedger;
use App\DTO\Career\CareerFullReleaseLedgerMember;
use App\Models\Occupation;

final class CareerFullReleaseLedgerService
{
    public const LEDGER_KIND = 'career_full_release_ledger';

    public const LEDGER_VERSION = 'career.release_ledger.full_342.v1';

    public const SCOPE = 'career_all_342';

    /**
     * @var list<string>
     */
    private const BATCH_MANIFEST_PATHS = [
        'docs/career/batches/batch_2_manifest.json',
        'docs/career/batches/batch_3_manifest.json',
        'docs/career/batches/batch_4_manifest.json',
    ];

    /**
     * @var array<string, true>
     */
    private const REVIEW_NEEDED_CROSSWALK_MODES = [
        'local_heavy_interpretation' => true,
        'unmapped' => true,
        'functional_equivalent' => true,
    ];

    public function __construct(
        private readonly FirstWaveManifestReader $firstWaveManifestReader,
        private readonly CareerAssetBatchManifestBuilder $manifestBuilder,
        private readonly CareerFirstWaveLaunchReadinessAuditService $firstWaveAuditService,
        private readonly CareerEditorialPatchAuthorityService $patchAuthorityService,
        private readonly CareerCrosswalkReviewQueueService $reviewQueueService,
        private readonly CareerCrosswalkOverrideResolver $crosswalkOverrideResolver,
    ) {}

    public function build(): CareerFullReleaseLedger
    {
        $firstWaveManifest = $this->firstWaveManifestReader->read();
        $firstWaveAuditPayload = $this->safeFirstWaveAudit();
        $firstWaveAudit = $firstWaveAuditPayload['audit'];
        $firstWaveAuditAvailable = (bool) ($firstWaveAuditPayload['available'] ?? false);
        $batchMembers = $this->loadBatchMembers();
        $trackedMembers = $this->buildTrackedMembers($firstWaveManifest, $batchMembers);

        $auditBySlug = $this->mapBySlug((array) ($firstWaveAudit['members'] ?? []));
        $batchContextBySlug = $this->buildBatchContextBySlug($trackedMembers);
        $approvedPatchesBySlug = $this->approvedPatchesBySlug();

        $subjects = $this->buildQueueSubjects($trackedMembers, $auditBySlug);
        $reviewQueue = $this->reviewQueueService->build(
            subjects: $subjects,
            approvedPatchesBySlug: $approvedPatchesBySlug,
            batchContextBySlug: $batchContextBySlug,
            scope: self::SCOPE,
        )->toArray();
        $reviewQueueBySlug = $this->mapBySlug((array) ($reviewQueue['items'] ?? []), 'subject_slug');

        $resolvedCrosswalk = $this->crosswalkOverrideResolver->resolve($subjects, $approvedPatchesBySlug);
        $resolvedBySlug = $this->mapBySlug((array) ($resolvedCrosswalk['resolved'] ?? []), 'subject_slug');

        $indexStateBySlug = $this->loadIndexStateBySlug(array_keys($trackedMembers));

        $releaseCounts = [
            'public_detail_indexable' => 0,
            'public_detail_conservative' => 0,
            'explorer_only' => 0,
            'family_handoff' => 0,
            'review_needed' => 0,
            'blocked' => 0,
        ];

        $opsHandoffCounts = [
            'review_queue_total' => (int) data_get($reviewQueue, 'counts.total', 0),
            'family_handoff_total' => 0,
            'review_needed_total' => 0,
            'explorer_only_total' => 0,
            'override_applied_total' => (int) data_get($resolvedCrosswalk, 'counts.override_applied', 0),
            'unmapped_total' => 0,
        ];

        $members = [];

        foreach ($trackedMembers as $slug => $tracked) {
            $auditRow = $auditBySlug[$slug] ?? null;
            $queueRow = $reviewQueueBySlug[$slug] ?? null;
            $resolvedRow = $resolvedBySlug[$slug] ?? null;
            $indexRow = $indexStateBySlug[$slug] ?? null;

            $currentCrosswalkMode = $this->normalizeNullableString($tracked['crosswalk_mode'] ?? null);
            $currentIndexState = $this->normalizeIndexState((string) ($auditRow['lifecycle_state'] ?? $indexRow['current_index_state'] ?? ''));
            $indexEligible = (bool) ($auditRow['index_eligible'] ?? $indexRow['index_eligible'] ?? false);
            $publicIndexState = $this->normalizePublicIndexState(
                (string) ($auditRow['public_index_state'] ?? $indexRow['public_index_state'] ?? ''),
                $indexEligible,
            );

            $blockedGovernanceStatus = $this->normalizeNullableString($auditRow['blocked_governance_status'] ?? null);
            $readinessStatus = $this->normalizeNullableString($auditRow['readiness_status'] ?? null);

            $releaseCohort = $this->resolveReleaseCohort(
                firstWaveMember: (bool) ($tracked['first_wave_member'] ?? false),
                batchOrigin: $this->normalizeNullableString($tracked['batch_origin'] ?? null),
                crosswalkMode: $currentCrosswalkMode,
                readinessStatus: $readinessStatus,
                blockedGovernanceStatus: $blockedGovernanceStatus,
                publicIndexState: $publicIndexState,
                queueRow: $queueRow,
                resolvedRow: $resolvedRow,
            );

            $blockerReasons = $this->buildBlockerReasons(
                releaseCohort: $releaseCohort,
                tracked: $tracked,
                readinessStatus: $readinessStatus,
                blockedGovernanceStatus: $blockedGovernanceStatus,
                publicIndexState: $publicIndexState,
                queueRow: $queueRow,
                firstWaveAuditAvailable: $firstWaveAuditAvailable,
            );

            $releaseCounts[$releaseCohort]++;
            if ($releaseCohort === 'family_handoff') {
                $opsHandoffCounts['family_handoff_total']++;
            }
            if ($releaseCohort === 'review_needed') {
                $opsHandoffCounts['review_needed_total']++;
            }
            if ($releaseCohort === 'explorer_only') {
                $opsHandoffCounts['explorer_only_total']++;
            }
            if ($currentCrosswalkMode === 'unmapped') {
                $opsHandoffCounts['unmapped_total']++;
            }

            $members[] = new CareerFullReleaseLedgerMember(
                memberKind: 'career_tracked_occupation',
                canonicalSlug: $slug,
                canonicalTitleEn: $this->normalizeNullableString($tracked['canonical_title_en'] ?? null),
                batchOrigin: $this->normalizeNullableString($tracked['batch_origin'] ?? null),
                currentCrosswalkMode: $currentCrosswalkMode,
                currentIndexState: $currentIndexState,
                publicIndexState: $publicIndexState,
                indexEligible: $indexEligible,
                releaseCohort: $releaseCohort,
                blockerReasons: $blockerReasons,
                evidenceRefs: $this->buildEvidenceRefs($tracked, $queueRow, $resolvedRow),
                resolvedTargetKind: $this->normalizeNullableString($resolvedRow['resolved_target_kind'] ?? null),
                resolvedTargetSlug: $this->normalizeNullableString($resolvedRow['resolved_target_slug'] ?? null),
                reviewQueueStatus: is_array($queueRow) ? 'queued' : null,
                overrideApplied: is_array($resolvedRow) ? (bool) ($resolvedRow['override_applied'] ?? false) : null,
            );
        }

        usort($members, static fn (CareerFullReleaseLedgerMember $left, CareerFullReleaseLedgerMember $right): int => strcmp(
            $left->canonicalSlug,
            $right->canonicalSlug,
        ));

        $expectedTotal = (int) data_get($batchMembers, 'coverage.expected_total_occupations', 0);
        $trackedTotal = count($trackedMembers);
        $missing = $expectedTotal > 0 ? max(0, $expectedTotal - $trackedTotal) : 0;

        return new CareerFullReleaseLedger(
            ledgerKind: self::LEDGER_KIND,
            ledgerVersion: self::LEDGER_VERSION,
            scope: self::SCOPE,
            trackingCounts: [
                'expected_total_occupations' => $expectedTotal,
                'tracked_total_occupations' => $trackedTotal,
                'missing_occupations' => $missing,
                'tracking_complete' => $expectedTotal > 0 && $missing === 0,
                'first_wave_members' => count((array) data_get($batchMembers, 'coverage.excluded_first_wave_slugs', [])),
                'batch_members' => max(0, $trackedTotal - count((array) data_get($batchMembers, 'coverage.excluded_first_wave_slugs', []))),
                'first_wave_audit_available' => $firstWaveAuditAvailable,
            ],
            releaseCounts: $releaseCounts,
            opsHandoffCounts: $opsHandoffCounts,
            members: $members,
        );
    }

    /**
     * @return array{audit: array<string, mixed>, available: bool}
     */
    private function safeFirstWaveAudit(): array
    {
        try {
            return [
                'audit' => $this->firstWaveAuditService->build()->toArray(),
                'available' => true,
            ];
        } catch (\Throwable) {
            return [
                'audit' => [
                    'summary_kind' => 'career_first_wave_launch_readiness_audit',
                    'summary_version' => 'career.launch_readiness.audit.v1',
                    'scope' => 'career_first_wave_10',
                    'counts' => [],
                    'members' => [],
                ],
                'available' => false,
            ];
        }
    }

    /**
     * @return array{members:array<string, array<string, mixed>>,coverage:array<string, mixed>}
     */
    private function loadBatchMembers(): array
    {
        $members = [];

        foreach (self::BATCH_MANIFEST_PATHS as $path) {
            $manifest = $this->manifestBuilder->fromPath($path);

            foreach ($manifest->members as $member) {
                $slug = trim((string) $member->canonicalSlug);
                if ($slug === '') {
                    continue;
                }

                $members[$slug] = [
                    'canonical_slug' => $slug,
                    'canonical_title_en' => $member->canonicalTitleEn,
                    'crosswalk_mode' => $member->crosswalkMode,
                    'batch_origin' => $manifest->batchKey,
                    'family_slug' => $member->familySlug,
                    'expected_publish_track' => $member->expectedPublishTrack,
                    'first_wave_member' => false,
                ];
            }
        }

        $setPath = base_path('docs/career/batches/b71x_batch_set.json');
        $setConfig = json_decode((string) file_get_contents($setPath), true);

        return [
            'members' => $members,
            'coverage' => is_array($setConfig['coverage_baseline'] ?? null)
                ? $setConfig['coverage_baseline']
                : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $firstWaveManifest
     * @param  array{members:array<string, array<string, mixed>>,coverage:array<string, mixed>}  $batchMembers
     * @return array<string, array<string, mixed>>
     */
    private function buildTrackedMembers(array $firstWaveManifest, array $batchMembers): array
    {
        $tracked = [];
        $firstWaveBySlug = [];

        foreach ((array) ($firstWaveManifest['occupations'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $slug = trim((string) ($row['canonical_slug'] ?? ''));
            if ($slug === '') {
                continue;
            }

            $firstWaveBySlug[$slug] = [
                'canonical_slug' => $slug,
                'canonical_title_en' => $this->normalizeNullableString($row['canonical_title_en'] ?? null),
                'crosswalk_mode' => $this->normalizeNullableString($row['crosswalk_mode'] ?? null),
                'batch_origin' => 'first_wave_manifest',
                'family_slug' => null,
                'expected_publish_track' => $this->normalizeNullableString($row['wave_classification'] ?? null),
                'first_wave_member' => true,
            ];
        }

        foreach ((array) ($batchMembers['members'] ?? []) as $slug => $row) {
            $tracked[$slug] = $row;
        }

        foreach ((array) data_get($batchMembers, 'coverage.excluded_first_wave_slugs', []) as $slugValue) {
            $slug = trim((string) $slugValue);
            if ($slug === '' || isset($tracked[$slug])) {
                continue;
            }

            $tracked[$slug] = [
                'canonical_slug' => $slug,
                'canonical_title_en' => $firstWaveBySlug[$slug]['canonical_title_en'] ?? null,
                'crosswalk_mode' => $firstWaveBySlug[$slug]['crosswalk_mode'] ?? null,
                'batch_origin' => 'b71x_excluded_first_wave',
                'family_slug' => null,
                'expected_publish_track' => null,
                'first_wave_member' => true,
            ];
        }

        ksort($tracked);

        return $tracked;
    }

    /**
     * @param  array<string, array<string, mixed>>  $trackedMembers
     * @return array<string, array{batch_origin:?string,publish_track:?string,family_slug:?string}>
     */
    private function buildBatchContextBySlug(array $trackedMembers): array
    {
        $context = [];

        foreach ($trackedMembers as $slug => $member) {
            $context[$slug] = [
                'batch_origin' => $this->normalizeNullableString($member['batch_origin'] ?? null),
                'publish_track' => $this->normalizeNullableString($member['expected_publish_track'] ?? null),
                'family_slug' => $this->normalizeNullableString($member['family_slug'] ?? null),
            ];
        }

        return $context;
    }

    /**
     * @param  array<string, array<string, mixed>>  $trackedMembers
     * @param  array<string, array<string, mixed>>  $auditBySlug
     * @return list<array<string, mixed>>
     */
    private function buildQueueSubjects(array $trackedMembers, array $auditBySlug): array
    {
        $subjects = [];

        foreach ($trackedMembers as $slug => $member) {
            $audit = $auditBySlug[$slug] ?? [];

            $subjects[] = [
                'canonical_slug' => $slug,
                'crosswalk_mode' => $member['crosswalk_mode'] ?? $audit['crosswalk_mode'] ?? 'unmapped',
                'readiness_status' => $audit['readiness_status'] ?? 'blocked_override_eligible',
                'blocked_governance_status' => $audit['blocked_governance_status'] ?? null,
            ];
        }

        return $subjects;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function approvedPatchesBySlug(): array
    {
        $patches = (array) (($this->patchAuthorityService->build()->toArray())['patches'] ?? []);
        $grouped = [];

        foreach ($patches as $patch) {
            if (! is_array($patch)) {
                continue;
            }

            $slug = trim((string) ($patch['subject_slug'] ?? ''));
            $status = strtolower(trim((string) ($patch['patch_status'] ?? '')));
            if ($slug === '' || $status !== 'approved') {
                continue;
            }

            $grouped[$slug][] = $patch;
        }

        $approved = [];
        foreach ($grouped as $slug => $items) {
            usort($items, static function (array $left, array $right): int {
                $leftTime = strtotime((string) ($left['reviewed_at'] ?? $left['created_at'] ?? '')) ?: 0;
                $rightTime = strtotime((string) ($right['reviewed_at'] ?? $right['created_at'] ?? '')) ?: 0;

                return $rightTime <=> $leftTime;
            });
            $approved[$slug] = $items[0];
        }

        return $approved;
    }

    /**
     * @param  list<string>  $slugs
     * @return array<string, array{current_index_state:string,public_index_state:string,index_eligible:bool}>
     */
    private function loadIndexStateBySlug(array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        return Occupation::query()
            ->with('indexStates:id,occupation_id,index_state,index_eligible,changed_at,updated_at')
            ->whereIn('canonical_slug', $slugs)
            ->get(['id', 'canonical_slug'])
            ->mapWithKeys(function (Occupation $occupation): array {
                $indexState = $occupation->indexStates->sortByDesc(
                    static fn ($row): int => strtotime((string) ($row->changed_at ?? $row->updated_at ?? '')) ?: 0
                )->first();

                $indexEligible = (bool) ($indexState?->index_eligible ?? false);
                $currentIndexState = $this->normalizeIndexState((string) ($indexState?->index_state ?? ''));

                return [
                    (string) $occupation->canonical_slug => [
                        'current_index_state' => $currentIndexState,
                        'public_index_state' => $this->normalizePublicIndexState(
                            (string) ($indexState?->index_state ?? ''),
                            $indexEligible,
                        ),
                        'index_eligible' => $indexEligible,
                    ],
                ];
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>|null  $queueRow
     * @param  array<string, mixed>|null  $resolvedRow
     */
    private function resolveReleaseCohort(
        bool $firstWaveMember,
        ?string $batchOrigin,
        ?string $crosswalkMode,
        ?string $readinessStatus,
        ?string $blockedGovernanceStatus,
        string $publicIndexState,
        ?array $queueRow,
        ?array $resolvedRow,
    ): string {
        if (
            $blockedGovernanceStatus !== null
            || in_array((string) $readinessStatus, ['blocked_override_eligible', 'blocked_not_safely_remediable'], true)
        ) {
            return 'blocked';
        }

        if ($firstWaveMember && $readinessStatus === 'publish_ready') {
            return $publicIndexState === IndexStateValue::INDEXABLE
                ? 'public_detail_indexable'
                : 'public_detail_conservative';
        }

        $candidateTargetKind = strtolower(trim((string) ($queueRow['candidate_target_kind'] ?? '')));
        $resolvedTargetKind = strtolower(trim((string) ($resolvedRow['resolved_target_kind'] ?? '')));

        if ($candidateTargetKind === 'family' || $resolvedTargetKind === 'family') {
            return 'family_handoff';
        }

        if ($crosswalkMode === 'family_proxy') {
            return 'explorer_only';
        }

        if ($crosswalkMode !== null && isset(self::REVIEW_NEEDED_CROSSWALK_MODES[$crosswalkMode])) {
            return 'review_needed';
        }

        if ($batchOrigin !== null && in_array($batchOrigin, ['batch_2', 'batch_3'], true)) {
            return 'public_detail_conservative';
        }

        return 'review_needed';
    }

    /**
     * @param  array<string, mixed>  $tracked
     * @param  array<string, mixed>|null  $queueRow
     * @return list<string>
     */
    private function buildBlockerReasons(
        string $releaseCohort,
        array $tracked,
        ?string $readinessStatus,
        ?string $blockedGovernanceStatus,
        string $publicIndexState,
        ?array $queueRow,
        bool $firstWaveAuditAvailable,
    ): array {
        $reasons = [];

        if ($blockedGovernanceStatus !== null) {
            $reasons[] = 'blocked_governance';
        }

        if (($tracked['first_wave_member'] ?? false) !== true) {
            $reasons[] = 'full_scope_readiness_authority_not_materialized';
        }
        if (($tracked['first_wave_member'] ?? false) === true && $firstWaveAuditAvailable === false) {
            $reasons[] = 'first_wave_readiness_audit_unavailable';
        }

        if ($releaseCohort === 'public_detail_conservative') {
            if ($publicIndexState !== IndexStateValue::INDEXABLE) {
                $reasons[] = 'public_index_not_indexable';
            }
            if (($tracked['batch_origin'] ?? null) === 'batch_3') {
                $reasons[] = 'batch3_default_noindex_conservative';
            }
        }

        if ($releaseCohort === 'explorer_only') {
            $reasons[] = 'family_proxy_explorer_only';
        }

        if ($releaseCohort === 'family_handoff') {
            $reasons[] = 'family_handoff_required';
        }

        if ($releaseCohort === 'review_needed') {
            $reasons[] = 'review_queue_required';
            if (is_array($queueRow)) {
                foreach ((array) ($queueRow['queue_reason'] ?? []) as $reason) {
                    if (is_string($reason) && trim($reason) !== '') {
                        $reasons[] = trim($reason);
                    }
                }
            }
        }

        if ($releaseCohort === 'blocked' && $readinessStatus !== null) {
            $reasons[] = 'first_wave_readiness_'.$readinessStatus;
        }

        return array_values(array_unique(array_filter($reasons, static fn (mixed $reason): bool => is_string($reason) && $reason !== '')));
    }

    /**
     * @param  array<string, mixed>  $tracked
     * @param  array<string, mixed>|null  $queueRow
     * @param  array<string, mixed>|null  $resolvedRow
     * @return array<string, mixed>
     */
    private function buildEvidenceRefs(array $tracked, ?array $queueRow, ?array $resolvedRow): array
    {
        return [
            'tracking_source' => [
                'kind' => 'career_batch_coverage_set',
                'path' => 'docs/career/batches/b71x_batch_set.json',
            ],
            'batch_manifest' => [
                'kind' => 'career_asset_batch_manifest',
                'batch_origin' => $tracked['batch_origin'] ?? null,
            ],
            'readiness_authority' => [
                'kind' => 'career_first_wave_launch_readiness_audit_v2',
                'scope' => 'career_first_wave_10',
                'applies_directly' => (bool) ($tracked['first_wave_member'] ?? false),
            ],
            'crosswalk_queue' => [
                'kind' => 'career_crosswalk_review_queue',
                'queued' => is_array($queueRow),
            ],
            'override_resolution' => [
                'kind' => 'career_crosswalk_override_resolver',
                'override_applied' => is_array($resolvedRow) ? (bool) ($resolvedRow['override_applied'] ?? false) : false,
            ],
        ];
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

    private function normalizePublicIndexState(string $state, bool $indexEligible): string
    {
        $normalized = strtolower(trim($state));

        if (in_array($normalized, [IndexStateValue::INDEXABLE, IndexStateValue::TRUST_LIMITED, IndexStateValue::NOINDEX], true)) {
            return $normalized;
        }

        return IndexStateValue::publicFacing($normalized, $indexEligible);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function mapBySlug(array $rows, string $key = 'canonical_slug'): array
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

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
