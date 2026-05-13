<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerEntityContextArtifactRow
{
    /**
     * @param  list<mixed>  $crosswalks
     * @param  list<string>  $missingEntityFields
     * @param  array<string, mixed>  $evidence
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $canonicalSlug,
        public readonly bool $occupationExists,
        public readonly ?string $occupationId = null,
        public readonly ?string $titleEn = null,
        public readonly ?string $titleZh = null,
        public readonly ?string $family = null,
        public readonly array $crosswalks = [],
        public readonly array $missingEntityFields = [],
        public readonly array $evidence = [],
        public readonly array $raw = [],
    ) {
        self::assertNonEmptyString($this->canonicalSlug, 'canonical_slug');
        self::assertList($this->crosswalks, 'crosswalks');
        self::assertListOfStrings($this->missingEntityFields, 'missing_entity_fields');
        self::assertMap($this->evidence, 'evidence');
        self::assertMap($this->raw, 'raw');

        foreach ([
            'occupation_id' => $this->occupationId,
            'title_en' => $this->titleEn,
            'title_zh' => $this->titleZh,
            'family' => $this->family,
        ] as $key => $value) {
            if ($value !== null) {
                self::assertNonEmptyString($value, $key);
            }
        }
    }

    /**
     * @return array{canonical_slug: string, occupation_exists: bool, occupation_id: string|null, title_en: string|null, title_zh: string|null, family: string|null, crosswalks: list<mixed>, missing_entity_fields: list<string>, evidence: array<string, mixed>, raw: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'canonical_slug' => $this->canonicalSlug,
            'occupation_exists' => $this->occupationExists,
            'occupation_id' => $this->occupationId,
            'title_en' => $this->titleEn,
            'title_zh' => $this->titleZh,
            'family' => $this->family,
            'crosswalks' => $this->crosswalks,
            'missing_entity_fields' => $this->missingEntityFields,
            'evidence' => $this->evidence,
            'raw' => $this->raw,
        ];
    }

    private static function assertNonEmptyString(string $value, string $key): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Career entity context artifact row requires non-empty [%s].', $key));
        }
    }

    private static function assertMap(array $value, string $key): void
    {
        if (array_is_list($value) && $value !== []) {
            throw new InvalidArgumentException(sprintf('Career entity context artifact row [%s] must be an object map.', $key));
        }
    }

    /**
     * @param  list<mixed>  $value
     */
    private static function assertList(array $value, string $key): void
    {
        if (! array_is_list($value)) {
            throw new InvalidArgumentException(sprintf('Career entity context artifact row [%s] must be a list.', $key));
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
                throw new InvalidArgumentException(sprintf('Career entity context artifact row [%s] must contain non-empty strings.', $key));
            }
        }
    }
}
