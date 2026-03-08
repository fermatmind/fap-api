<?php

declare(strict_types=1);

namespace App\TopicsCms\Baseline;

use App\Models\TopicProfile;
use App\Models\TopicProfileEntry;
use App\Models\TopicProfileSection;
use Illuminate\Support\Arr;
use RuntimeException;

final class TopicBaselineNormalizer
{
    /**
     * @var array<int, string>
     */
    private const MANAGED_SECTION_KEYS = [
        'overview',
        'key_concepts',
        'why_it_matters',
        'who_should_read',
        'faq',
        'related_topics_intro',
    ];

    /**
     * @param  array<int, array{file: string, payload: array<string, mixed>}>  $documents
     * @return array<int, array<string, mixed>>
     */
    public function normalizeDocuments(array $documents): array
    {
        $profiles = [];
        $seenByLocaleTopic = [];
        $seenByLocaleSlug = [];

        foreach ($documents as $document) {
            $file = (string) ($document['file'] ?? 'unknown');
            $payload = is_array($document['payload'] ?? null) ? $document['payload'] : [];
            $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
            $profileRow = is_array($payload['profile'] ?? null) ? $payload['profile'] : null;

            if ($profileRow === null) {
                throw new RuntimeException(sprintf(
                    'Topic baseline file %s must contain a profile object.',
                    $file,
                ));
            }

            $topicCode = $this->normalizeTopicCode($meta['topic_code'] ?? null, $file);
            $locale = $this->normalizeLocale($meta['locale'] ?? null, $file);
            $schemaVersion = trim((string) ($meta['schema_version'] ?? 'v1'));
            $profile = $this->normalizeProfile(
                $profileRow,
                is_array($payload['sections'] ?? null) ? $payload['sections'] : [],
                is_array($payload['entries'] ?? null) ? $payload['entries'] : [],
                is_array($payload['seo_meta'] ?? null) ? $payload['seo_meta'] : [],
                $topicCode,
                $locale,
                $schemaVersion,
                $file,
            );

            $localeTopicKey = $locale.'|'.$topicCode;
            $localeSlugKey = $locale.'|'.$profile['slug'];

            if (isset($seenByLocaleTopic[$localeTopicKey])) {
                throw new RuntimeException(sprintf(
                    'Duplicate topic_code %s for locale %s in baseline file %s.',
                    $topicCode,
                    $locale,
                    $file,
                ));
            }

            if (isset($seenByLocaleSlug[$localeSlugKey])) {
                throw new RuntimeException(sprintf(
                    'Duplicate slug %s for locale %s in baseline file %s.',
                    $profile['slug'],
                    $locale,
                    $file,
                ));
            }

            $seenByLocaleTopic[$localeTopicKey] = true;
            $seenByLocaleSlug[$localeSlugKey] = true;
            $profiles[] = $profile;
        }

        usort(
            $profiles,
            static fn (array $left, array $right): int => [$left['locale'], $left['topic_code']]
                <=> [$right['locale'], $right['topic_code']],
        );

        return $profiles;
    }

    /**
     * @param  array<string, mixed>  $profileRow
     * @param  array<int, mixed>  $sectionRows
     * @param  array<int, mixed>  $entryRows
     * @param  array<string, mixed>  $seoMeta
     * @return array<string, mixed>
     */
    private function normalizeProfile(
        array $profileRow,
        array $sectionRows,
        array $entryRows,
        array $seoMeta,
        string $topicCode,
        string $locale,
        string $schemaVersion,
        string $file,
    ): array {
        $slug = strtolower(trim((string) ($profileRow['slug'] ?? '')));
        $title = trim((string) ($profileRow['title'] ?? ''));

        if ($slug === '') {
            throw new RuntimeException(sprintf(
                'Topic baseline file %s is missing profile.slug.',
                $file,
            ));
        }

        if ($title === '') {
            throw new RuntimeException(sprintf(
                'Topic baseline file %s is missing profile.title.',
                $file,
            ));
        }

        return [
            'org_id' => 0,
            'topic_code' => $topicCode,
            'slug' => $slug,
            'locale' => $locale,
            'title' => $title,
            'subtitle' => $this->normalizeNullableText($profileRow['subtitle'] ?? null),
            'excerpt' => $this->normalizeNullableText($profileRow['excerpt'] ?? null),
            'hero_kicker' => $this->normalizeNullableText($profileRow['hero_kicker'] ?? null),
            'hero_quote' => $this->normalizeNullableText($profileRow['hero_quote'] ?? null),
            'cover_image_url' => $this->normalizeNullableText($profileRow['cover_image_url'] ?? null),
            'status' => $this->normalizeStatus($profileRow['status'] ?? TopicBaselineImporter::STATUS_PUBLISHED, $file),
            'is_public' => $this->normalizeBool($profileRow['is_public'] ?? true),
            'is_indexable' => $this->normalizeBool($profileRow['is_indexable'] ?? true),
            'published_at' => $this->normalizeNullableText($profileRow['published_at'] ?? null),
            'scheduled_at' => $this->normalizeNullableText($profileRow['scheduled_at'] ?? null),
            'schema_version' => $schemaVersion !== '' ? $schemaVersion : 'v1',
            'sort_order' => (int) ($profileRow['sort_order'] ?? 0),
            'sections' => $this->normalizeSections($sectionRows, $file),
            'entries' => $this->normalizeEntries($entryRows, $locale, $file),
            'seo_meta' => $this->normalizeSeoMeta($seoMeta),
        ];
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSections(array $rows, string $file): array
    {
        $sections = [];
        $seen = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                throw new RuntimeException(sprintf(
                    'Topic baseline file %s contains a non-object section at sections[%d].',
                    $file,
                    $index,
                ));
            }

            $sectionKey = trim((string) ($row['section_key'] ?? ''));
            if ($sectionKey === '' || ! in_array($sectionKey, self::MANAGED_SECTION_KEYS, true)) {
                throw new RuntimeException(sprintf(
                    'Topic baseline file %s contains unsupported section_key=%s at sections[%d].',
                    $file,
                    $sectionKey,
                    $index,
                ));
            }

            if (isset($seen[$sectionKey])) {
                throw new RuntimeException(sprintf(
                    'Topic baseline file %s contains duplicate section_key=%s.',
                    $file,
                    $sectionKey,
                ));
            }

            $renderVariant = trim((string) ($row['render_variant'] ?? ''));
            if ($renderVariant === '' || ! in_array($renderVariant, TopicProfileSection::RENDER_VARIANTS, true)) {
                throw new RuntimeException(sprintf(
                    'Topic baseline file %s contains invalid render_variant for section %s.',
                    $file,
                    $sectionKey,
                ));
            }

            $sections[] = [
                'section_key' => $sectionKey,
                'title' => $this->normalizeNullableText($row['title'] ?? null),
                'render_variant' => $renderVariant,
                'body_md' => $this->normalizeNullableText($row['body_md'] ?? null),
                'body_html' => $this->normalizeNullableText($row['body_html'] ?? null),
                'payload_json' => Arr::exists($row, 'payload_json')
                    ? $this->normalizeNullableArray($row['payload_json'])
                    : null,
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
     * @param  array<int, mixed>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function normalizeEntries(array $rows, string $profileLocale, string $file): array
    {
        $entries = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                throw new RuntimeException(sprintf(
                    'Topic baseline file %s contains a non-object entry at entries[%d].',
                    $file,
                    $index,
                ));
            }

            $entryType = trim((string) ($row['entry_type'] ?? ''));
            $groupKey = trim((string) ($row['group_key'] ?? ''));
            $targetKey = trim((string) ($row['target_key'] ?? ''));

            if ($entryType === '' || ! in_array($entryType, TopicProfileEntry::ENTRY_TYPES, true)) {
                throw new RuntimeException(sprintf(
                    'Topic baseline file %s contains unsupported entry_type=%s at entries[%d].',
                    $file,
                    $entryType,
                    $index,
                ));
            }

            if ($groupKey === '' || ! in_array($groupKey, TopicProfileEntry::GROUP_KEYS, true)) {
                throw new RuntimeException(sprintf(
                    'Topic baseline file %s contains unsupported group_key=%s at entries[%d].',
                    $file,
                    $groupKey,
                    $index,
                ));
            }

            if ($targetKey === '') {
                throw new RuntimeException(sprintf(
                    'Topic baseline file %s is missing target_key at entries[%d].',
                    $file,
                    $index,
                ));
            }

            $targetLocale = Arr::exists($row, 'target_locale')
                ? $this->normalizeNullableLocale($row['target_locale'], $file, false)
                : null;

            $targetUrlOverride = $this->normalizeNullableText($row['target_url_override'] ?? null);
            if ($entryType === 'custom_link') {
                if ($targetUrlOverride === null || ! $this->isValidRelativePath($targetUrlOverride)) {
                    throw new RuntimeException(sprintf(
                        'Topic baseline file %s contains invalid custom_link target_url_override at entries[%d].',
                        $file,
                        $index,
                    ));
                }

                if ($this->normalizeNullableText($row['title_override'] ?? null) === null) {
                    throw new RuntimeException(sprintf(
                        'Topic baseline file %s requires title_override for custom_link at entries[%d].',
                        $file,
                        $index,
                    ));
                }
            }

            if ($entryType !== 'custom_link' && $targetUrlOverride !== null && ! $this->isValidRelativePath($targetUrlOverride)) {
                throw new RuntimeException(sprintf(
                    'Topic baseline file %s contains invalid target_url_override at entries[%d].',
                    $file,
                    $index,
                ));
            }

            $entries[] = [
                'entry_type' => $entryType,
                'group_key' => $groupKey,
                'target_key' => $targetKey,
                'target_locale' => $targetLocale ?? ($entryType === 'scale' ? null : $profileLocale),
                'title_override' => $this->normalizeNullableText($row['title_override'] ?? null),
                'excerpt_override' => $this->normalizeNullableText($row['excerpt_override'] ?? null),
                'badge_label' => $this->normalizeNullableText($row['badge_label'] ?? null),
                'cta_label' => $this->normalizeNullableText($row['cta_label'] ?? null),
                'target_url_override' => $targetUrlOverride,
                'payload_json' => Arr::exists($row, 'payload_json')
                    ? $this->normalizeNullableArray($row['payload_json'])
                    : null,
                'sort_order' => (int) ($row['sort_order'] ?? 0),
                'is_featured' => $this->normalizeBool($row['is_featured'] ?? false),
                'is_enabled' => $this->normalizeBool($row['is_enabled'] ?? true),
            ];
        }

        usort(
            $entries,
            static fn (array $left, array $right): int => [$left['group_key'], $left['sort_order'], $left['entry_type'], $left['target_key']]
                <=> [$right['group_key'], $right['sort_order'], $right['entry_type'], $right['target_key']],
        );

        return $entries;
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

    private function normalizeTopicCode(mixed $topicCode, string $file): string
    {
        $normalized = strtolower(trim((string) $topicCode));

        if ($normalized === '' || ! preg_match('/^[a-z0-9-]+$/', $normalized)) {
            throw new RuntimeException(sprintf(
                'Topic baseline file %s has invalid topic_code=%s.',
                $file,
                (string) $topicCode,
            ));
        }

        return $normalized;
    }

    private function normalizeLocale(mixed $locale, string $file): string
    {
        $normalized = $this->normalizeNullableLocale($locale, $file, true);

        if ($normalized === null) {
            throw new RuntimeException(sprintf(
                'Topic baseline file %s is missing locale.',
                $file,
            ));
        }

        return $normalized;
    }

    private function normalizeNullableLocale(mixed $locale, string $file, bool $required): ?string
    {
        if ($locale === null || trim((string) $locale) === '') {
            return $required ? null : null;
        }

        $normalized = trim((string) $locale);
        $normalized = $normalized === 'zh' ? 'zh-CN' : $normalized;

        if (! in_array($normalized, TopicProfile::SUPPORTED_LOCALES, true)) {
            throw new RuntimeException(sprintf(
                'Topic baseline file %s has unsupported locale=%s.',
                $file,
                (string) $locale,
            ));
        }

        return $normalized;
    }

    private function normalizeStatus(mixed $status, string $file): string
    {
        $normalized = trim((string) $status);

        if (! in_array($normalized, [TopicBaselineImporter::STATUS_DRAFT, TopicBaselineImporter::STATUS_PUBLISHED], true)) {
            throw new RuntimeException(sprintf(
                'Topic baseline file %s has unsupported status=%s.',
                $file,
                (string) $status,
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
            throw new RuntimeException('Expected payload_json/jsonld_overrides_json to be an object or array.');
        }

        return $value;
    }

    private function isValidRelativePath(string $value): bool
    {
        return str_starts_with($value, '/')
            && ! str_starts_with($value, '//')
            && ! preg_match('/^[a-z][a-z0-9+.-]*:/i', $value);
    }
}
