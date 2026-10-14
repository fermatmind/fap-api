<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\TopicProfile;
use App\Models\TopicProfileSeoMeta;
use App\Support\CanonicalFrontendUrl;

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
            'alternates' => $this->publicAlternates($profile),
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

        $jsonLd = [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => $meta['title'],
            'description' => $meta['description'],
            'mainEntityOfPage' => $meta['canonical'],
        ];

        $seoMeta = $this->resolveSeoMeta($profile);
        if ($seoMeta instanceof TopicProfileSeoMeta && is_array($seoMeta->jsonld_overrides_json)) {
            return CanonicalFrontendUrl::normalizeNestedUrls(
                array_replace_recursive($jsonLd, $seoMeta->jsonld_overrides_json)
            );
        }

        return CanonicalFrontendUrl::normalizeNestedUrls($jsonLd);
    }

    public function buildCanonicalUrl(TopicProfile $profile, string $locale): ?string
    {
        $baseUrl = CanonicalFrontendUrl::fromConfig();
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

    private function publicAlternates(TopicProfile $profile): array
    {
        return TopicProfile::query()
            ->withoutGlobalScopes()
            ->where('org_id', (int) $profile->org_id)
            ->where('slug', (string) $profile->slug)
            ->where('status', TopicProfile::STATUS_PUBLISHED)
            ->where('is_public', true)
            ->whereIn('locale', ['en', 'zh-CN'])
            ->where(fn ($query) => $query->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->get()
            ->mapWithKeys(fn (TopicProfile $alternate): array => [
                (string) $alternate->locale => $this->buildCanonicalUrl($alternate, (string) $alternate->locale),
            ])
            ->all();
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
