<?php

declare(strict_types=1);

namespace App\Domain\Career\Operations;

use App\Domain\Career\Production\CareerAssetBatchManifestBuilder;
use App\Models\Occupation;
use RuntimeException;

final class CareerCrosswalkReviewQueueReadModelService
{
    /**
     * @var array<string, int>
     */
    private const MODE_RISK_PRIORITY = [
        'local_heavy_interpretation' => 400,
        'unmapped' => 300,
        'family_proxy' => 200,
        'functional_equivalent' => 100,
    ];

    /**
     * @var list<string>
     */
    private const DEFAULT_BATCH_MANIFEST_PATHS = [
        'docs/career/batches/batch_2_manifest.json',
        'docs/career/batches/batch_3_manifest.json',
        'docs/career/batches/batch_4_manifest.json',
    ];

    public function __construct(
        private readonly CareerEditorialPatchAuthorityService $patchAuthorityService,
        private readonly CareerCrosswalkReviewQueueService $reviewQueueService,
        private readonly CareerAssetBatchManifestBuilder $manifestBuilder,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function list(array $filters = []): array
    {
        $subjects = $this->subjects();
        $subjectsBySlug = $this->subjectsBySlug($subjects);
        $slugs = array_keys($subjectsBySlug);
        $familySlugBySlug = $this->familySlugBySlug($slugs);

        $patches = (array) (($this->patchAuthorityService->build()->toArray())['patches'] ?? []);
        $latestPatchBySlug = $this->latestPatchBySlug($patches);
        $approvedPatchBySlug = $this->approvedPatchBySlug($patches);
        $batchContextBySlug = $this->batchContextBySlug();

        $queue = $this->reviewQueueService->build(
            subjects: $subjects,
            approvedPatchesBySlug: $approvedPatchBySlug,
            batchContextBySlug: $batchContextBySlug,
        )->toArray();

        $items = array_map(function (array $item) use ($subjectsBySlug, $familySlugBySlug, $latestPatchBySlug): array {
            $slug = trim((string) ($item['subject_slug'] ?? ''));
            $subject = $subjectsBySlug[$slug] ?? [];
            $latestPatch = $latestPatchBySlug[$slug] ?? null;
            $latestStatus = is_array($latestPatch) ? strtolower(trim((string) ($latestPatch['patch_status'] ?? ''))) : null;

            return [
                'subject_slug' => $slug,
                'canonical_title_en' => $this->nullableString($subject['canonical_title_en'] ?? null),
                'family_slug' => $familySlugBySlug[$slug] ?? null,
                'current_crosswalk_mode' => $this->nullableString($item['current_crosswalk_mode'] ?? null),
                'candidate_target_kind' => $this->nullableString($item['candidate_target_kind'] ?? null),
                'candidate_target_slug' => $this->nullableString($item['candidate_target_slug'] ?? null),
                'queue_reason' => $this->normalizeStringArray($item['queue_reason'] ?? []),
                'requires_editorial_patch' => (bool) ($item['requires_editorial_patch'] ?? false),
                'batch_origin' => $this->nullableString($item['batch_origin'] ?? null),
                'publish_track' => $this->nullableString($item['publish_track'] ?? null),
                'blocking_flags' => $this->normalizeStringArray($item['blocking_flags'] ?? []),
                'has_approved_patch' => $latestStatus === 'approved',
                'latest_patch_key' => is_array($latestPatch) ? $this->nullableString($latestPatch['patch_key'] ?? null) : null,
                'latest_patch_status' => $latestStatus,
                'latest_patch_version' => is_array($latestPatch) ? $this->nullableString($latestPatch['patch_version'] ?? null) : null,
                'latest_patch_created_at' => is_array($latestPatch) ? $this->nullableString($latestPatch['created_at'] ?? null) : null,
            ];
        }, (array) ($queue['items'] ?? []));

        $filtered = $this->applyFilters($items, $filters);
        $sorted = $this->applySorting($filtered, (string) ($filters['sort'] ?? 'risk'));

        return [
            'queue_kind' => 'career_crosswalk_review_queue_read_model',
            'queue_version' => 'career.crosswalk.review_queue.read_model.v1',
            'scope' => (string) ($queue['scope'] ?? 'career_crosswalk_editorial_ops'),
            'filters_applied' => $this->normalizeFilterEcho($filters),
            'counts' => [
                'total' => count($sorted),
                'local_heavy_interpretation' => $this->countByMode($sorted, 'local_heavy_interpretation'),
                'family_proxy' => $this->countByMode($sorted, 'family_proxy'),
                'functional_equivalent' => $this->countByMode($sorted, 'functional_equivalent'),
                'unmapped' => $this->countByMode($sorted, 'unmapped'),
                'requires_editorial_patch' => count(array_filter(
                    $sorted,
                    static fn (array $item): bool => (bool) ($item['requires_editorial_patch'] ?? false)
                )),
                'has_approved_patch' => count(array_filter(
                    $sorted,
                    static fn (array $item): bool => (bool) ($item['has_approved_patch'] ?? false)
                )),
            ],
            'items' => array_values($sorted),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function subjects(): array
    {
        return Occupation::query()
            ->with('indexStates:id,occupation_id,index_state,index_eligible,changed_at,updated_at')
            ->orderBy('canonical_slug')
            ->get(['id', 'canonical_slug', 'canonical_title_en', 'crosswalk_mode'])
            ->map(function (Occupation $occupation): array {
                $indexState = $occupation->indexStates->sortByDesc(
                    static fn ($row): int => strtotime((string) ($row->changed_at ?? $row->updated_at ?? '')) ?: 0
                )->first();

                $readinessStatus = 'blocked_override_eligible';
                if ($indexState !== null && ($indexState->index_state ?? null) === 'indexable') {
                    $readinessStatus = 'publish_ready';
                }

                return [
                    'canonical_slug' => (string) $occupation->canonical_slug,
                    'canonical_title_en' => (string) ($occupation->canonical_title_en ?? ''),
                    'crosswalk_mode' => (string) ($occupation->crosswalk_mode ?? ''),
                    'readiness_status' => $readinessStatus,
                    'blocked_governance_status' => null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function item(string $slug, array $filters = []): ?array
    {
        $normalized = trim($slug);
        if ($normalized === '') {
            return null;
        }

        $payload = $this->list($filters);
        foreach ((array) ($payload['items'] ?? []) as $item) {
            if (trim((string) ($item['subject_slug'] ?? '')) === $normalized) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $subjects
     * @return array<string, array<string, mixed>>
     */
    private function subjectsBySlug(array $subjects): array
    {
        $result = [];
        foreach ($subjects as $subject) {
            if (! is_array($subject)) {
                continue;
            }
            $slug = trim((string) ($subject['canonical_slug'] ?? ''));
            if ($slug === '') {
                continue;
            }
            $result[$slug] = $subject;
        }

        return $result;
    }

    /**
     * @param  list<string>  $slugs
     * @return array<string, string>
     */
    private function familySlugBySlug(array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        return Occupation::query()
            ->with('family:id,canonical_slug')
            ->whereIn('canonical_slug', $slugs)
            ->get(['id', 'family_id', 'canonical_slug'])
            ->mapWithKeys(static function (Occupation $occupation): array {
                $slug = trim((string) ($occupation->canonical_slug ?? ''));
                $familySlug = trim((string) ($occupation->family?->canonical_slug ?? ''));
                if ($slug === '' || $familySlug === '') {
                    return [];
                }

                return [$slug => $familySlug];
            })
            ->all();
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
        foreach ($grouped as $slug => $items) {
            usort($items, static function (array $a, array $b): int {
                $aTime = strtotime((string) ($a['reviewed_at'] ?? $a['created_at'] ?? '')) ?: 0;
                $bTime = strtotime((string) ($b['reviewed_at'] ?? $b['created_at'] ?? '')) ?: 0;
                if ($aTime !== $bTime) {
                    return $bTime <=> $aTime;
                }

                return strcmp((string) ($b['patch_version'] ?? ''), (string) ($a['patch_version'] ?? ''));
            });

            $latest[$slug] = $items[0];
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
        foreach ($this->latestPatchBySlug($patches) as $slug => $patch) {
            $status = strtolower(trim((string) ($patch['patch_status'] ?? '')));
            if ($status === 'approved') {
                $approved[$slug] = $patch;
            }
        }

        return $approved;
    }

    /**
     * @return array<string, array{batch_origin:?string,publish_track:?string,family_slug:?string}>
     */
    private function batchContextBySlug(): array
    {
        $context = [];

        foreach (self::DEFAULT_BATCH_MANIFEST_PATHS as $path) {
            try {
                $manifest = $this->manifestBuilder->fromPath($path);
            } catch (RuntimeException) {
                continue;
            }

            foreach ($manifest->members as $member) {
                if (! isset($context[$member->canonicalSlug])) {
                    $context[$member->canonicalSlug] = [
                        'batch_origin' => $manifest->batchKey,
                        'publish_track' => $member->expectedPublishTrack,
                        'family_slug' => $member->familySlug,
                    ];
                }
            }
        }

        return $context;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function applyFilters(array $items, array $filters): array
    {
        $crosswalkMode = $this->nullableString($filters['crosswalk_mode'] ?? null);
        $publishTrack = $this->nullableString($filters['publish_track'] ?? null);
        $batchOrigin = $this->nullableString($filters['batch_origin'] ?? null);
        $queueReason = $this->nullableString($filters['queue_reason'] ?? null);
        $requiresEditorialPatch = $this->toNullableBool($filters['requires_editorial_patch'] ?? null);

        return array_values(array_filter($items, function (array $item) use (
            $crosswalkMode,
            $publishTrack,
            $batchOrigin,
            $queueReason,
            $requiresEditorialPatch
        ): bool {
            if ($crosswalkMode !== null && strtolower((string) ($item['current_crosswalk_mode'] ?? '')) !== strtolower($crosswalkMode)) {
                return false;
            }
            if ($publishTrack !== null && strtolower((string) ($item['publish_track'] ?? '')) !== strtolower($publishTrack)) {
                return false;
            }
            if ($batchOrigin !== null && strtolower((string) ($item['batch_origin'] ?? '')) !== strtolower($batchOrigin)) {
                return false;
            }
            if ($queueReason !== null) {
                $reasons = array_map('strtolower', (array) ($item['queue_reason'] ?? []));
                if (! in_array(strtolower($queueReason), $reasons, true)) {
                    return false;
                }
            }
            if ($requiresEditorialPatch !== null && (bool) ($item['requires_editorial_patch'] ?? false) !== $requiresEditorialPatch) {
                return false;
            }

            return true;
        }));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function applySorting(array $items, string $sort): array
    {
        $normalized = strtolower(trim($sort));
        if (! in_array($normalized, ['slug', 'newest', 'risk'], true)) {
            $normalized = 'risk';
        }

        usort($items, function (array $left, array $right) use ($normalized): int {
            if ($normalized === 'slug') {
                return strcmp(
                    (string) ($left['subject_slug'] ?? ''),
                    (string) ($right['subject_slug'] ?? ''),
                );
            }

            if ($normalized === 'newest') {
                $leftTime = strtotime((string) ($left['latest_patch_created_at'] ?? '')) ?: 0;
                $rightTime = strtotime((string) ($right['latest_patch_created_at'] ?? '')) ?: 0;
                if ($leftTime !== $rightTime) {
                    return $rightTime <=> $leftTime;
                }
            }

            $leftRisk = self::MODE_RISK_PRIORITY[(string) ($left['current_crosswalk_mode'] ?? '')] ?? 0;
            $rightRisk = self::MODE_RISK_PRIORITY[(string) ($right['current_crosswalk_mode'] ?? '')] ?? 0;
            if ($leftRisk !== $rightRisk) {
                return $rightRisk <=> $leftRisk;
            }

            return strcmp(
                (string) ($left['subject_slug'] ?? ''),
                (string) ($right['subject_slug'] ?? ''),
            );
        });

        return array_values($items);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function countByMode(array $items, string $mode): int
    {
        return count(array_filter($items, static fn (array $item): bool => (string) ($item['current_crosswalk_mode'] ?? '') === $mode));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function normalizeFilterEcho(array $filters): array
    {
        return [
            'crosswalk_mode' => $this->nullableString($filters['crosswalk_mode'] ?? null),
            'requires_editorial_patch' => $this->toNullableBool($filters['requires_editorial_patch'] ?? null),
            'publish_track' => $this->nullableString($filters['publish_track'] ?? null),
            'batch_origin' => $this->nullableString($filters['batch_origin'] ?? null),
            'queue_reason' => $this->nullableString($filters['queue_reason'] ?? null),
            'sort' => $this->nullableString($filters['sort'] ?? null) ?? 'risk',
        ];
    }

    /**
     * @return list<string>
     */
    private function normalizeStringArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $item): string => trim((string) $item),
            $value,
        ))));
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function toNullableBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (! is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));
        if (in_array($normalized, ['1', 'true', 'yes'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no'], true)) {
            return false;
        }

        return null;
    }
}
