<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\CareerJob;
use App\Models\CareerJobSeoMeta;

final class CareerJobSeoService
{
    /**
     * @return array<string, mixed>
     */
    public function buildMeta(CareerJob $job, string $locale): array
    {
        $resolvedLocale = $this->normalizeLocale($locale);
        $seoMeta = $this->resolveSeoMeta($job);

        $title = $this->fallbackText(
            $seoMeta?->seo_title,
            (string) $job->title
        ) ?? (string) $job->title;
        $description = $this->fallbackText(
            $seoMeta?->seo_description,
            (string) ($job->excerpt ?? null),
            (string) ($job->subtitle ?? null),
            (string) $job->title
        ) ?? (string) $job->title;
        $canonical = $seoMeta?->canonical_url ?? $this->buildCanonicalUrl($job, $resolvedLocale);
        $robots = $this->fallbackText($seoMeta?->robots)
            ?? ((bool) $job->is_indexable ? 'index,follow' : 'noindex,follow');
        $image = $this->fallbackText($seoMeta?->og_image_url, (string) ($job->cover_image_url ?? null));

        return [
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'alternates' => [
                'en' => $this->buildCanonicalUrl($job, 'en'),
                'zh-CN' => $this->buildCanonicalUrl($job, 'zh-CN'),
            ],
            'og' => [
                'title' => $this->fallbackText($seoMeta?->og_title, $title),
                'description' => $this->fallbackText($seoMeta?->og_description, $description),
                'image' => $image,
                'type' => 'article',
            ],
            'twitter' => [
                'card' => 'summary_large_image',
                'title' => $this->fallbackText($seoMeta?->twitter_title, $seoMeta?->og_title, $title),
                'description' => $this->fallbackText(
                    $seoMeta?->twitter_description,
                    $seoMeta?->og_description,
                    $description
                ),
                'image' => $this->fallbackText($seoMeta?->twitter_image_url, $image),
            ],
            'robots' => $robots,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildJsonLd(CareerJob $job, string $locale): array
    {
        $resolvedLocale = $this->normalizeLocale($locale);
        $meta = $this->buildMeta($job, $resolvedLocale);
        $visibleTitle = trim((string) $job->title);
        $visibleDescription = $this->fallbackText($job->excerpt, $job->subtitle, $job->title) ?? '';

        $skills = $this->flattenSkills($job->skills_json);
        $mainEntity = [
            '@type' => 'DefinedTerm',
            'name' => $visibleTitle,
            'description' => $visibleDescription,
            'identifier' => trim((string) ($job->job_code ?: $job->slug)),
        ];
        if ($skills !== []) {
            $mainEntity['skills'] = $skills;
        }

        $seoMeta = $this->resolveSeoMeta($job);

        return SeoSchemaPolicyService::finalize($job, [
            'name' => $visibleTitle,
            'description' => $visibleDescription,
            'mainEntity' => $mainEntity,
        ], [
            'page_type' => ContentGovernanceService::PAGE_TYPE_ENTITY,
            'title' => $visibleTitle,
            'description' => $visibleDescription,
            'canonical' => $meta['canonical'] ?? null,
            'locale' => $resolvedLocale,
            'updated_at' => $job->updated_at,
            'main_entity' => $mainEntity,
            'overrides' => $seoMeta instanceof CareerJobSeoMeta && is_array($seoMeta->jsonld_overrides_json)
                ? SeoSchemaPolicyService::sanitizeStoredOverrides($seoMeta->jsonld_overrides_json) ?? []
                : [],
        ]);
    }

    public function buildCanonicalUrl(CareerJob $job, string $locale): ?string
    {
        $baseUrl = rtrim((string) config('app.frontend_url', config('app.url', '')), '/');
        $slug = trim((string) $job->slug);

        if ($baseUrl === '' || $slug === '') {
            return null;
        }

        return $baseUrl
            .'/'.$this->mapBackendLocaleToFrontendSegment($locale)
            .'/career/jobs/'
            .rawurlencode($slug);
    }

    public function mapBackendLocaleToFrontendSegment(string $locale): string
    {
        return $this->normalizeLocale($locale) === 'zh-CN' ? 'zh' : 'en';
    }

    private function resolveSeoMeta(CareerJob $job): ?CareerJobSeoMeta
    {
        if ($job->relationLoaded('seoMeta') && $job->seoMeta instanceof CareerJobSeoMeta) {
            return $job->seoMeta;
        }

        return CareerJobSeoMeta::query()
            ->where('job_id', (int) $job->id)
            ->first();
    }

    private function normalizeLocale(string $locale): string
    {
        return trim($locale) === 'zh-CN' ? 'zh-CN' : 'en';
    }

    /**
     * @param  array<string, mixed>|null  $skills
     * @return array<int, string>
     */
    private function flattenSkills(?array $skills): array
    {
        if (! is_array($skills)) {
            return [];
        }

        $flattened = [];

        foreach ($skills as $value) {
            if (is_string($value)) {
                $normalized = trim($value);
                if ($normalized !== '') {
                    $flattened[] = $normalized;
                }

                continue;
            }

            if (! is_array($value)) {
                continue;
            }

            foreach ($value as $item) {
                if (! is_string($item)) {
                    continue;
                }

                $normalized = trim($item);
                if ($normalized !== '') {
                    $flattened[] = $normalized;
                }
            }
        }

        return array_values(array_unique($flattened));
    }

    private function fallbackText(?string ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if ($candidate === null) {
                continue;
            }

            $normalized = trim($candidate);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }
}
