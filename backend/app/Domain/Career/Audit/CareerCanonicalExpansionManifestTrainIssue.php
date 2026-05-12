<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerCanonicalExpansionManifestTrainIssue
{
    public const READINESS_MISSING = 'readiness_missing';

    public const READINESS_NOT_PASS = 'readiness_not_pass';

    public const INSUFFICIENT_READY_SLUGS = 'insufficient_ready_slugs';

    public const DUPLICATE_READY_SLUG = 'duplicate_ready_slug';

    public const STAGE_SIZE_INVALID = 'stage_size_invalid';

    public const SIDECAR_BLOCKS_TRAIN = 'sidecar_blocks_train';

    private const REASONS = [
        self::READINESS_MISSING,
        self::READINESS_NOT_PASS,
        self::INSUFFICIENT_READY_SLUGS,
        self::DUPLICATE_READY_SLUG,
        self::STAGE_SIZE_INVALID,
        self::SIDECAR_BLOCKS_TRAIN,
    ];

    /**
     * @param  list<mixed>  $evidence
     */
    public function __construct(
        public readonly string $reason,
        public readonly string $stage,
        public readonly string $severity,
        public readonly array $evidence = [],
    ) {
        self::assertReason($this->reason);
        self::assertNonEmptyString($this->stage, 'stage');
        CareerCanonicalEligibilitySeverity::assertValid($this->severity);
        if (! array_is_list($this->evidence)) {
            throw new InvalidArgumentException('Career manifest train issue evidence must be a list.');
        }
    }

    /**
     * @return array{reason: string, stage: string, severity: string, evidence: list<mixed>}
     */
    public function toArray(): array
    {
        return [
            'reason' => $this->reason,
            'stage' => $this->stage,
            'severity' => $this->severity,
            'evidence' => $this->evidence,
        ];
    }

    public static function assertReason(string $reason): void
    {
        if (! in_array($reason, self::REASONS, true)) {
            throw new InvalidArgumentException(sprintf('Invalid Career manifest train issue reason [%s].', $reason));
        }
    }

    private static function assertNonEmptyString(string $value, string $key): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Career manifest train issue requires non-empty [%s].', $key));
        }
    }
}
