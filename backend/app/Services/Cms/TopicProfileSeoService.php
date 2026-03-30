<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\TopicProfile;
use App\Models\TopicProfileSeoMeta;

final class TopicProfileSeoService
{
    /**
     * @return array<string, mixed>
     */
    public function buildMeta(TopicProfile $profile, string $locale): array
    {
        $resolvedLocale = $this->normalizeLocale($locale);
        $seoMeta = $this->resolveSeoMeta($profile);

        $title = $this->fallbackText(
            $seoMeta?->seo_title,
            (string) $profile->title
        ) ?? (string) $profile->title;
        $description = $this->fallbackText(
            $seoMeta?->seo_description,
            (string) ($profile->excerpt ?? null),
            (string) ($profile->subtitle ?? null)
        );
        $canonical = $this->buildCanonicalUrl($profile, $resolvedLocale);
        $robots = $this->fallbackText($seoMeta?->robots)
            ?? ((bool) $profile->is_indexable ? 'index,follow' : 'noindex,follow');
        $image = $this->fallbackText($seoMeta?->og_image_url, (string) ($profile->cover_image_url ?? null));

        return [
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'alternates' => [
                'en' => $this->buildCanonicalUrl($profile, 'en'),
                'zh-CN' => $this->buildCanonicalUrl($profile, 'zh-CN'),
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
    public function buildJsonLd(TopicProfile $profile, string $locale): array
    {
        $meta = $this->buildMeta($profile, $locale);
        $seoMeta = $this->resolveSeoMeta($profile);
        $visibleTitle = trim((string) $profile->title);
        $visibleDescription = trim((string) ($profile->excerpt ?? ''));

        return SeoSchemaPolicyService::finalize($profile, [
            'name' => $visibleTitle,
            'description' => $visibleDescription,
        ], [
            'page_type' => ContentGovernanceService::PAGE_TYPE_HUB,
            'title' => $visibleTitle,
            'description' => $visibleDescription,
            'canonical' => $meta['canonical'] ?? null,
            'locale' => $this->normalizeLocale($locale),
            'updated_at' => $profile->updated_at,
            'overrides' => $seoMeta instanceof TopicProfileSeoMeta && is_array($seoMeta->jsonld_overrides_json)
                ? SeoSchemaPolicyService::sanitizeStoredOverrides($seoMeta->jsonld_overrides_json) ?? []
                : [],
        ]);
    }

    public function buildCanonicalUrl(TopicProfile $profile, string $locale): ?string
    {
        $baseUrl = rtrim((string) config('app.frontend_url', config('app.url', '')), '/');
        $slug = trim((string) $profile->slug);

        if ($baseUrl === '' || $slug === '') {
            return null;
        }

        return $baseUrl
            .'/'.$this->mapBackendLocaleToFrontendSegment($locale)
            .'/topics/'
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

    private function resolveSeoMeta(TopicProfile $profile): ?TopicProfileSeoMeta
    {
        if ($profile->relationLoaded('seoMeta') && $profile->seoMeta instanceof TopicProfileSeoMeta) {
            return $profile->seoMeta;
        }

        return TopicProfileSeoMeta::query()
            ->where('profile_id', (int) $profile->id)
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
