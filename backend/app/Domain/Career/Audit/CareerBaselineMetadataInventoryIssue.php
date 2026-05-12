<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerBaselineMetadataInventoryIssue
{
    public const ZH_BASELINE_MISSING = 'zh_baseline_missing';

    public const EN_TITLE_MISSING = 'en_title_missing';

    public const EN_TITLE_DERIVATION_REQUIRED = 'en_title_derivation_required';

    public const REQUIRED_DISPLAY_FIELD_MISSING = 'required_display_field_missing';

    public const BASELINE_JSON_INVALID = 'baseline_json_invalid';

    public const BASELINE_SOURCE_MISSING = 'baseline_source_missing';

    /**
     * @param  list<mixed>  $evidence
     */
    public function __construct(
        public readonly string $reason,
        public readonly string $message,
        public readonly string $severity = CareerCanonicalEligibilitySeverity::MEDIUM,
        public readonly ?string $canonicalSlug = null,
        public readonly ?string $field = null,
        public readonly ?string $sourcePath = null,
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

        if ($this->sourcePath !== null) {
            self::assertNonEmptyString($this->sourcePath, 'source_path');
        }
    }

    /**
     * @return list<string>
     */
    public static function reasons(): array
    {
        return [
            self::ZH_BASELINE_MISSING,
            self::EN_TITLE_MISSING,
            self::EN_TITLE_DERIVATION_REQUIRED,
            self::REQUIRED_DISPLAY_FIELD_MISSING,
            self::BASELINE_JSON_INVALID,
            self::BASELINE_SOURCE_MISSING,
        ];
    }

    public static function assertValidReason(string $value): string
    {
        if (! in_array($value, self::reasons(), true)) {
            throw new InvalidArgumentException(sprintf('Invalid career baseline metadata inventory issue reason [%s].', $value));
        }

        return $value;
    }

    /**
     * @return array{reason: string, message: string, severity: string, canonical_slug: string|null, field: string|null, source_path: string|null, evidence: list<mixed>}
     */
    public function toArray(): array
    {
        return [
            'reason' => $this->reason,
            'message' => $this->message,
            'severity' => $this->severity,
            'canonical_slug' => $this->canonicalSlug,
            'field' => $this->field,
            'source_path' => $this->sourcePath,
            'evidence' => $this->evidence,
        ];
    }

    private static function assertNonEmptyString(string $value, string $key): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Career baseline metadata inventory issue requires non-empty [%s].', $key));
        }
    }

    /**
     * @param  list<mixed>  $value
     */
    private static function assertList(array $value, string $key): void
    {
        if (! array_is_list($value)) {
            throw new InvalidArgumentException(sprintf('Career baseline metadata inventory issue [%s] must be a list.', $key));
        }
    }
}
