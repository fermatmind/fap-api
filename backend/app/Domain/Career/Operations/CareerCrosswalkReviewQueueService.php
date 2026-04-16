<?php

declare(strict_types=1);

namespace App\Domain\Career\Operations;

use App\DTO\Career\CareerCrosswalkReviewQueue;
use App\DTO\Career\CareerCrosswalkReviewQueueItem;

final class CareerCrosswalkReviewQueueService
{
    /**
     * @var array<string, true>
     */
    private const QUEUE_MODES = [
        'local_heavy_interpretation' => true,
        'family_proxy' => true,
        'functional_equivalent' => true,
    ];

    /**
     * @param  list<array<string, mixed>>  $subjects
     * @param  array<string, array<string, mixed>>  $approvedPatchesBySlug
     * @param  array<string, array{batch_origin:?string,publish_track:?string,family_slug:?string}>  $batchContextBySlug
     */
    public function build(
        array $subjects,
        array $approvedPatchesBySlug = [],
        array $batchContextBySlug = [],
        string $scope = 'career_crosswalk_editorial_ops',
    ): CareerCrosswalkReviewQueue {
        $items = [];

        foreach ($subjects as $subject) {
            if (! is_array($subject)) {
                continue;
            }

            $slug = trim((string) ($subject['canonical_slug'] ?? ''));
            $mode = strtolower(trim((string) ($subject['crosswalk_mode'] ?? '')));
            if ($slug === '' || ! isset(self::QUEUE_MODES[$mode])) {
                continue;
            }

            $batchContext = $batchContextBySlug[$slug] ?? [
                'batch_origin' => null,
                'publish_track' => null,
                'family_slug' => null,
            ];
            $approvedPatch = $approvedPatchesBySlug[$slug] ?? null;

            $candidateTargetKind = is_array($approvedPatch)
                ? $this->normalizeNullableString($approvedPatch['target_kind'] ?? null)
                : ($mode === 'family_proxy' ? 'family' : 'occupation');
            $candidateTargetSlug = is_array($approvedPatch)
                ? $this->normalizeNullableString($approvedPatch['target_slug'] ?? null)
                : ($mode === 'family_proxy'
                    ? $this->normalizeNullableString($batchContext['family_slug'] ?? null)
                    : $slug);

            $queueReasons = match ($mode) {
                'local_heavy_interpretation' => ['local_heavy_requires_editorial_patch'],
                'family_proxy' => ['family_proxy_requires_editorial_patch'],
                default => ['functional_equivalent_requires_editorial_review'],
            };

            $blockingFlags = [];
            $readinessStatus = strtolower(trim((string) ($subject['readiness_status'] ?? '')));
            if ($readinessStatus !== '' && $readinessStatus !== 'publish_ready') {
                $blockingFlags[] = 'not_publish_ready';
            }
            if (trim((string) ($subject['blocked_governance_status'] ?? '')) !== '') {
                $blockingFlags[] = 'governance_blocked';
            }
            if (! is_array($approvedPatch)) {
                $blockingFlags[] = 'approved_patch_missing';
            }

            $items[] = new CareerCrosswalkReviewQueueItem(
                subjectSlug: $slug,
                currentCrosswalkMode: $mode,
                queueReasons: $queueReasons,
                candidateTargetKind: $candidateTargetKind,
                candidateTargetSlug: $candidateTargetSlug,
                requiresEditorialPatch: true,
                batchOrigin: $this->normalizeNullableString($batchContext['batch_origin'] ?? null),
                publishTrack: $this->normalizeNullableString($batchContext['publish_track'] ?? null),
                blockingFlags: array_values(array_unique($blockingFlags)),
            );
        }

        return new CareerCrosswalkReviewQueue(
            scope: $scope,
            items: $items,
        );
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }
}
