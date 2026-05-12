<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerSurfaceReadinessResult
{
    /**
     * @param  list<CareerSurfaceReadinessRow>  $rows
     * @param  list<CareerSurfaceReadinessIssue>  $issues
     * @param  list<CareerCanonicalEligibilitySidecar>  $sidecars
     */
    public function __construct(
        public readonly string $status,
        public readonly int $expectedRows,
        public readonly int $readyRows,
        public readonly int $blockedRows,
        public readonly int $unverifiedRows,
        public readonly int $surfaceMismatchRows,
        public readonly array $rows,
        public readonly array $issues = [],
        public readonly array $sidecars = [],
    ) {
        CareerCanonicalEligibilityStatus::assertValid($this->status);
        foreach ([
            'expected_rows' => $this->expectedRows,
            'ready_rows' => $this->readyRows,
            'blocked_rows' => $this->blockedRows,
            'unverified_rows' => $this->unverifiedRows,
            'surface_mismatch_rows' => $this->surfaceMismatchRows,
        ] as $key => $value) {
            if ($value < 0) {
                throw new InvalidArgumentException(sprintf('Career surface readiness [%s] must be non-negative.', $key));
            }
        }

        self::assertRows($this->rows);
        self::assertIssues($this->issues);
        self::assertSidecars($this->sidecars);
    }

    /**
     * @param  list<CareerSurfaceReadinessRow>  $rows
     * @param  list<CareerSurfaceReadinessIssue>  $issues
     * @param  list<CareerCanonicalEligibilitySidecar>  $sidecars
     */
    public static function build(array $rows, array $issues = [], array $sidecars = []): self
    {
        $allIssues = [
            ...$issues,
            ...array_values(array_merge(...array_map(
                static fn (CareerSurfaceReadinessRow $row): array => $row->issues,
                $rows
            ))),
        ];
        $unverifiedRows = count(array_filter($rows, static fn (CareerSurfaceReadinessRow $row): bool => $row->surfaceStatus->status === CareerCanonicalEligibilityStatus::UNVERIFIED));
        $blockingRows = count(array_filter($rows, static fn (CareerSurfaceReadinessRow $row): bool => $row->surfaceStatus->status === CareerCanonicalEligibilityStatus::BLOCKED));

        return new self(
            status: $blockingRows > 0 ? CareerCanonicalEligibilityStatus::BLOCKED : ($unverifiedRows > 0 ? CareerCanonicalEligibilityStatus::UNVERIFIED : CareerCanonicalEligibilityStatus::PASS),
            expectedRows: count($rows),
            readyRows: count(array_filter($rows, static fn (CareerSurfaceReadinessRow $row): bool => $row->issues === [])),
            blockedRows: $blockingRows,
            unverifiedRows: $unverifiedRows,
            surfaceMismatchRows: self::countRowsWithReason($rows, CareerSurfaceReadinessIssue::REAL_SURFACE_MISMATCH),
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
     * @return array{status: string, expected_rows: int, ready_rows: int, blocked_rows: int, unverified_rows: int, surface_mismatch_rows: int, by_reason: array<string, int>, rows: list<array<string, mixed>>, issues: list<array<string, mixed>>, sidecars: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'expected_rows' => $this->expectedRows,
            'ready_rows' => $this->readyRows,
            'blocked_rows' => $this->blockedRows,
            'unverified_rows' => $this->unverifiedRows,
            'surface_mismatch_rows' => $this->surfaceMismatchRows,
            'by_reason' => $this->byReason(),
            'rows' => array_map(
                static fn (CareerSurfaceReadinessRow $row): array => $row->toArray(),
                $this->rows
            ),
            'issues' => array_map(
                static fn (CareerSurfaceReadinessIssue $issue): array => $issue->toArray(),
                $this->issues
            ),
            'sidecars' => array_map(
                static fn (CareerCanonicalEligibilitySidecar $sidecar): array => $sidecar->toArray(),
                $this->sidecars
            ),
        ];
    }

    /**
     * @param  list<CareerSurfaceReadinessRow>  $rows
     */
    private static function countRowsWithReason(array $rows, string $reason): int
    {
        return count(array_filter($rows, static function (CareerSurfaceReadinessRow $row) use ($reason): bool {
            foreach ($row->issues as $issue) {
                if ($issue->reason === $reason) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * @param  list<CareerSurfaceReadinessRow>  $rows
     */
    private static function assertRows(array $rows): void
    {
        if (! array_is_list($rows)) {
            throw new InvalidArgumentException('Career surface readiness rows must be a list.');
        }

        foreach ($rows as $row) {
            if (! $row instanceof CareerSurfaceReadinessRow) {
                throw new InvalidArgumentException('Career surface readiness rows must contain row DTOs.');
            }
        }
    }

    /**
     * @param  list<CareerSurfaceReadinessIssue>  $issues
     */
    private static function assertIssues(array $issues): void
    {
        if (! array_is_list($issues)) {
            throw new InvalidArgumentException('Career surface readiness issues must be a list.');
        }

        foreach ($issues as $issue) {
            if (! $issue instanceof CareerSurfaceReadinessIssue) {
                throw new InvalidArgumentException('Career surface readiness issues must contain issue DTOs.');
            }
        }
    }

    /**
     * @param  list<CareerCanonicalEligibilitySidecar>  $sidecars
     */
    private static function assertSidecars(array $sidecars): void
    {
        if (! array_is_list($sidecars)) {
            throw new InvalidArgumentException('Career surface readiness sidecars must be a list.');
        }

        foreach ($sidecars as $sidecar) {
            if (! $sidecar instanceof CareerCanonicalEligibilitySidecar) {
                throw new InvalidArgumentException('Career surface readiness sidecars must contain sidecar DTOs.');
            }
        }
    }
}
