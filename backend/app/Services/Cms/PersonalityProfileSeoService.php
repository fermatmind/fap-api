<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileSeoMeta;

final class PersonalityProfileSeoService
{
    /**
     * @return array<string, mixed>
     */
    public function buildMeta(PersonalityProfile $profile): array
    {
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
        $canonical = $this->buildCanonicalUrl($profile, (string) $profile->locale);
        $robots = $this->fallbackText($seoMeta?->robots)
            ?? ((bool) $profile->is_indexable ? 'index,follow' : 'noindex,follow');

        return [
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'og' => [
                'title' => $this->fallbackText($seoMeta?->og_title, $title),
                'description' => $this->fallbackText($seoMeta?->og_description, $description),
                'image' => $this->fallbackText($seoMeta?->og_image_url),
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
                'image' => $this->fallbackText($seoMeta?->twitter_image_url, $seoMeta?->og_image_url),
            ],
            'robots' => $robots,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildJsonLd(PersonalityProfile $profile): array
    {
        $meta = $this->buildMeta($profile);
        $jsonLd = [
            '@context' => 'https://schema.org',
            '@type' => 'AboutPage',
            'name' => $meta['title'],
            'description' => $meta['description'],
            'about' => [
                '@type' => 'DefinedTerm',
                'name' => (string) $profile->type_code,
                'inDefinedTermSet' => (string) $profile->scale_code,
            ],
            'mainEntityOfPage' => $meta['canonical'],
        ];

        $seoMeta = $this->resolveSeoMeta($profile);
        if ($seoMeta instanceof PersonalityProfileSeoMeta && is_array($seoMeta->jsonld_overrides_json)) {
            return array_replace_recursive($jsonLd, $seoMeta->jsonld_overrides_json);
        }

        return $jsonLd;
    }

    public function buildCanonicalUrl(PersonalityProfile $profile, string $locale): ?string
    {
        $baseUrl = rtrim((string) config('app.frontend_url', config('app.url', '')), '/');
        $slug = $this->publicRouteSlug($profile);

        if ($baseUrl === '' || $slug === '') {
            return null;
        }

        return $baseUrl
            .'/'.$this->mapBackendLocaleToFrontendSegment($locale)
            .'/personality/'
            .rawurlencode($slug);
    }

    public function mapBackendLocaleToFrontendSegment(string $locale): string
    {
        return trim($locale) === 'zh-CN' ? 'zh' : 'en';
    }

    private function resolveSeoMeta(PersonalityProfile $profile): ?PersonalityProfileSeoMeta
    {
        if ($profile->relationLoaded('seoMeta') && $profile->seoMeta instanceof PersonalityProfileSeoMeta) {
            return $profile->seoMeta;
        }

        return PersonalityProfileSeoMeta::query()
            ->where('profile_id', (int) $profile->id)
            ->first();
    }

    private function publicRouteSlug(PersonalityProfile $profile): string
    {
        return trim((string) $profile->slug);
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
