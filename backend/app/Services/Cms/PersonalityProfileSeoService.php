<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileVariant;

final class PersonalityProfileSeoService
{
    public function __construct(
        private readonly PersonalityProfileService $personalityProfileService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildMeta(PersonalityProfile $profile, ?PersonalityProfileVariant $variant = null): array
    {
        $projection = $this->personalityProfileService->buildPublicProjection($profile, $variant);
        $title = $this->fallbackText(
            data_get($projection, 'seo.title'),
            data_get($projection, 'summary_card.title'),
            (string) $profile->title
        ) ?? (string) $profile->title;
        $description = $this->fallbackText(
            data_get($projection, 'seo.description'),
            data_get($projection, 'summary_card.summary'),
            data_get($projection, 'summary_card.subtitle'),
            (string) ($profile->excerpt ?? null),
            (string) ($profile->subtitle ?? null)
        );
        $canonical = $this->fallbackText(
            data_get($projection, 'seo.canonical_url'),
            $this->buildCanonicalUrl($profile, (string) $profile->locale)
        );
        $robots = $this->fallbackText(
            data_get($projection, 'seo.robots')
        ) ?? ((bool) $profile->is_indexable ? 'index,follow' : 'noindex,follow');

        return [
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'og' => [
                'title' => $this->fallbackText(data_get($projection, 'seo.og_title'), $title),
                'description' => $this->fallbackText(data_get($projection, 'seo.og_description'), $description),
                'image' => $this->fallbackText(data_get($projection, 'seo.og_image_url')),
                'type' => 'article',
            ],
            'twitter' => [
                'card' => 'summary_large_image',
                'title' => $this->fallbackText(
                    data_get($projection, 'seo.twitter_title'),
                    data_get($projection, 'seo.og_title'),
                    $title
                ),
                'description' => $this->fallbackText(
                    data_get($projection, 'seo.twitter_description'),
                    data_get($projection, 'seo.og_description'),
                    $description
                ),
                'image' => $this->fallbackText(
                    data_get($projection, 'seo.twitter_image_url'),
                    data_get($projection, 'seo.og_image_url')
                ),
            ],
            'robots' => $robots,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildJsonLd(PersonalityProfile $profile, ?PersonalityProfileVariant $variant = null): array
    {
        $projection = $this->personalityProfileService->buildPublicProjection($profile, $variant);
        $meta = $this->buildMeta($profile, $variant);
        $jsonLd = [
            '@context' => 'https://schema.org',
            '@type' => 'AboutPage',
            'name' => $meta['title'],
            'description' => $meta['description'],
            'about' => [
                '@type' => 'DefinedTerm',
                'name' => (string) data_get($projection, 'canonical_type_code', $profile->type_code),
                'inDefinedTermSet' => (string) $profile->scale_code,
            ],
            'mainEntityOfPage' => $meta['canonical'],
        ];

        $overrides = data_get($projection, 'seo.jsonld');
        if (is_array($overrides) && $overrides !== []) {
            $jsonLd = array_replace_recursive($jsonLd, $overrides);
        }

        $jsonLd['mainEntityOfPage'] = $meta['canonical'];

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
