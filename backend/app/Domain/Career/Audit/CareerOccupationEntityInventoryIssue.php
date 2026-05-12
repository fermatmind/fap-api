<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerOccupationEntityInventoryIssue
{
    public const OCCUPATION_MISSING = 'occupation_missing';

    public const CANONICAL_SLUG_DUPLICATE_IN_INPUT = 'canonical_slug_duplicate_in_input';

    public const CANONICAL_SLUG_DUPLICATE_IN_ENTITIES = 'canonical_slug_duplicate_in_entities';

    public const ENTITY_FIELD_MISSING = 'entity_field_missing';

    public const OCCUPATION_QUERY_FAILED = 'occupation_query_failed';

    public const INPUT_SLUG_MISSING = 'input_slug_missing';

    /**
     * @param  list<mixed>  $evidence
     */
    public function __construct(
        public readonly string $reason,
        public readonly string $message,
        public readonly string $severity = CareerCanonicalEligibilitySeverity::MEDIUM,
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
            self::OCCUPATION_MISSING,
            self::CANONICAL_SLUG_DUPLICATE_IN_INPUT,
            self::CANONICAL_SLUG_DUPLICATE_IN_ENTITIES,
            self::ENTITY_FIELD_MISSING,
            self::OCCUPATION_QUERY_FAILED,
            self::INPUT_SLUG_MISSING,
        ];
    }

    public static function assertValidReason(string $value): string
    {
        if (! in_array($value, self::reasons(), true)) {
            throw new InvalidArgumentException(sprintf('Invalid career occupation entity inventory issue reason [%s].', $value));
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
            throw new InvalidArgumentException(sprintf('Career occupation entity inventory issue requires non-empty [%s].', $key));
        }
    }

    /**
     * @param  list<mixed>  $value
     */
    private static function assertList(array $value, string $key): void
    {
        if (! array_is_list($value)) {
            throw new InvalidArgumentException(sprintf('Career occupation entity inventory issue [%s] must be a list.', $key));
        }
    }
}
