<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerBatchLiveAcceptanceV2Issue
{
    public const PROJECTION_ROW_MISSING = 'projection_row_missing';

    public const TRUTH_ROW_MISSING = 'truth_row_missing';

    public const RELEASE_GATE_BLOCKED = 'release_gate_blocked';

    public const SURFACE_MISMATCH = 'surface_mismatch';

    public const SURFACE_UNVERIFIED = 'surface_unverified';

    public const LOCALE_ROW_MISSING = 'locale_row_missing';

    private const REASONS = [
        self::PROJECTION_ROW_MISSING,
        self::TRUTH_ROW_MISSING,
        self::RELEASE_GATE_BLOCKED,
        self::SURFACE_MISMATCH,
        self::SURFACE_UNVERIFIED,
        self::LOCALE_ROW_MISSING,
    ];

    /**
     * @param  list<mixed>  $evidence
     */
    public function __construct(
        public readonly string $reason,
        public readonly string $canonicalSlug,
        public readonly string $locale,
        public readonly string $severity,
        public readonly array $evidence = [],
    ) {
        if (! in_array($this->reason, self::REASONS, true)) {
            throw new InvalidArgumentException(sprintf('Invalid batch live acceptance v2 issue reason [%s].', $this->reason));
        }
        foreach (['canonical_slug' => $this->canonicalSlug, 'locale' => $this->locale] as $key => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException(sprintf('Batch live acceptance v2 issue requires non-empty [%s].', $key));
            }
        }
        CareerCanonicalEligibilitySeverity::assertValid($this->severity);
        if (! array_is_list($this->evidence)) {
            throw new InvalidArgumentException('Batch live acceptance v2 issue evidence must be a list.');
        }
    }

    /**
     * @return array{reason: string, canonical_slug: string, locale: string, severity: string, evidence: list<mixed>}
     */
    public function toArray(): array
    {
        return [
            'reason' => $this->reason,
            'canonical_slug' => $this->canonicalSlug,
            'locale' => $this->locale,
            'severity' => $this->severity,
            'evidence' => $this->evidence,
        ];
    }
}
