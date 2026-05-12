<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerCanonicalExpansionManifestTrainGenerator
{
    public const TRAIN_KIND = 'career_canonical_expansion_manifest_train';

    public const TRAIN_VERSION = 'career.canonical_expansion_manifest_train.v1';

    /**
     * @var list<int>
     */
    public const DEFAULT_STAGE_TARGETS = [80, 300, 800, 2786];

    /**
     * @param  list<int>  $stageTargets
     * @param  list<string>  $locales
     */
    public function generate(
        ?CareerCanonical80CohortReadinessResult $readiness,
        array $stageTargets = self::DEFAULT_STAGE_TARGETS,
        array $locales = ['en', 'zh'],
    ): CareerCanonicalExpansionManifestTrainResult {
        $this->assertStageTargets($stageTargets);
        $locales = $this->normalizeStrings($locales, 'locales');

        if ($readiness === null) {
            return new CareerCanonicalExpansionManifestTrainResult(
                status: CareerCanonicalEligibilityStatus::BLOCKED,
                trainKind: self::TRAIN_KIND,
                trainVersion: self::TRAIN_VERSION,
                readinessStatus: CareerCanonicalEligibilityStatus::BLOCKED,
                publishingAllowed: false,
                mutationAllowed: false,
                stageTargets: $stageTargets,
                readySlugCount: 0,
                batches: [],
                issues: [new CareerCanonicalExpansionManifestTrainIssue(
                    reason: CareerCanonicalExpansionManifestTrainIssue::READINESS_MISSING,
                    stage: '__train__',
                    severity: CareerCanonicalEligibilitySeverity::BLOCKER_FOR_PUBLICATION,
                    evidence: [['readiness_result' => null]],
                )],
                sidecars: [],
            );
        }

        $readySlugs = $this->normalizeStrings($readiness->readySlugs, 'ready_slugs');
        $issues = [];
        if (count(array_unique($readySlugs)) !== count($readySlugs)) {
            $issues[] = new CareerCanonicalExpansionManifestTrainIssue(
                reason: CareerCanonicalExpansionManifestTrainIssue::DUPLICATE_READY_SLUG,
                stage: '__train__',
                severity: CareerCanonicalEligibilitySeverity::HIGH,
                evidence: [['ready_slugs' => $readySlugs]],
            );
        }

        if ($readiness->status !== CareerCanonicalEligibilityStatus::PASS || ! $readiness->rolloutAllowed) {
            $issues[] = new CareerCanonicalExpansionManifestTrainIssue(
                reason: CareerCanonicalExpansionManifestTrainIssue::READINESS_NOT_PASS,
                stage: '__train__',
                severity: CareerCanonicalEligibilitySeverity::BLOCKER_FOR_PUBLICATION,
                evidence: [$readiness->toArray()],
            );
        }

        foreach ($readiness->sidecars as $sidecar) {
            if (! $sidecar->canContinueTrain()) {
                $issues[] = new CareerCanonicalExpansionManifestTrainIssue(
                    reason: CareerCanonicalExpansionManifestTrainIssue::SIDECAR_BLOCKS_TRAIN,
                    stage: '__train__',
                    severity: CareerCanonicalEligibilitySeverity::HIGH,
                    evidence: [$sidecar->toArray()],
                );
            }
        }

        $batches = [];
        foreach ($stageTargets as $target) {
            $stage = 'canonical-'.$target;
            $slugs = array_slice($readySlugs, 0, min($target, count($readySlugs)));
            $batchIssues = [];
            if (count($slugs) < $target) {
                $batchIssues[] = new CareerCanonicalExpansionManifestTrainIssue(
                    reason: CareerCanonicalExpansionManifestTrainIssue::INSUFFICIENT_READY_SLUGS,
                    stage: $stage,
                    severity: CareerCanonicalEligibilitySeverity::BLOCKER_FOR_PUBLICATION,
                    evidence: [[
                        'stage_target' => $target,
                        'ready_slug_count' => count($readySlugs),
                    ]],
                );
            }

            $issues = [...$issues, ...$batchIssues];
            $batches[] = new CareerCanonicalExpansionManifestTrainBatch(
                stage: $stage,
                batchId: 'career-canonical-'.$target,
                batchSize: $target,
                slugs: $slugs,
                locales: $locales,
                rollbackGroup: $slugs,
                readinessGate: $batchIssues === [] ? CareerCanonicalEligibilityStatus::PASS : CareerCanonicalEligibilityStatus::BLOCKED,
                rolloutState: $batchIssues === [] ? 'published_candidate' : 'blocked',
                releaseGateRequired: true,
                surfaceEqualityRequired: true,
                dryRunOnly: true,
                issues: $batchIssues,
            );
        }

        return new CareerCanonicalExpansionManifestTrainResult(
            status: $issues === [] ? CareerCanonicalEligibilityStatus::PASS : CareerCanonicalEligibilityStatus::BLOCKED,
            trainKind: self::TRAIN_KIND,
            trainVersion: self::TRAIN_VERSION,
            readinessStatus: $readiness->status,
            publishingAllowed: false,
            mutationAllowed: false,
            stageTargets: $stageTargets,
            readySlugCount: count($readySlugs),
            batches: $batches,
            issues: $issues,
            sidecars: $readiness->sidecars,
        );
    }

    /**
     * @param  list<int>  $stageTargets
     */
    private function assertStageTargets(array $stageTargets): void
    {
        if (! array_is_list($stageTargets) || $stageTargets === []) {
            throw new InvalidArgumentException('Career manifest train stage targets must be a non-empty list.');
        }

        foreach ($stageTargets as $target) {
            if (! is_int($target) || $target < 1) {
                throw new InvalidArgumentException('Career manifest train stage targets must contain positive integers.');
            }
        }
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function normalizeStrings(array $values, string $key): array
    {
        if (! array_is_list($values)) {
            throw new InvalidArgumentException(sprintf('Career manifest train [%s] must be a list.', $key));
        }

        return array_values(array_map(static function (string $value) use ($key): string {
            $value = trim($value);
            if ($value === '') {
                throw new InvalidArgumentException(sprintf('Career manifest train [%s] must contain non-empty strings.', $key));
            }

            return $value;
        }, $values));
    }
}
