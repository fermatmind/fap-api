<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerPublicResolutionPlanValidationResult
{
    /**
     * @param  list<CareerPublicResolutionPlanIssue>  $issues
     */
    public function __construct(
        public readonly string $status,
        public readonly ?int $expectedRows,
        public readonly string $sourcePath,
        public readonly ?CareerPublicResolutionPlan $plan = null,
        public readonly array $issues = [],
    ) {
        self::assertStatus($this->status);
        if ($this->expectedRows !== null && $this->expectedRows < 0) {
            throw new InvalidArgumentException('Career public resolution plan expected_rows must be non-negative.');
        }

        if (trim($this->sourcePath) === '') {
            throw new InvalidArgumentException('Career public resolution plan validation source_path is required.');
        }

        if (! array_is_list($this->issues)) {
            throw new InvalidArgumentException('Career public resolution plan issues must be a list.');
        }

        foreach ($this->issues as $issue) {
            if (! $issue instanceof CareerPublicResolutionPlanIssue) {
                throw new InvalidArgumentException('Career public resolution plan issues must contain issue DTOs.');
            }
        }
    }

    /**
     * @param  list<CareerPublicResolutionPlanIssue>  $issues
     */
    public static function build(?int $expectedRows, string $sourcePath, ?CareerPublicResolutionPlan $plan, array $issues): self
    {
        return new self(
            status: self::statusForIssues($issues),
            expectedRows: $expectedRows,
            sourcePath: $sourcePath,
            plan: $plan,
            issues: $issues,
        );
    }

    public function foundRows(): int
    {
        return $this->plan?->foundRows() ?? 0;
    }

    /**
     * @return list<CareerPublicResolutionPlanRow>
     */
    public function rows(): array
    {
        return $this->plan?->rows ?? [];
    }

    public function checksum(): ?string
    {
        return $this->plan?->checksum;
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
     * @return array{status: string, expected_rows: int|null, found_rows: int, source_path: string, checksum: string|null, by_reason: array<string, int>, rows: list<array<string, mixed>>, issues: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'expected_rows' => $this->expectedRows,
            'found_rows' => $this->foundRows(),
            'source_path' => $this->sourcePath,
            'checksum' => $this->checksum(),
            'by_reason' => $this->byReason(),
            'rows' => array_map(
                static fn (CareerPublicResolutionPlanRow $row): array => $row->toArray(),
                $this->rows()
            ),
            'issues' => array_map(
                static fn (CareerPublicResolutionPlanIssue $issue): array => $issue->toArray(),
                $this->issues
            ),
        ];
    }

    /**
     * @param  list<CareerPublicResolutionPlanIssue>  $issues
     */
    private static function statusForIssues(array $issues): string
    {
        foreach ($issues as $issue) {
            if ($issue->reason === CareerPublicResolutionPlanIssue::PLAN_FILE_MISSING) {
                return CareerCanonicalEligibilityStatus::BLOCKED;
            }
        }

        return $issues === []
            ? CareerCanonicalEligibilityStatus::PASS
            : CareerCanonicalEligibilityStatus::FAIL;
    }

    private static function assertStatus(string $status): void
    {
        if (! in_array($status, [
            CareerCanonicalEligibilityStatus::PASS,
            CareerCanonicalEligibilityStatus::FAIL,
            CareerCanonicalEligibilityStatus::BLOCKED,
        ], true)) {
            throw new InvalidArgumentException(sprintf('Invalid career public resolution plan validation status [%s].', $status));
        }
    }
}
