<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerOccupationEntityInventoryResult
{
    /**
     * @param  list<CareerOccupationEntityInventoryRow>  $rows
     * @param  list<CareerOccupationEntityInventoryIssue>  $issues
     * @param  list<CareerCanonicalEligibilitySidecar>  $sidecars
     */
    public function __construct(
        public readonly string $status,
        public readonly int $expectedCount,
        public readonly int $foundCount,
        public readonly int $missingCount,
        public readonly int $duplicateInputCount,
        public readonly int $duplicateEntityCount,
        public readonly int $missingEntityFieldCount,
        public readonly array $rows,
        public readonly array $issues = [],
        public readonly array $sidecars = [],
    ) {
        if (! in_array($this->status, [
            CareerCanonicalEligibilityStatus::PASS,
            CareerCanonicalEligibilityStatus::FAIL,
            CareerCanonicalEligibilityStatus::BLOCKED,
        ], true)) {
            throw new InvalidArgumentException(sprintf('Invalid career occupation entity inventory status [%s].', $this->status));
        }

        foreach ([
            'expected_count' => $this->expectedCount,
            'found_count' => $this->foundCount,
            'missing_count' => $this->missingCount,
            'duplicate_input_count' => $this->duplicateInputCount,
            'duplicate_entity_count' => $this->duplicateEntityCount,
            'missing_entity_field_count' => $this->missingEntityFieldCount,
        ] as $key => $value) {
            if ($value < 0) {
                throw new InvalidArgumentException(sprintf('Career occupation entity inventory [%s] must be non-negative.', $key));
            }
        }

        self::assertIssueList($this->issues);
        self::assertRowList($this->rows);
        self::assertSidecarList($this->sidecars);
    }

    /**
     * @param  list<CareerOccupationEntityInventoryRow>  $rows
     * @param  list<CareerOccupationEntityInventoryIssue>  $issues
     * @param  list<CareerCanonicalEligibilitySidecar>  $sidecars
     */
    public static function build(array $rows, array $issues, int $duplicateInputCount, array $sidecars = []): self
    {
        self::assertRowList($rows);
        self::assertIssueList($issues);
        self::assertSidecarList($sidecars);

        $missingEntityFieldCount = 0;
        foreach ($rows as $row) {
            $missingEntityFieldCount += count($row->missingEntityFields);
        }

        return new self(
            status: self::statusForIssues($issues),
            expectedCount: count($rows),
            foundCount: count(array_filter($rows, static fn (CareerOccupationEntityInventoryRow $row): bool => $row->occupationExists)),
            missingCount: count(array_filter($rows, static fn (CareerOccupationEntityInventoryRow $row): bool => ! $row->occupationExists)),
            duplicateInputCount: $duplicateInputCount,
            duplicateEntityCount: count(array_filter($rows, static fn (CareerOccupationEntityInventoryRow $row): bool => $row->duplicateEntitySlug)),
            missingEntityFieldCount: $missingEntityFieldCount,
            rows: $rows,
            issues: $issues,
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
     * @return array{status: string, expected_count: int, found_count: int, missing_count: int, duplicate_input_count: int, duplicate_entity_count: int, missing_entity_field_count: int, by_reason: array<string, int>, rows: list<array<string, mixed>>, issues: list<array<string, mixed>>, sidecars: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'expected_count' => $this->expectedCount,
            'found_count' => $this->foundCount,
            'missing_count' => $this->missingCount,
            'duplicate_input_count' => $this->duplicateInputCount,
            'duplicate_entity_count' => $this->duplicateEntityCount,
            'missing_entity_field_count' => $this->missingEntityFieldCount,
            'by_reason' => $this->byReason(),
            'rows' => array_map(
                static fn (CareerOccupationEntityInventoryRow $row): array => $row->toArray(),
                $this->rows
            ),
            'issues' => array_map(
                static fn (CareerOccupationEntityInventoryIssue $issue): array => $issue->toArray(),
                $this->issues
            ),
            'sidecars' => array_map(
                static fn (CareerCanonicalEligibilitySidecar $sidecar): array => $sidecar->toArray(),
                $this->sidecars
            ),
        ];
    }

    /**
     * @param  list<CareerOccupationEntityInventoryIssue>  $issues
     */
    private static function statusForIssues(array $issues): string
    {
        return $issues === []
            ? CareerCanonicalEligibilityStatus::PASS
            : CareerCanonicalEligibilityStatus::BLOCKED;
    }

    /**
     * @param  list<CareerOccupationEntityInventoryRow>  $rows
     */
    private static function assertRowList(array $rows): void
    {
        if (! array_is_list($rows)) {
            throw new InvalidArgumentException('Career occupation entity inventory rows must be a list.');
        }

        foreach ($rows as $row) {
            if (! $row instanceof CareerOccupationEntityInventoryRow) {
                throw new InvalidArgumentException('Career occupation entity inventory rows must contain row DTOs.');
            }
        }
    }

    /**
     * @param  list<CareerOccupationEntityInventoryIssue>  $issues
     */
    private static function assertIssueList(array $issues): void
    {
        if (! array_is_list($issues)) {
            throw new InvalidArgumentException('Career occupation entity inventory issues must be a list.');
        }

        foreach ($issues as $issue) {
            if (! $issue instanceof CareerOccupationEntityInventoryIssue) {
                throw new InvalidArgumentException('Career occupation entity inventory issues must contain issue DTOs.');
            }
        }
    }

    /**
     * @param  list<CareerCanonicalEligibilitySidecar>  $sidecars
     */
    private static function assertSidecarList(array $sidecars): void
    {
        if (! array_is_list($sidecars)) {
            throw new InvalidArgumentException('Career occupation entity inventory sidecars must be a list.');
        }

        foreach ($sidecars as $sidecar) {
            if (! $sidecar instanceof CareerCanonicalEligibilitySidecar) {
                throw new InvalidArgumentException('Career occupation entity inventory sidecars must contain sidecar DTOs.');
            }
        }
    }
}
