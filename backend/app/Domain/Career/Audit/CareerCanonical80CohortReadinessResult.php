<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerCanonical80CohortReadinessResult
{
    /**
     * @param  list<string>  $candidateSlugs
     * @param  list<string>  $readySlugs
     * @param  list<string>  $blockedSlugs
     * @param  list<CareerCanonical80CohortReadinessRow>  $rows
     * @param  list<CareerCanonical80CohortReadinessIssue>  $issues
     * @param  list<CareerCanonicalEligibilitySidecar>  $sidecars
     */
    public function __construct(
        public readonly string $status,
        public readonly int $targetCount,
        public readonly int $candidateCount,
        public readonly int $plannedCount,
        public readonly int $eligibleCount,
        public readonly int $blockedCount,
        public readonly bool $rolloutAllowed,
        public readonly array $candidateSlugs,
        public readonly array $readySlugs,
        public readonly array $blockedSlugs,
        public readonly array $rows,
        public readonly array $issues = [],
        public readonly array $sidecars = [],
    ) {
        CareerCanonicalEligibilityStatus::assertValid($this->status);
        foreach ([
            'target_count' => $this->targetCount,
            'candidate_count' => $this->candidateCount,
            'planned_count' => $this->plannedCount,
            'eligible_count' => $this->eligibleCount,
            'blocked_count' => $this->blockedCount,
        ] as $key => $value) {
            if ($value < 0) {
                throw new InvalidArgumentException(sprintf('Career 80-cohort readiness [%s] must be non-negative.', $key));
            }
        }

        self::assertListOfStrings($this->candidateSlugs, 'candidate_slugs');
        self::assertListOfStrings($this->readySlugs, 'ready_slugs');
        self::assertListOfStrings($this->blockedSlugs, 'blocked_slugs');
        self::assertRows($this->rows);
        self::assertIssues($this->issues);
        self::assertSidecars($this->sidecars);
    }

    /**
     * @param  list<CareerCanonical80CohortReadinessRow>  $rows
     * @param  list<CareerCanonical80CohortReadinessIssue>  $issues
     * @param  list<CareerCanonicalEligibilitySidecar>  $sidecars
     * @param  list<string>  $candidateSlugs
     */
    public static function build(int $targetCount, array $candidateSlugs, array $rows, array $issues = [], array $sidecars = []): self
    {
        self::assertRows($rows);
        self::assertIssues($issues);
        self::assertSidecars($sidecars);
        self::assertListOfStrings($candidateSlugs, 'candidate_slugs');

        $allIssues = [
            ...$issues,
            ...array_values(array_merge(...array_map(
                static fn (CareerCanonical80CohortReadinessRow $row): array => $row->issues,
                $rows
            ))),
        ];
        foreach ($sidecars as $sidecar) {
            if (! $sidecar->canContinueTrain()) {
                $allIssues[] = new CareerCanonical80CohortReadinessIssue(
                    reason: CareerCanonical80CohortReadinessIssue::SIDECAR_BLOCKS_TRAIN,
                    canonicalSlug: '__sidecar__',
                    severity: CareerCanonicalEligibilitySeverity::HIGH,
                    evidence: [$sidecar->toArray()],
                );
            }
        }

        $readySlugs = array_values(array_map(
            static fn (CareerCanonical80CohortReadinessRow $row): string => $row->canonicalSlug,
            array_filter($rows, static fn (CareerCanonical80CohortReadinessRow $row): bool => $row->selected)
        ));
        $blockedSlugs = array_values(array_map(
            static fn (CareerCanonical80CohortReadinessRow $row): string => $row->canonicalSlug,
            array_filter($rows, static fn (CareerCanonical80CohortReadinessRow $row): bool => ! $row->selected)
        ));
        $status = $allIssues === [] && count($readySlugs) === $targetCount
            ? CareerCanonicalEligibilityStatus::PASS
            : CareerCanonicalEligibilityStatus::BLOCKED;

        return new self(
            status: $status,
            targetCount: $targetCount,
            candidateCount: count($candidateSlugs),
            plannedCount: count($readySlugs),
            eligibleCount: count($readySlugs),
            blockedCount: count($blockedSlugs),
            rolloutAllowed: $status === CareerCanonicalEligibilityStatus::PASS,
            candidateSlugs: $candidateSlugs,
            readySlugs: $readySlugs,
            blockedSlugs: $blockedSlugs,
            rows: $rows,
            issues: $allIssues,
            sidecars: $sidecars,
        );
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
     * @return array{status: string, target_count: int, candidate_count: int, planned_count: int, eligible_count: int, blocked_count: int, rollout_allowed: bool, candidate_slugs: list<string>, ready_slugs: list<string>, blocked_slugs: list<string>, by_reason: array<string, int>, rows: list<array<string, mixed>>, issues: list<array<string, mixed>>, sidecars: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'target_count' => $this->targetCount,
            'candidate_count' => $this->candidateCount,
            'planned_count' => $this->plannedCount,
            'eligible_count' => $this->eligibleCount,
            'blocked_count' => $this->blockedCount,
            'rollout_allowed' => $this->rolloutAllowed,
            'candidate_slugs' => $this->candidateSlugs,
            'ready_slugs' => $this->readySlugs,
            'blocked_slugs' => $this->blockedSlugs,
            'by_reason' => $this->byReason(),
            'rows' => array_map(
                static fn (CareerCanonical80CohortReadinessRow $row): array => $row->toArray(),
                $this->rows
            ),
            'issues' => array_map(
                static fn (CareerCanonical80CohortReadinessIssue $issue): array => $issue->toArray(),
                $this->issues
            ),
            'sidecars' => array_map(
                static fn (CareerCanonicalEligibilitySidecar $sidecar): array => $sidecar->toArray(),
                $this->sidecars
            ),
        ];
    }

    /**
     * @param  list<CareerCanonical80CohortReadinessRow>  $rows
     */
    private static function assertRows(array $rows): void
    {
        if (! array_is_list($rows)) {
            throw new InvalidArgumentException('Career 80-cohort readiness rows must be a list.');
        }

        foreach ($rows as $row) {
            if (! $row instanceof CareerCanonical80CohortReadinessRow) {
                throw new InvalidArgumentException('Career 80-cohort readiness rows must contain row DTOs.');
            }
        }
    }

    /**
     * @param  list<CareerCanonical80CohortReadinessIssue>  $issues
     */
    private static function assertIssues(array $issues): void
    {
        if (! array_is_list($issues)) {
            throw new InvalidArgumentException('Career 80-cohort readiness issues must be a list.');
        }

        foreach ($issues as $issue) {
            if (! $issue instanceof CareerCanonical80CohortReadinessIssue) {
                throw new InvalidArgumentException('Career 80-cohort readiness issues must contain issue DTOs.');
            }
        }
    }

    /**
     * @param  list<CareerCanonicalEligibilitySidecar>  $sidecars
     */
    private static function assertSidecars(array $sidecars): void
    {
        if (! array_is_list($sidecars)) {
            throw new InvalidArgumentException('Career 80-cohort readiness sidecars must be a list.');
        }

        foreach ($sidecars as $sidecar) {
            if (! $sidecar instanceof CareerCanonicalEligibilitySidecar) {
                throw new InvalidArgumentException('Career 80-cohort readiness sidecars must contain sidecar DTOs.');
            }
        }
    }

    /**
     * @param  list<string>  $value
     */
    private static function assertListOfStrings(array $value, string $key): void
    {
        if (! array_is_list($value)) {
            throw new InvalidArgumentException(sprintf('Career 80-cohort readiness [%s] must be a list.', $key));
        }

        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new InvalidArgumentException(sprintf('Career 80-cohort readiness [%s] must contain non-empty strings.', $key));
            }
        }
    }
}
