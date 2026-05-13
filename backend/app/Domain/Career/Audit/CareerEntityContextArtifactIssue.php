<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerEntityContextArtifactIssue
{
    public const FILE_MISSING = 'entity_context_file_missing';

    public const JSON_INVALID = 'entity_context_json_invalid';

    public const ROWS_MISSING = 'entity_context_rows_missing';

    public const ROW_MALFORMED = 'entity_context_row_malformed';

    public const SLUG_MISSING = 'entity_context_slug_missing';

    public const SLUG_DUPLICATE = 'entity_context_slug_duplicate';

    public const REQUIRED_FIELD_MISSING = 'entity_context_required_field_missing';

    /**
     * @param  list<mixed>  $evidence
     */
    public function __construct(
        public readonly string $reason,
        public readonly string $message,
        public readonly string $severity = CareerCanonicalEligibilitySeverity::HIGH,
        public readonly ?string $canonicalSlug = null,
        public readonly ?string $field = null,
        public readonly array $evidence = [],
    ) {
        self::assertValidReason($this->reason);
        CareerCanonicalEligibilitySeverity::assertValid($this->severity);
        self::assertNonEmptyString($this->message, 'message');
        self::assertList($this->evidence, 'evidence');

        if ($this->canonicalSlug !== null) {
            self::assertNonEmptyString($this->canonicalSlug, 'canonical_slug');
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
            self::SLUG_MISSING,
            self::SLUG_DUPLICATE,
            self::REQUIRED_FIELD_MISSING,
        ];
    }

    public static function assertValidReason(string $value): string
    {
        if (! in_array($value, self::reasons(), true)) {
            throw new InvalidArgumentException(sprintf('Invalid career entity context artifact issue reason [%s].', $value));
        }

        return $value;
    }

    /**
     * @return array{reason: string, message: string, severity: string, canonical_slug: string|null, field: string|null, evidence: list<mixed>}
     */
    public function toArray(): array
    {
        return [
            'reason' => $this->reason,
            'message' => $this->message,
            'severity' => $this->severity,
            'canonical_slug' => $this->canonicalSlug,
            'field' => $this->field,
            'evidence' => $this->evidence,
        ];
    }

    private static function assertNonEmptyString(string $value, string $key): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Career entity context artifact issue requires non-empty [%s].', $key));
        }
    }

    /**
     * @param  list<mixed>  $value
     */
    private static function assertList(array $value, string $key): void
    {
        if (! array_is_list($value)) {
            throw new InvalidArgumentException(sprintf('Career entity context artifact issue [%s] must be a list.', $key));
        }
    }
}
