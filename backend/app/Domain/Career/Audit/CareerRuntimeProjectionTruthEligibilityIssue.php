<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerRuntimeProjectionTruthEligibilityIssue
{
    public const LEDGER_MEMBER_MISSING = 'ledger_member_missing';

    public const PROJECTION_ROW_MISSING = 'projection_row_missing';

    public const PROJECTION_STATE_NOT_PUBLISHED = 'projection_state_not_published';

    public const RUNTIME_PUBLISH_STATE_NOT_PUBLISHED = 'runtime_publish_state_not_published';

    public const TRUTH_ROW_MISSING = 'truth_row_missing';

    public const TRUTH_STATE_NOT_PUBLISHED = 'truth_state_not_published';

    public const CANONICAL_PUBLIC_TYPE_INVALID = 'canonical_public_type_invalid';

    public const LOCALE_ROW_MISSING = 'locale_row_missing';

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
            self::LEDGER_MEMBER_MISSING,
            self::PROJECTION_ROW_MISSING,
            self::PROJECTION_STATE_NOT_PUBLISHED,
            self::RUNTIME_PUBLISH_STATE_NOT_PUBLISHED,
            self::TRUTH_ROW_MISSING,
            self::TRUTH_STATE_NOT_PUBLISHED,
            self::CANONICAL_PUBLIC_TYPE_INVALID,
            self::LOCALE_ROW_MISSING,
        ];
    }

    public static function assertValidReason(string $value): string
    {
        if (! in_array($value, self::reasons(), true)) {
            throw new InvalidArgumentException(sprintf('Invalid career runtime projection/truth issue reason [%s].', $value));
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
            throw new InvalidArgumentException(sprintf('Career runtime projection/truth issue requires non-empty [%s].', $key));
        }
    }

    /**
     * @param  list<mixed>  $value
     */
    private static function assertList(array $value, string $key): void
    {
        if (! array_is_list($value)) {
            throw new InvalidArgumentException(sprintf('Career runtime projection/truth issue [%s] must be a list.', $key));
        }
    }
}
