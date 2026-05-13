<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class Career2786ReadinessPolicyRow
{
    /**
     * @param  list<string>  $locales
     * @param  list<string>  $reasons
     * @param  list<string>  $hardBlockerReasons
     * @param  list<string>  $remediationRequiredReasons
     * @param  list<string>  $expectedNotReadyReasons
     * @param  list<string>  $deferredUntilCandidateReasons
     * @param  list<string>  $approvalGatedReasons
     * @param  list<Career2786ReadinessPolicyIssue>  $issues
     * @param  list<mixed>  $evidence
     */
    public function __construct(
        public readonly string $canonicalSlug,
        public readonly string $classification,
        public readonly bool $candidateReady,
        public readonly bool $nearEligible,
        public readonly bool $eligibleCandidate,
        public readonly bool $requiresApproval,
        public readonly bool $blocks80Readiness,
        public readonly array $locales,
        public readonly array $reasons = [],
        public readonly array $hardBlockerReasons = [],
        public readonly array $remediationRequiredReasons = [],
        public readonly array $expectedNotReadyReasons = [],
        public readonly array $deferredUntilCandidateReasons = [],
        public readonly array $approvalGatedReasons = [],
        public readonly array $issues = [],
        public readonly array $evidence = [],
    ) {
        self::assertNonEmptyString($this->canonicalSlug, 'canonical_slug');
        Career2786ReadinessPolicyIssue::assertValidClassification($this->classification);
        self::assertListOfStrings($this->locales, 'locales');
        self::assertListOfStrings($this->reasons, 'reasons');
        self::assertListOfStrings($this->hardBlockerReasons, 'hard_blocker_reasons');
        self::assertListOfStrings($this->remediationRequiredReasons, 'remediation_required_reasons');
        self::assertListOfStrings($this->expectedNotReadyReasons, 'expected_not_ready_reasons');
        self::assertListOfStrings($this->deferredUntilCandidateReasons, 'deferred_until_candidate_reasons');
        self::assertListOfStrings($this->approvalGatedReasons, 'approval_gated_reasons');
        self::assertIssues($this->issues);
        self::assertList($this->evidence, 'evidence');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'canonical_slug' => $this->canonicalSlug,
            'classification' => $this->classification,
            'candidate_ready' => $this->candidateReady,
            'near_eligible' => $this->nearEligible,
            'eligible_candidate' => $this->eligibleCandidate,
            'requires_approval' => $this->requiresApproval,
            'blocks_80_readiness' => $this->blocks80Readiness,
            'locales' => $this->locales,
            'reasons' => $this->reasons,
            'hard_blocker_reasons' => $this->hardBlockerReasons,
            'remediation_required_reasons' => $this->remediationRequiredReasons,
            'expected_not_ready_reasons' => $this->expectedNotReadyReasons,
            'deferred_until_candidate_reasons' => $this->deferredUntilCandidateReasons,
            'approval_gated_reasons' => $this->approvalGatedReasons,
            'issues' => array_map(
                static fn (Career2786ReadinessPolicyIssue $issue): array => $issue->toArray(),
                $this->issues
            ),
            'evidence' => $this->evidence,
        ];
    }

    private static function assertNonEmptyString(string $value, string $key): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Career 2786 readiness policy row requires non-empty [%s].', $key));
        }
    }

    /**
     * @param  list<mixed>  $value
     */
    private static function assertList(array $value, string $key): void
    {
        if (! array_is_list($value)) {
            throw new InvalidArgumentException(sprintf('Career 2786 readiness policy row [%s] must be a list.', $key));
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
                throw new InvalidArgumentException(sprintf('Career 2786 readiness policy row [%s] must contain non-empty strings.', $key));
            }
        }
    }

    /**
     * @param  list<Career2786ReadinessPolicyIssue>  $issues
     */
    private static function assertIssues(array $issues): void
    {
        self::assertList($issues, 'issues');

        foreach ($issues as $issue) {
            if (! $issue instanceof Career2786ReadinessPolicyIssue) {
                throw new InvalidArgumentException('Career 2786 readiness policy row issues must contain issue DTOs.');
            }
        }
    }
}
