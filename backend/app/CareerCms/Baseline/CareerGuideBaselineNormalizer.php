<?php

declare(strict_types=1);

namespace App\CareerCms\Baseline;

use App\Models\CareerGuide;
use App\Models\PersonalityProfile;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;

final class CareerGuideBaselineNormalizer
{
    /**
     * @var array<int, string>
     */
    private const SEO_META_FIELDS = [
        'seo_title',
        'seo_description',
        'canonical_url',
        'og_title',
        'og_description',
        'og_image_url',
        'twitter_title',
        'twitter_description',
        'twitter_image_url',
        'robots',
        'jsonld_overrides_json',
    ];

    /**
     * @param  array<int, array{file: string, payload: array<string, mixed>}>  $documents
     * @param  array<int, string>  $selectedGuides
     * @return array<int, array<string, mixed>>
     */
    public function normalizeDocuments(array $documents, array $selectedGuides = []): array
    {
        $normalizedGuides = $this->normalizeSelectedGuides($selectedGuides);
        $guides = [];
        $seenByLocaleGuide = [];
        $seenByLocaleSlug = [];

        foreach ($documents as $document) {
            $file = (string) ($document['file'] ?? 'unknown');
            $payload = is_array($document['payload'] ?? null) ? $document['payload'] : [];
            $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
            $locale = $this->normalizeLocale($meta['locale'] ?? null, $file);
            $schemaVersion = trim((string) ($meta['schema_version'] ?? 'v1'));
            $rows = $payload['guides'] ?? null;

            if (! is_array($rows)) {
                throw new RuntimeException(sprintf(
                    'Baseline file %s must contain a guides array.',
                    $file,
                ));
            }

            foreach ($rows as $index => $row) {
                if (! is_array($row)) {
                    throw new RuntimeException(sprintf(
                        'Baseline file %s contains a non-object guide row at index %d.',
                        $file,
                        $index,
                    ));
                }

                $guide = $this->normalizeGuide($row, $locale, $schemaVersion, $file, $index);
                $guideCode = (string) $guide['guide_code'];
                $slug = (string) $guide['slug'];

                if ($normalizedGuides !== [] && ! in_array($guideCode, $normalizedGuides, true)) {
                    continue;
                }

                $guideKey = $locale.'|'.$guideCode;
                $slugKey = $locale.'|'.$slug;

                if (isset($seenByLocaleGuide[$guideKey])) {
                    throw new RuntimeException(sprintf(
                        'Duplicate guide_code %s for locale %s in baseline file %s.',
                        $guideCode,
                        $locale,
                        $file,
                    ));
                }

                if (isset($seenByLocaleSlug[$slugKey])) {
                    throw new RuntimeException(sprintf(
                        'Duplicate slug %s for locale %s in baseline file %s.',
                        $slug,
                        $locale,
                        $file,
                    ));
                }

                $seenByLocaleGuide[$guideKey] = true;
                $seenByLocaleSlug[$slugKey] = true;
                $guides[] = $guide;
            }
        }

        usort(
            $guides,
            static fn (array $left, array $right): int => [$left['locale'], $left['guide_code']]
                <=> [$right['locale'], $right['guide_code']],
        );

        return $guides;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeGuide(
        array $row,
        string $documentLocale,
        string $schemaVersion,
        string $file,
        int $index,
    ): array {
        $guideCode = $this->normalizeIdentifier($row['guide_code'] ?? $row['slug'] ?? null, 'guide_code', $file, $index);
        $slug = $this->normalizeIdentifier($row['slug'] ?? $guideCode, 'slug', $file, $index);
        $locale = Arr::exists($row, 'locale')
            ? $this->normalizeLocale($row['locale'], $file)
            : $documentLocale;
        $title = trim((string) ($row['title'] ?? ''));
        $excerpt = $this->normalizeRequiredText($row['excerpt'] ?? null, 'excerpt', $file, $guideCode);
        $categorySlug = $this->normalizeRequiredSlug($row['category_slug'] ?? null, 'category_slug', $file, $guideCode);
        $bodyMd = $this->normalizeRequiredText($row['body_md'] ?? null, 'body_md', $file, $guideCode);

        if ($locale !== $documentLocale) {
            throw new RuntimeException(sprintf(
                'Baseline file %s has locale mismatch for %s at guides[%d].',
                $file,
                $guideCode,
                $index,
            ));
        }

        if ($title === '') {
            throw new RuntimeException(sprintf(
                'Baseline file %s is missing title for %s at guides[%d].',
                $file,
                $guideCode,
                $index,
            ));
        }

        return [
            'org_id' => 0,
            'schema_version' => $schemaVersion !== '' ? $schemaVersion : 'v1',
            'guide_code' => $guideCode,
            'slug' => $slug,
            'locale' => $locale,
            'title' => $title,
            'excerpt' => $excerpt,
            'category_slug' => $categorySlug,
            'body_md' => $bodyMd,
            'body_html' => $this->normalizeNullableText($row['body_html'] ?? null),
            'related_industry_slugs_json' => $this->normalizeSlugArray(
                $row['related_industry_slugs_json'] ?? null,
                $file,
                $guideCode,
                'related_industry_slugs_json',
            ),
            'related_jobs' => $this->normalizeJobRelations(
                $row['related_jobs'] ?? null,
                $file,
                $guideCode,
            ),
            'related_articles' => $this->normalizeArticleRelations(
                $row['related_articles'] ?? null,
                $file,
                $guideCode,
            ),
            'related_personality_profiles' => $this->normalizePersonalityRelations(
                $row['related_personality_profiles'] ?? null,
                $file,
                $guideCode,
            ),
            'seo_meta' => $this->normalizeSeoMeta($row['seo_meta'] ?? null, $file, $guideCode),
            'status' => $this->normalizeStatus($row['status'] ?? CareerGuide::STATUS_PUBLISHED, $file, $index),
            'is_public' => $this->normalizeBool($row['is_public'] ?? true),
            'is_indexable' => $this->normalizeBool($row['is_indexable'] ?? true),
            'published_at' => $this->normalizeNullableText($row['published_at'] ?? null),
            'scheduled_at' => $this->normalizeNullableText($row['scheduled_at'] ?? null),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
        ];
    }

    /**
     * @return array<int, array{job_code: string}>
     */
    private function normalizeJobRelations(mixed $value, string $file, string $guideCode): array
    {
        return $this->normalizeRelationRows(
            $value,
            $file,
            $guideCode,
            'related_jobs',
            'job_code',
            static fn (mixed $candidate): string => Str::lower(trim((string) $candidate)),
            null,
        );
    }

    /**
     * @return array<int, array{slug: string}>
     */
    private function normalizeArticleRelations(mixed $value, string $file, string $guideCode): array
    {
        return $this->normalizeRelationRows(
            $value,
            $file,
            $guideCode,
            'related_articles',
            'slug',
            static fn (mixed $candidate): string => Str::lower(trim((string) $candidate)),
            null,
        );
    }

    /**
     * @return array<int, array{type_code: string}>
     */
    private function normalizePersonalityRelations(mixed $value, string $file, string $guideCode): array
    {
        return $this->normalizeRelationRows(
            $value,
            $file,
            $guideCode,
            'related_personality_profiles',
            'type_code',
            static fn (mixed $candidate): string => Str::upper(trim((string) $candidate)),
            static function (string $typeCode) use ($file, $guideCode): void {
                if (! in_array($typeCode, PersonalityProfile::TYPE_CODES, true)) {
                    throw new RuntimeException(sprintf(
                        'Baseline file %s has invalid related_personality_profiles type_code=%s for %s.',
                        $file,
                        $typeCode,
                        $guideCode,
                    ));
                }
            },
        );
    }

    /**
     * @param  callable(mixed):string  $normalizer
     * @param  (callable(string):void)|null  $validator
     * @return array<int, array<string, string>>
     */
    private function normalizeRelationRows(
        mixed $value,
        string $file,
        string $guideCode,
        string $field,
        string $itemKey,
        callable $normalizer,
        ?callable $validator,
    ): array {
        if (! is_array($value)) {
            throw new RuntimeException(sprintf(
                'Baseline file %s must contain %s as an array for %s.',
                $file,
                $field,
                $guideCode,
            ));
        }

        $rows = [];
        $seen = [];

        foreach ($value as $index => $row) {
            if (! is_array($row)) {
                throw new RuntimeException(sprintf(
                    'Baseline file %s contains a non-object relation row in %s for %s at index %d.',
                    $file,
                    $field,
                    $guideCode,
                    $index,
                ));
            }

            if (! Arr::exists($row, $itemKey)) {
                throw new RuntimeException(sprintf(
                    'Baseline file %s is missing %s.%s for %s at index %d.',
                    $file,
                    $field,
                    $itemKey,
                    $guideCode,
                    $index,
                ));
            }

            $normalized = $normalizer($row[$itemKey]);

            if ($normalized === '') {
                throw new RuntimeException(sprintf(
                    'Baseline file %s has empty %s.%s for %s at index %d.',
                    $file,
                    $field,
                    $itemKey,
                    $guideCode,
                    $index,
                ));
            }

            if ($validator !== null) {
                $validator($normalized);
            }

            if (isset($seen[$normalized])) {
                throw new RuntimeException(sprintf(
                    'Baseline file %s has duplicate %s.%s=%s for %s.',
                    $file,
                    $field,
                    $itemKey,
                    $normalized,
                    $guideCode,
                ));
            }

            $rows[] = [
                $itemKey => $normalized,
            ];
            $seen[$normalized] = true;
        }

        return $rows;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeSlugArray(mixed $value, string $file, string $guideCode, string $field): array
    {
        if (! is_array($value)) {
            throw new RuntimeException(sprintf(
                'Baseline file %s must contain %s as an array for %s.',
                $file,
                $field,
                $guideCode,
            ));
        }

        $normalized = [];

        foreach ($value as $index => $item) {
            $slug = Str::lower(trim((string) $item));

            if ($slug === '') {
                throw new RuntimeException(sprintf(
                    'Baseline file %s has empty %s item for %s at index %d.',
                    $file,
                    $field,
                    $guideCode,
                    $index,
                ));
            }

            if (in_array($slug, $normalized, true)) {
                throw new RuntimeException(sprintf(
                    'Baseline file %s has duplicate %s item=%s for %s.',
                    $file,
                    $field,
                    $slug,
                    $guideCode,
                ));
            }

            $normalized[] = $slug;
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeSeoMeta(mixed $value, string $file, string $guideCode): array
    {
        if (! is_array($value)) {
            throw new RuntimeException(sprintf(
                'Baseline file %s must contain seo_meta as an object for %s.',
                $file,
                $guideCode,
            ));
        }

        return [
            'seo_title' => $this->normalizeNullableText($value['seo_title'] ?? null),
            'seo_description' => $this->normalizeNullableText($value['seo_description'] ?? null),
            'canonical_url' => $this->normalizeNullableText($value['canonical_url'] ?? null),
            'og_title' => $this->normalizeNullableText($value['og_title'] ?? null),
            'og_description' => $this->normalizeNullableText($value['og_description'] ?? null),
            'og_image_url' => $this->normalizeNullableText($value['og_image_url'] ?? null),
            'twitter_title' => $this->normalizeNullableText($value['twitter_title'] ?? null),
            'twitter_description' => $this->normalizeNullableText($value['twitter_description'] ?? null),
            'twitter_image_url' => $this->normalizeNullableText($value['twitter_image_url'] ?? null),
            'robots' => $this->normalizeNullableText($value['robots'] ?? null),
            'jsonld_overrides_json' => Arr::exists($value, 'jsonld_overrides_json')
                ? $this->normalizeNullableArray($value['jsonld_overrides_json'])
                : null,
        ];
    }

    private function normalizeLocale(mixed $locale, string $file): string
    {
        $normalized = trim((string) $locale);
        $normalized = $normalized === 'zh' ? 'zh-CN' : $normalized;

        if (! in_array($normalized, CareerGuide::SUPPORTED_LOCALES, true)) {
            throw new RuntimeException(sprintf(
                'Baseline file %s has unsupported locale=%s.',
                $file,
                (string) $locale,
            ));
        }

        return $normalized;
    }

    /**
     * @param  array<int, string>  $selectedGuides
     * @return array<int, string>
     */
    private function normalizeSelectedGuides(array $selectedGuides): array
    {
        $normalized = [];

        foreach ($selectedGuides as $guide) {
            $candidate = Str::lower(trim((string) $guide));

            if ($candidate === '') {
                continue;
            }

            $normalized[] = $candidate;
        }

        return array_values(array_unique($normalized));
    }

    private function normalizeIdentifier(mixed $value, string $field, string $file, int $index): string
    {
        $normalized = Str::of((string) $value)
            ->trim()
            ->lower()
            ->replace('_', '-')
            ->value();

        if ($normalized === '' || ! preg_match('/^[a-z0-9-]+$/', $normalized)) {
            throw new RuntimeException(sprintf(
                'Baseline file %s has invalid %s at guides[%d].',
                $file,
                $field,
                $index,
            ));
        }

        return $normalized;
    }

    private function normalizeRequiredText(mixed $value, string $field, string $file, string $guideCode): string
    {
        $normalized = $this->normalizeNullableText($value);

        if ($normalized === null) {
            throw new RuntimeException(sprintf(
                'Baseline file %s is missing %s for %s.',
                $file,
                $field,
                $guideCode,
            ));
        }

        return $normalized;
    }

    private function normalizeRequiredSlug(mixed $value, string $field, string $file, string $guideCode): string
    {
        $normalized = Str::lower(trim((string) $value));

        if ($normalized === '' || ! preg_match('/^[a-z0-9-]+$/', $normalized)) {
            throw new RuntimeException(sprintf(
                'Baseline file %s has invalid %s for %s.',
                $file,
                $field,
                $guideCode,
            ));
        }

        return $normalized;
    }

    private function normalizeStatus(mixed $status, string $file, int $index): string
    {
        $normalized = trim((string) $status);

        if (! in_array($normalized, [CareerGuide::STATUS_DRAFT, CareerGuide::STATUS_PUBLISHED], true)) {
            throw new RuntimeException(sprintf(
                'Baseline file %s has invalid status at guides[%d].',
                $file,
                $index,
            ));
        }

        return $normalized;
    }

    private function normalizeBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
    }

    private function normalizeNullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return array<string, mixed>|list<mixed>|null
     */
    private function normalizeNullableArray(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            throw new RuntimeException('Expected jsonld_overrides_json to be an object or array when provided.');
        }

        return $value;
    }
}
