<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Services\BigFive\AuthorityV2\StructuredData\BigFiveStructuredDataProjector;
use App\Services\Career\StructuredData\CareerArticleStructuredDataBuilder;
use App\Support\CanonicalFrontendUrl;
use App\Support\PublicMediaUrlGuard;
use App\Support\PublicSeoTitleNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class ArticleSeoService
{
    public const SUPPORTED_LOCALES = ['en', 'zh-CN'];

    public function __construct(
        private readonly CareerArticleStructuredDataBuilder $careerArticleStructuredDataBuilder,
        private readonly BigFiveStructuredDataProjector $bigFiveStructuredDataProjector,
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

            $title = $this->publicTitle($article, (string) $article->title);
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

        $title = $this->publicTitle(
            $article,
            (string) ($revision?->seo_title ?? $revision?->title ?? $seo?->seo_title ?? $article->title)
        );
        $descriptionSource = (string) ($revision?->excerpt ?? $revision?->content_md ?? $article->excerpt ?? $article->content_md);
        $description = $revision?->seo_description ?? $seo?->seo_description
            ?? Str::limit($this->normalizeWhitespace(strip_tags($descriptionSource)), 160);
        $canonical = $this->buildCanonicalUrl((string) $article->slug, $locale);
        $alternates = $this->buildAlternates($article);
        $image = PublicMediaUrlGuard::sanitizeNullableUrl(
            $seo?->og_image_url ?? $this->resolveArticleImageUrl($article)
        );
        $structuredData = $this->buildStructuredData($article, $revision, $seo, $canonical, $locale);
        $bigFiveStructuredData = $this->buildBigFiveStructuredDataProjection(
            $article,
            $revision,
            $seo,
            $canonical,
            $locale,
        );

        return [
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'alternates' => $alternates,
            'article_authority_v1' => $this->buildArticleAuthorityProjection(
                $article,
                $revision,
                $seo,
                $alternates,
                $structuredData,
                $locale,
                $bigFiveStructuredData,
            ),
            ...($bigFiveStructuredData !== null
                ? ['big_five_structured_data_v1' => $this->publicBigFiveStructuredDataProjection($bigFiveStructuredData)]
                : []),

            'og' => [
                'title' => $this->publicTitle($article, (string) ($seo?->og_title ?? $title)),
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
        return $this->generateJsonLdWithGateOverrides(
            $article,
            $revision,
            $faqSchemaEnabledOverride,
            [],
        );
    }

    /**
     * Build the authority-validated candidate used by the controlled SEO gate rollout preflight.
     * Runtime callers must use generateJsonLd(), which always honors the persisted gates.
     *
     * @param  array<string,bool>  $plannedGates
     * @return array<string,mixed>
     */
    public function generateJsonLdForGateRollout(
        Article $article,
        ?ArticleTranslationRevision $revision,
        array $plannedGates,
    ): array {
        $gateOverrides = [];
        foreach (['article_schema_enabled', 'breadcrumb_schema_enabled', 'faq_schema_enabled'] as $gate) {
            if (array_key_exists($gate, $plannedGates) && is_bool($plannedGates[$gate])) {
                $gateOverrides[$gate] = $plannedGates[$gate];
            }
        }

        return $this->generateJsonLdWithGateOverrides(
            $article,
            $revision,
            $gateOverrides['faq_schema_enabled'] ?? null,
            $gateOverrides,
        );
    }

    /**
     * @param  array<string,bool>  $bigFiveGateOverrides
     * @return array<string,mixed>
     */
    private function generateJsonLdWithGateOverrides(
        Article $article,
        ?ArticleTranslationRevision $revision,
        ?bool $faqSchemaEnabledOverride,
        array $bigFiveGateOverrides,
    ): array {
        $locale = $this->normalizeLocale((string) $article->locale);
        $seo = $this->resolveSeoMeta($article, $locale);
        $revision = $this->resolvePublishedRevision($article, $revision);
        $canonical = $this->buildCanonicalUrl((string) $article->slug, $locale);
        $bigFiveStructuredData = $this->buildBigFiveStructuredDataProjection(
            $article,
            $revision,
            $seo,
            $canonical,
            $locale,
            $bigFiveGateOverrides,
        );
        if ($bigFiveStructuredData !== null) {
            $articleFragment = data_get($bigFiveStructuredData, 'fragments.article');
            $breadcrumbFragment = data_get($bigFiveStructuredData, 'fragments.breadcrumb_list');
            $faqFragment = data_get($bigFiveStructuredData, 'fragments.faq_page');
            $publicFragments = [];
            if (is_array($articleFragment)) {
                $publicFragments[] = $articleFragment;
            }
            if (is_array($breadcrumbFragment)) {
                $publicFragments[] = $breadcrumbFragment;
            }
            if (! is_array($articleFragment) && is_array($faqFragment)) {
                $publicFragments[] = $faqFragment;
            }
            $jsonLd = match (count($publicFragments)) {
                0 => [],
                1 => $publicFragments[0],
                default => ['@context' => 'https://schema.org', '@graph' => $publicFragments],
            };

            return PublicMediaUrlGuard::sanitizeJsonLdImageFields(
                CanonicalFrontendUrl::normalizeNestedUrls(
                    $jsonLd
                )
            );
        }

        $structured = $this->buildStructuredData($article, $revision, $seo, $canonical, $locale);
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

    private function publicTitle(Article $article, string $title): string
    {
        $slug = strtolower(trim((string) $article->slug));
        if (! str_starts_with($slug, 'big-five-')) {
            return trim($title);
        }

        return PublicSeoTitleNormalizer::withoutTrailingBrand($title);
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
    private function buildStructuredData(
        Article $article,
        ?ArticleTranslationRevision $revision,
        ?ArticleSeoMeta $seo,
        ?string $canonical,
        string $locale,
    ): ?array {
        $descriptionSource = (string) ($revision?->excerpt ?? $revision?->content_md ?? $article->excerpt ?? $article->content_md);
        $structured = $this->careerArticleStructuredDataBuilder->build('article_public_detail', [
            'id' => $canonical !== null ? $canonical.'#article' : null,
            'headline' => $this->publicTitle(
                $article,
                (string) ($revision?->seo_title ?? $revision?->title ?? $seo?->seo_title ?? $article->title)
            ),
            'description' => $revision?->seo_description ?? $seo?->seo_description
                ?? Str::limit($this->normalizeWhitespace(strip_tags($descriptionSource)), 160),
            'url' => $canonical,
            'main_entity_of_page' => $canonical,
            'breadcrumb_root_url' => $this->buildListUrl($locale),
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

        if (! is_array($structured)) {
            return null;
        }

        return PublicMediaUrlGuard::sanitizeJsonLdImageFields(
            CanonicalFrontendUrl::normalizeNestedUrls(
                $this->normalizeJsonLdUrls($structured, $canonical, (string) $article->slug)
            )
        );
    }

    /**
     * @param  array<string,string>  $alternates
     * @param  array<string,mixed>|null  $structuredData
     * @return array<string,mixed>
     */
    private function buildArticleAuthorityProjection(
        Article $article,
        ?ArticleTranslationRevision $revision,
        ?ArticleSeoMeta $seo,
        array $alternates,
        ?array $structuredData,
        string $locale,
        ?array $bigFiveStructuredData = null,
    ): array {
        $publishedRevisionBacked = $revision instanceof ArticleTranslationRevision
            && $revision->revision_status === ArticleTranslationRevision::STATUS_PUBLISHED;
        $publiclyIndexable = $publishedRevisionBacked
            && (string) $article->status === 'published'
            && (bool) $article->is_public
            && (bool) $article->is_indexable;

        $eligibleAlternates = [];
        if ($publiclyIndexable) {
            foreach (self::SUPPORTED_LOCALES as $supportedLocale) {
                $url = $alternates[$supportedLocale] ?? null;
                if (is_string($url) && trim($url) !== '') {
                    $eligibleAlternates[$supportedLocale] = trim($url);
                }
            }
        }

        $metadata = $this->editorialPackageMetadata($article, $seo);
        $articleFragment = $bigFiveStructuredData !== null
            ? data_get($bigFiveStructuredData, 'fragments.article')
            : data_get($structuredData, 'fragments.article');
        $breadcrumbFragment = $bigFiveStructuredData !== null
            ? data_get($bigFiveStructuredData, 'fragments.breadcrumb_list')
            : data_get($structuredData, 'fragments.breadcrumb_list');
        $articleEnabled = $bigFiveStructuredData !== null
            ? (bool) data_get($bigFiveStructuredData, 'eligibility.article.enabled', false)
            : $publiclyIndexable
                && ($metadata['article_schema_enabled'] ?? null) === true
                && is_array($articleFragment);
        $breadcrumbEnabled = $bigFiveStructuredData !== null
            ? (bool) data_get($bigFiveStructuredData, 'eligibility.breadcrumb_list.enabled', false)
            : $publiclyIndexable
                && ($metadata['breadcrumb_schema_enabled'] ?? null) === true
                && is_array($breadcrumbFragment);

        return [
            'contract_version' => 'article.seo.authority.v1',
            'published_revision_backed' => $publishedRevisionBacked,
            'alternate_eligibility' => [
                'basis' => 'published_indexable_locale_siblings',
                'current_locale' => $locale,
                'eligible_locales' => array_keys($eligibleAlternates),
                'alternates' => $eligibleAlternates,
            ],
            'structured_data_eligibility' => [
                'basis' => 'cms_explicit_schema_gates',
                'article' => ['enabled' => $articleEnabled],
                'breadcrumb_list' => ['enabled' => $breadcrumbEnabled],
            ],
            'structured_data_fragments' => [
                'article' => $articleEnabled ? $articleFragment : null,
                'breadcrumb_list' => $breadcrumbEnabled ? $breadcrumbFragment : null,
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function buildBigFiveStructuredDataProjection(
        Article $article,
        ?ArticleTranslationRevision $revision,
        ?ArticleSeoMeta $seo,
        ?string $canonical,
        string $locale,
        array $gateOverrides = [],
    ): ?array {
        if (! str_starts_with(strtolower(trim((string) $article->slug)), 'big-five-')) {
            return null;
        }

        $descriptionSource = (string) ($revision?->excerpt ?? $revision?->content_md ?? $article->excerpt ?? $article->content_md);

        $editorialPackage = $this->editorialPackageMetadata($article, $seo);
        foreach (['article_schema_enabled', 'breadcrumb_schema_enabled', 'faq_schema_enabled'] as $gate) {
            if (array_key_exists($gate, $gateOverrides) && is_bool($gateOverrides[$gate])) {
                $editorialPackage[$gate] = $gateOverrides[$gate];
            }
        }

        return $this->bigFiveStructuredDataProjector->forArticle($article, $revision, [
            'canonical' => $canonical,
            'headline' => $this->publicTitle(
                $article,
                (string) ($revision?->seo_title ?? $revision?->title ?? $seo?->seo_title ?? $article->title)
            ),
            'description' => $revision?->seo_description ?? $seo?->seo_description
                ?? Str::limit($this->normalizeWhitespace(strip_tags($descriptionSource)), 160),
            'breadcrumb_root_url' => $this->buildListUrl($locale),
            'image' => PublicMediaUrlGuard::sanitizeNullableUrl(
                $seo?->og_image_url ?? $this->resolveArticleImageUrl($article)
            ),
            'article_section' => $this->normalizeString($article->category?->name),
            'keywords' => $article->relationLoaded('tags')
                ? $article->tags->pluck('name')->all()
                : null,
            'seo_indexable' => is_bool($seo?->is_indexable) ? $seo->is_indexable : null,
            'robots' => $seo?->robots,
            'editorial_package' => $editorialPackage,
        ]);
    }

    /**
     * Keep public SEO metadata limited to visible labels, dates, canonical URLs, and schema fragments.
     * Internal actor identities, source ids, and authority references remain backend-only.
     *
     * @param  array<string,mixed>  $projection
     * @return array<string,mixed>
     */
    private function publicBigFiveStructuredDataProjection(array $projection): array
    {
        $sourceLabels = collect((array) data_get($projection, 'visible_alignment.sources', []))
            ->pluck('label')
            ->filter(static fn (mixed $label): bool => is_string($label) && trim($label) !== '')
            ->map(static fn (string $label): array => ['label' => trim($label)])
            ->values()
            ->all();
        $authorLabel = $this->normalizeString(data_get($projection, 'visible_alignment.author.label'));
        $reviewerLabel = $this->normalizeString(data_get($projection, 'visible_alignment.reviewer_gate.label'));

        return [
            'contract_version' => $projection['contract_version'] ?? null,
            'authority_surface' => $projection['authority_surface'] ?? null,
            'current_public_authority_eligible' => (bool) ($projection['current_public_authority_eligible'] ?? false),
            'visible_alignment' => [
                'canonical' => data_get($projection, 'visible_alignment.canonical'),
                'author' => $authorLabel !== null ? ['label' => $authorLabel] : null,
                'reviewer_gate' => $reviewerLabel !== null ? ['label' => $reviewerLabel] : null,
                'sources' => $sourceLabels,
                'dates' => (array) data_get($projection, 'visible_alignment.dates', []),
            ],
            'eligibility' => (array) ($projection['eligibility'] ?? []),
            'fragments' => (array) ($projection['fragments'] ?? []),
            'preservation' => (array) ($projection['preservation'] ?? []),
        ];
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
        $faqLimit = 8;

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

            if (count($mainEntity) >= $faqLimit) {
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
