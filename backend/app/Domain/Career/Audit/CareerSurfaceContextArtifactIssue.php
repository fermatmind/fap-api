<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerSurfaceContextArtifactIssue
{
    public const FILE_MISSING = 'surface_context_file_missing';

    public const JSON_INVALID = 'surface_context_json_invalid';

    public const ROWS_MISSING = 'surface_context_rows_missing';

    public const ROW_MALFORMED = 'surface_context_row_malformed';

    public const ROW_MISSING = 'surface_context_row_missing';

    public const SLUG_MISSING = 'surface_context_slug_missing';

    public const LOCALE_MISSING = 'surface_context_locale_missing';

    public const SLUG_LOCALE_DUPLICATE = 'surface_context_slug_locale_duplicate';

    public const REQUIRED_FIELD_MISSING = 'surface_context_required_field_missing';

    public const SURFACE_UNVERIFIED = 'surface_unverified';

    public const SURFACE_ARTIFACT_MISSING = 'surface_artifact_missing';

    /**
     * @param  list<mixed>  $evidence
     */
    public function __construct(
        public readonly string $reason,
        public readonly string $message,
        public readonly ?string $canonicalSlug = null,
        public readonly ?string $locale = null,
        public readonly ?string $field = null,
        public readonly array $evidence = [],
    ) {
        self::assertValidReason($this->reason);
        self::assertNonEmptyString($this->message, 'message');
        self::assertList($this->evidence, 'evidence');

        if ($this->canonicalSlug !== null) {
            self::assertNonEmptyString($this->canonicalSlug, 'canonical_slug');
        }

        if ($this->locale !== null) {
            self::assertNonEmptyString($this->locale, 'locale');
        }

        if ($this->field !== null) {
            self::assertNonEmptyString($this->field, 'field');
        }
    }

    /**
     * @return list<string>
     */
    public static function reasons(): array
    {
        return [
            self::FILE_MISSING,
            self::JSON_INVALID,
            self::ROWS_MISSING,
            self::ROW_MALFORMED,
            self::ROW_MISSING,
            self::SLUG_MISSING,
            self::LOCALE_MISSING,
            self::SLUG_LOCALE_DUPLICATE,
            self::REQUIRED_FIELD_MISSING,
            self::SURFACE_UNVERIFIED,
            self::SURFACE_ARTIFACT_MISSING,
        ];
    }

    public static function assertValidReason(string $value): string
    {
        if (! in_array($value, self::reasons(), true)) {
            throw new InvalidArgumentException(sprintf('Invalid career surface context artifact issue reason [%s].', $value));
        }

        return $value;
    }

    /**
     * @return array{reason: string, message: string, canonical_slug: string|null, locale: string|null, field: string|null, evidence: list<mixed>}
     */
    public function toArray(): array
    {
        return [
            'reason' => $this->reason,
            'message' => $this->message,
            'canonical_slug' => $this->canonicalSlug,
            'locale' => $this->locale,
            'field' => $this->field,
            'evidence' => $this->evidence,
        ];
    }

    private static function assertNonEmptyString(string $value, string $key): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Career surface context artifact issue requires non-empty [%s].', $key));
        }
    }

    private static function assertList(array $value, string $key): void
    {
        if (! array_is_list($value)) {
            throw new InvalidArgumentException(sprintf('Career surface context artifact issue [%s] must be a list.', $key));
        }
    }
}
