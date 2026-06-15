<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Services\Career\StructuredData\CareerArticleStructuredDataBuilder;
use App\Support\CanonicalFrontendUrl;
use App\Support\PublicMediaUrlGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class ArticleSeoService
{
    public const SUPPORTED_LOCALES = ['en', 'zh-CN'];

    public function __construct(
        private readonly CareerArticleStructuredDataBuilder $careerArticleStructuredDataBuilder,
    ) {}

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
    public function buildSeoPayload(Article $article, ?ArticleTranslationRevision $revision = null): array
    {
        $locale = $this->normalizeLocale((string) $article->locale);
        $seo = $this->resolveSeoMeta($article, $locale);
        $revision = $this->resolvePublishedRevision($article, $revision);

        $title = $revision?->seo_title ?? $revision?->title ?? $seo?->seo_title ?? $article->title;
        $descriptionSource = (string) ($revision?->excerpt ?? $revision?->content_md ?? $article->excerpt ?? $article->content_md);
        $description = $revision?->seo_description ?? $seo?->seo_description
            ?? Str::limit($this->normalizeWhitespace(strip_tags($descriptionSource)), 160);
        $canonical = $this->buildCanonicalUrl((string) $article->slug, $locale);
        $image = PublicMediaUrlGuard::sanitizeNullableUrl(
            $seo?->og_image_url ?? $this->resolveArticleImageUrl($article)
        );

        return [
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'alternates' => $this->buildAlternates($article),

            'og' => [
                'title' => $seo?->og_title ?? $title,
                'description' => $seo?->og_description ?? $description,
                'image' => $image,
                'type' => 'article',
            ],

            'twitter' => [
                'card' => 'summary_large_image',
                'title' => $title,
                'description' => $description,
                'image' => $image,
            ],

            'robots' => $seo?->robots ?? ((bool) $article->is_indexable ? 'index,follow' : 'noindex,nofollow'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function generateJsonLd(Article $article, ?ArticleTranslationRevision $revision = null, ?bool $faqSchemaEnabledOverride = null): array
    {
        $locale = $this->normalizeLocale((string) $article->locale);
        $seo = $this->resolveSeoMeta($article, $locale);
        $revision = $this->resolvePublishedRevision($article, $revision);
        $canonical = $this->buildCanonicalUrl((string) $article->slug, $locale);
        $descriptionSource = (string) ($revision?->excerpt ?? $revision?->content_md ?? $article->excerpt ?? $article->content_md);
        $structured = $this->careerArticleStructuredDataBuilder->build('article_public_detail', [
            'id' => $canonical !== null ? $canonical.'#article' : null,
            'headline' => $revision?->seo_title ?? $revision?->title ?? $seo?->seo_title ?? $article->title,
            'description' => $revision?->seo_description ?? $seo?->seo_description
                ?? Str::limit($this->normalizeWhitespace(strip_tags($descriptionSource)), 160),
            'url' => $canonical,
            'main_entity_of_page' => $canonical,
            'image' => PublicMediaUrlGuard::sanitizeNullableUrl(
                $seo?->og_image_url ?? $this->resolveArticleImageUrl($article)
            ),
            'date_published' => $revision?->published_at?->toAtomString() ?? $article->published_at?->toAtomString(),
            'date_modified' => $revision?->updated_at?->toAtomString() ?? $article->updated_at?->toAtomString(),
            'article_section' => $this->normalizeString($article->category?->name),
            'author_name' => $this->normalizeString($article->author_name),
            'keywords' => $article->relationLoaded('tags')
                ? $article->tags->pluck('name')->all()
                : null,
        ]);
        $jsonLd = is_array($structured)
            ? (array) data_get($structured, 'fragments.article', [])
            : [];

        if ($seo instanceof ArticleSeoMeta && is_array($seo->schema_json)) {
            $jsonLd = array_replace_recursive($jsonLd, $seo->schema_json);
            unset($jsonLd['editorial_package_v1']);
        }

        $faqPage = $this->buildVisibleFaqPage($article, $seo, $canonical);
        if ($faqPage !== null) {
            if ($this->shouldExposeFaqJsonLd($article, $seo, $faqSchemaEnabledOverride)) {
                $hasPart = is_array($jsonLd['hasPart'] ?? null) ? $jsonLd['hasPart'] : [];
                $hasPart[] = $faqPage;
                $jsonLd['hasPart'] = array_values($hasPart);
            } else {
                $jsonLd = $this->removeFaqPageFromJsonLd($jsonLd);
            }
        }

        return PublicMediaUrlGuard::sanitizeJsonLdImageFields(
            CanonicalFrontendUrl::normalizeNestedUrls(
                $this->normalizeJsonLdUrls($jsonLd, $canonical, (string) $article->slug)
            )
        );
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

    private function resolveArticleImageUrl(Article $article): ?string
    {
        $variants = is_array($article->cover_image_variants) ? $article->cover_image_variants : [];

        foreach (['og', 'hero', 'card', 'thumbnail'] as $key) {
            $variant = $variants[$key] ?? null;
            if (is_string($variant) && trim($variant) !== '') {
                return trim($variant);
            }
            if (is_array($variant)) {
                $url = $this->normalizeString($variant['url'] ?? null);
                if ($url !== null) {
                    return $url;
                }
            }
        }

        return $this->normalizeString($article->cover_image_url);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function buildVisibleFaqPage(Article $article, ?ArticleSeoMeta $seo, ?string $canonical): ?array
    {
        $metadata = $this->editorialPackageMetadata($article, $seo);
        if ($metadata === []) {
            return null;
        }

        $policy = $this->normalizeString($metadata['answer_surface_policy'] ?? null);
        $visibility = $this->normalizeString($metadata['answer_surface_visibility'] ?? null);
        if ($policy !== 'editor_supplied' || $visibility === null || $visibility === 'disabled') {
            return null;
        }

        $answerSurface = is_array($metadata['answer_surface_v1'] ?? null) ? $metadata['answer_surface_v1'] : [];
        $faqItems = is_array($answerSurface['faq_items'] ?? null) ? $answerSurface['faq_items'] : [];

        $mainEntity = [];
        foreach ($faqItems as $index => $item) {
            if (! is_array($item) || $this->isHiddenFaqItem($item)) {
                continue;
            }

            $question = $this->normalizeString($item['question'] ?? $item['q'] ?? null);
            $answer = $this->normalizeString($item['answer'] ?? $item['a'] ?? null);
            if ($question === null || $answer === null) {
                continue;
            }

            $mainEntity[] = [
                '@type' => 'Question',
                'name' => $question,
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $answer,
                ],
            ];

            if (count($mainEntity) >= 8) {
                break;
            }
        }

        if ($mainEntity === []) {
            return null;
        }

        return array_filter([
            '@type' => 'FAQPage',
            '@id' => $canonical !== null ? $canonical.'#faq' : null,
            'mainEntity' => $mainEntity,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function shouldExposeFaqJsonLd(Article $article, ?ArticleSeoMeta $seo, ?bool $faqSchemaEnabledOverride): bool
    {
        if ($faqSchemaEnabledOverride !== null) {
            return $faqSchemaEnabledOverride;
        }

        $metadata = $this->editorialPackageMetadata($article, $seo);
        if (array_key_exists('faq_schema_enabled', $metadata) && is_bool($metadata['faq_schema_enabled'])) {
            return (bool) $metadata['faq_schema_enabled'];
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $jsonLd
     * @return array<string,mixed>
     */
    private function removeFaqPageFromJsonLd(array $jsonLd): array
    {
        if (($jsonLd['@type'] ?? null) === 'FAQPage') {
            return [];
        }

        if (is_array($jsonLd['@type'] ?? null) && in_array('FAQPage', $jsonLd['@type'], true)) {
            $jsonLd['@type'] = array_values(array_filter(
                $jsonLd['@type'],
                static fn (mixed $type): bool => $type !== 'FAQPage'
            ));
        }

        if (is_array($jsonLd['hasPart'] ?? null)) {
            $hasPart = [];
            foreach ($jsonLd['hasPart'] as $part) {
                if (is_array($part) && ($part['@type'] ?? null) === 'FAQPage') {
                    continue;
                }
                $hasPart[] = $part;
            }

            if ($hasPart === []) {
                unset($jsonLd['hasPart']);
            } else {
                $jsonLd['hasPart'] = array_values($hasPart);
            }
        }

        return $jsonLd;
    }

    /**
     * @return array<string,mixed>
     */
    private function editorialPackageMetadata(Article $article, ?ArticleSeoMeta $seo): array
    {
        $schemaPackage = is_array($seo?->schema_json)
            && is_array($seo->schema_json['editorial_package_v1'] ?? null)
                ? $seo->schema_json['editorial_package_v1']
                : [];

        if ($schemaPackage !== []) {
            return $schemaPackage;
        }

        $variants = is_array($article->cover_image_variants) ? $article->cover_image_variants : [];

        return is_array($variants['editorial_package_v1'] ?? null)
            ? $variants['editorial_package_v1']
            : [];
    }

    /**
     * @param  array<string,mixed>  $item
     */
    private function isHiddenFaqItem(array $item): bool
    {
        if (($item['hidden'] ?? false) === true || ($item['is_visible'] ?? true) === false) {
            return true;
        }

        $visibility = strtolower((string) ($item['visibility'] ?? 'visible'));

        return in_array($visibility, ['hidden', 'disabled', 'private'], true);
    }

    /**
     * @return array<string, string>
     */
    private function buildAlternates(Article $article): array
    {
        $variants = [];
        $translationGroupId = trim((string) ($article->translation_group_id ?? ''));

        if ($translationGroupId !== '') {
            $variants = Article::query()
                ->withoutGlobalScopes()
                ->where('org_id', (int) $article->org_id)
                ->where('translation_group_id', $translationGroupId)
                ->publiclyIndexable()
                ->whereIn('locale', self::SUPPORTED_LOCALES)
                ->get(['slug', 'locale'])
                ->all();
        }

        $legacySameSlugVariants = Article::query()
            ->withoutGlobalScopes()
            ->where('org_id', (int) $article->org_id)
            ->where('slug', (string) $article->slug)
            ->publiclyIndexable()
            ->whereIn('locale', self::SUPPORTED_LOCALES)
            ->get(['slug', 'locale'])
            ->all();

        $availableLocales = [];
        foreach (array_merge($variants, $legacySameSlugVariants) as $variant) {
            if (! $variant instanceof Article) {
                continue;
            }

            $locale = $this->normalizeLocale((string) $variant->locale);
            $slug = trim((string) $variant->slug);
            if ($slug === '') {
                continue;
            }

            $availableLocales[$locale] = $slug;
        }

        $alternates = [];
        foreach (self::SUPPORTED_LOCALES as $supportedLocale) {
            if (! isset($availableLocales[$supportedLocale])) {
                continue;
            }

            $canonical = $this->buildCanonicalUrl((string) $availableLocales[$supportedLocale], $supportedLocale);
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
        return CanonicalFrontendUrl::fromConfig();
    }

    private function resolvePublishedRevision(
        Article $article,
        ?ArticleTranslationRevision $revision
    ): ?ArticleTranslationRevision {
        if ($revision instanceof ArticleTranslationRevision) {
            return $revision;
        }

        if (
            $article->relationLoaded('publishedRevision')
            && $article->publishedRevision instanceof ArticleTranslationRevision
        ) {
            return $article->publishedRevision;
        }

        return null;
    }

    private function normalizeLocale(string $locale): string
    {
        return trim($locale) === 'zh-CN' ? 'zh-CN' : 'en';
    }

    private function normalizeString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim($value);

        return $normalized !== '' ? $normalized : null;
    }
}
