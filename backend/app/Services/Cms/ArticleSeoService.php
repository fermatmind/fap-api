<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class ArticleSeoService
{
    public const SUPPORTED_LOCALES = ['en', 'zh-CN'];

    public function generateSeoMeta(int $articleId): ArticleSeoMeta
    {
        if ($articleId <= 0) {
            throw new InvalidArgumentException('article_id must be positive.');
        }

        return DB::transaction(function () use ($articleId): ArticleSeoMeta {
            $article = Article::query()
                ->withoutGlobalScopes()
                ->where('id', $articleId)
                ->lockForUpdate()
                ->first();

            if (! $article instanceof Article) {
                throw new RuntimeException('article not found.');
            }

            $locale = $this->normalizeLocale((string) $article->locale);

            $title = trim((string) $article->title);
            $descSource = trim((string) ($article->excerpt ?? ''));
            if ($descSource === '') {
                $descSource = $this->extractDescription((string) $article->content_md);
            }

            $seoTitle = Str::limit($title, 60, '');
            $seoDescription = Str::limit($this->normalizeWhitespace($descSource), 160, '');
            $canonicalUrl = $this->buildCanonicalUrl((string) $article->slug, $locale);
            $ogTitle = Str::limit($title, 90, '');
            $ogDescription = Str::limit($this->normalizeWhitespace($descSource), 200, '');

            return ArticleSeoMeta::query()
                ->withoutGlobalScopes()
                ->updateOrCreate(
                    [
                        'org_id' => (int) $article->org_id,
                        'article_id' => (int) $article->id,
                        'locale' => $locale,
                    ],
                    [
                        'seo_title' => $seoTitle,
                        'seo_description' => $seoDescription,
                        'canonical_url' => $canonicalUrl,
                        'og_title' => $ogTitle,
                        'og_description' => $ogDescription,
                        'is_indexable' => (bool) $article->is_indexable,
                        'robots' => (bool) $article->is_indexable ? 'index,follow' : 'noindex,nofollow',
                    ]
                );
        });
    }

    /**
     * @return array<string,mixed>
     */
    public function buildSeoPayload(Article $article): array
    {
        $locale = $this->normalizeLocale((string) $article->locale);
        $seo = $this->resolveSeoMeta($article, $locale);

        $title = $seo?->seo_title ?? $article->title;
        $descriptionSource = (string) ($article->excerpt ?? $article->content_md);
        $description = $seo?->seo_description
            ?? Str::limit($this->normalizeWhitespace(strip_tags($descriptionSource)), 160);
        $canonical = $this->buildCanonicalUrl((string) $article->slug, $locale);

        return [
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'alternates' => $this->buildAlternates($article),

            'og' => [
                'title' => $seo?->og_title ?? $title,
                'description' => $seo?->og_description ?? $description,
                'image' => $seo?->og_image_url,
                'type' => 'article',
            ],

            'twitter' => [
                'card' => 'summary_large_image',
                'title' => $title,
                'description' => $description,
                'image' => $seo?->og_image_url,
            ],

            'robots' => $seo?->robots ?? ((bool) $article->is_indexable ? 'index,follow' : 'noindex,nofollow'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function generateJsonLd(Article $article): array
    {
        $locale = $this->normalizeLocale((string) $article->locale);
        $seo = $this->resolveSeoMeta($article, $locale);
        $canonical = $this->buildCanonicalUrl((string) $article->slug, $locale);
        $visibleTitle = trim((string) $article->title);
        $visibleDescription = Str::limit(
            $this->normalizeWhitespace(strip_tags((string) ($article->excerpt ?? $article->content_md))),
            160
        );

        return SeoSchemaPolicyService::finalize($article, [
            'headline' => $visibleTitle,
            'description' => $visibleDescription,
            'image' => $seo?->og_image_url,
        ], [
            'page_type' => ContentGovernanceService::PAGE_TYPE_GUIDE,
            'title' => $visibleTitle,
            'description' => $visibleDescription,
            'canonical' => $canonical,
            'locale' => $locale,
            'image' => $seo?->og_image_url,
            'published_at' => $article->published_at,
            'updated_at' => $article->updated_at,
            'overrides' => $seo instanceof ArticleSeoMeta && is_array($seo->schema_json)
                ? $this->normalizeJsonLdUrls($seo->schema_json, $canonical, (string) $article->slug)
                : [],
        ]);
    }

    public function buildCanonicalUrl(string $slug, string $locale): ?string
    {
        $baseUrl = $this->frontendBaseUrl();
        $resolvedSlug = trim($slug);

        if ($baseUrl === '' || $resolvedSlug === '') {
            return null;
        }

        return $baseUrl
            .'/'.$this->mapBackendLocaleToFrontendSegment($locale)
            .'/articles/'
            .rawurlencode($resolvedSlug);
    }

    public function buildListUrl(string $locale): ?string
    {
        $baseUrl = $this->frontendBaseUrl();
        if ($baseUrl === '') {
            return null;
        }

        return $baseUrl.'/'.$this->mapBackendLocaleToFrontendSegment($locale).'/articles';
    }

    public function mapBackendLocaleToFrontendSegment(string $locale): string
    {
        return $this->normalizeLocale($locale) === 'zh-CN' ? 'zh' : 'en';
    }

    private function extractDescription(string $contentMd): string
    {
        $text = preg_replace('/`{1,3}[^`]*`{1,3}/u', ' ', $contentMd);
        if (! is_string($text)) {
            $text = $contentMd;
        }

        $text = preg_replace('/\[[^\]]+\]\(([^)]+)\)/u', '$1', $text);
        if (! is_string($text)) {
            $text = $contentMd;
        }

        $text = preg_replace('/[#>*_~\-]+/u', ' ', $text);
        if (! is_string($text)) {
            return $this->normalizeWhitespace($contentMd);
        }

        return $this->normalizeWhitespace($text);
    }

    private function normalizeWhitespace(string $value): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($value));

        return is_string($normalized) ? $normalized : trim($value);
    }

    private function resolveSeoMeta(Article $article, string $locale): ?ArticleSeoMeta
    {
        if (
            $article->relationLoaded('seoMeta')
            && $article->seoMeta instanceof ArticleSeoMeta
            && $this->normalizeLocale((string) $article->seoMeta->locale) === $locale
        ) {
            return $article->seoMeta;
        }

        return ArticleSeoMeta::query()
            ->withoutGlobalScopes()
            ->where('org_id', (int) $article->org_id)
            ->where('article_id', (int) $article->id)
            ->where('locale', $locale)
            ->first();
    }

    /**
     * @return array<string, string>
     */
    private function buildAlternates(Article $article): array
    {
        $variants = Article::query()
            ->withoutGlobalScopes()
            ->where('org_id', (int) $article->org_id)
            ->where('slug', (string) $article->slug)
            ->where('status', 'published')
            ->where('is_public', true)
            ->whereIn('locale', self::SUPPORTED_LOCALES)
            ->pluck('locale')
            ->all();

        $availableLocales = [];
        foreach ($variants as $variantLocale) {
            $availableLocales[$this->normalizeLocale((string) $variantLocale)] = true;
        }

        $alternates = [];
        foreach (self::SUPPORTED_LOCALES as $supportedLocale) {
            if (! isset($availableLocales[$supportedLocale])) {
                continue;
            }

            $canonical = $this->buildCanonicalUrl((string) $article->slug, $supportedLocale);
            if ($canonical === null) {
                continue;
            }

            $alternates[$supportedLocale] = $canonical;
            if ($supportedLocale === 'zh-CN') {
                $alternates['zh'] = $canonical;
            }
        }

        return $alternates;
    }

    /**
     * @param  array<string, mixed>  $jsonLd
     * @return array<string, mixed>
     */
    private function normalizeJsonLdUrls(array $jsonLd, ?string $canonical, string $slug): array
    {
        $walk = function (mixed $value) use (&$walk, $canonical, $slug): mixed {
            if (is_array($value)) {
                $normalized = [];
                foreach ($value as $key => $nested) {
                    $normalized[$key] = $walk($nested);
                }

                return $normalized;
            }

            if (! is_string($value) || $canonical === null || trim($value) === '') {
                return $value;
            }

            $legacyCandidates = [];
            foreach (array_unique(array_filter([
                rtrim((string) config('app.url', ''), '/'),
                $this->frontendBaseUrl(),
            ])) as $baseUrl) {
                $legacyCandidates[] = $baseUrl.'/articles/'.rawurlencode(trim($slug));
            }
            $legacyCandidates[] = '/articles/'.rawurlencode(trim($slug));

            foreach ($legacyCandidates as $candidate) {
                if ($candidate === '') {
                    continue;
                }

                if ($value === $candidate) {
                    return $canonical;
                }

                if (str_starts_with($value, $candidate.'#')) {
                    return $canonical.substr($value, strlen($candidate));
                }
            }

            return $value;
        };

        return $walk($jsonLd);
    }

    private function frontendBaseUrl(): string
    {
        return rtrim((string) config('app.frontend_url', config('app.url', '')), '/');
    }

    private function normalizeLocale(string $locale): string
    {
        return trim($locale) === 'zh-CN' ? 'zh-CN' : 'en';
    }
}
