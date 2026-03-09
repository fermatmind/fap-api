<?php

declare(strict_types=1);

namespace App\CareerCms\Baseline;

use App\Models\CareerJob;
use App\Models\CareerJobSection;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;

final class CareerJobBaselineNormalizer
{
    /**
     * @var array<int, string>
     */
    private const VALID_MBTI_CODES = [
        'INTJ',
        'INTP',
        'ENTJ',
        'ENTP',
        'INFJ',
        'INFP',
        'ENFJ',
        'ENFP',
        'ISTJ',
        'ISFJ',
        'ESTJ',
        'ESFJ',
        'ISTP',
        'ISFP',
        'ESTP',
        'ESFP',
    ];

    /**
     * @param  array<int, array{file: string, payload: array<string, mixed>}>  $documents
     * @param  array<int, string>  $selectedJobs
     * @return array<int, array<string, mixed>>
     */
    public function normalizeDocuments(array $documents, array $selectedJobs = []): array
    {
        $normalizedJobs = $this->normalizeSelectedJobs($selectedJobs);
        $jobs = [];
        $seenByLocaleJob = [];
        $seenByLocaleSlug = [];

        foreach ($documents as $document) {
            $file = (string) ($document['file'] ?? 'unknown');
            $payload = is_array($document['payload'] ?? null) ? $document['payload'] : [];
            $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
            $locale = $this->normalizeLocale($meta['locale'] ?? null, $file);
            $schemaVersion = trim((string) ($meta['schema_version'] ?? 'v1'));
            $rows = $payload['jobs'] ?? null;

            if (! is_array($rows)) {
                throw new RuntimeException(sprintf(
                    'Baseline file %s must contain a jobs array.',
                    $file,
                ));
            }

            foreach ($rows as $index => $row) {
                if (! is_array($row)) {
                    throw new RuntimeException(sprintf(
                        'Baseline file %s contains a non-object job row at index %d.',
                        $file,
                        $index,
                    ));
                }

                $job = $this->normalizeJob($row, $locale, $schemaVersion, $file, $index);
                $jobCode = (string) $job['job_code'];
                $slug = (string) $job['slug'];

                if ($normalizedJobs !== [] && ! in_array($jobCode, $normalizedJobs, true)) {
                    continue;
                }

                $jobKey = $locale.'|'.$jobCode;
                $slugKey = $locale.'|'.$slug;

                if (isset($seenByLocaleJob[$jobKey])) {
                    throw new RuntimeException(sprintf(
                        'Duplicate job_code %s for locale %s in baseline file %s.',
                        $jobCode,
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

                $seenByLocaleJob[$jobKey] = true;
                $seenByLocaleSlug[$slugKey] = true;
                $jobs[] = $job;
            }
        }

        usort(
            $jobs,
            static fn (array $left, array $right): int => [$left['locale'], $left['job_code']]
                <=> [$right['locale'], $right['job_code']],
        );

        return $jobs;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeJob(
        array $row,
        string $documentLocale,
        string $schemaVersion,
        string $file,
        int $index,
    ): array {
        $jobCode = $this->normalizeIdentifier($row['job_code'] ?? $row['slug'] ?? null, 'job_code', $file, $index);
        $slug = $this->normalizeIdentifier($row['slug'] ?? $jobCode, 'slug', $file, $index);
        $locale = Arr::exists($row, 'locale')
            ? $this->normalizeLocale($row['locale'], $file)
            : $documentLocale;
        $title = trim((string) ($row['title'] ?? ''));

        if ($locale !== $documentLocale) {
            throw new RuntimeException(sprintf(
                'Baseline file %s has locale mismatch for %s at jobs[%d].',
                $file,
                $jobCode,
                $index,
            ));
        }

        if ($title === '') {
            throw new RuntimeException(sprintf(
                'Baseline file %s is missing title for %s at jobs[%d].',
                $file,
                $jobCode,
                $index,
            ));
        }

        return [
            'org_id' => 0,
            'schema_version' => $schemaVersion !== '' ? $schemaVersion : 'v1',
            'job_code' => $jobCode,
            'slug' => $slug,
            'locale' => $locale,
            'title' => $title,
            'subtitle' => $this->normalizeNullableText($row['subtitle'] ?? null),
            'excerpt' => $this->normalizeNullableText($row['excerpt'] ?? null),
            'hero_kicker' => $this->normalizeNullableText($row['hero_kicker'] ?? null),
            'hero_quote' => $this->normalizeNullableText($row['hero_quote'] ?? null),
            'cover_image_url' => $this->normalizeNullableText($row['cover_image_url'] ?? null),
            'industry_slug' => $this->normalizeNullableText($row['industry_slug'] ?? null),
            'industry_label' => $this->normalizeNullableText($row['industry_label'] ?? null),
            'body_md' => $this->normalizeNullableText($row['body_md'] ?? null),
            'body_html' => $this->normalizeNullableText($row['body_html'] ?? null),
            'salary_json' => $this->normalizeNullableArray($row['salary_json'] ?? null),
            'outlook_json' => $this->normalizeNullableArray($row['outlook_json'] ?? null),
            'skills_json' => $this->normalizeNullableArray($row['skills_json'] ?? null),
            'work_contents_json' => $this->normalizeNullableArray($row['work_contents_json'] ?? null),
            'growth_path_json' => $this->normalizeNullableArray($row['growth_path_json'] ?? null),
            'fit_personality_codes_json' => $this->normalizeNullableMbtiArray(
                $row['fit_personality_codes_json'] ?? null,
                $file,
                $jobCode,
                'fit_personality_codes_json',
            ),
            'mbti_primary_codes_json' => $this->normalizeRequiredMbtiArray(
                $row['mbti_primary_codes_json'] ?? null,
                $file,
                $jobCode,
                'mbti_primary_codes_json',
            ),
            'mbti_secondary_codes_json' => $this->normalizeRequiredMbtiArray(
                $row['mbti_secondary_codes_json'] ?? null,
                $file,
                $jobCode,
                'mbti_secondary_codes_json',
            ),
            'riasec_profile_json' => $this->normalizeRiasecProfile(
                $row['riasec_profile_json'] ?? null,
                $file,
                $jobCode,
            ),
            'big5_targets_json' => $this->normalizeNullableArray($row['big5_targets_json'] ?? null),
            'iq_eq_notes_json' => $this->normalizeNullableArray($row['iq_eq_notes_json'] ?? null),
            'market_demand_json' => $this->normalizeNullableArray($row['market_demand_json'] ?? null),
            'status' => $this->normalizeStatus($row['status'] ?? 'published', $file, $index),
            'is_public' => $this->normalizeBool($row['is_public'] ?? true),
            'is_indexable' => $this->normalizeBool($row['is_indexable'] ?? true),
            'published_at' => $this->normalizeNullableText($row['published_at'] ?? null),
            'scheduled_at' => $this->normalizeNullableText($row['scheduled_at'] ?? null),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'sections' => $this->normalizeSections(
                is_array($row['sections'] ?? null) ? $row['sections'] : [],
                $file,
                $jobCode,
            ),
            'seo_meta' => $this->normalizeSeoMeta(
                is_array($row['seo_meta'] ?? null) ? $row['seo_meta'] : [],
            ),
        ];
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSections(array $rows, string $file, string $jobCode): array
    {
        $sections = [];
        $seen = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                throw new RuntimeException(sprintf(
                    'Baseline file %s contains a non-object section for %s at sections[%d].',
                    $file,
                    $jobCode,
                    $index,
                ));
            }

            $sectionKey = trim((string) ($row['section_key'] ?? ''));
            $renderVariant = trim((string) ($row['render_variant'] ?? ''));

            if ($sectionKey === '' || ! in_array($sectionKey, CareerJobSection::SECTION_KEYS, true)) {
                throw new RuntimeException(sprintf(
                    'Baseline file %s contains unsupported section_key=%s for %s.',
                    $file,
                    $sectionKey,
                    $jobCode,
                ));
            }

            if ($renderVariant === '' || ! in_array($renderVariant, CareerJobSection::RENDER_VARIANTS, true)) {
                throw new RuntimeException(sprintf(
                    'Baseline file %s contains invalid render_variant for %s section %s.',
                    $file,
                    $jobCode,
                    $sectionKey,
                ));
            }

            if (isset($seen[$sectionKey])) {
                throw new RuntimeException(sprintf(
                    'Baseline file %s contains duplicate section_key=%s for %s.',
                    $file,
                    $sectionKey,
                    $jobCode,
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

        if (! in_array($normalized, CareerJob::SUPPORTED_LOCALES, true)) {
            throw new RuntimeException(sprintf(
                'Baseline file %s has unsupported locale=%s.',
                $file,
                (string) $locale,
            ));
        }

        return $normalized;
    }

    /**
     * @param  array<int, string>  $selectedJobs
     * @return array<int, string>
     */
    private function normalizeSelectedJobs(array $selectedJobs): array
    {
        $normalized = [];

        foreach ($selectedJobs as $job) {
            $candidate = Str::lower(trim((string) $job));

            if ($candidate === '') {
                continue;
            }

            $normalized[] = $candidate;
        }

        return array_values(array_unique($normalized));
    }

    private function normalizeIdentifier(mixed $value, string $field, string $file, int $index): string
    {
        $normalized = Str::lower(trim((string) $value));

        if ($normalized === '') {
            throw new RuntimeException(sprintf(
                'Baseline file %s is missing %s at jobs[%d].',
                $file,
                $field,
                $index,
            ));
        }

        return $normalized;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeRequiredMbtiArray(mixed $value, string $file, string $jobCode, string $field): array
    {
        $arr = $this->normalizeMbtiArray($value, $file, $jobCode, $field);

        if ($arr === []) {
            throw new RuntimeException(sprintf(
                'Baseline file %s has empty %s for %s.',
                $file,
                $field,
                $jobCode,
            ));
        }

        return $arr;
    }

    /**
     * @return array<int, string>|null
     */
    private function normalizeNullableMbtiArray(mixed $value, string $file, string $jobCode, string $field): ?array
    {
        if ($value === null) {
            return null;
        }

        $arr = $this->normalizeMbtiArray($value, $file, $jobCode, $field);

        return $arr === [] ? null : $arr;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeMbtiArray(mixed $value, string $file, string $jobCode, string $field): array
    {
        if (is_string($value)) {
            $value = [$value];
        }

        if (! is_array($value)) {
            throw new RuntimeException(sprintf(
                'Baseline file %s has invalid %s for %s.',
                $file,
                $field,
                $jobCode,
            ));
        }

        $arr = array_values(array_filter(
            array_map(static fn (mixed $item): string => Str::upper(trim((string) $item)), $value),
            static fn (string $item): bool => $item !== '',
        ));

        foreach ($arr as $code) {
            if (! in_array($code, self::VALID_MBTI_CODES, true)) {
                throw new RuntimeException(sprintf(
                    'Baseline file %s has invalid MBTI code=%s in %s for %s.',
                    $file,
                    $code,
                    $field,
                    $jobCode,
                ));
            }
        }

        return array_values(array_unique($arr));
    }

    /**
     * @return array<string, int|float>|null
     */
    private function normalizeRiasecProfile(mixed $value, string $file, string $jobCode): ?array
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            throw new RuntimeException(sprintf(
                'Baseline file %s has invalid riasec_profile_json for %s.',
                $file,
                $jobCode,
            ));
        }

        $normalized = [];
        $validKeys = ['R', 'I', 'A', 'S', 'E', 'C'];

        foreach ($value as $key => $score) {
            $riasecKey = Str::upper(trim((string) $key));
            if (! in_array($riasecKey, $validKeys, true)) {
                throw new RuntimeException(sprintf(
                    'Baseline file %s has invalid RIASEC key=%s for %s.',
                    $file,
                    (string) $key,
                    $jobCode,
                ));
            }

            if (! is_numeric($score)) {
                throw new RuntimeException(sprintf(
                    'Baseline file %s has non-numeric RIASEC score for %s key %s.',
                    $file,
                    $jobCode,
                    $riasecKey,
                ));
            }

            $normalized[$riasecKey] = $score + 0;
        }

        return $normalized;
    }

    private function normalizeStatus(mixed $status, string $file, int $index): string
    {
        $normalized = trim((string) $status);

        if (! in_array($normalized, [CareerJob::STATUS_DRAFT, CareerJob::STATUS_PUBLISHED], true)) {
            throw new RuntimeException(sprintf(
                'Baseline file %s has invalid status at jobs[%d].',
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
        if ($value === null) {
            return null;
        }

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
            throw new RuntimeException('Expected array-compatible baseline payload.');
        }

        return $value;
    }
}
