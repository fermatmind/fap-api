<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Cms;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleTag;
use App\Models\ArticleTestEdge;
use App\Models\ArticleTranslationRevision;
use App\Services\Cms\Article15ExactPackageRevisionBoundAdapter;
use App\Services\Cms\ArticleBlogService;
use App\Services\Cms\ArticleBodyHeadingGuard;
use App\Services\Cms\ArticlePublicListQuery;
use App\Services\Cms\ArticlePublicListReadCache;
use App\Services\Cms\ArticlePublishService;
use App\Services\Cms\ArticleSeoService;
use App\Services\Cms\ArticleService;
use App\Services\Cms\IqPublicArticleFaqProjection;
use App\Services\PublicSurface\AnswerSurfaceContractService;
use App\Services\PublicSurface\LandingSurfaceContractService;
use App\Services\PublicSurface\SeoSurfaceContractService;
use App\Services\ReviewGovernance\PublicReviewContract;
use App\Support\CanonicalFrontendUrl;
use App\Support\CanonicalTranslationPayloadHash;
use App\Support\PublicMediaUrlGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/** @review-surface article */
class ArticleController extends Controller
{
    public function __construct(
        private readonly ArticleService $articleService,
        private readonly ArticleBlogService $articleBlogService,
        private readonly ArticlePublishService $articlePublishService,
        private readonly ArticlePublicListQuery $articlePublicListQuery,
        private readonly ArticlePublicListReadCache $articlePublicListReadCache,
        private readonly ArticleBodyHeadingGuard $articleBodyHeadingGuard,
        private readonly ArticleSeoService $articleSeoService,
        private readonly AnswerSurfaceContractService $answerSurfaceContractService,
        private readonly LandingSurfaceContractService $landingSurfaceContractService,
        private readonly SeoSurfaceContractService $seoSurfaceContractService,
        private readonly PublicReviewContract $publicReviewContract,
    ) {}

    /**
     * GET /api/v0.5/articles
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $this->validateListQuery($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        if ($validated['category'] !== null) {
            $category = ArticleCategory::withoutGlobalScopes()->where('org_id', $validated['org_id'])
                ->where('slug', $validated['category'])->first();
            $validated['category_cache_token'] = $category === null ? 'missing' : hash('sha256', json_encode([
                $category->id, $category->is_active, $category->name, $category->description,
            ], JSON_THROW_ON_ERROR));
        }

        $resolved = $this->articlePublicListReadCache->resolve(
            $validated,
            fn (): array => $this->buildArticleListResponse($validated),
        );

        $payload = $resolved['payload'];
        if ($validated['include_blog']) {
            $payload['blog_v1'] = $this->articleBlogService->read(
                $validated['org_id'], $validated['locale'],
                fn (Article $article): array => $this->publicArticleListPayload($article),
            );
        }

        return response()
            ->json($payload)
            ->header('X-FM-Article-List-Cache', $resolved['state']);
    }

    /**
     * GET /api/v0.5/articles/{slug}
     */
    public function feed(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'in:en,zh-CN'],
            'org_id' => ['nullable', 'integer', 'in:0'],
        ]);
        if (array_diff(array_keys($request->query()), ['locale', 'org_id']) !== []) {
            return response()->json(['ok' => false, 'error' => 'unsupported_feed_query'], 422);
        }
        $locale = $validated['locale'];
        $items = [];
        foreach ($this->articlePublicListQuery->feed(0, $locale) as $article) {
            $revision = $article->publishedRevision;
            $publishedAt = $article->published_at;
            $changedAt = $article->getAttribute('feed_material_changed_at');
            $updatedAt = $changedAt !== null ? \Illuminate\Support\Carbon::parse($changedAt)
                : ($revision->published_at ?? $publishedAt);
            if ($updatedAt->isFuture() || $updatedAt->lessThan($publishedAt)
                || trim((string) $revision->title) === ''
                || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,127}\z/', (string) $article->slug) !== 1) {
                continue;
            }
            $items[] = [
                'id' => (int) $article->id,
                'locale' => $locale,
                'slug' => (string) $article->slug,
                'title' => (string) $revision->title,
                'excerpt' => $revision->excerpt,
                'published_revision_id' => (int) $revision->id,
                'published_at' => $publishedAt->toIso8601String(),
                'material_updated_at' => $updatedAt->toIso8601String(),
                'canonical' => CanonicalFrontendUrl::normalizeAbsoluteUrl(
                    CanonicalFrontendUrl::APEX_URL.'/'.$this->frontendLocaleSegment($locale).'/articles/'.$article->slug
                ),
            ];
        }

        return response()->json(['ok' => true, 'schema_version' => 'public-article-feed.v1',
            'locale' => $locale, 'items' => $items])->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $locale = trim((string) $request->query('locale', 'en'));
        if ($locale === '') {
            $locale = 'en';
        }

        $orgId = $this->resolveOrgId($request);
        $normalizedSlug = trim($slug);
        if ($normalizedSlug === '') {
            return response()->json([
                'ok' => false,
                'error_code' => 'SLUG_REQUIRED',
                'message' => 'slug is required.',
            ], 400);
        }

        $article = $this->findPublicArticle($normalizedSlug, $locale, $orgId);

        if (! $article instanceof Article) {
            return response()->json([
                'ok' => false,
                'error_code' => 'NOT_FOUND',
                'message' => 'article not found.',
            ], 404);
        }

        $revision = $this->publicRevision($article);
        if (! $revision instanceof ArticleTranslationRevision) {
            return response()->json([
                'ok' => false,
                'error_code' => 'NOT_FOUND',
                'message' => 'article not found.',
            ], 404);
        }

        return response()->json($this->publicDetailProjection($article));
    }

    /** The reader and derived Truth consume the same public projection. */
    public function publicDetailProjection(Article $article): array
    {
        $article->loadMissing($this->articleRelations());
        $revision = $this->publicRevision($article);
        if (! $revision instanceof ArticleTranslationRevision || ! $revision->isPubliclyReadableForArticle($article)) {
            throw new RuntimeException('published revision not found.');
        }
        $meta = PublicMediaUrlGuard::sanitizeSeoMeta(
            $this->articleSeoService->buildSeoPayload($article, $revision)
        );
        $jsonLd = $this->articleSeoService->generateJsonLd($article, $revision);
        $payload = $this->publicArticlePayload($article, $revision);

        return [
            'ok' => true,
            'article' => $payload,
            'seo_surface_v1' => $this->buildSeoSurface($meta, $jsonLd, 'article_public_detail'),
            'landing_surface_v1' => $this->buildDetailLandingSurface($article, $payload, (string) $article->locale),
            'answer_surface_v1' => $this->buildDetailAnswerSurface($article, $payload, (string) $article->locale),
        ];
    }

    public function publicAuthorityRevision(Article $article): string
    {
        // Publication/lastmod clocks and review workflow are not content revisions.
        $normalize = function (array $payload) use (&$normalize): array {
            foreach (['created_at', 'updated_at', 'published_at', 'scheduled_at', 'last_reviewed_at',
                'review_state', 'reviewer', 'datePublished', 'dateModified', 'metadata_fingerprint'] as $key) {
                unset($payload[$key]);
            }
            foreach ($payload as $key => $value) {
                if (is_array($value)) {
                    $payload[$key] = $normalize($value);
                }
            }

            return $payload;
        };

        return CanonicalTranslationPayloadHash::hash([
            'schema_version' => 'article.public_authority_revision.v1',
            'projection' => $normalize(json_decode(
                json_encode($this->publicDetailProjection($article), JSON_THROW_ON_ERROR),
                true, 512, JSON_THROW_ON_ERROR,
            )),
        ]);
    }

    /**
     * GET /api/v0.5/articles/{slug}/seo
     */
    public function seo(Request $request, string $slug): JsonResponse
    {
        $locale = trim((string) $request->query('locale', 'en'));
        if ($locale === '') {
            $locale = 'en';
        }

        $orgId = $this->resolveOrgId($request);
        $normalizedSlug = trim($slug);
        if ($normalizedSlug === '') {
            return response()->json(['error' => 'not found'], 404);
        }

        $article = $this->findPublicArticle($normalizedSlug, $locale, $orgId);

        if (! $article instanceof Article) {
            return response()->json(['error' => 'not found'], 404);
        }

        $revision = $this->publicRevision($article);
        if (! $revision instanceof ArticleTranslationRevision) {
            return response()->json(['error' => 'not found'], 404);
        }

        $meta = PublicMediaUrlGuard::sanitizeSeoMeta(
            $this->articleSeoService->buildSeoPayload($article, $revision)
        );
        $jsonLd = $this->articleSeoService->generateJsonLd($article, $revision);

        return response()->json([
            'meta' => $meta,
            'jsonld' => $jsonLd,
            'seo_surface_v1' => $this->buildSeoSurface($meta, $jsonLd, 'article_public_detail'),
        ]);
    }

    /**
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>
     */
    private function buildSeoSurface(array $meta, array $jsonLd, string $surfaceType): array
    {
        return $this->seoSurfaceContractService->build([
            'metadata_scope' => 'public_indexable_detail',
            'surface_type' => $surfaceType,
            'canonical_url' => $meta['canonical'] ?? null,
            'robots_policy' => $meta['robots'] ?? null,
            'title' => $meta['title'] ?? null,
            'description' => $meta['description'] ?? null,
            'og_payload' => is_array($meta['og'] ?? null) ? $meta['og'] : [],
            'twitter_payload' => is_array($meta['twitter'] ?? null) ? $meta['twitter'] : [],
            'alternates' => is_array($meta['alternates'] ?? null) ? $meta['alternates'] : [],
            'structured_data' => $jsonLd,
        ]);
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @return array<string,mixed>
     */
    private function buildIndexLandingSurface(array $items, string $locale): array
    {
        $segment = $this->frontendLocaleSegment($locale);
        $discoverabilityItems = array_values(array_filter(array_map(
            static function (array $item): ?array {
                $slug = trim((string) ($item['slug'] ?? ''));
                $title = trim((string) ($item['title'] ?? ''));
                if ($slug === '' || $title === '') {
                    return null;
                }

                $locale = trim((string) ($item['locale'] ?? 'en'));
                $segment = $locale === 'zh-CN' ? 'zh' : 'en';

                return [
                    'key' => $slug,
                    'title' => $title,
                    'summary' => trim((string) ($item['excerpt'] ?? '')),
                    'href' => '/'.$segment.'/articles/'.$slug,
                    'kind' => 'article_detail',
                    'badge_label' => is_array($item['category'] ?? null)
                        ? trim((string) (($item['category']['name'] ?? '')))
                        : null,
                ];
            },
            array_slice($items, 0, 6)
        )));
        $firstHref = $discoverabilityItems[0]['href'] ?? '/'.$segment.'/articles';

        return $this->landingSurfaceContractService->build([
            'landing_scope' => 'public_indexable_hub',
            'entry_surface' => 'article_index',
            'entry_type' => 'content_hub',
            'summary_blocks' => [
                [
                    'key' => 'articles_index',
                    'title' => $locale === 'zh-CN' ? '文章与洞察' : 'Articles and insights',
                    'body' => $locale === 'zh-CN'
                        ? '从公开文章进入人格、主题、职业与测试主链。'
                        : 'Use public articles to continue into personality, topic, career, and assessment surfaces.',
                    'kind' => 'answer_first',
                ],
            ],
            'discoverability_items' => $discoverabilityItems,
            'discoverability_keys' => array_column($discoverabilityItems, 'key'),
            'continue_reading_keys' => ['article_detail', 'topics_index', 'personality_index'],
            'start_test_target' => '/'.$segment.'/tests/mbti-personality-test-16-personality-types',
            'content_continue_target' => $firstHref,
            'cta_bundle' => [
                [
                    'key' => 'featured_article',
                    'label' => $locale === 'zh-CN' ? '阅读精选文章' : 'Read featured article',
                    'href' => $firstHref,
                    'kind' => 'content_continue',
                ],
                [
                    'key' => 'topic_hub',
                    'label' => $locale === 'zh-CN' ? '查看主题聚合' : 'Browse topic hubs',
                    'href' => '/'.$segment.'/topics',
                    'kind' => 'discover',
                ],
                [
                    'key' => 'start_test',
                    'label' => $locale === 'zh-CN' ? '开始测试' : 'Take the test',
                    'href' => '/'.$segment.'/tests/mbti-personality-test-16-personality-types',
                    'kind' => 'start_test',
                ],
            ],
            'indexability_state' => 'indexable',
            'attribution_scope' => 'public_article_landing',
            'surface_family' => 'article',
            'primary_content_ref' => 'articles_index',
            'related_surface_keys' => ['topics_index', 'personality_index', 'tests_index'],
            'fingerprint_seed' => [
                'locale' => $locale,
                'discoverability_keys' => array_column($discoverabilityItems, 'key'),
            ],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function buildDetailLandingSurface(Article $article, array $payload, string $locale): array
    {
        $segment = $this->frontendLocaleSegment($locale);
        $slug = trim((string) $article->slug);
        $cmsCtas = $this->publicArticleCtaBundle($article, $locale);
        $ctaBundle = $cmsCtas !== []
            ? $cmsCtas
            : $this->fallbackArticleCtaBundle($article, $locale);
        $startTestTarget = $this->firstStartTestTarget($ctaBundle)
            ?? $this->articleTestTarget($article, $locale);

        return $this->landingSurfaceContractService->build([
            'landing_scope' => 'public_indexable_detail',
            'entry_surface' => 'article_detail',
            'entry_type' => 'editorial_article',
            'summary_blocks' => [
                [
                    'key' => 'article_hero',
                    'title' => (string) ($payload['title'] ?? ''),
                    'body' => trim((string) ($payload['excerpt'] ?? '')),
                    'kind' => 'answer_first',
                ],
            ],
            'discoverability_keys' => ['article_index', 'topic_hub', 'personality_hub', 'career_recommendations'],
            'continue_reading_keys' => ['article_index', 'topic_hub'],
            'start_test_target' => $startTestTarget,
            'content_continue_target' => '/'.$segment.'/articles',
            'cta_bundle' => $ctaBundle,
            'indexability_state' => $article->is_indexable ? 'indexable' : 'noindex',
            'attribution_scope' => 'public_article_detail',
            'surface_family' => 'article',
            'primary_content_ref' => $slug,
            'related_surface_keys' => ['topic_hub', 'personality_hub', 'tests_index'],
            'fingerprint_seed' => [
                'slug' => $slug,
                'locale' => $locale,
            ],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function buildDetailAnswerSurface(Article $article, array $payload, string $locale): array
    {
        $segment = $this->frontendLocaleSegment($locale);
        $category = $article->relationLoaded('category') && $article->category
            ? trim((string) ($article->category->name ?? ''))
            : null;
        $tagNames = $article->relationLoaded('tags')
            ? array_values(array_filter(array_map(
                static fn ($tag): string => trim((string) ($tag->name ?? '')),
                $article->tags->all()
            )))
            : [];
        $cmsFaqBlocks = $this->publicArticleFaqBlocks($article);
        $faqBlocks = $cmsFaqBlocks;

        $compareBlocks = array_values(array_filter([
            $category !== null
                ? [
                    'key' => 'article_category',
                    'title' => $locale === 'zh-CN' ? '内容分类' : 'Content category',
                    'body' => $category,
                    'kind' => 'content_compare',
                ]
                : null,
            $tagNames !== []
                ? [
                    'key' => 'article_tags',
                    'title' => $locale === 'zh-CN' ? '相关标签' : 'Related tags',
                    'body' => implode($locale === 'zh-CN' ? '、' : ', ', array_slice($tagNames, 0, 4)),
                    'kind' => 'content_compare',
                ]
                : null,
        ]));

        $cmsNextStepBlocks = $this->answerSurfaceContractService->buildNextStepBlocksFromCtas(
            $this->publicArticleCtaBundle($article, $locale)
        );
        $nextStepBlocks = $cmsNextStepBlocks !== [] ? $cmsNextStepBlocks : array_values(array_filter([
            [
                'key' => 'articles_index',
                'title' => $locale === 'zh-CN' ? '继续浏览文章' : 'Continue with articles',
                'body' => $locale === 'zh-CN' ? '回到文章目录，继续扩展公开内容阅读链路。' : 'Return to the article hub to keep expanding the public reading chain.',
                'href' => '/'.$segment.'/articles',
                'kind' => 'content_continue',
            ],
            [
                'key' => 'topic_hub',
                'title' => $locale === 'zh-CN' ? '进入主题聚合' : 'Go to topic hubs',
                'body' => $locale === 'zh-CN' ? '把文章阅读继续到更结构化的主题入口。' : 'Continue from the article into a more structured topic entry surface.',
                'href' => '/'.$segment.'/topics',
                'kind' => 'discover',
            ],
            $this->articleTestTarget($article, $locale) !== null ? [
                'key' => 'start_test',
                'title' => $locale === 'zh-CN' ? '开始测试' : 'Take the test',
                'body' => $locale === 'zh-CN' ? '如果你想把阅读转成自我测量，可以从测试入口开始。' : 'If you want to turn reading into self-measurement, continue into an assessment.',
                'href' => $this->articleTestTarget($article, $locale),
                'kind' => 'start_test',
            ] : null,
        ]));

        return $this->answerSurfaceContractService->build([
            'answer_scope' => 'public_indexable_detail',
            'surface_type' => 'article_public_detail',
            'summary_blocks' => [
                [
                    'key' => 'article_summary',
                    'title' => (string) ($payload['title'] ?? ''),
                    'body' => trim((string) ($payload['excerpt'] ?? '')),
                    'kind' => 'answer_first',
                ],
            ],
            'faq_blocks' => $faqBlocks,
            'compare_blocks' => $compareBlocks,
            'next_step_blocks' => $nextStepBlocks,
            'evidence_refs' => array_values(array_filter(array_merge(
                ['article:'.trim((string) $article->slug)],
                $category !== null ? ['category:'.$category] : [],
                array_map(static fn (string $tag): string => 'tag:'.$tag, array_slice($tagNames, 0, 4))
            ))),
            'public_safety_state' => 'public_indexable',
            'indexability_state' => $article->is_indexable ? 'indexable' : 'noindex',
            'attribution_scope' => 'public_article_answer',
            'primary_content_ref' => trim((string) $article->slug),
            'related_surface_keys' => ['articles_index', 'topic_hub', 'tests_index'],
            'fingerprint_seed' => [
                'slug' => trim((string) $article->slug),
                'locale' => $locale,
                'tag_count' => count($tagNames),
            ],
        ]);
    }

    /**
     * @return list<array<string,string>>
     */
    private function publicArticleCtaBundle(Article $article, string $locale): array
    {
        $metadata = $this->editorialPackageMetadata($article);
        $slots = is_array($metadata['cta_slots'] ?? null) ? $metadata['cta_slots'] : [];
        $ctas = [];

        foreach ($slots as $slot) {
            if (! is_array($slot)) {
                continue;
            }

            $label = $this->normalizeString($slot['label'] ?? $slot['title'] ?? null);
            $href = $this->normalizePublicTestHref($slot['href'] ?? $slot['url'] ?? null, $locale);
            if ($label === null || $href === null) {
                continue;
            }

            $key = $this->normalizeString($slot['key'] ?? $slot['slot_id'] ?? $slot['id'] ?? null)
                ?? 'article_cta_'.(count($ctas) + 1);
            $kind = $this->normalizeString($slot['kind'] ?? null) ?? 'start_test';

            $ctas[] = [
                'key' => $key,
                'label' => $label,
                'href' => $href,
                'kind' => $kind,
            ];

            if (count($ctas) >= 3) {
                break;
            }
        }

        return $ctas;
    }

    /**
     * @return list<array<string,string|null>>
     */
    private function publicArticleFaqBlocks(Article $article): array
    {
        $metadata = $this->editorialPackageMetadata($article);
        $policy = $this->normalizeString($metadata['answer_surface_policy'] ?? null);
        $visibility = $this->normalizeString($metadata['answer_surface_visibility'] ?? null);
        if ($policy !== 'editor_supplied' || $visibility === null || $visibility === 'disabled') {
            return [];
        }

        $answerSurface = is_array($metadata['answer_surface_v1'] ?? null) ? $metadata['answer_surface_v1'] : [];
        $faqItems = is_array($answerSurface['faq_items'] ?? null) ? $answerSurface['faq_items'] : [];
        $article15Metadata = $metadata['article15_exact_package_v1'] ?? null;
        $faqLimit = Article15ExactPackageRevisionBoundAdapter::isPublishedArticle15Metadata(
            $article15Metadata,
            (int) $article->id,
            (int) ($article->published_revision_id ?? 0),
        ) ? 8 : 6;
        $faqLimit = IqPublicArticleFaqProjection::limit($article, $metadata, $faqLimit);
        $blocks = [];

        foreach ($faqItems as $index => $item) {
            if (! is_array($item) || $this->isHiddenFaqItem($item)) {
                continue;
            }

            $question = $this->normalizeString($item['question'] ?? $item['q'] ?? null);
            $answer = $this->normalizeString($item['answer'] ?? $item['a'] ?? null);
            if ($question === null || $answer === null) {
                continue;
            }

            $blocks[] = [
                'key' => $this->normalizeString($item['key'] ?? $item['id'] ?? null) ?? 'article_faq_'.$index,
                'question' => $question,
                'answer' => $answer,
            ];

            if (count($blocks) >= $faqLimit) {
                break;
            }
        }

        return $blocks;
    }

    /**
     * @return array<string,mixed>
     */
    private function editorialPackageMetadata(Article $article): array
    {
        $seoMeta = $article->relationLoaded('seoMeta') ? $article->seoMeta : null;
        $variants = is_array($article->cover_image_variants) ? $article->cover_image_variants : [];
        $variantMetadata = is_array($variants['editorial_package_v1'] ?? null)
            ? $variants['editorial_package_v1']
            : [];
        $schemaMetadata = is_array($seoMeta?->schema_json) && is_array($seoMeta->schema_json['editorial_package_v1'] ?? null)
            ? $seoMeta->schema_json['editorial_package_v1']
            : [];

        $metadata = array_replace_recursive($variantMetadata, $schemaMetadata);
        // FAQ is an ordered editorial list, not an object to merge by numeric index.
        $schemaAnswer = $schemaMetadata['answer_surface_v1'] ?? null;
        if (is_array($schemaAnswer) && array_key_exists('faq_items', $schemaAnswer)) {
            $metadata['answer_surface_v1']['faq_items'] = $schemaAnswer['faq_items'];
        }

        return $metadata;
    }

    /**
     * @return list<array<string,string>>
     */
    private function fallbackArticleCtaBundle(Article $article, string $locale): array
    {
        $segment = $this->frontendLocaleSegment($locale);

        return array_values(array_filter([
            [
                'key' => 'back_to_articles',
                'label' => $locale === 'zh-CN' ? '返回文章列表' : 'Back to articles',
                'href' => '/'.$segment.'/articles',
                'kind' => 'content_continue',
            ],
            [
                'key' => 'topic_hub',
                'label' => $locale === 'zh-CN' ? '查看主题聚合' : 'Browse topic hubs',
                'href' => '/'.$segment.'/topics',
                'kind' => 'discover',
            ],
            $this->articleTestTarget($article, $locale) !== null ? [
                'key' => 'start_test',
                'label' => $locale === 'zh-CN' ? '开始测试' : 'Take the test',
                'href' => $this->articleTestTarget($article, $locale),
                'kind' => 'start_test',
            ] : null,
        ]));
    }

    private function articleTestTarget(Article $article, string $locale): ?string
    {
        $segment = $this->frontendLocaleSegment($locale);
        foreach ($this->publicRelatedTestSlugs($article) as $slug) {
            $href = $this->normalizePublicTestHref('/'.$segment.'/tests/'.$slug, $locale);
            if ($href !== null) {
                return $href;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string,string>>  $ctas
     */
    private function firstStartTestTarget(array $ctas): ?string
    {
        foreach ($ctas as $cta) {
            $href = $this->normalizePublicTestHref($cta['href'] ?? null);
            if ($href !== null) {
                return $href;
            }
        }

        return null;
    }

    private function normalizePublicTestHref(mixed $href, ?string $locale = null): ?string
    {
        if (! is_scalar($href)) {
            return null;
        }

        $value = trim((string) $href);
        if ($value === '' || ! str_starts_with($value, '/')) {
            return null;
        }

        $parts = parse_url($value);
        if (! is_array($parts) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '');
        if (preg_match('#/(result|orders?|share|pay|payment|history|take)(/|$)#i', $path) === 1) {
            return null;
        }

        if (preg_match('#^/(en|zh)/tests/([a-z0-9][a-z0-9-]*)$#', $path, $matches) !== 1
            || ($locale !== null && $matches[1] !== $this->frontendLocaleSegment($locale))) {
            return null;
        }
        $scale = app(\App\Services\Scale\ScaleRegistry::class)->lookupBySlug($matches[2], 0);
        $canonicalSlug = $scale['primary_slug'] ?? null;
        if (! is_string($canonicalSlug) || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $canonicalSlug) !== 1
            || ! ($scale['is_public'] ?? false) || ! ($scale['is_active'] ?? false)) {
            return null;
        }

        return '/'.$matches[1].'/tests/'.$canonicalSlug;
    }

    private function normalizeString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
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
     * POST /api/v0.5/cms/articles
     */
    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:127'],
            'locale' => ['nullable', 'string', 'max:16'],
            'content_md' => ['required', 'string'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['integer', 'min:1'],
        ]);

        try {
            $article = $this->articleService->createArticle(
                (string) $payload['title'],
                isset($payload['slug']) ? (string) $payload['slug'] : null,
                isset($payload['locale']) ? (string) $payload['locale'] : 'en',
                (string) $payload['content_md'],
                isset($payload['category_id']) ? (int) $payload['category_id'] : null,
                isset($payload['tags']) && is_array($payload['tags']) ? $payload['tags'] : [],
                $this->resolveTrustedOrgId($request)
            );
        } catch (InvalidArgumentException $e) {
            return $this->invalidArgument($e);
        } catch (RuntimeException $e) {
            return $this->runtimeError($e);
        }

        $article->loadMissing($this->articleRelations());

        return response()->json([
            'ok' => true,
            'article' => $this->articlePayload($article),
        ], 201);
    }

    /**
     * PUT /api/v0.5/cms/articles/{id}
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $articleId = $this->resolveArticleId($id);
        if ($articleId === null) {
            return response()->json([
                'ok' => false,
                'error_code' => 'ARTICLE_ID_INVALID',
                'message' => 'article id must be a positive integer.',
            ], 422);
        }

        $payload = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:127'],
            'locale' => ['nullable', 'string', 'max:16'],
            'content_md' => ['sometimes', 'string'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'author_admin_user_id' => ['nullable', 'integer', 'min:1'],
            'author_name' => ['nullable', 'string', 'max:128'],
            'reviewer_name' => ['nullable', 'string', 'max:128'],
            'reading_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'excerpt' => ['nullable', 'string'],
            'content_html' => ['nullable', 'string'],
            'cover_image_url' => ['nullable', 'string', 'max:255'],
            'cover_image_alt' => ['nullable', 'string', 'max:255'],
            'cover_image_width' => ['nullable', 'integer', 'min:1'],
            'cover_image_height' => ['nullable', 'integer', 'min:1'],
            'cover_image_variants' => ['nullable', 'array'],
            'related_test_slug' => ['nullable', 'string', 'max:127'],
            'voice' => ['nullable', 'string', 'max:32'],
            'voice_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_indexable' => ['sometimes', 'boolean'],
            'sitemap_eligible' => ['sometimes', 'boolean'],
            'llms_eligible' => ['sometimes', 'boolean'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['integer', 'min:1'],
        ]);

        $fields = $payload;
        $tags = null;
        if (array_key_exists('tags', $fields)) {
            $rawTags = $fields['tags'];
            $tags = is_array($rawTags) ? $rawTags : [];
            unset($fields['tags']);
        }

        try {
            $this->assertArticleInOrgScope($articleId, $this->resolveTrustedOrgId($request));
            $article = $this->articleService->updateArticle($articleId, $fields, $tags);
        } catch (InvalidArgumentException $e) {
            return $this->invalidArgument($e);
        } catch (RuntimeException $e) {
            return $this->runtimeError($e);
        }

        $article->loadMissing($this->articleRelations());

        return response()->json([
            'ok' => true,
            'article' => $this->articlePayload($article),
        ]);
    }

    /**
     * POST /api/v0.5/cms/articles/{id}/publish
     */
    public function publish(Request $request, string $id): JsonResponse
    {
        $articleId = $this->resolveArticleId($id);
        if ($articleId === null) {
            return response()->json([
                'ok' => false,
                'error_code' => 'ARTICLE_ID_INVALID',
                'message' => 'article id must be a positive integer.',
            ], 422);
        }

        try {
            $this->assertArticleInOrgScope($articleId, $this->resolveTrustedOrgId($request));
            $article = $this->articlePublishService->publishArticle($articleId);
        } catch (InvalidArgumentException $e) {
            return $this->invalidArgument($e);
        } catch (RuntimeException $e) {
            return $this->runtimeError($e);
        }

        $article->loadMissing($this->articleRelations());

        return response()->json([
            'ok' => true,
            'article' => $this->articlePayload($article),
        ]);
    }

    /**
     * POST /api/v0.5/cms/articles/{id}/unpublish
     */
    public function unpublish(Request $request, string $id): JsonResponse
    {
        $articleId = $this->resolveArticleId($id);
        if ($articleId === null) {
            return response()->json([
                'ok' => false,
                'error_code' => 'ARTICLE_ID_INVALID',
                'message' => 'article id must be a positive integer.',
            ], 422);
        }

        try {
            $this->assertArticleInOrgScope($articleId, $this->resolveTrustedOrgId($request));
            $article = $this->articlePublishService->unpublishArticle($articleId);
        } catch (InvalidArgumentException $e) {
            return $this->invalidArgument($e);
        } catch (RuntimeException $e) {
            return $this->runtimeError($e);
        }

        $article->loadMissing($this->articleRelations());

        return response()->json([
            'ok' => true,
            'article' => $this->articlePayload($article),
        ]);
    }

    /**
     * POST /api/v0.5/cms/articles/{id}/seo
     */
    public function generateSeo(Request $request, string $id): JsonResponse
    {
        $articleId = $this->resolveArticleId($id);
        if ($articleId === null) {
            return response()->json([
                'ok' => false,
                'error_code' => 'ARTICLE_ID_INVALID',
                'message' => 'article id must be a positive integer.',
            ], 422);
        }

        try {
            $this->assertArticleInOrgScope($articleId, $this->resolveTrustedOrgId($request));
            $seoMeta = $this->articleSeoService->generateSeoMeta($articleId);
        } catch (InvalidArgumentException $e) {
            return $this->invalidArgument($e);
        } catch (RuntimeException $e) {
            return $this->runtimeError($e);
        }

        return response()->json([
            'ok' => true,
            'seo_meta' => [
                'id' => (int) $seoMeta->id,
                'org_id' => (int) $seoMeta->org_id,
                'article_id' => (int) $seoMeta->article_id,
                'locale' => (string) $seoMeta->locale,
                'seo_title' => (string) ($seoMeta->seo_title ?? ''),
                'seo_description' => (string) ($seoMeta->seo_description ?? ''),
                'canonical_url' => $seoMeta->canonical_url,
                'og_title' => (string) ($seoMeta->og_title ?? ''),
                'og_description' => (string) ($seoMeta->og_description ?? ''),
                'is_indexable' => (bool) $seoMeta->is_indexable,
                'robots' => (string) ($seoMeta->robots ?? ''),
                'created_at' => $seoMeta->created_at?->toISOString(),
                'updated_at' => $seoMeta->updated_at?->toISOString(),
            ],
        ]);
    }

    private function resolveOrgId(Request $request): int
    {
        $raw = trim((string) $request->query('org_id', '0'));

        return preg_match('/^\d+$/', $raw) === 1 ? (int) $raw : 0;
    }

    private function frontendLocaleSegment(string $locale): string
    {
        return $locale === 'zh-CN' ? 'zh' : 'en';
    }

    /**
     * @return array{org_id:int,locale:?string,related_test_slug:?string,voice:?string,page:int,per_page:int}|JsonResponse
     */
    private function validateListQuery(Request $request): array|JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'org_id' => ['nullable', 'integer', 'min:0'],
            'locale' => ['nullable', 'in:en,zh-CN'],
            'related_test_slug' => ['nullable', 'string', 'max:127'],
            'voice' => ['nullable', 'string', 'max:32'],
            'category' => ['nullable', 'string', 'max:127', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'include_blog' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->invalidArgumentMessage($validator->errors()->first());
        }

        $validated = $validator->validated();
        if (($validated['include_blog'] ?? false) && ! isset($validated['locale'])) {
            return $this->invalidArgumentMessage('locale is required for include_blog.');
        }

        return [
            'org_id' => (int) ($validated['org_id'] ?? 0),
            'locale' => isset($validated['locale']) ? (string) $validated['locale'] : null,
            'related_test_slug' => isset($validated['related_test_slug']) ? trim((string) $validated['related_test_slug']) : null,
            'voice' => isset($validated['voice']) ? trim((string) $validated['voice']) : null,
            'category' => $validated['category'] ?? null,
            'include_blog' => (bool) ($validated['include_blog'] ?? false),
            'page' => (int) ($validated['page'] ?? 1),
            'per_page' => (int) ($validated['per_page'] ?? 20),
        ];
    }

    private function resolveTrustedOrgId(Request $request): int
    {
        $candidates = [
            $request->attributes->get('fm_org_id'),
            $request->hasSession() ? $request->session()->get('ops_org_id') : null,
        ];

        foreach ($candidates as $candidate) {
            if (! is_int($candidate) && ! is_string($candidate) && ! is_numeric($candidate)) {
                continue;
            }

            $raw = trim((string) $candidate);
            if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
                continue;
            }

            return max(0, (int) $raw);
        }

        // CMS global/internal preview content lives in org 0 when no ops org is selected.
        return 0;
    }

    private function resolveArticleId(string $id): ?int
    {
        $normalized = trim($id);
        if ($normalized === '' || preg_match('/^\d+$/', $normalized) !== 1) {
            return null;
        }

        $articleId = (int) $normalized;

        return $articleId > 0 ? $articleId : null;
    }

    private function assertArticleInOrgScope(int $articleId, int $trustedOrgId): void
    {
        $exists = Article::query()
            ->withoutGlobalScopes()
            ->where('id', $articleId)
            ->whereIn('org_id', $this->allowedOrgIds($trustedOrgId))
            ->exists();

        if (! $exists) {
            throw new RuntimeException('article not found.');
        }
    }

    /**
     * @return array<int, int>
     */
    private function allowedOrgIds(int $trustedOrgId): array
    {
        $normalizedOrgId = max(0, $trustedOrgId);

        return $normalizedOrgId > 0 ? [0, $normalizedOrgId] : [0];
    }

    /**
     * @return array<string, \Closure>
     */
    private function articleRelations(): array
    {
        return [
            'category' => static fn ($query) => $query->withoutGlobalScopes(),
            'tags' => static fn ($query) => $query->withoutGlobalScopes(),
            'seoMeta' => static fn ($query) => $query->withoutGlobalScopes(),
            'publishedRevision' => static fn ($query) => $query->withoutGlobalScopes(),
            'testEdges' => static fn ($query) => $query->withoutGlobalScopes()
                ->where('visibility', ArticleTestEdge::VISIBILITY_PUBLIC)
                ->orderBy('sort_order')
                ->orderBy('id'),
        ];
    }

    /**
     * @param  array{org_id:int,locale:?string,related_test_slug:?string,voice:?string,page:int,per_page:int}  $validated
     * @return array<string,mixed>
     */
    private function buildArticleListResponse(array $validated): array
    {
        $paginator = $this->articlePublicListQuery->paginate($validated);
        $items = [];

        foreach ($paginator->items() as $article) {
            if ($article instanceof Article) {
                $items[] = $this->publicArticleListPayload($article);
            }
        }

        return [
            'ok' => true,
            'items' => $items,
            'pagination' => [
                'current_page' => (int) $paginator->currentPage(),
                'per_page' => (int) $paginator->perPage(),
                'total' => (int) $paginator->total(),
                'last_page' => (int) $paginator->lastPage(),
            ],
            'landing_surface_v1' => $this->buildIndexLandingSurface(
                $items,
                $validated['locale'] ?? 'en',
            ),
        ];
    }

    private function findPublicArticle(string $slug, string $locale, int $orgId): ?Article
    {
        /** @var Article|null */
        return Article::query()
            ->withoutGlobalScopes()
            ->where('org_id', $orgId)
            ->where('slug', $slug)
            ->where('locale', $locale)
            ->whereNull('articles.deleted_at')
            ->publiclyReadable()
            ->with($this->articleRelations())
            ->first();
    }

    private function publicRevision(Article $article): ?ArticleTranslationRevision
    {
        if (
            $article->relationLoaded('publishedRevision')
            && $article->publishedRevision instanceof ArticleTranslationRevision
        ) {
            return $article->publishedRevision;
        }

        $article->loadMissing('publishedRevision');

        return $article->publishedRevision instanceof ArticleTranslationRevision
            ? $article->publishedRevision
            : null;
    }

    /**
     * @return array<string,mixed>
     */
    private function publicArticlePayload(Article $article, ?ArticleTranslationRevision $revision = null): array
    {
        $revision ??= $this->publicRevision($article);
        if (! $revision instanceof ArticleTranslationRevision) {
            throw new RuntimeException('published revision not found.');
        }

        return array_merge([
            'id' => (int) $article->id,
            'org_id' => (int) $article->org_id,
            'category_id' => $article->category_id !== null ? (int) $article->category_id : null,
            'author_admin_user_id' => $article->author_admin_user_id !== null ? (int) $article->author_admin_user_id : null,
            'author_name' => $article->author_name,
            'reading_minutes' => $article->reading_minutes !== null ? (int) $article->reading_minutes : null,
            'slug' => (string) $article->slug,
            'locale' => (string) $article->locale,
            'translation_group_id' => (string) ($article->translation_group_id ?? ''),
            'source_article_id' => $article->source_article_id !== null ? (int) $article->source_article_id : null,
            'source_locale' => $article->source_locale,
            'published_revision_id' => (int) $revision->id,
            'title' => (string) $revision->title,
            'excerpt' => $revision->excerpt,
            'content_md' => $this->articleBodyHeadingGuard->downgradeMarkdownH1ToH2((string) $revision->content_md),
            'content_html' => null,
            'cover_image_url' => PublicMediaUrlGuard::sanitizeNullableUrl($article->cover_image_url),
            'cover_image_alt' => $article->cover_image_alt,
            'cover_image_width' => $article->cover_image_width !== null ? (int) $article->cover_image_width : null,
            'cover_image_height' => $article->cover_image_height !== null ? (int) $article->cover_image_height : null,
            'cover_image_variants' => $this->publicCoverImageVariants($article),
            'body_visual' => $this->publicBodyVisualPayload($article),
            'related_test_slug' => $article->related_test_slug,
            'related_test_slugs' => $this->publicRelatedTestSlugs($article),
            'test_edges' => $this->publicTestEdges($article),
            'voice' => $article->voice,
            'voice_order' => $article->voice_order !== null ? (int) $article->voice_order : null,
            'status' => (string) $article->status,
            'is_public' => (bool) $article->is_public,
            'is_indexable' => (bool) $article->is_indexable,
            'sitemap_eligible' => (bool) $article->sitemap_eligible,
            'llms_eligible' => (bool) $article->llms_eligible,
            'published_at' => $article->published_at?->toISOString(),
            'scheduled_at' => $article->scheduled_at?->toISOString(),
            'created_at' => $article->created_at?->toISOString(),
            'updated_at' => $revision->updated_at?->toISOString() ?? $article->updated_at?->toISOString(),
            'category' => $this->scopedCategory($article),
            'tags' => $this->scopedTags($article),
            'seo_meta' => $this->publicSeoMetaSnapshot($article, $revision),
        ], $this->publicReviewContract->project(
            $revision->reviewed_at !== null || $revision->approved_at !== null ? 'approved' : $revision->revision_status,
            $revision->reviewed_at ?? $revision->approved_at,
        ));
    }

    /**
     * @return array<string,mixed>
     */
    private function publicArticleListPayload(Article $article): array
    {
        $revision = $this->publicRevision($article);
        if (! $revision instanceof ArticleTranslationRevision) {
            throw new RuntimeException('published revision not found.');
        }

        $excerpt = trim((string) ($revision->excerpt ?? ''));
        if ($excerpt === '') {
            $plainBody = preg_replace('/[#*_>`~\[\]()!-]+/u', ' ', (string) $revision->content_md) ?? '';
            $excerpt = Str::limit(trim((string) preg_replace('/\s+/u', ' ', strip_tags($plainBody))), 240, '…');
        }

        return array_merge([
            'id' => (int) $article->id,
            'org_id' => (int) $article->org_id,
            'category_id' => $article->category_id !== null ? (int) $article->category_id : null,
            'author_admin_user_id' => $article->author_admin_user_id !== null ? (int) $article->author_admin_user_id : null,
            'author_name' => $article->author_name,
            'reading_minutes' => $article->reading_minutes !== null ? (int) $article->reading_minutes : null,
            'slug' => (string) $article->slug,
            'locale' => (string) $article->locale,
            'translation_group_id' => (string) ($article->translation_group_id ?? ''),
            'source_article_id' => $article->source_article_id !== null ? (int) $article->source_article_id : null,
            'source_locale' => $article->source_locale,
            'published_revision_id' => (int) $revision->id,
            'title' => (string) $revision->title,
            'excerpt' => $excerpt,
            'cover_image_url' => PublicMediaUrlGuard::sanitizeNullableUrl($article->cover_image_url),
            'cover_image_alt' => $article->cover_image_alt,
            'cover_image_width' => $article->cover_image_width !== null ? (int) $article->cover_image_width : null,
            'cover_image_height' => $article->cover_image_height !== null ? (int) $article->cover_image_height : null,
            'cover_image_variants' => $this->publicCoverImageVariants($article),
            'related_test_slug' => $article->related_test_slug,
            'related_test_slugs' => $this->publicRelatedTestSlugs($article),
            'test_edges' => $this->publicTestEdges($article),
            'voice' => $article->voice,
            'voice_order' => $article->voice_order !== null ? (int) $article->voice_order : null,
            'status' => (string) $article->status,
            'is_public' => (bool) $article->is_public,
            'is_indexable' => (bool) $article->is_indexable,
            'sitemap_eligible' => (bool) $article->sitemap_eligible,
            'llms_eligible' => (bool) $article->llms_eligible,
            'published_at' => $article->published_at?->toISOString(),
            'scheduled_at' => $article->scheduled_at?->toISOString(),
            'created_at' => $article->created_at?->toISOString(),
            'updated_at' => $revision->updated_at?->toISOString() ?? $article->updated_at?->toISOString(),
            'category' => $this->scopedCategory($article),
            'tags' => $this->scopedTags($article),
        ], $this->publicReviewContract->project(
            $revision->reviewed_at !== null || $revision->approved_at !== null ? 'approved' : $revision->revision_status,
            $revision->reviewed_at ?? $revision->approved_at,
        ));
    }

    /**
     * @return array<string,mixed>|null
     */
    private function publicSeoMetaSnapshot(Article $article, ArticleTranslationRevision $revision): ?array
    {
        if (! $article->relationLoaded('seoMeta')) {
            return null;
        }

        $seoMeta = PublicMediaUrlGuard::sanitizeArrayFields(
            $article->seoMeta?->toArray(),
            ['og_image_url', 'twitter_image_url']
        );

        if (! is_array($seoMeta)) {
            return null;
        }

        $seoMeta['seo_title'] = $revision->seo_title;
        $seoMeta['seo_description'] = $revision->seo_description;
        $seoMeta['canonical_url'] = CanonicalFrontendUrl::normalizeAbsoluteUrl($seoMeta['canonical_url'] ?? null);
        if (is_array($seoMeta['schema_json'] ?? null)) {
            $this->projectPublicSeoGateMetadata($seoMeta, $seoMeta['schema_json']);
            $seoMeta['schema_json'] = PublicMediaUrlGuard::sanitizeJsonLdImageFields(
                CanonicalFrontendUrl::normalizeNestedUrls($seoMeta['schema_json'])
            );
            unset($seoMeta['schema_json']['editorial_package_v1']);
        }

        return $seoMeta;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function publicCoverImageVariants(Article $article): ?array
    {
        $variants = PublicMediaUrlGuard::sanitizeArrayFields($article->cover_image_variants, ['url']);
        if (! is_array($variants)) {
            return null;
        }

        unset($variants['editorial_package_v1']);

        return $variants;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function publicBodyVisualPayload(Article $article): ?array
    {
        $metadata = $this->editorialPackageMetadata($article);
        $imageUrl = PublicMediaUrlGuard::sanitizeNullableUrl($metadata['body_visual_image_url'] ?? null);
        if ($imageUrl === null) {
            return null;
        }

        return [
            'image_url' => $imageUrl,
            'fallback_authorized' => (bool) ($metadata['body_visual_fallback_authorized'] ?? false),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function articlePayload(Article $article): array
    {
        return [
            'id' => (int) $article->id,
            'org_id' => (int) $article->org_id,
            'category_id' => $article->category_id !== null ? (int) $article->category_id : null,
            'author_admin_user_id' => $article->author_admin_user_id !== null ? (int) $article->author_admin_user_id : null,
            'author_name' => $article->author_name,
            'reviewer_name' => $article->reviewer_name,
            'reading_minutes' => $article->reading_minutes !== null ? (int) $article->reading_minutes : null,
            'slug' => (string) $article->slug,
            'locale' => (string) $article->locale,
            'title' => (string) $article->title,
            'excerpt' => $article->excerpt,
            'content_md' => $this->articleBodyHeadingGuard->downgradeMarkdownH1ToH2((string) $article->content_md),
            'content_html' => $article->content_html !== null
                ? $this->articleBodyHeadingGuard->downgradeHtmlH1ToH2((string) $article->content_html)
                : null,
            'cover_image_url' => PublicMediaUrlGuard::sanitizeNullableUrl($article->cover_image_url),
            'cover_image_alt' => $article->cover_image_alt,
            'cover_image_width' => $article->cover_image_width !== null ? (int) $article->cover_image_width : null,
            'cover_image_height' => $article->cover_image_height !== null ? (int) $article->cover_image_height : null,
            'cover_image_variants' => PublicMediaUrlGuard::sanitizeArrayFields($article->cover_image_variants, ['url']),
            'related_test_slug' => $article->related_test_slug,
            'related_test_slugs' => $this->publicRelatedTestSlugs($article),
            'test_edges' => $this->publicTestEdges($article),
            'voice' => $article->voice,
            'voice_order' => $article->voice_order !== null ? (int) $article->voice_order : null,
            'status' => (string) $article->status,
            'is_public' => (bool) $article->is_public,
            'is_indexable' => (bool) $article->is_indexable,
            'sitemap_eligible' => (bool) $article->sitemap_eligible,
            'llms_eligible' => (bool) $article->llms_eligible,
            'published_at' => $article->published_at?->toISOString(),
            'scheduled_at' => $article->scheduled_at?->toISOString(),
            'created_at' => $article->created_at?->toISOString(),
            'updated_at' => $article->updated_at?->toISOString(),
            'category' => $this->scopedCategory($article),
            'tags' => $this->scopedTags($article),
            'seo_meta' => $this->articleSeoMetaPayload($article),
        ];
    }

    private function scopedCategory(Article $article): ?ArticleCategory
    {
        if (! $article->relationLoaded('category')) {
            return null;
        }

        $category = $article->category;

        return $category instanceof ArticleCategory
            && (int) $category->org_id === (int) $article->org_id
                ? $category
                : null;
    }

    /**
     * @return array<int, ArticleTag>
     */
    private function scopedTags(Article $article): array
    {
        if (! $article->relationLoaded('tags')) {
            return [];
        }

        return $article->tags
            ->filter(static fn (ArticleTag $tag): bool => (int) $tag->org_id === (int) $article->org_id)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function publicRelatedTestSlugs(Article $article): array
    {
        $slugs = [];
        if (filled($article->related_test_slug)) {
            $slugs[] = (string) $article->related_test_slug;
        }

        foreach ($this->publicTestEdges($article) as $edge) {
            $slug = (string) ($edge['test_slug'] ?? '');
            if ($slug !== '') {
                $slugs[] = $slug;
            }
        }

        return array_values(array_unique($slugs));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function publicTestEdges(Article $article): array
    {
        if (! $article->relationLoaded('testEdges')) {
            return [];
        }

        return $article->testEdges
            ->filter(static fn (ArticleTestEdge $edge): bool => (int) $edge->org_id === (int) $article->org_id
                && (string) $edge->locale === (string) $article->locale
                && (string) $edge->visibility === ArticleTestEdge::VISIBILITY_PUBLIC)
            ->sortBy([
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ])
            ->map(static fn (ArticleTestEdge $edge): array => [
                'test_slug' => (string) $edge->test_slug,
                'role' => (string) $edge->role,
                'locale' => (string) $edge->locale,
                'sort_order' => (int) $edge->sort_order,
                'safety_level' => (string) $edge->safety_level,
                'visibility' => (string) $edge->visibility,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>|null
     */
    private function articleSeoMetaPayload(Article $article): ?array
    {
        if (! $article->relationLoaded('seoMeta')) {
            return null;
        }

        $seoMeta = PublicMediaUrlGuard::sanitizeArrayFields(
            $article->seoMeta?->toArray(),
            ['og_image_url', 'twitter_image_url']
        );

        if (! is_array($seoMeta)) {
            return null;
        }

        $seoMeta['canonical_url'] = CanonicalFrontendUrl::normalizeAbsoluteUrl($seoMeta['canonical_url'] ?? null);
        if (is_array($seoMeta['schema_json'] ?? null)) {
            $this->projectPublicSeoGateMetadata($seoMeta, $seoMeta['schema_json']);
            $seoMeta['schema_json'] = PublicMediaUrlGuard::sanitizeJsonLdImageFields(
                CanonicalFrontendUrl::normalizeNestedUrls($seoMeta['schema_json'])
            );
            unset($seoMeta['schema_json']['editorial_package_v1']);
        }

        return $seoMeta;
    }

    /**
     * @param  array<string,mixed>  $seoMeta
     * @param  array<string,mixed>  $schemaJson
     */
    private function projectPublicSeoGateMetadata(array &$seoMeta, array $schemaJson): void
    {
        $editorialPackage = $schemaJson['editorial_package_v1'] ?? null;
        if (! is_array($editorialPackage)) {
            return;
        }

        $schemaGates = [];
        foreach ([
            'article_schema_enabled' => 'article_schema_gate_v1',
            'breadcrumb_schema_enabled' => 'breadcrumb_schema_gate_v1',
            'faq_schema_enabled' => 'faq_schema_gate_v1',
        ] as $sourceKey => $targetKey) {
            if (is_bool($editorialPackage[$sourceKey] ?? null)) {
                $schemaGates[$targetKey] = ['enabled' => (bool) $editorialPackage[$sourceKey]];
            }
        }

        if ($schemaGates !== []) {
            $seoMeta['schema_gates_v1'] = $schemaGates;
        }

        $hreflangGate = $editorialPackage['hreflang_gate_v1'] ?? null;
        if (is_array($hreflangGate) && is_bool($hreflangGate['enabled'] ?? null)) {
            $publicHreflangGate = ['enabled' => (bool) $hreflangGate['enabled']];

            foreach (['policy', 'reason'] as $key) {
                if (is_string($hreflangGate[$key] ?? null) && trim((string) $hreflangGate[$key]) !== '') {
                    $publicHreflangGate[$key] = trim((string) $hreflangGate[$key]);
                }
            }

            $seoMeta['hreflang_gate_v1'] = $publicHreflangGate;
        }
    }

    private function invalidArgument(InvalidArgumentException $e): JsonResponse
    {
        return $this->invalidArgumentMessage($e->getMessage());
    }

    private function invalidArgumentMessage(string $message): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error_code' => 'INVALID_ARGUMENT',
            'message' => $message,
        ], 422);
    }

    private function runtimeError(RuntimeException $e): JsonResponse
    {
        $message = $e->getMessage();
        $status = $message === 'article not found.' ? 404 : 400;
        $errorCode = $status === 404 ? 'NOT_FOUND' : 'RUNTIME_ERROR';

        return response()->json([
            'ok' => false,
            'error_code' => $errorCode,
            'message' => $message,
        ], $status);
    }
}
