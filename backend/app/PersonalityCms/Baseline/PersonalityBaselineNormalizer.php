<?php

declare(strict_types=1);

namespace App\PersonalityCms\Baseline;

use App\Models\PersonalityProfile;
use App\Support\Mbti\MbtiCanonicalSectionRegistry;
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
        $profilesByLocaleType = [];
        $seenByLocaleType = [];
        $seenByLocaleSlug = [];
        $variantsByLocaleType = [];
        $seenVariantRuntime = [];
        $seenVariantCode = [];

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
                $profilesByLocaleType[$typeKey] = $profile;
            }

            $variantRows = $payload['variants'] ?? [];
            if (! is_array($variantRows)) {
                throw new RuntimeException(sprintf(
                    'Baseline file %s must contain a variants array when provided.',
                    $file,
                ));
            }

            foreach ($variantRows as $index => $row) {
                if (! is_array($row)) {
                    throw new RuntimeException(sprintf(
                        'Baseline file %s contains a non-object variant row at index %d.',
                        $file,
                        $index,
                    ));
                }

                $variant = $this->normalizeVariant($row, $locale, $schemaVersion, $file, $index);
                $canonicalTypeCode = (string) $variant['canonical_type_code'];
                $runtimeTypeCode = (string) $variant['runtime_type_code'];
                $variantCode = (string) $variant['variant_code'];

                if ($normalizedTypes !== [] && ! in_array($canonicalTypeCode, $normalizedTypes, true)) {
                    continue;
                }

                $runtimeKey = $locale.'|'.$runtimeTypeCode;
                $variantKey = $locale.'|'.$canonicalTypeCode.'|'.$variantCode;

                if (isset($seenVariantRuntime[$runtimeKey])) {
                    throw new RuntimeException(sprintf(
                        'Duplicate runtime_type_code %s for locale %s in baseline file %s.',
                        $runtimeTypeCode,
                        $locale,
                        $file,
                    ));
                }

                if (isset($seenVariantCode[$variantKey])) {
                    throw new RuntimeException(sprintf(
                        'Duplicate variant_code %s for canonical_type_code %s locale %s in baseline file %s.',
                        $variantCode,
                        $canonicalTypeCode,
                        $locale,
                        $file,
                    ));
                }

                $seenVariantRuntime[$runtimeKey] = true;
                $seenVariantCode[$variantKey] = true;
                $variantsByLocaleType[$locale.'|'.$canonicalTypeCode][] = $variant;
            }
        }

        foreach ($variantsByLocaleType as $typeKey => $variants) {
            if (! isset($profilesByLocaleType[$typeKey])) {
                throw new RuntimeException(sprintf(
                    'Baseline variants reference missing canonical profile %s.',
                    $typeKey,
                ));
            }
        }

        $profiles = [];
        foreach ($profilesByLocaleType as $typeKey => $profile) {
            $profile['variants'] = $this->sortVariants($variantsByLocaleType[$typeKey] ?? []);
            $profiles[] = $profile;
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
            'canonical_type_code' => $typeCode,
            'slug' => $slug,
            'locale' => $locale,
            'title' => $title,
            'type_name' => $this->normalizeNullableText($row['type_name'] ?? null),
            'nickname' => $this->normalizeNullableText($row['nickname'] ?? null),
            'rarity_text' => $this->normalizeNullableText($row['rarity_text'] ?? null),
            'keywords_json' => $this->normalizeStringList($row['keywords_json'] ?? []),
            'subtitle' => $this->normalizeNullableText($row['subtitle'] ?? null),
            'excerpt' => $this->normalizeNullableText($row['excerpt'] ?? null),
            'hero_kicker' => $this->normalizeNullableText($row['hero_kicker'] ?? null),
            'hero_quote' => $this->normalizeNullableText($row['hero_quote'] ?? null),
            'hero_summary_md' => $this->normalizeNullableText($row['hero_summary_md'] ?? null),
            'hero_summary_html' => $this->normalizeNullableText($row['hero_summary_html'] ?? null),
            'hero_image_url' => $this->normalizeNullableText($row['hero_image_url'] ?? null),
            'status' => $this->normalizeStatus($row['status'] ?? 'published', $file, $index),
            'is_public' => $this->normalizeBool($row['is_public'] ?? true),
            'is_indexable' => $this->normalizeBool($row['is_indexable'] ?? true),
            'published_at' => $this->normalizeNullableText($row['published_at'] ?? null),
            'scheduled_at' => $this->normalizeNullableText($row['scheduled_at'] ?? null),
            'schema_version' => $schemaVersion !== '' ? $schemaVersion : PersonalityProfile::SCHEMA_VERSION_V2,
            'sections' => $sections,
            'seo_meta' => $this->normalizeSeoMeta(
                is_array($row['seo_meta'] ?? null) ? $row['seo_meta'] : [],
            ),
            'variants' => [],
        ];
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSections(array $rows, string $file, int $profileIndex): array
    {
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
            $definition = $this->canonicalSectionDefinition($sectionKey, $file, $profileIndex, $sectionIndex);

            if (isset($seen[$sectionKey])) {
                throw new RuntimeException(sprintf(
                    'Baseline file %s contains duplicate section_key=%s at profiles[%d].',
                    $file,
                    $sectionKey,
                    $profileIndex,
                ));
            }

            $renderVariant = trim((string) ($row['render_variant'] ?? $definition['render_variant']));
            if ($renderVariant === '' || $renderVariant !== (string) $definition['render_variant']) {
                throw new RuntimeException(sprintf(
                    'Baseline file %s contains invalid render_variant for canonical section %s at profiles[%d].sections[%d].',
                    $file,
                    $sectionKey,
                    $profileIndex,
                    $sectionIndex,
                ));
            }

            $sections[] = [
                'section_key' => $sectionKey,
                'title' => $this->normalizeNullableText($row['title'] ?? null) ?? (string) $definition['title'],
                'render_variant' => $renderVariant,
                'body_md' => $this->normalizeNullableText($row['body_md'] ?? null),
                'body_html' => $this->normalizeNullableText($row['body_html'] ?? null),
                'payload_json' => Arr::exists($row, 'payload_json') ? $this->normalizeNullableArray($row['payload_json']) : null,
                'sort_order' => (int) ($row['sort_order'] ?? $definition['sort_order'] ?? 0),
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
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeVariant(
        array $row,
        string $locale,
        string $schemaVersion,
        string $file,
        int $index,
    ): array {
        $canonicalTypeCode = Str::upper(trim((string) ($row['canonical_type_code'] ?? '')));
        $variantCode = Str::upper(trim((string) ($row['variant_code'] ?? '')));
        $runtimeTypeCode = Str::upper(trim((string) ($row['runtime_type_code'] ?? '')));

        if (! in_array($canonicalTypeCode, PersonalityProfile::TYPE_CODES, true)) {
            throw new RuntimeException(sprintf(
                'Baseline file %s has invalid canonical_type_code at variants[%d].',
                $file,
                $index,
            ));
        }

        if (! in_array($variantCode, ['A', 'T'], true)) {
            throw new RuntimeException(sprintf(
                'Baseline file %s has invalid variant_code at variants[%d].',
                $file,
                $index,
            ));
        }

        if (preg_match('/^(?<base>[EI][SN][TF][JP])-(?<variant>[AT])$/', $runtimeTypeCode, $matches) !== 1) {
            throw new RuntimeException(sprintf(
                'Baseline file %s has invalid runtime_type_code at variants[%d].',
                $file,
                $index,
            ));
        }

        if ((string) $matches['base'] !== $canonicalTypeCode || (string) $matches['variant'] !== $variantCode) {
            throw new RuntimeException(sprintf(
                'Baseline file %s has mismatched runtime_type_code/canonical_type_code/variant_code at variants[%d].',
                $file,
                $index,
            ));
        }

        return [
            'canonical_type_code' => $canonicalTypeCode,
            'variant_code' => $variantCode,
            'runtime_type_code' => $runtimeTypeCode,
            'locale' => $locale,
            'schema_version' => $schemaVersion !== '' ? $schemaVersion : PersonalityProfile::SCHEMA_VERSION_V2,
            'is_published' => $this->normalizeBool($row['is_published'] ?? false),
            'published_at' => $this->normalizeNullableText($row['published_at'] ?? null),
            'profile_overrides' => $this->normalizeVariantProfileOverrides(
                is_array($row['profile_overrides'] ?? null) ? $row['profile_overrides'] : [],
            ),
            'section_overrides' => $this->normalizeVariantSections(
                is_array($row['section_overrides'] ?? null) ? $row['section_overrides'] : [],
                $file,
                $index,
            ),
            'seo_overrides' => $this->normalizeSeoMeta(
                is_array($row['seo_overrides'] ?? null) ? $row['seo_overrides'] : [],
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $profileOverrides
     * @return array<string, mixed>
     */
    private function normalizeVariantProfileOverrides(array $profileOverrides): array
    {
        return [
            'type_name' => $this->normalizeNullableText($profileOverrides['type_name'] ?? null),
            'nickname' => $this->normalizeNullableText($profileOverrides['nickname'] ?? null),
            'rarity_text' => $this->normalizeNullableText($profileOverrides['rarity_text'] ?? null),
            'keywords_json' => $this->normalizeStringList($profileOverrides['keywords_json'] ?? []),
            'hero_summary_md' => $this->normalizeNullableText($profileOverrides['hero_summary_md'] ?? null),
            'hero_summary_html' => $this->normalizeNullableText($profileOverrides['hero_summary_html'] ?? null),
        ];
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function normalizeVariantSections(array $rows, string $file, int $variantIndex): array
    {
        $sections = [];
        $seen = [];

        foreach ($rows as $sectionIndex => $row) {
            if (! is_array($row)) {
                throw new RuntimeException(sprintf(
                    'Baseline file %s contains a non-object variant section at variants[%d].section_overrides[%d].',
                    $file,
                    $variantIndex,
                    $sectionIndex,
                ));
            }

            $sectionKey = trim((string) ($row['section_key'] ?? ''));
            $definition = $this->canonicalSectionDefinition($sectionKey, $file, $variantIndex, $sectionIndex, true);

            if (isset($seen[$sectionKey])) {
                throw new RuntimeException(sprintf(
                    'Baseline file %s contains duplicate variant section_key=%s at variants[%d].',
                    $file,
                    $sectionKey,
                    $variantIndex,
                ));
            }

            $renderVariant = trim((string) ($row['render_variant'] ?? $definition['render_variant']));
            if ($renderVariant === '' || $renderVariant !== (string) $definition['render_variant']) {
                throw new RuntimeException(sprintf(
                    'Baseline file %s contains invalid render_variant for variant section %s at variants[%d].section_overrides[%d].',
                    $file,
                    $sectionKey,
                    $variantIndex,
                    $sectionIndex,
                ));
            }

            $sections[] = [
                'section_key' => $sectionKey,
                'render_variant' => $renderVariant,
                'body_md' => $this->normalizeNullableText($row['body_md'] ?? null),
                'body_html' => $this->normalizeNullableText($row['body_html'] ?? null),
                'payload_json' => Arr::exists($row, 'payload_json') ? $this->normalizeNullableArray($row['payload_json']) : null,
                'sort_order' => (int) ($row['sort_order'] ?? $definition['sort_order'] ?? 0),
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
     * @return list<string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];

        foreach ($value as $item) {
            $candidate = $this->normalizeNullableText($item);
            if ($candidate === null) {
                continue;
            }

            $normalized[$candidate] = true;
        }

        return array_values(array_keys($normalized));
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

    /**
     * @return array<string, mixed>
     */
    private function canonicalSectionDefinition(
        string $sectionKey,
        string $file,
        int $rowIndex,
        int $sectionIndex,
        bool $isVariant = false,
    ): array {
        try {
            return MbtiCanonicalSectionRegistry::definition($sectionKey);
        } catch (\InvalidArgumentException) {
            throw new RuntimeException(sprintf(
                $isVariant
                    ? 'Baseline file %s contains unsupported variant section_key=%s at variants[%d].section_overrides[%d].'
                    : 'Baseline file %s contains unsupported section_key=%s at profiles[%d].sections[%d].',
                $file,
                $sectionKey,
                $rowIndex,
                $sectionIndex,
            ));
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $variants
     * @return array<int, array<string, mixed>>
     */
    private function sortVariants(array $variants): array
    {
        usort(
            $variants,
            static fn (array $left, array $right): int => [$left['variant_code'], $left['runtime_type_code']]
                <=> [$right['variant_code'], $right['runtime_type_code']],
        );

        return $variants;
    }
}
