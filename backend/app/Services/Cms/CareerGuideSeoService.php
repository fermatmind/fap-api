<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\CareerGuide;
use App\Models\CareerGuideSeoMeta;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class CareerGuideSeoService
{
    public const SUPPORTED_LOCALES = [
        'en',
        'zh-CN',
    ];

    public function generateSeoMeta(int $guideId): CareerGuideSeoMeta
    {
        if ($guideId <= 0) {
            throw new InvalidArgumentException('career_guide_id must be positive.');
        }

        return DB::transaction(function () use ($guideId): CareerGuideSeoMeta {
            $guide = CareerGuide::query()
                ->withoutGlobalScopes()
                ->where('id', $guideId)
                ->lockForUpdate()
                ->first();

            if (! $guide instanceof CareerGuide) {
                throw new RuntimeException('career guide not found.');
            }

            $canonical = $this->buildCanonicalUrl($guide);
            $title = trim((string) $guide->title);
            $description = $this->resolveDescription($guide, null);
            $robots = $this->resolveRobots($guide, null);

            return CareerGuideSeoMeta::query()->updateOrCreate(
                [
                    'career_guide_id' => (int) $guide->id,
                ],
                [
                    'seo_title' => Str::limit($title, 60, ''),
                    'seo_description' => Str::limit($description, 160, ''),
                    'canonical_url' => $canonical,
                    'og_title' => Str::limit($title, 90, ''),
                    'og_description' => Str::limit($description, 200, ''),
                    'twitter_title' => Str::limit($title, 70, ''),
                    'twitter_description' => Str::limit($description, 200, ''),
                    'robots' => $robots,
                ]
            );
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function buildSeoPayload(CareerGuide $guide): array
    {
        $seoMeta = $this->resolveSeoMeta($guide);
        $title = $this->resolveTitle($guide, $seoMeta);
        $description = $this->resolveDescription($guide, $seoMeta);
        $canonical = $this->buildCanonicalUrl($guide);
        $image = $this->fallbackText($seoMeta?->og_image_url, $seoMeta?->twitter_image_url);

        return [
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'alternates' => $this->buildAlternates($guide),
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
            'robots' => $this->resolveRobots($guide, $seoMeta),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildJsonLd(CareerGuide $guide): array
    {
        $seoMeta = $this->resolveSeoMeta($guide);
        $canonical = $this->buildCanonicalUrl($guide);
        $payload = $this->buildSeoPayload($guide);
        $visibleTitle = trim((string) $guide->title);
        $visibleDescription = $this->fallbackText($guide->excerpt, $guide->title) ?? '';

        return SeoSchemaPolicyService::finalize($guide, [
            'headline' => $visibleTitle,
            'description' => $visibleDescription,
            'image' => data_get($payload, 'og.image'),
        ], [
            'page_type' => ContentGovernanceService::PAGE_TYPE_GUIDE,
            'title' => $visibleTitle,
            'description' => $visibleDescription,
            'canonical' => $canonical,
            'locale' => $this->normalizeLocale((string) $guide->locale),
            'image' => data_get($payload, 'og.image'),
            'published_at' => $guide->published_at,
            'updated_at' => $guide->updated_at,
            'overrides' => $seoMeta instanceof CareerGuideSeoMeta && is_array($seoMeta->jsonld_overrides_json)
                ? $this->normalizeJsonLdUrls($seoMeta->jsonld_overrides_json, $canonical, $seoMeta?->canonical_url)
                : [],
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function detailSeoMetaPayload(CareerGuide $guide): ?array
    {
        $payload = $this->buildSeoPayload($guide);
        $seoMeta = $this->resolveSeoMeta($guide);

        return [
            'seo_title' => $payload['title'],
            'seo_description' => $payload['description'],
            'canonical_url' => $payload['canonical'],
            'og_title' => data_get($payload, 'og.title'),
            'og_description' => data_get($payload, 'og.description'),
            'og_image_url' => data_get($payload, 'og.image'),
            'twitter_title' => data_get($payload, 'twitter.title'),
            'twitter_description' => data_get($payload, 'twitter.description'),
            'twitter_image_url' => data_get($payload, 'twitter.image'),
            'robots' => $payload['robots'],
            'jsonld_overrides_json' => $seoMeta?->jsonld_overrides_json,
        ];
    }

    public function buildCanonicalUrl(CareerGuide $guide, ?string $locale = null, ?string $slug = null): ?string
    {
        $baseUrl = rtrim((string) config('app.frontend_url', config('app.url', '')), '/');
        $resolvedSlug = strtolower(trim((string) ($slug ?? $guide->slug)));
        $resolvedLocale = $this->normalizeLocale((string) ($locale ?? $guide->locale));

        if ($baseUrl === '' || $resolvedSlug === '') {
            return null;
        }

        return $baseUrl
            .'/'.$this->mapBackendLocaleToFrontendSegment($resolvedLocale)
            .'/career/guides/'
            .rawurlencode($resolvedSlug);
    }

    public function mapBackendLocaleToFrontendSegment(string $locale): string
    {
        return $this->normalizeLocale($locale) === 'zh-CN' ? 'zh' : 'en';
    }

    /**
     * @return array<string, string>
     */
    private function buildAlternates(CareerGuide $guide): array
    {
        $variants = CareerGuide::query()
            ->withoutGlobalScopes()
            ->where('org_id', (int) $guide->org_id)
            ->where('guide_code', (string) $guide->guide_code)
            ->where('status', CareerGuide::STATUS_PUBLISHED)
            ->where('is_public', true)
            ->whereIn('locale', self::SUPPORTED_LOCALES)
            ->where(static function ($query): void {
                $query->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->get(['slug', 'locale']);

        $alternates = [];
        foreach ($variants as $variant) {
            if (! $variant instanceof CareerGuide) {
                continue;
            }

            $locale = $this->normalizeLocale((string) $variant->locale);
            $canonical = $this->buildCanonicalUrl($guide, $locale, (string) $variant->slug);
            if ($canonical === null) {
                continue;
            }

            $alternates[$locale] = $canonical;
        }

        ksort($alternates);

        return $alternates;
    }

    private function resolveSeoMeta(CareerGuide $guide): ?CareerGuideSeoMeta
    {
        if ($guide->relationLoaded('seoMeta') && $guide->seoMeta instanceof CareerGuideSeoMeta) {
            return $guide->seoMeta;
        }

        return CareerGuideSeoMeta::query()
            ->where('career_guide_id', (int) $guide->id)
            ->first();
    }

    private function resolveTitle(CareerGuide $guide, ?CareerGuideSeoMeta $seoMeta): string
    {
        return $this->fallbackText($seoMeta?->seo_title, (string) $guide->title) ?? (string) $guide->title;
    }

    private function resolveDescription(CareerGuide $guide, ?CareerGuideSeoMeta $seoMeta): string
    {
        return $this->fallbackText(
            $seoMeta?->seo_description,
            (string) ($guide->excerpt ?? null),
            $this->extractDescription((string) ($guide->body_md ?? '')),
            (string) $guide->title
        ) ?? (string) $guide->title;
    }

    private function resolveRobots(CareerGuide $guide, ?CareerGuideSeoMeta $seoMeta): string
    {
        return $this->fallbackText($seoMeta?->robots)
            ?? ((bool) $guide->is_indexable ? 'index,follow' : 'noindex,follow');
    }

    private function normalizeLocale(string $locale): string
    {
        return trim($locale) === 'zh-CN' ? 'zh-CN' : 'en';
    }

    private function extractDescription(string $bodyMarkdown): string
    {
        $text = preg_replace('/`{1,3}[^`]*`{1,3}/u', ' ', $bodyMarkdown);
        if (! is_string($text)) {
            $text = $bodyMarkdown;
        }

        $text = preg_replace('/\[[^\]]+\]\(([^)]+)\)/u', '$1', $text);
        if (! is_string($text)) {
            $text = $bodyMarkdown;
        }

        $text = preg_replace('/[#>*_~\-]+/u', ' ', $text);
        if (! is_string($text)) {
            return $this->normalizeWhitespace($bodyMarkdown);
        }

        return $this->normalizeWhitespace($text);
    }

    private function normalizeWhitespace(string $value): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($value));

        return is_string($normalized) ? $normalized : trim($value);
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

    /**
     * @param  array<string, mixed>  $jsonLd
     * @return array<string, mixed>
     */
    private function normalizeJsonLdUrls(array $jsonLd, ?string $canonical, ?string $sourceCanonical): array
    {
        $walk = function (mixed $value) use (&$walk, $canonical, $sourceCanonical): mixed {
            if (is_array($value)) {
                $normalized = [];
                foreach ($value as $key => $item) {
                    $normalized[$key] = $walk($item);
                }

                return $normalized;
            }

            if (! is_string($value) || $canonical === null) {
                return $value;
            }

            $legacyCanonical = trim((string) $sourceCanonical);
            if ($legacyCanonical === '') {
                return $value;
            }

            if ($value === $legacyCanonical) {
                return $canonical;
            }

            if (str_starts_with($value, $legacyCanonical.'#')) {
                return $canonical.substr($value, strlen($legacyCanonical));
            }

            return $value;
        };

        /** @var array<string, mixed> $normalized */
        $normalized = $walk($jsonLd);

        return $normalized;
    }
}
