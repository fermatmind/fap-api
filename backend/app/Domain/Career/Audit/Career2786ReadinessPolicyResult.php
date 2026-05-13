<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class Career2786ReadinessPolicyResult
{
    public const SCHEMA_VERSION = 'career_2786_readiness_policy.v1';

    /**
     * @param  list<Career2786ReadinessPolicyRow>  $rows
     * @param  array<string, int>  $byClassification
     * @param  array<string, int>  $byReason
     * @param  list<string>  $nearEligibleSlugs
     * @param  list<string>  $eligibleCandidateSlugs
     * @param  list<string>  $candidateBlockingSlugs
     * @param  list<string>  $approvalGatedSlugs
     * @param  list<string>  $candidateCohortPrerequisites
     * @param  list<string>  $recommendedOrder
     */
    public function __construct(
        public readonly string $schemaVersion,
        public readonly int $targetCount,
        public readonly bool $readinessCanRun,
        public readonly array $rows,
        public readonly array $byClassification,
        public readonly array $byReason,
        public readonly array $nearEligibleSlugs,
        public readonly array $eligibleCandidateSlugs,
        public readonly array $candidateBlockingSlugs,
        public readonly array $approvalGatedSlugs,
        public readonly array $candidateCohortPrerequisites,
        public readonly array $recommendedOrder,
    ) {
        if ($this->schemaVersion !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException('Career 2786 readiness policy schema version is invalid.');
        }
        if ($this->targetCount <= 0) {
            throw new InvalidArgumentException('Career 2786 readiness policy target_count must be positive.');
        }
        self::assertRows($this->rows);
        self::assertIntMap($this->byClassification, 'by_classification');
        self::assertIntMap($this->byReason, 'by_reason');
        self::assertListOfStrings($this->nearEligibleSlugs, 'near_eligible_slugs');
        self::assertListOfStrings($this->eligibleCandidateSlugs, 'eligible_candidate_slugs');
        self::assertListOfStrings($this->candidateBlockingSlugs, 'candidate_blocking_slugs');
        self::assertListOfStrings($this->approvalGatedSlugs, 'approval_gated_slugs');
        self::assertListOfStrings($this->candidateCohortPrerequisites, 'candidate_cohort_prerequisites');
        self::assertListOfStrings($this->recommendedOrder, 'recommended_order');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'target_count' => $this->targetCount,
            'readiness_can_run' => $this->readinessCanRun,
            'by_classification' => $this->byClassification,
            'by_reason' => $this->byReason,
            'near_eligible_count' => count($this->nearEligibleSlugs),
            'eligible_candidate_count' => count($this->eligibleCandidateSlugs),
            'candidate_blocking_count' => count($this->candidateBlockingSlugs),
            'approval_gated_count' => count($this->approvalGatedSlugs),
            'near_eligible_slugs' => $this->nearEligibleSlugs,
            'eligible_candidate_slugs' => $this->eligibleCandidateSlugs,
            'candidate_blocking_slugs' => $this->candidateBlockingSlugs,
            'approval_gated_slugs' => $this->approvalGatedSlugs,
            'candidate_cohort_prerequisites' => $this->candidateCohortPrerequisites,
            'recommended_order' => $this->recommendedOrder,
            'rows' => array_map(
                static fn (Career2786ReadinessPolicyRow $row): array => $row->toArray(),
                $this->rows
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'target_count' => $this->targetCount,
            'readiness_can_run' => $this->readinessCanRun,
            'hard_blocker_count' => $this->byClassification[Career2786ReadinessPolicyIssue::HARD_BLOCKER] ?? 0,
            'remediation_required_count' => $this->byClassification[Career2786ReadinessPolicyIssue::REMEDIATION_REQUIRED] ?? 0,
            'expected_not_ready_count' => $this->byClassification[Career2786ReadinessPolicyIssue::EXPECTED_NOT_READY] ?? 0,
            'deferred_until_candidate_count' => $this->byClassification[Career2786ReadinessPolicyIssue::DEFERRED_UNTIL_CANDIDATE] ?? 0,
            'approval_gated_count' => $this->byClassification[Career2786ReadinessPolicyIssue::APPROVAL_GATED] ?? 0,
            'near_eligible_count' => count($this->nearEligibleSlugs),
            'eligible_candidate_count' => count($this->eligibleCandidateSlugs),
            'candidate_blocking_count' => count($this->candidateBlockingSlugs),
            'candidate_cohort_prerequisites' => $this->candidateCohortPrerequisites,
            'recommended_order' => $this->recommendedOrder,
        ];
    }

    /**
     * @param  list<Career2786ReadinessPolicyRow>  $rows
     */
    private static function assertRows(array $rows): void
    {
        if (! array_is_list($rows)) {
            throw new InvalidArgumentException('Career 2786 readiness policy rows must be a list.');
        }

        foreach ($rows as $row) {
            if (! $row instanceof Career2786ReadinessPolicyRow) {
                throw new InvalidArgumentException('Career 2786 readiness policy rows must contain row DTOs.');
            }
        }
    }

    /**
     * @param  array<string, int>  $value
     */
    private static function assertIntMap(array $value, string $key): void
    {
        if (array_is_list($value) && $value !== []) {
            throw new InvalidArgumentException(sprintf('Career 2786 readiness policy [%s] must be an object map.', $key));
        }

        foreach ($value as $mapKey => $count) {
            if (! is_string($mapKey) || trim($mapKey) === '' || ! is_int($count) || $count < 0) {
                throw new InvalidArgumentException(sprintf('Career 2786 readiness policy [%s] must map non-empty strings to non-negative integers.', $key));
            }
        }
    }

    /**
     * @param  list<string>  $value
     */
    private static function assertListOfStrings(array $value, string $key): void
    {
        if (! array_is_list($value)) {
            throw new InvalidArgumentException(sprintf('Career 2786 readiness policy [%s] must be a list.', $key));
        }

        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new InvalidArgumentException(sprintf('Career 2786 readiness policy [%s] must contain non-empty strings.', $key));
            }
        }
    }
}
