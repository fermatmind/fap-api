<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerBaselineMetadataInventoryResult
{
    /**
     * @param  list<CareerBaselineMetadataInventoryRow>  $rows
     * @param  list<CareerBaselineMetadataInventoryIssue>  $issues
     * @param  list<CareerCanonicalEligibilitySidecar>  $sidecars
     * @param  array{zh_baseline: string|null, en_baseline: string|null, manifests: list<string>}  $sourcePaths
     */
    public function __construct(
        public readonly string $status,
        public readonly int $expectedCount,
        public readonly int $zhBaselineFoundCount,
        public readonly int $zhBaselineMissingCount,
        public readonly int $enTitleFoundCount,
        public readonly int $enTitleDerivedCount,
        public readonly int $missingDisplayFieldCount,
        public readonly array $sourcePaths,
        public readonly array $rows,
        public readonly array $issues = [],
        public readonly array $sidecars = [],
    ) {
        CareerCanonicalEligibilityStatus::assertValid($this->status);
        foreach ([
            'expected_count' => $this->expectedCount,
            'zh_baseline_found_count' => $this->zhBaselineFoundCount,
            'zh_baseline_missing_count' => $this->zhBaselineMissingCount,
            'en_title_found_count' => $this->enTitleFoundCount,
            'en_title_derived_count' => $this->enTitleDerivedCount,
            'missing_display_field_count' => $this->missingDisplayFieldCount,
        ] as $key => $value) {
            if ($value < 0) {
                throw new InvalidArgumentException(sprintf('Career baseline metadata inventory [%s] must be non-negative.', $key));
            }
        }

        self::assertSourcePaths($this->sourcePaths);
        self::assertRows($this->rows);
        self::assertIssues($this->issues);
        self::assertSidecars($this->sidecars);
    }

    /**
     * @param  list<CareerBaselineMetadataInventoryRow>  $rows
     * @param  list<CareerBaselineMetadataInventoryIssue>  $issues
     * @param  list<CareerCanonicalEligibilitySidecar>  $sidecars
     * @param  array{zh_baseline: string|null, en_baseline: string|null, manifests: list<string>}  $sourcePaths
     */
    public static function build(array $sourcePaths, array $rows, array $issues = [], array $sidecars = []): self
    {
        $allIssues = [
            ...$issues,
            ...array_values(array_merge(...array_map(
                static fn (CareerBaselineMetadataInventoryRow $row): array => $row->issues,
                $rows
            ))),
        ];

        return new self(
            status: self::statusForIssues($allIssues),
            expectedCount: count($rows),
            zhBaselineFoundCount: count(array_filter(
                $rows,
                static fn (CareerBaselineMetadataInventoryRow $row): bool => $row->zhBaselineExists
            )),
            zhBaselineMissingCount: count(array_filter(
                $rows,
                static fn (CareerBaselineMetadataInventoryRow $row): bool => ! $row->zhBaselineExists
            )),
            enTitleFoundCount: count(array_filter(
                $rows,
                static fn (CareerBaselineMetadataInventoryRow $row): bool => $row->titleEn !== null
                    && $row->titleEnSource !== CareerBaselineMetadataInventoryRow::TITLE_EN_SOURCE_CANONICAL_SLUG_DERIVED
            )),
            enTitleDerivedCount: count(array_filter(
                $rows,
                static fn (CareerBaselineMetadataInventoryRow $row): bool => $row->titleEnSource === CareerBaselineMetadataInventoryRow::TITLE_EN_SOURCE_CANONICAL_SLUG_DERIVED
            )),
            missingDisplayFieldCount: array_sum(array_map(
                static fn (CareerBaselineMetadataInventoryRow $row): int => count($row->missingDisplayFields),
                $rows
            )),
            sourcePaths: $sourcePaths,
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
     * @return array{status: string, expected_count: int, zh_baseline_found_count: int, zh_baseline_missing_count: int, en_title_found_count: int, en_title_derived_count: int, missing_display_field_count: int, by_reason: array<string, int>, source_paths: array<string, mixed>, rows: list<array<string, mixed>>, issues: list<array<string, mixed>>, sidecars: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'expected_count' => $this->expectedCount,
            'zh_baseline_found_count' => $this->zhBaselineFoundCount,
            'zh_baseline_missing_count' => $this->zhBaselineMissingCount,
            'en_title_found_count' => $this->enTitleFoundCount,
            'en_title_derived_count' => $this->enTitleDerivedCount,
            'missing_display_field_count' => $this->missingDisplayFieldCount,
            'by_reason' => $this->byReason(),
            'source_paths' => $this->sourcePaths,
            'rows' => array_map(
                static fn (CareerBaselineMetadataInventoryRow $row): array => $row->toArray(),
                $this->rows
            ),
            'issues' => array_map(
                static fn (CareerBaselineMetadataInventoryIssue $issue): array => $issue->toArray(),
                $this->issues
            ),
            'sidecars' => array_map(
                static fn (CareerCanonicalEligibilitySidecar $sidecar): array => $sidecar->toArray(),
                $this->sidecars
            ),
        ];
    }

    /**
     * @param  list<CareerBaselineMetadataInventoryIssue>  $issues
     */
    private static function statusForIssues(array $issues): string
    {
        if ($issues === []) {
            return CareerCanonicalEligibilityStatus::PASS;
        }

        foreach ($issues as $issue) {
            if ($issue->reason !== CareerBaselineMetadataInventoryIssue::EN_TITLE_DERIVATION_REQUIRED) {
                return CareerCanonicalEligibilityStatus::BLOCKED;
            }
        }

        return CareerCanonicalEligibilityStatus::WARNING;
    }

    /**
     * @param  array{zh_baseline: string|null, en_baseline: string|null, manifests: list<string>}  $sourcePaths
     */
    private static function assertSourcePaths(array $sourcePaths): void
    {
        foreach (['zh_baseline', 'en_baseline', 'manifests'] as $key) {
            if (! array_key_exists($key, $sourcePaths)) {
                throw new InvalidArgumentException(sprintf('Career baseline metadata inventory source_paths missing [%s].', $key));
            }
        }

        if (! is_array($sourcePaths['manifests']) || ! array_is_list($sourcePaths['manifests'])) {
            throw new InvalidArgumentException('Career baseline metadata inventory source_paths manifests must be a list.');
        }
    }

    /**
     * @param  list<CareerBaselineMetadataInventoryRow>  $rows
     */
    private static function assertRows(array $rows): void
    {
        if (! array_is_list($rows)) {
            throw new InvalidArgumentException('Career baseline metadata inventory rows must be a list.');
        }

        foreach ($rows as $row) {
            if (! $row instanceof CareerBaselineMetadataInventoryRow) {
                throw new InvalidArgumentException('Career baseline metadata inventory rows must contain row DTOs.');
            }
        }
    }

    /**
     * @param  list<CareerBaselineMetadataInventoryIssue>  $issues
     */
    private static function assertIssues(array $issues): void
    {
        if (! array_is_list($issues)) {
            throw new InvalidArgumentException('Career baseline metadata inventory issues must be a list.');
        }

        foreach ($issues as $issue) {
            if (! $issue instanceof CareerBaselineMetadataInventoryIssue) {
                throw new InvalidArgumentException('Career baseline metadata inventory issues must contain issue DTOs.');
            }
        }
    }

    /**
     * @param  list<CareerCanonicalEligibilitySidecar>  $sidecars
     */
    private static function assertSidecars(array $sidecars): void
    {
        if (! array_is_list($sidecars)) {
            throw new InvalidArgumentException('Career baseline metadata inventory sidecars must be a list.');
        }

        foreach ($sidecars as $sidecar) {
            if (! $sidecar instanceof CareerCanonicalEligibilitySidecar) {
                throw new InvalidArgumentException('Career baseline metadata inventory sidecars must contain sidecar DTOs.');
            }
        }
    }
}
