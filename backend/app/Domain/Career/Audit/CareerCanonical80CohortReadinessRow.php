<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerCanonical80CohortReadinessRow
{
    /**
     * @param  list<string>  $reasons
     * @param  list<mixed>  $evidence
     * @param  list<CareerCanonical80CohortReadinessIssue>  $issues
     */
    public function __construct(
        public readonly string $canonicalSlug,
        public readonly int $cohortPosition,
        public readonly bool $selected,
        public readonly string $eligibilityStatus,
        public readonly array $reasons = [],
        public readonly array $evidence = [],
        public readonly array $issues = [],
    ) {
        self::assertNonEmptyString($this->canonicalSlug, 'canonical_slug');
        if ($this->cohortPosition < 0) {
            throw new InvalidArgumentException('Career 80-cohort readiness row cohort_position must be non-negative.');
        }
        CareerCanonicalEligibilityStatus::assertValid($this->eligibilityStatus);
        self::assertListOfStrings($this->reasons, 'reasons');
        self::assertList($this->evidence, 'evidence');
        self::assertIssues($this->issues);
    }

    /**
     * @return array{canonical_slug: string, cohort_position: int, selected: bool, eligibility_status: string, reasons: list<string>, evidence: list<mixed>, issues: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'canonical_slug' => $this->canonicalSlug,
            'cohort_position' => $this->cohortPosition,
            'selected' => $this->selected,
            'eligibility_status' => $this->eligibilityStatus,
            'reasons' => $this->reasons,
            'evidence' => $this->evidence,
            'issues' => array_map(
                static fn (CareerCanonical80CohortReadinessIssue $issue): array => $issue->toArray(),
                $this->issues
            ),
        ];
    }

    private static function assertNonEmptyString(string $value, string $key): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Career 80-cohort readiness row requires non-empty [%s].', $key));
        }
    }

    /**
     * @param  list<mixed>  $value
     */
    private static function assertList(array $value, string $key): void
    {
        if (! array_is_list($value)) {
            throw new InvalidArgumentException(sprintf('Career 80-cohort readiness row [%s] must be a list.', $key));
        }
    }

    /**
     * @param  list<string>  $value
     */
    private static function assertListOfStrings(array $value, string $key): void
    {
        self::assertList($value, $key);

        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new InvalidArgumentException(sprintf('Career 80-cohort readiness row [%s] must contain non-empty strings.', $key));
            }
        }
    }

    /**
     * @param  list<CareerCanonical80CohortReadinessIssue>  $issues
     */
    private static function assertIssues(array $issues): void
    {
        if (! array_is_list($issues)) {
            throw new InvalidArgumentException('Career 80-cohort readiness row issues must be a list.');
        }

        foreach ($issues as $issue) {
            if (! $issue instanceof CareerCanonical80CohortReadinessIssue) {
                throw new InvalidArgumentException('Career 80-cohort readiness row issues must contain issue DTOs.');
            }
        }
    }
}
