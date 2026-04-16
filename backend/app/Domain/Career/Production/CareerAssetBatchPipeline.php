<?php

declare(strict_types=1);

namespace App\Domain\Career\Production;

use App\Domain\Career\Operations\CareerCrosswalkOverrideResolver;
use App\Domain\Career\Operations\CareerCrosswalkReviewQueueService;
use App\Domain\Career\Operations\CareerEditorialPatchAuthorityService;
use App\Domain\Career\Publish\CareerFirstWaveLaunchReadinessAuditV2Service;
use App\Domain\Career\Publish\CareerFirstWavePromotionCandidateEngine;
use App\Domain\Career\Publish\CareerTrustFreshnessAuthorityService;
use App\DTO\Career\CareerAssetBatchManifest;
use RuntimeException;

final class CareerAssetBatchPipeline
{
    public const MODE_VALIDATE = 'validate';

    public const MODE_COMPILE_TRUST = 'compile-trust';

    public const MODE_PUBLISH_CANDIDATE = 'publish-candidate';

    public const MODE_REGRESSION = 'regression';

    public const MODE_FULL = 'full';

    public function __construct(
        private readonly CareerAssetBatchManifestBuilder $manifestBuilder,
        private readonly CareerAssetBatchValidator $validator,
        private readonly CareerAssetBatchTrustCompiler $trustCompiler,
        private readonly CareerAssetBatchPublishCandidateService $publishCandidateService,
        private readonly CareerAssetBatchRegressionRunner $regressionRunner,
        private readonly CareerFirstWaveLaunchReadinessAuditV2Service $auditV2Service,
        private readonly CareerTrustFreshnessAuthorityService $trustFreshnessAuthorityService,
        private readonly CareerFirstWavePromotionCandidateEngine $promotionCandidateEngine,
        private readonly CareerCrosswalkReviewQueueService $crosswalkReviewQueueService,
        private readonly CareerCrosswalkOverrideResolver $crosswalkOverrideResolver,
        private readonly CareerEditorialPatchAuthorityService $patchAuthorityService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(string $manifestPath, string $mode = self::MODE_FULL): array
    {
        $normalizedMode = $this->normalizeMode($mode);
        $manifest = $this->manifestBuilder->fromPath($manifestPath);

        $truthBySlug = $this->truthBySlug();
        $stages = [];
        $strictTrustCompile = $manifest->batchKind === CareerAssetBatchManifestBuilder::BATCH_KIND_1;

        $validate = $this->validator->validate($manifest, $truthBySlug);
        $stages['validate'] = $validate;

        if ($normalizedMode === self::MODE_VALIDATE) {
            return $this->result(
                (bool) ($validate['passed'] ?? false) ? 'completed' : 'aborted',
                $normalizedMode,
                $manifest->toArray(),
                $stages,
            );
        }

        if (! (bool) $validate['passed']) {
            $stages['compile_trust'] = $this->skippedStage('compile_trust', 'validation_failed');
            $stages['publish_candidate'] = $this->skippedStage('publish_candidate', 'validation_failed');
            $stages['regression'] = $this->skippedStage('regression', 'validation_failed');

            return $this->result('aborted', $normalizedMode, $manifest->toArray(), $stages);
        }

        $trustFreshnessBySlug = $this->trustFreshnessBySlug();
        $compileTrust = $this->trustCompiler->compile($manifest, $trustFreshnessBySlug, $strictTrustCompile);
        $stages['compile_trust'] = $compileTrust;

        if ($normalizedMode === self::MODE_COMPILE_TRUST) {
            return $this->result('completed', $normalizedMode, $manifest->toArray(), $stages);
        }

        if (! (bool) $compileTrust['passed']) {
            $stages['publish_candidate'] = $this->skippedStage('publish_candidate', 'trust_compile_failed');
            $stages['regression'] = $this->skippedStage('regression', 'trust_compile_failed');

            return $this->result('aborted', $normalizedMode, $manifest->toArray(), $stages);
        }

        if ($manifest->batchKind === CareerAssetBatchManifestBuilder::BATCH_KIND_4) {
            $promotionBySlug = $this->promotionBySlug($truthBySlug, $manifest->scope);
            $publishCandidate = $this->publishCandidateService->project(
                $manifest,
                $truthBySlug,
                $promotionBySlug,
            );
            $stages['publish_candidate'] = $publishCandidate;
            $stages['review_queue_handoff'] = $this->reviewQueueHandoff($manifest, $truthBySlug);
        } else {
            $promotionBySlug = $this->promotionBySlug($truthBySlug, $manifest->scope);
            $publishCandidate = $this->publishCandidateService->project(
                $manifest,
                $truthBySlug,
                $promotionBySlug,
            );
            $stages['publish_candidate'] = $publishCandidate;
            if ($manifest->batchKind === CareerAssetBatchManifestBuilder::BATCH_KIND_3) {
                $stages['review_queue_handoff'] = $this->reviewQueueHandoff($manifest, $truthBySlug);
            }
        }

        if ($normalizedMode === self::MODE_PUBLISH_CANDIDATE) {
            return $this->result('completed', $normalizedMode, $manifest->toArray(), $stages);
        }

        $regression = $this->regressionRunner->run($manifest, $truthBySlug);
        $stages['regression'] = $regression;

        return $this->result(
            (bool) ($regression['passed'] ?? false) ? 'completed' : 'aborted',
            $normalizedMode,
            $manifest->toArray(),
            $stages,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function result(string $status, string $mode, array $manifest, array $stages): array
    {
        return [
            'pipeline_kind' => 'career_asset_batch_pipeline',
            'pipeline_version' => 'career.asset_batch_pipeline.v2',
            'status' => $status,
            'mode' => $mode,
            'manifest' => $manifest,
            'stages' => $stages,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function skippedStage(string $stage, string $reason): array
    {
        return [
            'stage' => $stage,
            'passed' => false,
            'skipped' => true,
            'reason' => $reason,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function truthBySlug(): array
    {
        $audit = $this->auditV2Service->build();
        $truthBySlug = [];

        foreach ($audit->members as $member) {
            $truthBySlug[$member->canonicalSlug] = [
                'canonical_slug' => $member->canonicalSlug,
                'occupation_uuid' => $member->occupationUuid,
                'canonical_title_en' => $member->canonicalTitleEn,
                'launch_tier' => $member->launchTier,
                'readiness_status' => $member->readinessStatus,
                'lifecycle_state' => $member->lifecycleState,
                'public_index_state' => $member->publicIndexState,
                'index_eligible' => $member->indexEligible,
                'reviewer_status' => $member->reviewerStatus,
                'crosswalk_mode' => $member->crosswalkMode,
                'allow_strong_claim' => $member->allowStrongClaim,
                'confidence_score' => $member->confidenceScore,
                'blocked_governance_status' => $member->blockedGovernanceStatus,
                'next_step_links_count' => $member->nextStepLinksCount,
                'trust_freshness' => is_array($member->trustFreshness) ? $member->trustFreshness : [],
            ];
        }

        return $truthBySlug;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function trustFreshnessBySlug(): array
    {
        $authority = $this->trustFreshnessAuthorityService->build();
        $freshnessBySlug = [];

        foreach ($authority->members as $member) {
            $freshnessBySlug[$member->canonicalSlug] = [
                'review_due_known' => $member->reviewDueKnown,
                'review_staleness_state' => $member->reviewStalenessState,
                'reviewer_status' => $member->reviewerStatus,
                'reviewed_at' => $member->reviewedAt,
                'next_review_due_at' => $member->nextReviewDueAt,
            ];
        }

        return $freshnessBySlug;
    }

    /**
     * @param  array<string, array<string, mixed>>  $truthBySlug
     * @return array<string, array<string, mixed>>
     */
    private function promotionBySlug(array $truthBySlug, string $scope): array
    {
        $authority = $this->promotionCandidateEngine->build(array_values($truthBySlug), $scope)->toArray();
        $promotionBySlug = [];

        foreach ((array) ($authority['members'] ?? []) as $member) {
            if (! is_array($member)) {
                continue;
            }

            $slug = trim((string) ($member['canonical_slug'] ?? ''));
            if ($slug === '') {
                continue;
            }

            $promotionBySlug[$slug] = $member;
        }

        return $promotionBySlug;
    }

    private function normalizeMode(string $mode): string
    {
        $normalized = strtolower(trim($mode));

        return match ($normalized) {
            self::MODE_VALIDATE,
            self::MODE_COMPILE_TRUST,
            self::MODE_PUBLISH_CANDIDATE,
            self::MODE_REGRESSION,
            self::MODE_FULL => $normalized,
            default => throw new RuntimeException(sprintf(
                'Unsupported batch mode [%s]. Allowed modes: %s',
                $mode,
                implode(', ', [
                    self::MODE_VALIDATE,
                    self::MODE_COMPILE_TRUST,
                    self::MODE_PUBLISH_CANDIDATE,
                    self::MODE_REGRESSION,
                    self::MODE_FULL,
                ]),
            )),
        };
    }

    /**
     * @param  array<string, array<string, mixed>>  $truthBySlug
     * @return array<string, mixed>
     */
    private function reviewQueueHandoff(CareerAssetBatchManifest $manifest, array $truthBySlug): array
    {
        $patches = $this->patchAuthorityService->build()->toArray();
        $approvedPatchesBySlug = $this->approvedPatchesBySlug((array) ($patches['patches'] ?? []));
        $subjects = [];
        $batchContextBySlug = [];

        foreach ($manifest->members as $member) {
            $slug = trim((string) $member->canonicalSlug);
            if ($slug === '') {
                continue;
            }
            $truth = $truthBySlug[$slug] ?? [];
            $subjects[] = [
                'canonical_slug' => $slug,
                'crosswalk_mode' => (string) ($member->crosswalkMode ?: ($truth['crosswalk_mode'] ?? 'unmapped')),
                'readiness_status' => (string) ($truth['readiness_status'] ?? 'blocked_override_eligible'),
                'blocked_governance_status' => $truth['blocked_governance_status'] ?? null,
            ];
            $batchContextBySlug[$slug] = [
                'batch_origin' => $manifest->batchKey,
                'publish_track' => (string) ($member->expectedPublishTrack ?: 'hold'),
                'family_slug' => trim((string) $member->familySlug) !== ''
                    ? (string) $member->familySlug
                    : null,
            ];
        }

        $queue = $this->crosswalkReviewQueueService->build(
            $subjects,
            $approvedPatchesBySlug,
            $batchContextBySlug,
            $manifest->scope !== '' ? $manifest->scope : 'career_crosswalk_editorial_ops',
        )->toArray();

        $resolvedCrosswalk = $this->crosswalkOverrideResolver->resolve($subjects, $approvedPatchesBySlug);
        $familyHandoffCount = collect((array) ($queue['items'] ?? []))
            ->where('candidate_target_kind', 'family')
            ->count();
        $unmappedCount = collect($subjects)
            ->where('crosswalk_mode', 'unmapped')
            ->count();

        return [
            'stage' => 'review_queue_handoff',
            'passed' => true,
            'review_queue' => $queue,
            'resolved_crosswalk' => $resolvedCrosswalk,
            'counts' => [
                'queue_total' => (int) data_get($queue, 'counts.total', 0),
                'family_handoff' => $familyHandoffCount,
                'unmapped' => $unmappedCount,
                'approved_patch_applied' => (int) data_get($resolvedCrosswalk, 'counts.override_applied', 0),
            ],
        ];
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
}
