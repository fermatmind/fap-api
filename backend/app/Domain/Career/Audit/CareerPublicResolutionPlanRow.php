<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerPublicResolutionPlanRow
{
    /**
     * @param  list<string>  $locales
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?int $rowNumber,
        public readonly ?string $canonicalSlug,
        public readonly ?string $publicResolutionState,
        public readonly ?string $canonicalPublicType,
        public readonly ?string $rolloutState,
        public readonly ?string $projectionState,
        public readonly ?string $indexStateHint,
        public readonly ?string $titleEn,
        public readonly ?string $titleZh,
        public readonly ?string $sourceCode,
        public readonly ?string $family,
        public readonly ?string $batchId,
        public readonly array $locales,
        public readonly array $raw,
    ) {
        self::assertLocales($this->locales);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromRaw(array $raw): self
    {
        return new self(
            rowNumber: self::normalizeInt($raw['row_number'] ?? null),
            canonicalSlug: self::normalizeSlug($raw['canonical_slug'] ?? null)
                ?? self::normalizeSlug($raw['slug'] ?? null)
                ?? self::normalizeSlug($raw['source_slug'] ?? null),
            publicResolutionState: self::normalizeString($raw['public_resolution_state'] ?? null)
                ?? self::normalizeString($raw['status'] ?? null)
                ?? self::normalizeString($raw['current_status'] ?? null),
            canonicalPublicType: self::normalizeString($raw['canonical_public_type'] ?? null)
                ?? self::normalizeString($raw['public_resolution_type'] ?? null)
                ?? self::normalizeString($raw['public_type'] ?? null),
            rolloutState: self::normalizeString($raw['rollout_state'] ?? null),
            projectionState: self::normalizeString($raw['projection_state'] ?? null),
            indexStateHint: self::normalizeString($raw['index_state_hint'] ?? null)
                ?? self::normalizeString($raw['indexability'] ?? null)
                ?? self::normalizeString($raw['index_state'] ?? null),
            titleEn: self::titleValue($raw, 'en'),
            titleZh: self::titleValue($raw, 'zh'),
            sourceCode: self::normalizeString($raw['source_code'] ?? null)
                ?? self::normalizeString($raw['o_net_code'] ?? null)
                ?? self::normalizeString($raw['onet_code'] ?? null),
            family: self::normalizeString($raw['family'] ?? null)
                ?? self::normalizeString($raw['career_family'] ?? null),
            batchId: self::normalizeString($raw['batch_id'] ?? null)
                ?? self::normalizeString($raw['batch'] ?? null),
            locales: self::normalizeLocales($raw),
            raw: $raw,
        );
    }

    /**
     * @return array{row_number: int|null, canonical_slug: string|null, public_resolution_state: string|null, canonical_public_type: string|null, rollout_state: string|null, projection_state: string|null, index_state_hint: string|null, title_en: string|null, title_zh: string|null, source_code: string|null, family: string|null, batch_id: string|null, locales: list<string>, raw: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'row_number' => $this->rowNumber,
            'canonical_slug' => $this->canonicalSlug,
            'public_resolution_state' => $this->publicResolutionState,
            'canonical_public_type' => $this->canonicalPublicType,
            'rollout_state' => $this->rolloutState,
            'projection_state' => $this->projectionState,
            'index_state_hint' => $this->indexStateHint,
            'title_en' => $this->titleEn,
            'title_zh' => $this->titleZh,
            'source_code' => $this->sourceCode,
            'family' => $this->family,
            'batch_id' => $this->batchId,
            'locales' => $this->locales,
            'raw' => $this->raw,
        ];
    }

    private static function normalizeString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private static function normalizeSlug(mixed $value): ?string
    {
        $normalized = self::normalizeString($value);

        return $normalized === null ? null : strtolower($normalized);
    }

    private static function normalizeInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private static function titleValue(array $raw, string $locale): ?string
    {
        if ($locale === 'en') {
            $direct = self::normalizeString($raw['title_en'] ?? null)
                ?? self::normalizeString($raw['EN_Title'] ?? null);
            if ($direct !== null) {
                return $direct;
            }
        }

        if ($locale === 'zh') {
            $direct = self::normalizeString($raw['title_zh'] ?? null)
                ?? self::normalizeString($raw['title_zh_cn'] ?? null);
            if ($direct !== null) {
                return $direct;
            }
        }

        $title = $raw['title'] ?? null;
        if (! is_array($title)) {
            return null;
        }

        return self::normalizeString($title[$locale] ?? null)
            ?? ($locale === 'zh' ? self::normalizeString($title['zh-CN'] ?? null) : null);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return list<string>
     */
    private static function normalizeLocales(array $raw): array
    {
        $locales = $raw['locales'] ?? null;
        if (is_array($locales) && array_is_list($locales)) {
            $normalized = [];
            foreach ($locales as $locale) {
                $value = self::normalizeString($locale);
                if ($value !== null) {
                    $normalized[] = $value;
                }
            }

            return array_values(array_unique($normalized));
        }

        $locale = self::normalizeString($raw['locale'] ?? null);

        return $locale === null ? [] : [$locale];
    }

    /**
     * @param  list<string>  $locales
     */
    private static function assertLocales(array $locales): void
    {
        if (! array_is_list($locales)) {
            throw new InvalidArgumentException('Career public resolution plan row locales must be a list.');
        }

        foreach ($locales as $locale) {
            if (! is_string($locale) || trim($locale) === '') {
                throw new InvalidArgumentException('Career public resolution plan row locales must contain non-empty strings.');
            }
        }
    }
}
