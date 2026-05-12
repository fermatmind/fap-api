<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerCanonical80CohortReadinessIssue
{
    public const COHORT_SIZE_NOT_MET = 'cohort_size_not_met';

    public const ELIGIBILITY_ROW_MISSING = 'eligibility_row_missing';

    public const ELIGIBILITY_BLOCKED = 'eligibility_blocked';

    public const SIDECAR_BLOCKS_TRAIN = 'sidecar_blocks_train';

    public const DUPLICATE_CANDIDATE_SLUG = 'duplicate_candidate_slug';

    private const REASONS = [
        self::COHORT_SIZE_NOT_MET,
        self::ELIGIBILITY_ROW_MISSING,
        self::ELIGIBILITY_BLOCKED,
        self::SIDECAR_BLOCKS_TRAIN,
        self::DUPLICATE_CANDIDATE_SLUG,
    ];

    /**
     * @param  list<mixed>  $evidence
     */
    public function __construct(
        public readonly string $reason,
        public readonly string $canonicalSlug,
        public readonly string $severity,
        public readonly array $evidence = [],
    ) {
        self::assertReason($this->reason);
        self::assertNonEmptyString($this->canonicalSlug, 'canonical_slug');
        CareerCanonicalEligibilitySeverity::assertValid($this->severity);
        if (! array_is_list($this->evidence)) {
            throw new InvalidArgumentException('Career 80-cohort readiness issue evidence must be a list.');
        }
    }

    /**
     * @return array{reason: string, canonical_slug: string, severity: string, evidence: list<mixed>}
     */
    public function toArray(): array
    {
        return [
            'reason' => $this->reason,
            'canonical_slug' => $this->canonicalSlug,
            'severity' => $this->severity,
            'evidence' => $this->evidence,
        ];
    }

    public static function assertReason(string $reason): void
    {
        if (! in_array($reason, self::REASONS, true)) {
            throw new InvalidArgumentException(sprintf('Invalid Career 80-cohort readiness issue reason [%s].', $reason));
        }
    }

    private static function assertNonEmptyString(string $value, string $key): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Career 80-cohort readiness issue requires non-empty [%s].', $key));
        }
    }
}
