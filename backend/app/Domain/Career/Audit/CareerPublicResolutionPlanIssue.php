<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerPublicResolutionPlanIssue
{
    public const PLAN_FILE_MISSING = 'plan_file_missing';

    public const PLAN_JSON_INVALID = 'plan_json_invalid';

    public const PLAN_ROWS_MISSING = 'plan_rows_missing';

    public const PLAN_ROW_MALFORMED = 'plan_row_malformed';

    public const CANONICAL_SLUG_MISSING = 'canonical_slug_missing';

    public const CANONICAL_SLUG_DUPLICATE = 'canonical_slug_duplicate';

    public const EXPECTED_ROW_COUNT_MISMATCH = 'expected_row_count_mismatch';

    public const REQUIRED_FIELD_MISSING = 'required_field_missing';

    public const UNSUPPORTED_PLAN_SHAPE = 'unsupported_plan_shape';

    /**
     * @param  list<mixed>  $evidence
     */
    public function __construct(
        public readonly string $reason,
        public readonly string $message,
        public readonly string $severity = CareerCanonicalEligibilitySeverity::MEDIUM,
        public readonly ?int $rowIndex = null,
        public readonly ?string $canonicalSlug = null,
        public readonly ?string $jsonPath = null,
        public readonly array $evidence = [],
    ) {
        self::assertValidReason($this->reason);
        CareerCanonicalEligibilitySeverity::assertValid($this->severity);
        self::assertNonEmptyString($this->message, 'message');
        self::assertList($this->evidence, 'evidence');
    }

    /**
     * @return list<string>
     */
    public static function reasons(): array
    {
        return [
            self::PLAN_FILE_MISSING,
            self::PLAN_JSON_INVALID,
            self::PLAN_ROWS_MISSING,
            self::PLAN_ROW_MALFORMED,
            self::CANONICAL_SLUG_MISSING,
            self::CANONICAL_SLUG_DUPLICATE,
            self::EXPECTED_ROW_COUNT_MISMATCH,
            self::REQUIRED_FIELD_MISSING,
            self::UNSUPPORTED_PLAN_SHAPE,
        ];
    }

    public static function assertValidReason(string $value): string
    {
        if (! in_array($value, self::reasons(), true)) {
            throw new InvalidArgumentException(sprintf('Invalid career public resolution plan issue reason [%s].', $value));
        }

        return $value;
    }

    /**
     * @return array{reason: string, message: string, severity: string, row_index: int|null, canonical_slug: string|null, json_path: string|null, evidence: list<mixed>}
     */
    public function toArray(): array
    {
        return [
            'reason' => $this->reason,
            'message' => $this->message,
            'severity' => $this->severity,
            'row_index' => $this->rowIndex,
            'canonical_slug' => $this->canonicalSlug,
            'json_path' => $this->jsonPath,
            'evidence' => $this->evidence,
        ];
    }

    private static function assertNonEmptyString(string $value, string $key): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Career public resolution plan issue requires non-empty [%s].', $key));
        }
    }

    /**
     * @param  list<mixed>  $value
     */
    private static function assertList(array $value, string $key): void
    {
        if (! array_is_list($value)) {
            throw new InvalidArgumentException(sprintf('Career public resolution plan issue [%s] must be a list.', $key));
        }
    }
}
