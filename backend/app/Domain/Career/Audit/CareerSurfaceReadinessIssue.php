<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerSurfaceReadinessIssue
{
    public const API_CANONICAL_NOT_SELF = 'api_canonical_not_self';

    public const API_NOINDEX_PRESENT = 'api_noindex_present';

    public const LIVE_CANONICAL_NOT_SELF = 'live_canonical_not_self';

    public const LIVE_NOINDEX_PRESENT = 'live_noindex_present';

    public const CTA_MISSING_OR_UNATTRIBUTED = 'cta_missing_or_unattributed';

    public const SURFACE_VERIFIER_MISSING = 'surface_verifier_missing';

    public const VALIDATOR_CONTEXT_MISSING = 'validator_context_missing';

    public const UNEXPECTED_EXPOSURE = 'unexpected_exposure';

    public const REAL_SURFACE_MISMATCH = 'real_surface_mismatch';

    /**
     * @param  list<mixed>  $evidence
     */
    public function __construct(
        public readonly string $reason,
        public readonly string $message,
        public readonly string $severity = CareerCanonicalEligibilitySeverity::MEDIUM,
        public readonly ?string $canonicalSlug = null,
        public readonly ?string $locale = null,
        public readonly array $evidence = [],
    ) {
        self::assertValidReason($this->reason);
        CareerCanonicalEligibilitySeverity::assertValid($this->severity);
        self::assertNonEmptyString($this->message, 'message');
        self::assertList($this->evidence, 'evidence');

        if ($this->canonicalSlug !== null) {
            self::assertNonEmptyString($this->canonicalSlug, 'canonical_slug');
        }

        if ($this->locale !== null) {
            self::assertNonEmptyString($this->locale, 'locale');
        }
    }

    /**
     * @return list<string>
     */
    public static function reasons(): array
    {
        return [
            self::API_CANONICAL_NOT_SELF,
            self::API_NOINDEX_PRESENT,
            self::LIVE_CANONICAL_NOT_SELF,
            self::LIVE_NOINDEX_PRESENT,
            self::CTA_MISSING_OR_UNATTRIBUTED,
            self::SURFACE_VERIFIER_MISSING,
            self::VALIDATOR_CONTEXT_MISSING,
            self::UNEXPECTED_EXPOSURE,
            self::REAL_SURFACE_MISMATCH,
        ];
    }

    public static function assertValidReason(string $value): string
    {
        if (! in_array($value, self::reasons(), true)) {
            throw new InvalidArgumentException(sprintf('Invalid career surface readiness issue reason [%s].', $value));
        }

        return $value;
    }

    /**
     * @return array{reason: string, message: string, severity: string, canonical_slug: string|null, locale: string|null, evidence: list<mixed>}
     */
    public function toArray(): array
    {
        return [
            'reason' => $this->reason,
            'message' => $this->message,
            'severity' => $this->severity,
            'canonical_slug' => $this->canonicalSlug,
            'locale' => $this->locale,
            'evidence' => $this->evidence,
        ];
    }

    private static function assertNonEmptyString(string $value, string $key): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Career surface readiness issue requires non-empty [%s].', $key));
        }
    }

    /**
     * @param  list<mixed>  $value
     */
    private static function assertList(array $value, string $key): void
    {
        if (! array_is_list($value)) {
            throw new InvalidArgumentException(sprintf('Career surface readiness issue [%s] must be a list.', $key));
        }
    }
}
