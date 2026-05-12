<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerIndexStateAuthorityIssue
{
    public const INDEX_STATE_MISSING = 'index_state_missing';

    public const INDEX_STATE_NOT_INDEXED_LIKE = 'index_state_not_indexed_like';

    public const INDEX_ELIGIBLE_FALSE = 'index_eligible_false';

    public const EXPLICIT_NOINDEX_BLOCK = 'explicit_noindex_block';

    public const QUARANTINE_BLOCK = 'quarantine_block';

    public const ROLLBACK_BLOCK = 'rollback_block';

    /**
     * @param  list<mixed>  $evidence
     */
    public function __construct(
        public readonly string $reason,
        public readonly string $message,
        public readonly string $severity = CareerCanonicalEligibilitySeverity::MEDIUM,
        public readonly ?string $canonicalSlug = null,
        public readonly ?string $indexStateId = null,
        public readonly array $evidence = [],
    ) {
        self::assertValidReason($this->reason);
        CareerCanonicalEligibilitySeverity::assertValid($this->severity);
        self::assertNonEmptyString($this->message, 'message');
        self::assertList($this->evidence, 'evidence');

        if ($this->canonicalSlug !== null) {
            self::assertNonEmptyString($this->canonicalSlug, 'canonical_slug');
        }

        if ($this->indexStateId !== null) {
            self::assertNonEmptyString($this->indexStateId, 'index_state_id');
        }
    }

    /**
     * @return list<string>
     */
    public static function reasons(): array
    {
        return [
            self::INDEX_STATE_MISSING,
            self::INDEX_STATE_NOT_INDEXED_LIKE,
            self::INDEX_ELIGIBLE_FALSE,
            self::EXPLICIT_NOINDEX_BLOCK,
            self::QUARANTINE_BLOCK,
            self::ROLLBACK_BLOCK,
        ];
    }

    public static function assertValidReason(string $value): string
    {
        if (! in_array($value, self::reasons(), true)) {
            throw new InvalidArgumentException(sprintf('Invalid career index-state authority issue reason [%s].', $value));
        }

        return $value;
    }

    /**
     * @return array{reason: string, message: string, severity: string, canonical_slug: string|null, index_state_id: string|null, evidence: list<mixed>}
     */
    public function toArray(): array
    {
        return [
            'reason' => $this->reason,
            'message' => $this->message,
            'severity' => $this->severity,
            'canonical_slug' => $this->canonicalSlug,
            'index_state_id' => $this->indexStateId,
            'evidence' => $this->evidence,
        ];
    }

    private static function assertNonEmptyString(string $value, string $key): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Career index-state authority issue requires non-empty [%s].', $key));
        }
    }

    /**
     * @param  list<mixed>  $value
     */
    private static function assertList(array $value, string $key): void
    {
        if (! array_is_list($value)) {
            throw new InvalidArgumentException(sprintf('Career index-state authority issue [%s] must be a list.', $key));
        }
    }
}
