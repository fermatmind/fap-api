<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerSeoGeoReadinessResult
{
    /**
     * @param  list<CareerSeoGeoReadinessRow>  $rows
     * @param  list<CareerSeoGeoReadinessIssue>  $issues
     * @param  list<CareerCanonicalEligibilitySidecar>  $sidecars
     */
    public function __construct(
        public readonly string $status,
        public readonly int $expectedRows,
        public readonly int $readyRows,
        public readonly int $blockedRows,
        public readonly int $sitemapMissingRows,
        public readonly int $llmsMissingRows,
        public readonly int $llmsFullMissingRows,
        public readonly int $structuredDataMissingRows,
        public readonly int $datasetMissingRows,
        public readonly int $searchMissingRows,
        public readonly int $citationMetadataMissingRows,
        public readonly array $rows,
        public readonly array $issues = [],
        public readonly array $sidecars = [],
    ) {
        CareerCanonicalEligibilityStatus::assertValid($this->status);
        foreach ([
            'expected_rows' => $this->expectedRows,
            'ready_rows' => $this->readyRows,
            'blocked_rows' => $this->blockedRows,
            'sitemap_missing_rows' => $this->sitemapMissingRows,
            'llms_missing_rows' => $this->llmsMissingRows,
            'llms_full_missing_rows' => $this->llmsFullMissingRows,
            'structured_data_missing_rows' => $this->structuredDataMissingRows,
            'dataset_missing_rows' => $this->datasetMissingRows,
            'search_missing_rows' => $this->searchMissingRows,
            'citation_metadata_missing_rows' => $this->citationMetadataMissingRows,
        ] as $key => $value) {
            if ($value < 0) {
                throw new InvalidArgumentException(sprintf('Career SEO/GEO readiness [%s] must be non-negative.', $key));
            }
        }

        self::assertRows($this->rows);
        self::assertIssues($this->issues);
        self::assertSidecars($this->sidecars);
    }

    /**
     * @param  list<CareerSeoGeoReadinessRow>  $rows
     * @param  list<CareerSeoGeoReadinessIssue>  $issues
     * @param  list<CareerCanonicalEligibilitySidecar>  $sidecars
     */
    public static function build(array $rows, array $issues = [], array $sidecars = []): self
    {
        $allIssues = [
            ...$issues,
            ...array_values(array_merge(...array_map(
                static fn (CareerSeoGeoReadinessRow $row): array => $row->issues,
                $rows
            ))),
        ];

        return new self(
            status: $allIssues === [] ? CareerCanonicalEligibilityStatus::PASS : CareerCanonicalEligibilityStatus::BLOCKED,
            expectedRows: count($rows),
            readyRows: count(array_filter($rows, static fn (CareerSeoGeoReadinessRow $row): bool => $row->issues === [])),
            blockedRows: count(array_filter($rows, static fn (CareerSeoGeoReadinessRow $row): bool => $row->issues !== [])),
            sitemapMissingRows: self::countRowsWithReason($rows, CareerSeoGeoReadinessIssue::SITEMAP_MISSING),
            llmsMissingRows: self::countRowsWithReason($rows, CareerSeoGeoReadinessIssue::LLMS_MISSING),
            llmsFullMissingRows: self::countRowsWithReason($rows, CareerSeoGeoReadinessIssue::LLMS_FULL_MISSING),
            structuredDataMissingRows: self::countRowsWithReason($rows, CareerSeoGeoReadinessIssue::STRUCTURED_DATA_MISSING),
            datasetMissingRows: self::countRowsWithReason($rows, CareerSeoGeoReadinessIssue::DATASET_MISSING),
            searchMissingRows: self::countRowsWithReason($rows, CareerSeoGeoReadinessIssue::SEARCH_MISSING),
            citationMetadataMissingRows: self::countRowsWithReason($rows, CareerSeoGeoReadinessIssue::CITATION_METADATA_MISSING),
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
     * @return array{status: string, expected_rows: int, ready_rows: int, blocked_rows: int, sitemap_missing_rows: int, llms_missing_rows: int, llms_full_missing_rows: int, structured_data_missing_rows: int, dataset_missing_rows: int, search_missing_rows: int, citation_metadata_missing_rows: int, by_reason: array<string, int>, rows: list<array<string, mixed>>, issues: list<array<string, mixed>>, sidecars: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'expected_rows' => $this->expectedRows,
            'ready_rows' => $this->readyRows,
            'blocked_rows' => $this->blockedRows,
            'sitemap_missing_rows' => $this->sitemapMissingRows,
            'llms_missing_rows' => $this->llmsMissingRows,
            'llms_full_missing_rows' => $this->llmsFullMissingRows,
            'structured_data_missing_rows' => $this->structuredDataMissingRows,
            'dataset_missing_rows' => $this->datasetMissingRows,
            'search_missing_rows' => $this->searchMissingRows,
            'citation_metadata_missing_rows' => $this->citationMetadataMissingRows,
            'by_reason' => $this->byReason(),
            'rows' => array_map(
                static fn (CareerSeoGeoReadinessRow $row): array => $row->toArray(),
                $this->rows
            ),
            'issues' => array_map(
                static fn (CareerSeoGeoReadinessIssue $issue): array => $issue->toArray(),
                $this->issues
            ),
            'sidecars' => array_map(
                static fn (CareerCanonicalEligibilitySidecar $sidecar): array => $sidecar->toArray(),
                $this->sidecars
            ),
        ];
    }

    /**
     * @param  list<CareerSeoGeoReadinessRow>  $rows
     */
    private static function countRowsWithReason(array $rows, string $reason): int
    {
        return count(array_filter($rows, static function (CareerSeoGeoReadinessRow $row) use ($reason): bool {
            foreach ($row->issues as $issue) {
                if ($issue->reason === $reason) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * @param  list<CareerSeoGeoReadinessRow>  $rows
     */
    private static function assertRows(array $rows): void
    {
        if (! array_is_list($rows)) {
            throw new InvalidArgumentException('Career SEO/GEO readiness rows must be a list.');
        }

        foreach ($rows as $row) {
            if (! $row instanceof CareerSeoGeoReadinessRow) {
                throw new InvalidArgumentException('Career SEO/GEO readiness rows must contain row DTOs.');
            }
        }
    }

    /**
     * @param  list<CareerSeoGeoReadinessIssue>  $issues
     */
    private static function assertIssues(array $issues): void
    {
        if (! array_is_list($issues)) {
            throw new InvalidArgumentException('Career SEO/GEO readiness issues must be a list.');
        }

        foreach ($issues as $issue) {
            if (! $issue instanceof CareerSeoGeoReadinessIssue) {
                throw new InvalidArgumentException('Career SEO/GEO readiness issues must contain issue DTOs.');
            }
        }
    }

    /**
     * @param  list<CareerCanonicalEligibilitySidecar>  $sidecars
     */
    private static function assertSidecars(array $sidecars): void
    {
        if (! array_is_list($sidecars)) {
            throw new InvalidArgumentException('Career SEO/GEO readiness sidecars must be a list.');
        }

        foreach ($sidecars as $sidecar) {
            if (! $sidecar instanceof CareerCanonicalEligibilitySidecar) {
                throw new InvalidArgumentException('Career SEO/GEO readiness sidecars must contain sidecar DTOs.');
            }
        }
    }
}
