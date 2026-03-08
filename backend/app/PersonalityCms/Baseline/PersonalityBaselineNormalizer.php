<?php

declare(strict_types=1);

namespace App\PersonalityCms\Baseline;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileSection;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;

final class PersonalityBaselineNormalizer
{
    /**
     * @param  array<int, array{file: string, payload: array<string, mixed>}>  $documents
     * @param  array<int, string>  $selectedTypes
     * @return array<int, array<string, mixed>>
     */
    public function normalizeDocuments(array $documents, array $selectedTypes = []): array
    {
        $normalizedTypes = $this->normalizeSelectedTypes($selectedTypes);
        $profiles = [];
        $seenByLocaleType = [];
        $seenByLocaleSlug = [];

        foreach ($documents as $document) {
            $file = (string) ($document['file'] ?? 'unknown');
            $payload = is_array($document['payload'] ?? null) ? $document['payload'] : [];
            $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
            $locale = $this->normalizeLocale($meta['locale'] ?? null, $file);
            $scaleCode = trim((string) ($meta['scale_code'] ?? PersonalityProfile::SCALE_CODE_MBTI));
            $schemaVersion = trim((string) ($meta['schema_version'] ?? 'v1'));

            if ($scaleCode !== PersonalityProfile::SCALE_CODE_MBTI) {
                throw new RuntimeException(sprintf(
                    'Baseline file %s has unsupported scale_code=%s.',
                    $file,
                    $scaleCode,
                ));
            }

            $rows = $payload['profiles'] ?? null;

            if (! is_array($rows)) {
                throw new RuntimeException(sprintf(
                    'Baseline file %s must contain a profiles array.',
                    $file,
                ));
            }

            foreach ($rows as $index => $row) {
                if (! is_array($row)) {
                    throw new RuntimeException(sprintf(
                        'Baseline file %s contains a non-object profile row at index %d.',
                        $file,
                        $index,
                    ));
                }

                $profile = $this->normalizeProfile($row, $locale, $schemaVersion, $file, $index);
                $typeCode = (string) $profile['type_code'];
                $slug = (string) $profile['slug'];

                if ($normalizedTypes !== [] && ! in_array($typeCode, $normalizedTypes, true)) {
                    continue;
                }

                $typeKey = $locale.'|'.$typeCode;
                $slugKey = $locale.'|'.$slug;

                if (isset($seenByLocaleType[$typeKey])) {
                    throw new RuntimeException(sprintf(
                        'Duplicate type_code %s for locale %s in baseline file %s.',
                        $typeCode,
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

                $seenByLocaleType[$typeKey] = true;
                $seenByLocaleSlug[$slugKey] = true;
                $profiles[] = $profile;
            }
        }

        usort(
            $profiles,
            static fn (array $left, array $right): int => [$left['locale'], $left['type_code']]
                <=> [$right['locale'], $right['type_code']],
        );

        return $profiles;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeProfile(
        array $row,
        string $locale,
        string $schemaVersion,
        string $file,
        int $index,
    ): array {
        $typeCode = Str::upper(trim((string) ($row['type_code'] ?? '')));
        $slug = Str::lower(trim((string) ($row['slug'] ?? '')));
        $title = trim((string) ($row['title'] ?? ''));

        if ($typeCode === '' || ! in_array($typeCode, PersonalityProfile::TYPE_CODES, true)) {
            throw new RuntimeException(sprintf(
                'Baseline file %s has invalid type_code at profiles[%d].',
                $file,
                $index,
            ));
        }

        if ($slug === '' || $slug !== Str::lower($typeCode)) {
            throw new RuntimeException(sprintf(
                'Baseline file %s has invalid slug for %s at profiles[%d].',
                $file,
                $typeCode,
                $index,
            ));
        }

        if ($title === '') {
            throw new RuntimeException(sprintf(
                'Baseline file %s is missing title for %s at profiles[%d].',
                $file,
                $typeCode,
                $index,
            ));
        }

        $sections = $this->normalizeSections(
            is_array($row['sections'] ?? null) ? $row['sections'] : [],
            $file,
            $index,
        );

        return [
            'org_id' => 0,
            'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
            'type_code' => $typeCode,
            'slug' => $slug,
            'locale' => $locale,
            'title' => $title,
            'subtitle' => $this->normalizeNullableText($row['subtitle'] ?? null),
            'excerpt' => $this->normalizeNullableText($row['excerpt'] ?? null),
            'hero_kicker' => $this->normalizeNullableText($row['hero_kicker'] ?? null),
            'hero_quote' => $this->normalizeNullableText($row['hero_quote'] ?? null),
            'hero_image_url' => $this->normalizeNullableText($row['hero_image_url'] ?? null),
            'status' => $this->normalizeStatus($row['status'] ?? 'published', $file, $index),
            'is_public' => $this->normalizeBool($row['is_public'] ?? true),
            'is_indexable' => $this->normalizeBool($row['is_indexable'] ?? true),
            'published_at' => $this->normalizeNullableText($row['published_at'] ?? null),
            'scheduled_at' => $this->normalizeNullableText($row['scheduled_at'] ?? null),
            'schema_version' => $schemaVersion !== '' ? $schemaVersion : 'v1',
            'sections' => $sections,
            'seo_meta' => $this->normalizeSeoMeta(
                is_array($row['seo_meta'] ?? null) ? $row['seo_meta'] : [],
            ),
        ];
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSections(array $rows, string $file, int $profileIndex): array
    {
        $known = PersonalityProfileSection::SECTION_KEYS;
        $sections = [];
        $seen = [];

        foreach ($rows as $sectionIndex => $row) {
            if (! is_array($row)) {
                throw new RuntimeException(sprintf(
                    'Baseline file %s contains a non-object section at profiles[%d].sections[%d].',
                    $file,
                    $profileIndex,
                    $sectionIndex,
                ));
            }

            $sectionKey = trim((string) ($row['section_key'] ?? ''));

            if ($sectionKey === '' || ! in_array($sectionKey, $known, true)) {
                throw new RuntimeException(sprintf(
                    'Baseline file %s contains unsupported section_key=%s at profiles[%d].sections[%d].',
                    $file,
                    $sectionKey,
                    $profileIndex,
                    $sectionIndex,
                ));
            }

            if (isset($seen[$sectionKey])) {
                throw new RuntimeException(sprintf(
                    'Baseline file %s contains duplicate section_key=%s at profiles[%d].',
                    $file,
                    $sectionKey,
                    $profileIndex,
                ));
            }

            $renderVariant = trim((string) ($row['render_variant'] ?? ''));
            if ($renderVariant === '' || ! in_array($renderVariant, PersonalityProfileSection::RENDER_VARIANTS, true)) {
                throw new RuntimeException(sprintf(
                    'Baseline file %s contains invalid render_variant for section %s at profiles[%d].sections[%d].',
                    $file,
                    $sectionKey,
                    $profileIndex,
                    $sectionIndex,
                ));
            }

            $sections[] = [
                'section_key' => $sectionKey,
                'title' => $this->normalizeNullableText($row['title'] ?? null),
                'render_variant' => $renderVariant,
                'body_md' => $this->normalizeNullableText($row['body_md'] ?? null),
                'body_html' => $this->normalizeNullableText($row['body_html'] ?? null),
                'payload_json' => Arr::exists($row, 'payload_json') ? $this->normalizeNullableArray($row['payload_json']) : null,
                'sort_order' => (int) ($row['sort_order'] ?? 0),
                'is_enabled' => $this->normalizeBool($row['is_enabled'] ?? true),
            ];

            $seen[$sectionKey] = true;
        }

        usort(
            $sections,
            static fn (array $left, array $right): int => [$left['sort_order'], $left['section_key']]
                <=> [$right['sort_order'], $right['section_key']],
        );

        return $sections;
    }

    /**
     * @param  array<string, mixed>  $seoMeta
     * @return array<string, mixed>
     */
    private function normalizeSeoMeta(array $seoMeta): array
    {
        return [
            'seo_title' => $this->normalizeNullableText($seoMeta['seo_title'] ?? null),
            'seo_description' => $this->normalizeNullableText($seoMeta['seo_description'] ?? null),
            'canonical_url' => $this->normalizeNullableText($seoMeta['canonical_url'] ?? null),
            'og_title' => $this->normalizeNullableText($seoMeta['og_title'] ?? null),
            'og_description' => $this->normalizeNullableText($seoMeta['og_description'] ?? null),
            'og_image_url' => $this->normalizeNullableText($seoMeta['og_image_url'] ?? null),
            'twitter_title' => $this->normalizeNullableText($seoMeta['twitter_title'] ?? null),
            'twitter_description' => $this->normalizeNullableText($seoMeta['twitter_description'] ?? null),
            'twitter_image_url' => $this->normalizeNullableText($seoMeta['twitter_image_url'] ?? null),
            'robots' => $this->normalizeNullableText($seoMeta['robots'] ?? null),
            'jsonld_overrides_json' => Arr::exists($seoMeta, 'jsonld_overrides_json')
                ? $this->normalizeNullableArray($seoMeta['jsonld_overrides_json'])
                : null,
        ];
    }

    private function normalizeLocale(mixed $locale, string $file): string
    {
        $normalized = trim((string) $locale);
        $normalized = $normalized === 'zh' ? 'zh-CN' : $normalized;

        if (! in_array($normalized, PersonalityProfile::SUPPORTED_LOCALES, true)) {
            throw new RuntimeException(sprintf(
                'Baseline file %s has unsupported locale=%s.',
                $file,
                (string) $locale,
            ));
        }

        return $normalized;
    }

    /**
     * @param  array<int, string>  $selectedTypes
     * @return array<int, string>
     */
    private function normalizeSelectedTypes(array $selectedTypes): array
    {
        $normalized = [];

        foreach ($selectedTypes as $type) {
            $candidate = Str::upper(trim((string) $type));

            if ($candidate === '') {
                continue;
            }

            if (! in_array($candidate, PersonalityProfile::TYPE_CODES, true)) {
                throw new RuntimeException(sprintf(
                    'Unsupported type selection: %s',
                    $candidate,
                ));
            }

            $normalized[] = $candidate;
        }

        return array_values(array_unique($normalized));
    }

    private function normalizeStatus(mixed $status, string $file, int $index): string
    {
        $normalized = trim((string) $status);

        if (! in_array($normalized, ['draft', 'published'], true)) {
            throw new RuntimeException(sprintf(
                'Baseline file %s has invalid status at profiles[%d].',
                $file,
                $index,
            ));
        }

        return $normalized;
    }

    private function normalizeBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (bool) $value;
        }

        $normalized = Str::lower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private function normalizeNullableText(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return array<string, mixed>|array<int, mixed>|null
     */
    private function normalizeNullableArray(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            throw new RuntimeException('Baseline JSON field must be an object or array when provided.');
        }

        return $value === [] ? null : $value;
    }
}
