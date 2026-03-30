<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\MethodPage;
use App\Models\MethodPageSeoMeta;

final class MethodPageSeoService
{
    /**
     * @return array<string, mixed>
     */
    public function buildMeta(MethodPage $page, string $locale): array
    {
        $resolvedLocale = $this->normalizeLocale($locale);
        $seoMeta = $this->resolveSeoMeta($page);
        $title = $this->fallbackText($seoMeta?->seo_title, (string) $page->title) ?? (string) $page->title;
        $description = $this->fallbackText(
            $seoMeta?->seo_description,
            (string) ($page->excerpt ?? null),
            (string) ($page->subtitle ?? null)
        );
        $canonical = $this->buildCanonicalUrl($page, $resolvedLocale);
        $robots = $this->fallbackText($seoMeta?->robots)
            ?? ((bool) $page->is_indexable ? 'index,follow' : 'noindex,follow');
        $image = $this->fallbackText($seoMeta?->og_image_url, (string) ($page->cover_image_url ?? null));

        return [
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'alternates' => [
                'en' => $this->buildCanonicalUrl($page, 'en'),
                'zh-CN' => $this->buildCanonicalUrl($page, 'zh-CN'),
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
    public function buildJsonLd(MethodPage $page, string $locale): array
    {
        $meta = $this->buildMeta($page, $locale);
        $seoMeta = $this->resolveSeoMeta($page);
        $visibleTitle = trim((string) $page->title);
        $visibleDescription = trim((string) ($page->excerpt ?? ''));

        return SeoSchemaPolicyService::finalize($page, [
            'headline' => $visibleTitle,
            'description' => $visibleDescription,
            'image' => data_get($meta, 'og.image'),
        ], [
            'page_type' => ContentGovernanceService::PAGE_TYPE_METHOD,
            'title' => $visibleTitle,
            'description' => $visibleDescription,
            'canonical' => $meta['canonical'] ?? null,
            'locale' => $this->normalizeLocale($locale),
            'image' => data_get($meta, 'og.image'),
            'published_at' => $page->published_at,
            'updated_at' => $page->updated_at,
            'overrides' => $seoMeta instanceof MethodPageSeoMeta && is_array($seoMeta->jsonld_overrides_json)
                ? SeoSchemaPolicyService::sanitizeStoredOverrides($seoMeta->jsonld_overrides_json) ?? []
                : [],
        ]);
    }

    public function buildCanonicalUrl(MethodPage $page, string $locale): ?string
    {
        $baseUrl = rtrim((string) config('app.frontend_url', config('app.url', '')), '/');
        $slug = trim((string) $page->slug);

        if ($baseUrl === '' || $slug === '') {
            return null;
        }

        return $baseUrl
            .'/'.$this->mapBackendLocaleToFrontendSegment($locale)
            .'/methods/'
            .rawurlencode($slug);
    }

    public function mapBackendLocaleToFrontendSegment(string $locale): string
    {
        return $this->normalizeLocale($locale) === 'zh-CN' ? 'zh' : 'en';
    }

    private function normalizeLocale(string $locale): string
    {
        return trim($locale) === 'zh-CN' ? 'zh-CN' : 'en';
    }

    private function resolveSeoMeta(MethodPage $page): ?MethodPageSeoMeta
    {
        if ($page->relationLoaded('seoMeta') && $page->seoMeta instanceof MethodPageSeoMeta) {
            return $page->seoMeta;
        }

        return MethodPageSeoMeta::query()
            ->where('method_page_id', (int) $page->id)
            ->first();
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
