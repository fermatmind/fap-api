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
        $canonicalVariant = $variant instanceof PersonalityProfileVariant
            ? $variant
            : $this->personalityProfileService->getDefaultPublishedVariantForCanonicalRoute($profile);
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
            $this->buildCanonicalUrl($profile, (string) $profile->locale, $canonicalVariant),
            data_get($projection, 'seo.canonical_url')
        );
        $robots = $this->fallbackText(
            data_get($projection, 'seo.robots')
        ) ?? ((bool) $profile->is_indexable ? 'index,follow' : 'noindex,follow');

        return [
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'alternates' => $this->buildAlternates($profile, $canonicalVariant),
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
        $visibleTitle = trim((string) ($profile->title ?? ''));
        $visibleDescription = trim((string) ($profile->excerpt ?? $profile->hero_summary_md ?? ''));
        $mainEntity = [
            '@type' => 'DefinedTerm',
            'name' => (string) data_get($projection, 'canonical_type_code', $profile->type_code),
            'alternateName' => $visibleTitle,
            'description' => $visibleDescription,
            'inDefinedTermSet' => (string) $profile->scale_code,
        ];

        $overrides = data_get($projection, 'seo.jsonld');

        return SeoSchemaPolicyService::finalize($profile, [
            'name' => $visibleTitle,
            'description' => $visibleDescription,
            'mainEntity' => $mainEntity,
        ], [
            'page_type' => ContentGovernanceService::PAGE_TYPE_ENTITY,
            'title' => $visibleTitle,
            'description' => $visibleDescription,
            'canonical' => $meta['canonical'] ?? null,
            'locale' => (string) $profile->locale,
            'updated_at' => $profile->updated_at,
            'main_entity' => $mainEntity,
            'overrides' => is_array($overrides) ? SeoSchemaPolicyService::sanitizeStoredOverrides($overrides) ?? [] : [],
        ]);
    }

    public function buildCanonicalUrl(
        PersonalityProfile $profile,
        string $locale,
        ?PersonalityProfileVariant $variant = null
    ): ?string {
        $baseUrl = rtrim((string) config('app.frontend_url', config('app.url', '')), '/');
        $slug = $this->publicRouteSlug($profile, $variant);

        if ($baseUrl === '' || $slug === '') {
            return null;
        }

        return $baseUrl
            .'/'.$this->mapBackendLocaleToFrontendSegment($locale)
            .'/personality/'
            .rawurlencode($slug);
    }

    /**
     * @return array{en:?string,zh-CN:?string}
     */
    public function buildAlternates(PersonalityProfile $profile, ?PersonalityProfileVariant $variant = null): array
    {
        return [
            'en' => $this->buildCanonicalUrl($profile, 'en', $variant),
            'zh-CN' => $this->buildCanonicalUrl($profile, 'zh-CN', $variant),
        ];
    }

    public function mapBackendLocaleToFrontendSegment(string $locale): string
    {
        return trim($locale) === 'zh-CN' ? 'zh' : 'en';
    }

    private function publicRouteSlug(PersonalityProfile $profile, ?PersonalityProfileVariant $variant = null): string
    {
        $baseSlug = trim((string) $profile->slug);
        if ($baseSlug === '') {
            return '';
        }

        if (! $variant instanceof PersonalityProfileVariant) {
            return $baseSlug;
        }

        $variantCode = strtoupper(trim((string) $variant->variant_code));
        if (! in_array($variantCode, ['A', 'T'], true)) {
            return $baseSlug;
        }

        return strtolower($baseSlug.'-'.$variantCode);
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
