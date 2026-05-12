<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerRuntimeProjectionTruthEligibilityResult
{
    /**
     * @param  list<CareerRuntimeProjectionTruthEligibilityRow>  $rows
     * @param  list<CareerRuntimeProjectionTruthEligibilityIssue>  $issues
     * @param  list<CareerCanonicalEligibilitySidecar>  $sidecars
     */
    public function __construct(
        public readonly string $status,
        public readonly int $expectedRows,
        public readonly int $foundProjectionRows,
        public readonly int $foundTruthRows,
        public readonly int $foundPublished,
        public readonly int $missingProjectionRows,
        public readonly int $missingTruthRows,
        public readonly int $notPublishedRows,
        public readonly int $invalidPublicTypeRows,
        public readonly int $ledgerMissingRows,
        public readonly array $rows,
        public readonly array $issues = [],
        public readonly array $sidecars = [],
    ) {
        CareerCanonicalEligibilityStatus::assertValid($this->status);
        foreach ([
            'expected_rows' => $this->expectedRows,
            'found_projection_rows' => $this->foundProjectionRows,
            'found_truth_rows' => $this->foundTruthRows,
            'found_published' => $this->foundPublished,
            'missing_projection_rows' => $this->missingProjectionRows,
            'missing_truth_rows' => $this->missingTruthRows,
            'not_published_rows' => $this->notPublishedRows,
            'invalid_public_type_rows' => $this->invalidPublicTypeRows,
            'ledger_missing_rows' => $this->ledgerMissingRows,
        ] as $key => $value) {
            if ($value < 0) {
                throw new InvalidArgumentException(sprintf('Career runtime projection/truth [%s] must be non-negative.', $key));
            }
        }

        self::assertRows($this->rows);
        self::assertIssues($this->issues);
        self::assertSidecars($this->sidecars);
    }

    /**
     * @param  list<CareerRuntimeProjectionTruthEligibilityRow>  $rows
     * @param  list<CareerRuntimeProjectionTruthEligibilityIssue>  $issues
     * @param  list<CareerCanonicalEligibilitySidecar>  $sidecars
     */
    public static function build(array $rows, array $issues = [], array $sidecars = []): self
    {
        $allIssues = [
            ...$issues,
            ...array_values(array_merge(...array_map(
                static fn (CareerRuntimeProjectionTruthEligibilityRow $row): array => $row->issues,
                $rows
            ))),
        ];

        return new self(
            status: $allIssues === [] ? CareerCanonicalEligibilityStatus::PASS : CareerCanonicalEligibilityStatus::BLOCKED,
            expectedRows: count($rows),
            foundProjectionRows: count(array_filter($rows, static fn (CareerRuntimeProjectionTruthEligibilityRow $row): bool => $row->projectionExists)),
            foundTruthRows: count(array_filter($rows, static fn (CareerRuntimeProjectionTruthEligibilityRow $row): bool => $row->truthExists)),
            foundPublished: count(array_filter($rows, static fn (CareerRuntimeProjectionTruthEligibilityRow $row): bool => $row->issues === [])),
            missingProjectionRows: self::countRowsWithReason($rows, CareerRuntimeProjectionTruthEligibilityIssue::PROJECTION_ROW_MISSING),
            missingTruthRows: self::countRowsWithReason($rows, CareerRuntimeProjectionTruthEligibilityIssue::TRUTH_ROW_MISSING),
            notPublishedRows: count(array_filter($rows, static fn (CareerRuntimeProjectionTruthEligibilityRow $row): bool => self::rowHasAnyReason($row, [
                CareerRuntimeProjectionTruthEligibilityIssue::PROJECTION_STATE_NOT_PUBLISHED,
                CareerRuntimeProjectionTruthEligibilityIssue::RUNTIME_PUBLISH_STATE_NOT_PUBLISHED,
                CareerRuntimeProjectionTruthEligibilityIssue::TRUTH_STATE_NOT_PUBLISHED,
            ]))),
            invalidPublicTypeRows: self::countRowsWithReason($rows, CareerRuntimeProjectionTruthEligibilityIssue::CANONICAL_PUBLIC_TYPE_INVALID),
            ledgerMissingRows: self::countRowsWithReason($rows, CareerRuntimeProjectionTruthEligibilityIssue::LEDGER_MEMBER_MISSING),
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
     * @return array{status: string, expected_rows: int, found_projection_rows: int, found_truth_rows: int, found_published: int, missing_projection_rows: int, missing_truth_rows: int, not_published_rows: int, invalid_public_type_rows: int, ledger_missing_rows: int, by_reason: array<string, int>, rows: list<array<string, mixed>>, issues: list<array<string, mixed>>, sidecars: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'expected_rows' => $this->expectedRows,
            'found_projection_rows' => $this->foundProjectionRows,
            'found_truth_rows' => $this->foundTruthRows,
            'found_published' => $this->foundPublished,
            'missing_projection_rows' => $this->missingProjectionRows,
            'missing_truth_rows' => $this->missingTruthRows,
            'not_published_rows' => $this->notPublishedRows,
            'invalid_public_type_rows' => $this->invalidPublicTypeRows,
            'ledger_missing_rows' => $this->ledgerMissingRows,
            'by_reason' => $this->byReason(),
            'rows' => array_map(
                static fn (CareerRuntimeProjectionTruthEligibilityRow $row): array => $row->toArray(),
                $this->rows
            ),
            'issues' => array_map(
                static fn (CareerRuntimeProjectionTruthEligibilityIssue $issue): array => $issue->toArray(),
                $this->issues
            ),
            'sidecars' => array_map(
                static fn (CareerCanonicalEligibilitySidecar $sidecar): array => $sidecar->toArray(),
                $this->sidecars
            ),
        ];
    }

    /**
     * @param  list<CareerRuntimeProjectionTruthEligibilityRow>  $rows
     */
    private static function countRowsWithReason(array $rows, string $reason): int
    {
        return count(array_filter(
            $rows,
            static fn (CareerRuntimeProjectionTruthEligibilityRow $row): bool => self::rowHasAnyReason($row, [$reason])
        ));
    }

    /**
     * @param  list<string>  $reasons
     */
    private static function rowHasAnyReason(CareerRuntimeProjectionTruthEligibilityRow $row, array $reasons): bool
    {
        foreach ($row->issues as $issue) {
            if (in_array($issue->reason, $reasons, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<CareerRuntimeProjectionTruthEligibilityRow>  $rows
     */
    private static function assertRows(array $rows): void
    {
        if (! array_is_list($rows)) {
            throw new InvalidArgumentException('Career runtime projection/truth rows must be a list.');
        }

        foreach ($rows as $row) {
            if (! $row instanceof CareerRuntimeProjectionTruthEligibilityRow) {
                throw new InvalidArgumentException('Career runtime projection/truth rows must contain row DTOs.');
            }
        }
    }

    /**
     * @param  list<CareerRuntimeProjectionTruthEligibilityIssue>  $issues
     */
    private static function assertIssues(array $issues): void
    {
        if (! array_is_list($issues)) {
            throw new InvalidArgumentException('Career runtime projection/truth issues must be a list.');
        }

        foreach ($issues as $issue) {
            if (! $issue instanceof CareerRuntimeProjectionTruthEligibilityIssue) {
                throw new InvalidArgumentException('Career runtime projection/truth issues must contain issue DTOs.');
            }
        }
    }

    /**
     * @param  list<CareerCanonicalEligibilitySidecar>  $sidecars
     */
    private static function assertSidecars(array $sidecars): void
    {
        if (! array_is_list($sidecars)) {
            throw new InvalidArgumentException('Career runtime projection/truth sidecars must be a list.');
        }

        foreach ($sidecars as $sidecar) {
            if (! $sidecar instanceof CareerCanonicalEligibilitySidecar) {
                throw new InvalidArgumentException('Career runtime projection/truth sidecars must contain sidecar DTOs.');
            }
        }
    }
}
