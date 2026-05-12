<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerSeoGeoReadinessIssue
{
    public const CANONICAL_NOT_SELF = 'canonical_not_self';

    public const ROBOTS_NOINDEX = 'robots_noindex';

    public const SITEMAP_MISSING = 'sitemap_missing';

    public const LLMS_MISSING = 'llms_missing';

    public const LLMS_FULL_MISSING = 'llms_full_missing';

    public const STRUCTURED_DATA_MISSING = 'structured_data_missing';

    public const DATASET_MISSING = 'dataset_missing';

    public const SEARCH_MISSING = 'search_missing';

    public const CITATION_METADATA_MISSING = 'citation_metadata_missing';

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
            self::CANONICAL_NOT_SELF,
            self::ROBOTS_NOINDEX,
            self::SITEMAP_MISSING,
            self::LLMS_MISSING,
            self::LLMS_FULL_MISSING,
            self::STRUCTURED_DATA_MISSING,
            self::DATASET_MISSING,
            self::SEARCH_MISSING,
            self::CITATION_METADATA_MISSING,
        ];
    }

    public static function assertValidReason(string $value): string
    {
        if (! in_array($value, self::reasons(), true)) {
            throw new InvalidArgumentException(sprintf('Invalid career SEO/GEO readiness issue reason [%s].', $value));
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
            throw new InvalidArgumentException(sprintf('Career SEO/GEO readiness issue requires non-empty [%s].', $key));
        }
    }

    /**
     * @param  list<mixed>  $value
     */
    private static function assertList(array $value, string $key): void
    {
        if (! array_is_list($value)) {
            throw new InvalidArgumentException(sprintf('Career SEO/GEO readiness issue [%s] must be a list.', $key));
        }
    }
}
