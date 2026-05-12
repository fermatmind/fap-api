<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerBaselineMetadataInventoryRow
{
    public const TITLE_EN_SOURCE_EN_BASELINE = 'en_baseline';

    public const TITLE_EN_SOURCE_BATCH_MANIFEST = 'batch_manifest';

    public const TITLE_EN_SOURCE_CANONICAL_SLUG_DERIVED = 'canonical_slug_derived';

    public const TITLE_EN_SOURCE_MISSING = 'missing';

    /**
     * @param  list<string>  $missingDisplayFields
     * @param  list<mixed>  $evidence
     * @param  list<CareerBaselineMetadataInventoryIssue>  $issues
     */
    public function __construct(
        public readonly string $canonicalSlug,
        public readonly bool $zhBaselineExists,
        public readonly ?string $titleZh,
        public readonly ?string $titleEn,
        public readonly string $titleEnSource,
        public readonly CareerCanonicalEligibilityLayerStatus $baselineStatus,
        public readonly array $missingDisplayFields = [],
        public readonly ?string $sourceScope = null,
        public readonly array $evidence = [],
        public readonly array $issues = [],
    ) {
        self::assertNonEmptyString($this->canonicalSlug, 'canonical_slug');
        self::assertValidTitleEnSource($this->titleEnSource);
        self::assertListOfStrings($this->missingDisplayFields, 'missing_display_fields');
        self::assertList($this->evidence, 'evidence');

        if ($this->titleZh !== null) {
            self::assertNonEmptyString($this->titleZh, 'title_zh');
        }

        if ($this->titleEn !== null) {
            self::assertNonEmptyString($this->titleEn, 'title_en');
        }

        if ($this->sourceScope !== null) {
            self::assertNonEmptyString($this->sourceScope, 'source_scope');
        }

        if (! array_is_list($this->issues)) {
            throw new InvalidArgumentException('Career baseline metadata inventory row issues must be a list.');
        }

        foreach ($this->issues as $issue) {
            if (! $issue instanceof CareerBaselineMetadataInventoryIssue) {
                throw new InvalidArgumentException('Career baseline metadata inventory row issues must contain issue DTOs.');
            }
        }
    }

    /**
     * @return list<string>
     */
    public static function titleEnSources(): array
    {
        return [
            self::TITLE_EN_SOURCE_EN_BASELINE,
            self::TITLE_EN_SOURCE_BATCH_MANIFEST,
            self::TITLE_EN_SOURCE_CANONICAL_SLUG_DERIVED,
            self::TITLE_EN_SOURCE_MISSING,
        ];
    }

    public static function assertValidTitleEnSource(string $value): string
    {
        if (! in_array($value, self::titleEnSources(), true)) {
            throw new InvalidArgumentException(sprintf('Invalid career baseline metadata title_en_source [%s].', $value));
        }

        return $value;
    }

    /**
     * @return array{canonical_slug: string, zh_baseline_exists: bool, title_zh: string|null, title_en: string|null, title_en_source: string, baseline_status: array<string, mixed>, missing_display_fields: list<string>, source_scope: string|null, evidence: list<mixed>, issues: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'canonical_slug' => $this->canonicalSlug,
            'zh_baseline_exists' => $this->zhBaselineExists,
            'title_zh' => $this->titleZh,
            'title_en' => $this->titleEn,
            'title_en_source' => $this->titleEnSource,
            'baseline_status' => $this->baselineStatus->toArray(),
            'missing_display_fields' => $this->missingDisplayFields,
            'source_scope' => $this->sourceScope,
            'evidence' => $this->evidence,
            'issues' => array_map(
                static fn (CareerBaselineMetadataInventoryIssue $issue): array => $issue->toArray(),
                $this->issues
            ),
        ];
    }

    private static function assertNonEmptyString(string $value, string $key): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Career baseline metadata inventory row requires non-empty [%s].', $key));
        }
    }

    /**
     * @param  list<mixed>  $value
     */
    private static function assertList(array $value, string $key): void
    {
        if (! array_is_list($value)) {
            throw new InvalidArgumentException(sprintf('Career baseline metadata inventory row [%s] must be a list.', $key));
        }
    }

    /**
     * @param  list<string>  $value
     */
    private static function assertListOfStrings(array $value, string $key): void
    {
        self::assertList($value, $key);

        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new InvalidArgumentException(sprintf('Career baseline metadata inventory row [%s] must contain non-empty strings.', $key));
            }
        }
    }
}
