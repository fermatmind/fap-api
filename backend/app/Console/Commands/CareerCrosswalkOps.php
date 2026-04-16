<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Operations\CareerCrosswalkOverrideResolver;
use App\Domain\Career\Operations\CareerCrosswalkReviewQueueService;
use App\Domain\Career\Operations\CareerEditorialPatchAuthorityService;
use App\Domain\Career\Production\CareerAssetBatchManifestBuilder;
use App\Domain\Career\Publish\CareerFirstWaveLaunchReadinessAuditV2Service;
use Illuminate\Console\Command;
use RuntimeException;

final class CareerCrosswalkOps extends Command
{
    protected $signature = 'career:crosswalk-ops
        {--mode=build-review-queue : build-review-queue|validate-patches|resolve-overrides|snapshot}
        {--manifest= : Optional batch manifest path for batch origin/publish track context}
        {--json : Emit JSON output}';

    protected $description = 'Run internal crosswalk/editorial operations baseline actions.';

    public function handle(
        CareerEditorialPatchAuthorityService $patchAuthorityService,
        CareerCrosswalkReviewQueueService $reviewQueueService,
        CareerCrosswalkOverrideResolver $overrideResolver,
        CareerFirstWaveLaunchReadinessAuditV2Service $auditService,
        CareerAssetBatchManifestBuilder $manifestBuilder,
    ): int {
        $mode = strtolower(trim((string) $this->option('mode')));
        if (! in_array($mode, ['build-review-queue', 'validate-patches', 'resolve-overrides', 'snapshot'], true)) {
            $this->error(sprintf('Unsupported --mode [%s].', (string) $this->option('mode')));

            return self::FAILURE;
        }

        $patchAuthority = $patchAuthorityService->build()->toArray();
        $patches = (array) ($patchAuthority['patches'] ?? []);
        $approvedPatchesBySlug = $this->approvedPatchesBySlug($patches);
        $subjects = (array) (($auditService->build()->toArray())['members'] ?? []);
        $batchContextBySlug = $this->batchContextBySlug(
            trim((string) $this->option('manifest')),
            $manifestBuilder,
        );

        $result = match ($mode) {
            'build-review-queue' => [
                'mode' => $mode,
                'review_queue' => $reviewQueueService->build(
                    subjects: $subjects,
                    approvedPatchesBySlug: $approvedPatchesBySlug,
                    batchContextBySlug: $batchContextBySlug,
                )->toArray(),
            ],
            'validate-patches' => [
                'mode' => $mode,
                'patch_validation' => $patchAuthorityService->validate($patches),
            ],
            'resolve-overrides' => [
                'mode' => $mode,
                'resolved_crosswalk' => $overrideResolver->resolve(
                    subjects: $subjects,
                    approvedPatchesBySlug: $approvedPatchesBySlug,
                ),
            ],
            default => [
                'mode' => $mode,
                'patch_authority' => $patchAuthority,
                'patch_validation' => $patchAuthorityService->validate($patches),
                'review_queue' => $reviewQueueService->build(
                    subjects: $subjects,
                    approvedPatchesBySlug: $approvedPatchesBySlug,
                    batchContextBySlug: $batchContextBySlug,
                )->toArray(),
                'resolved_crosswalk' => $overrideResolver->resolve(
                    subjects: $subjects,
                    approvedPatchesBySlug: $approvedPatchesBySlug,
                ),
            ],
        };

        $hasPatchValidation = array_key_exists('patch_validation', $result);
        $failed = $hasPatchValidation && (bool) data_get($result, 'patch_validation.passed') === false;
        $status = $failed ? 'failed' : 'ok';
        $result['status'] = $status;

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        $this->line('mode='.$mode);
        $this->line('status='.$status);
        $this->line('patch_count='.(string) count($patches));
        if (isset($result['patch_validation'])) {
            $this->line(sprintf(
                'patch_validation=%s',
                (bool) data_get($result, 'patch_validation.passed', false) ? 'passed' : 'failed'
            ));
        }
        if (isset($result['review_queue'])) {
            $this->line('review_queue_total='.(string) data_get($result, 'review_queue.counts.total', 0));
        }
        if (isset($result['resolved_crosswalk'])) {
            $this->line('override_applied='.(string) data_get($result, 'resolved_crosswalk.counts.override_applied', 0));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $patches
     * @return array<string, array<string, mixed>>
     */
    private function approvedPatchesBySlug(array $patches): array
    {
        $grouped = [];
        foreach ($patches as $patch) {
            if (! is_array($patch)) {
                continue;
            }

            $status = strtolower(trim((string) ($patch['patch_status'] ?? '')));
            $slug = trim((string) ($patch['subject_slug'] ?? ''));
            if ($status !== 'approved' || $slug === '') {
                continue;
            }

            $grouped[$slug][] = $patch;
        }

        $approved = [];
        foreach ($grouped as $slug => $items) {
            usort($items, function (array $a, array $b): int {
                $at = strtotime((string) ($a['reviewed_at'] ?? $a['created_at'] ?? '')) ?: 0;
                $bt = strtotime((string) ($b['reviewed_at'] ?? $b['created_at'] ?? '')) ?: 0;

                return $bt <=> $at;
            });
            $approved[$slug] = $items[0];
        }

        return $approved;
    }

    /**
     * @return array<string, array{batch_origin:?string,publish_track:?string,family_slug:?string}>
     */
    private function batchContextBySlug(string $manifestPath, CareerAssetBatchManifestBuilder $manifestBuilder): array
    {
        if ($manifestPath === '') {
            return [];
        }

        try {
            $manifest = $manifestBuilder->fromPath($manifestPath);
        } catch (RuntimeException) {
            return [];
        }

        $context = [];
        foreach ($manifest->members as $member) {
            $context[$member->canonicalSlug] = [
                'batch_origin' => $manifest->batchKey,
                'publish_track' => $member->expectedPublishTrack,
                'family_slug' => $member->familySlug,
            ];
        }

        return $context;
    }
}
