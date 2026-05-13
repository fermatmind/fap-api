<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerCanonical80CandidateSelectionReport
{
    public const SCHEMA_VERSION = 'career_80_candidate_selection.v1';

    /**
     * @param  list<CareerCanonical80CandidateSelectionRow>  $rows
     * @param  list<string>  $selectedSlugs
     * @param  list<string>  $nearEligibleSlugs
     * @param  list<string>  $excludedSlugs
     */
    public function __construct(
        public readonly string $schemaVersion,
        public readonly string $status,
        public readonly int $targetCount,
        public readonly int $candidateCount,
        public readonly int $selectedCount,
        public readonly int $nearEligibleCount,
        public readonly int $excludedCount,
        public readonly bool $readinessCanRun,
        public readonly array $rows,
        public readonly array $selectedSlugs,
        public readonly array $nearEligibleSlugs,
        public readonly array $excludedSlugs,
    ) {
        self::assertNonEmptyString($this->schemaVersion, 'schema_version');
        CareerCanonicalEligibilityStatus::assertValid($this->status);
        foreach ([
            'target_count' => $this->targetCount,
            'candidate_count' => $this->candidateCount,
            'selected_count' => $this->selectedCount,
            'near_eligible_count' => $this->nearEligibleCount,
            'excluded_count' => $this->excludedCount,
        ] as $key => $value) {
            if ($value < 0) {
                throw new InvalidArgumentException(sprintf('Career 80 candidate selection [%s] must be non-negative.', $key));
            }
        }
        self::assertRows($this->rows);
        self::assertListOfStrings($this->selectedSlugs, 'selected_slugs');
        self::assertListOfStrings($this->nearEligibleSlugs, 'near_eligible_slugs');
        self::assertListOfStrings($this->excludedSlugs, 'excluded_slugs');
    }

    /**
     * @param  list<CareerCanonical80CandidateSelectionRow>  $rows
     */
    public static function build(int $targetCount, array $rows): self
    {
        self::assertRows($rows);

        $selectedSlugs = array_values(array_map(
            static fn (CareerCanonical80CandidateSelectionRow $row): string => $row->canonicalSlug,
            array_filter($rows, static fn (CareerCanonical80CandidateSelectionRow $row): bool => $row->selected)
        ));
        $nearEligibleSlugs = array_values(array_map(
            static fn (CareerCanonical80CandidateSelectionRow $row): string => $row->canonicalSlug,
            array_filter($rows, static fn (CareerCanonical80CandidateSelectionRow $row): bool => $row->candidateStatus === CareerCanonical80CandidateSelectionRow::STATUS_NEAR_ELIGIBLE)
        ));
        $excludedSlugs = array_values(array_map(
            static fn (CareerCanonical80CandidateSelectionRow $row): string => $row->canonicalSlug,
            array_filter($rows, static fn (CareerCanonical80CandidateSelectionRow $row): bool => $row->candidateStatus === CareerCanonical80CandidateSelectionRow::STATUS_EXCLUDED_HARD_BLOCKER)
        ));
        $readinessCanRun = count($selectedSlugs) >= $targetCount;

        return new self(
            schemaVersion: self::SCHEMA_VERSION,
            status: $readinessCanRun ? CareerCanonicalEligibilityStatus::PASS : CareerCanonicalEligibilityStatus::BLOCKED,
            targetCount: $targetCount,
            candidateCount: count($rows),
            selectedCount: count($selectedSlugs),
            nearEligibleCount: count($nearEligibleSlugs),
            excludedCount: count($excludedSlugs),
            readinessCanRun: $readinessCanRun,
            rows: $rows,
            selectedSlugs: array_slice($selectedSlugs, 0, $targetCount),
            nearEligibleSlugs: $nearEligibleSlugs,
            excludedSlugs: $excludedSlugs,
        );
    }

    /**
     * @return array{schema_version: string, status: string, target_count: int, candidate_count: int, selected_count: int, near_eligible_count: int, excluded_count: int, readiness_can_run: bool, selected_slugs: list<string>, near_eligible_slugs: list<string>, excluded_slugs: list<string>, rows: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'status' => $this->status,
            'target_count' => $this->targetCount,
            'candidate_count' => $this->candidateCount,
            'selected_count' => $this->selectedCount,
            'near_eligible_count' => $this->nearEligibleCount,
            'excluded_count' => $this->excludedCount,
            'readiness_can_run' => $this->readinessCanRun,
            'selected_slugs' => $this->selectedSlugs,
            'near_eligible_slugs' => $this->nearEligibleSlugs,
            'excluded_slugs' => $this->excludedSlugs,
            'rows' => array_map(
                static fn (CareerCanonical80CandidateSelectionRow $row): array => $row->toArray(),
                $this->rows
            ),
        ];
    }

    private static function assertNonEmptyString(string $value, string $key): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Career 80 candidate selection requires non-empty [%s].', $key));
        }
    }

    /**
     * @param  list<CareerCanonical80CandidateSelectionRow>  $rows
     */
    private static function assertRows(array $rows): void
    {
        if (! array_is_list($rows)) {
            throw new InvalidArgumentException('Career 80 candidate selection rows must be a list.');
        }

        foreach ($rows as $row) {
            if (! $row instanceof CareerCanonical80CandidateSelectionRow) {
                throw new InvalidArgumentException('Career 80 candidate selection rows must contain row DTOs.');
            }
        }
    }

    /**
     * @param  list<string>  $value
     */
    private static function assertListOfStrings(array $value, string $key): void
    {
        if (! array_is_list($value)) {
            throw new InvalidArgumentException(sprintf('Career 80 candidate selection [%s] must be a list.', $key));
        }

        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new InvalidArgumentException(sprintf('Career 80 candidate selection [%s] must contain non-empty strings.', $key));
            }
        }
    }
}
