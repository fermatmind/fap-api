<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerIndexStateAuthorityResult
{
    /**
     * @param  list<CareerIndexStateAuthorityRow>  $rows
     * @param  list<CareerIndexStateAuthorityIssue>  $issues
     * @param  list<CareerCanonicalEligibilitySidecar>  $sidecars
     */
    public function __construct(
        public readonly string $status,
        public readonly int $expectedCount,
        public readonly int $indexedLikeCount,
        public readonly int $missingIndexStateCount,
        public readonly int $blockedCount,
        public readonly array $rows,
        public readonly array $issues = [],
        public readonly array $sidecars = [],
    ) {
        CareerCanonicalEligibilityStatus::assertValid($this->status);
        foreach ([
            'expected_count' => $this->expectedCount,
            'indexed_like_count' => $this->indexedLikeCount,
            'missing_index_state_count' => $this->missingIndexStateCount,
            'blocked_count' => $this->blockedCount,
        ] as $key => $value) {
            if ($value < 0) {
                throw new InvalidArgumentException(sprintf('Career index-state authority [%s] must be non-negative.', $key));
            }
        }

        self::assertRows($this->rows);
        self::assertIssues($this->issues);
        self::assertSidecars($this->sidecars);
    }

    /**
     * @param  list<CareerIndexStateAuthorityRow>  $rows
     * @param  list<CareerIndexStateAuthorityIssue>  $issues
     * @param  list<CareerCanonicalEligibilitySidecar>  $sidecars
     */
    public static function build(array $rows, array $issues = [], array $sidecars = []): self
    {
        $allIssues = [
            ...$issues,
            ...array_values(array_merge(...array_map(
                static fn (CareerIndexStateAuthorityRow $row): array => $row->issues,
                $rows
            ))),
        ];

        return new self(
            status: $allIssues === [] ? CareerCanonicalEligibilityStatus::PASS : CareerCanonicalEligibilityStatus::BLOCKED,
            expectedCount: count($rows),
            indexedLikeCount: count(array_filter(
                $rows,
                static fn (CareerIndexStateAuthorityRow $row): bool => $row->issues === []
            )),
            missingIndexStateCount: count(array_filter(
                $rows,
                static fn (CareerIndexStateAuthorityRow $row): bool => $row->indexStateId === null
            )),
            blockedCount: count(array_filter(
                $rows,
                static fn (CareerIndexStateAuthorityRow $row): bool => $row->issues !== []
            )),
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
     * @return array{status: string, expected_count: int, indexed_like_count: int, missing_index_state_count: int, blocked_count: int, by_reason: array<string, int>, rows: list<array<string, mixed>>, issues: list<array<string, mixed>>, sidecars: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'expected_count' => $this->expectedCount,
            'indexed_like_count' => $this->indexedLikeCount,
            'missing_index_state_count' => $this->missingIndexStateCount,
            'blocked_count' => $this->blockedCount,
            'by_reason' => $this->byReason(),
            'rows' => array_map(
                static fn (CareerIndexStateAuthorityRow $row): array => $row->toArray(),
                $this->rows
            ),
            'issues' => array_map(
                static fn (CareerIndexStateAuthorityIssue $issue): array => $issue->toArray(),
                $this->issues
            ),
            'sidecars' => array_map(
                static fn (CareerCanonicalEligibilitySidecar $sidecar): array => $sidecar->toArray(),
                $this->sidecars
            ),
        ];
    }

    /**
     * @param  list<CareerIndexStateAuthorityRow>  $rows
     */
    private static function assertRows(array $rows): void
    {
        if (! array_is_list($rows)) {
            throw new InvalidArgumentException('Career index-state authority rows must be a list.');
        }

        foreach ($rows as $row) {
            if (! $row instanceof CareerIndexStateAuthorityRow) {
                throw new InvalidArgumentException('Career index-state authority rows must contain row DTOs.');
            }
        }
    }

    /**
     * @param  list<CareerIndexStateAuthorityIssue>  $issues
     */
    private static function assertIssues(array $issues): void
    {
        if (! array_is_list($issues)) {
            throw new InvalidArgumentException('Career index-state authority issues must be a list.');
        }

        foreach ($issues as $issue) {
            if (! $issue instanceof CareerIndexStateAuthorityIssue) {
                throw new InvalidArgumentException('Career index-state authority issues must contain issue DTOs.');
            }
        }
    }

    /**
     * @param  list<CareerCanonicalEligibilitySidecar>  $sidecars
     */
    private static function assertSidecars(array $sidecars): void
    {
        if (! array_is_list($sidecars)) {
            throw new InvalidArgumentException('Career index-state authority sidecars must be a list.');
        }

        foreach ($sidecars as $sidecar) {
            if (! $sidecar instanceof CareerCanonicalEligibilitySidecar) {
                throw new InvalidArgumentException('Career index-state authority sidecars must contain sidecar DTOs.');
            }
        }
    }
}
