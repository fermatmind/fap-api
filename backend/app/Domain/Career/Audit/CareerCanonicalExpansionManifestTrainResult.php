<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerCanonicalExpansionManifestTrainResult
{
    /**
     * @param  list<int>  $stageTargets
     * @param  list<CareerCanonicalExpansionManifestTrainBatch>  $batches
     * @param  list<CareerCanonicalExpansionManifestTrainIssue>  $issues
     * @param  list<CareerCanonicalEligibilitySidecar>  $sidecars
     */
    public function __construct(
        public readonly string $status,
        public readonly string $trainKind,
        public readonly string $trainVersion,
        public readonly string $readinessStatus,
        public readonly bool $publishingAllowed,
        public readonly bool $mutationAllowed,
        public readonly array $stageTargets,
        public readonly int $readySlugCount,
        public readonly array $batches,
        public readonly array $issues = [],
        public readonly array $sidecars = [],
    ) {
        CareerCanonicalEligibilityStatus::assertValid($this->status);
        self::assertNonEmptyString($this->trainKind, 'train_kind');
        self::assertNonEmptyString($this->trainVersion, 'train_version');
        CareerCanonicalEligibilityStatus::assertValid($this->readinessStatus);
        self::assertStageTargets($this->stageTargets);
        if ($this->readySlugCount < 0) {
            throw new InvalidArgumentException('Career manifest train ready_slug_count must be non-negative.');
        }
        self::assertBatches($this->batches);
        self::assertIssues($this->issues);
        self::assertSidecars($this->sidecars);
    }

    /**
     * @return array<string, int>
     */
    public function byReason(): array
    {
        $counts = [];
        foreach ($this->issues as $issue) {
            $counts[$issue->reason] = ($counts[$issue->reason] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @return array{status: string, train_kind: string, train_version: string, readiness_status: string, publishing_allowed: bool, mutation_allowed: bool, stage_targets: list<int>, ready_slug_count: int, by_reason: array<string, int>, batches: list<array<string, mixed>>, issues: list<array<string, mixed>>, sidecars: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'train_kind' => $this->trainKind,
            'train_version' => $this->trainVersion,
            'readiness_status' => $this->readinessStatus,
            'publishing_allowed' => $this->publishingAllowed,
            'mutation_allowed' => $this->mutationAllowed,
            'stage_targets' => $this->stageTargets,
            'ready_slug_count' => $this->readySlugCount,
            'by_reason' => $this->byReason(),
            'batches' => array_map(
                static fn (CareerCanonicalExpansionManifestTrainBatch $batch): array => $batch->toArray(),
                $this->batches
            ),
            'issues' => array_map(
                static fn (CareerCanonicalExpansionManifestTrainIssue $issue): array => $issue->toArray(),
                $this->issues
            ),
            'sidecars' => array_map(
                static fn (CareerCanonicalEligibilitySidecar $sidecar): array => $sidecar->toArray(),
                $this->sidecars
            ),
        ];
    }

    private static function assertNonEmptyString(string $value, string $key): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Career manifest train result requires non-empty [%s].', $key));
        }
    }

    /**
     * @param  list<int>  $stageTargets
     */
    private static function assertStageTargets(array $stageTargets): void
    {
        if (! array_is_list($stageTargets)) {
            throw new InvalidArgumentException('Career manifest train stage_targets must be a list.');
        }

        foreach ($stageTargets as $target) {
            if (! is_int($target) || $target < 1) {
                throw new InvalidArgumentException('Career manifest train stage_targets must contain positive integers.');
            }
        }
    }

    /**
     * @param  list<CareerCanonicalExpansionManifestTrainBatch>  $batches
     */
    private static function assertBatches(array $batches): void
    {
        if (! array_is_list($batches)) {
            throw new InvalidArgumentException('Career manifest train batches must be a list.');
        }

        foreach ($batches as $batch) {
            if (! $batch instanceof CareerCanonicalExpansionManifestTrainBatch) {
                throw new InvalidArgumentException('Career manifest train batches must contain batch DTOs.');
            }
        }
    }

    /**
     * @param  list<CareerCanonicalExpansionManifestTrainIssue>  $issues
     */
    private static function assertIssues(array $issues): void
    {
        if (! array_is_list($issues)) {
            throw new InvalidArgumentException('Career manifest train issues must be a list.');
        }

        foreach ($issues as $issue) {
            if (! $issue instanceof CareerCanonicalExpansionManifestTrainIssue) {
                throw new InvalidArgumentException('Career manifest train issues must contain issue DTOs.');
            }
        }
    }

    /**
     * @param  list<CareerCanonicalEligibilitySidecar>  $sidecars
     */
    private static function assertSidecars(array $sidecars): void
    {
        if (! array_is_list($sidecars)) {
            throw new InvalidArgumentException('Career manifest train sidecars must be a list.');
        }

        foreach ($sidecars as $sidecar) {
            if (! $sidecar instanceof CareerCanonicalEligibilitySidecar) {
                throw new InvalidArgumentException('Career manifest train sidecars must contain sidecar DTOs.');
            }
        }
    }
}
