<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Cms;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleTag;
use App\Models\ArticleTranslationRevision;
use App\Services\Cms\ArticlePublishService;
use App\Services\Cms\ArticleSeoService;
use App\Services\Cms\ArticleService;
use App\Services\PublicSurface\AnswerSurfaceContractService;
use App\Services\PublicSurface\LandingSurfaceContractService;
use App\Services\PublicSurface\SeoSurfaceContractService;
use App\Support\CanonicalFrontendUrl;
use App\Support\PublicMediaUrlGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use RuntimeException;

class ArticleController extends Controller
{
    public function __construct(
        private readonly ArticleService $articleService,
        private readonly ArticlePublishService $articlePublishService,
        private readonly ArticleSeoService $articleSeoService,
        private readonly AnswerSurfaceContractService $answerSurfaceContractService,
        private readonly LandingSurfaceContractService $landingSurfaceContractService,
        private readonly SeoSurfaceContractService $seoSurfaceContractService,
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

        $query = Article::query()
            ->withoutGlobalScopes()
            ->where('org_id', $validated['org_id'])
            ->publiclyReadable()
            ->with($this->articleRelations());

        if ($validated['locale'] !== null) {
            $query->where('locale', $validated['locale']);
        }
        if ($validated['related_test_slug'] !== null) {
            $query->where('related_test_slug', $validated['related_test_slug']);
        }
        if ($validated['voice'] !== null) {
            $query->where('voice', $validated['voice']);
        }

        $paginator = $query
            ->orderByRaw('voice_order is null')
            ->orderBy('voice_order')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(20, ['*'], 'page', $validated['page']);

        $items = [];
        foreach ($paginator->items() as $article) {
            if (! $article instanceof Article) {
                continue;
            }

            $items[] = $this->publicArticlePayload($article);
        }

        return response()->json([
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
                $validated['locale'] ?? 'en'
            ),
        ]);
    }

    /**
     * GET /api/v0.5/articles/{slug}
     */
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

        $meta = PublicMediaUrlGuard::sanitizeSeoMeta(
            $this->articleSeoService->buildSeoPayload($article, $revision)
        );
        $jsonLd = $this->articleSeoService->generateJsonLd($article, $revision);
        $payload = $this->publicArticlePayload($article, $revision);

        return response()->json([
            'ok' => true,
            'article' => $payload,
            'seo_surface_v1' => $this->buildSeoSurface($meta, $jsonLd, 'article_public_detail'),
            'landing_surface_v1' => $this->buildDetailLandingSurface($article, $payload, $locale),
            'answer_surface_v1' => $this->buildDetailAnswerSurface($article, $payload, $locale),
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
            'start_test_target' => '/'.$segment.'/tests/mbti-personality-test-16-personality-types',
            'content_continue_target' => '/'.$segment.'/articles',
            'cta_bundle' => [
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
                [
                    'key' => 'start_test',
                    'label' => $locale === 'zh-CN' ? '开始测试' : 'Take the test',
                    'href' => '/'.$segment.'/tests/mbti-personality-test-16-personality-types',
                    'kind' => 'start_test',
                ],
            ],
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
        $faqBlocks = [
            [
                'key' => 'article_use',
                'question' => $locale === 'zh-CN' ? '什么时候适合阅读这篇文章？' : 'When should I use this article?',
                'answer' => $locale === 'zh-CN'
                    ? '当你想把公开内容和测评、人格画像或职业建议串起来时，先从这篇文章的核心摘要开始。'
                    : 'Use this article when you want to connect public content with tests, personality profiles, or career guidance from a single starting point.',
            ],
            [
                'key' => 'article_limits',
                'question' => $locale === 'zh-CN' ? '这篇文章会替代正式判断吗？' : 'Does this replace formal judgment?',
                'answer' => $locale === 'zh-CN'
                    ? '不会。它只提供公开解释和行动线索，不替代医疗、法律或专业诊断。'
                    : 'No. It offers public explanation and action cues, but does not replace medical, legal, or professional judgment.',
            ],
        ];

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

        $nextStepBlocks = [
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
            [
                'key' => 'start_test',
                'title' => $locale === 'zh-CN' ? '开始测试' : 'Take the test',
                'body' => $locale === 'zh-CN' ? '如果你想把阅读转成自我测量，可以从测试入口开始。' : 'If you want to turn reading into self-measurement, continue into an assessment.',
                'href' => '/'.$segment.'/tests/mbti-personality-test-16-personality-types',
                'kind' => 'start_test',
            ],
        ];

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
            'status' => ['sometimes', 'string', 'max:32'],
            'is_public' => ['sometimes', 'boolean'],
            'is_indexable' => ['sometimes', 'boolean'],
            'published_at' => ['nullable', 'date'],
            'scheduled_at' => ['nullable', 'date'],
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
     * @return array{org_id:int,locale:?string,related_test_slug:?string,voice:?string,page:int}|JsonResponse
     */
    private function validateListQuery(Request $request): array|JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'org_id' => ['nullable', 'integer', 'min:0'],
            'locale' => ['nullable', 'in:en,zh-CN'],
            'related_test_slug' => ['nullable', 'string', 'max:127'],
            'voice' => ['nullable', 'string', 'max:32'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return $this->invalidArgumentMessage($validator->errors()->first());
        }

        $validated = $validator->validated();

        return [
            'org_id' => (int) ($validated['org_id'] ?? 0),
            'locale' => isset($validated['locale']) ? (string) $validated['locale'] : null,
            'related_test_slug' => isset($validated['related_test_slug']) ? trim((string) $validated['related_test_slug']) : null,
            'voice' => isset($validated['voice']) ? trim((string) $validated['voice']) : null,
            'page' => (int) ($validated['page'] ?? 1),
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
            'translation_group_id' => (string) ($article->translation_group_id ?? ''),
            'source_article_id' => $article->source_article_id !== null ? (int) $article->source_article_id : null,
            'source_locale' => $article->source_locale,
            'published_revision_id' => (int) $revision->id,
            'title' => (string) $revision->title,
            'excerpt' => $revision->excerpt,
            'content_md' => (string) $revision->content_md,
            'content_html' => null,
            'cover_image_url' => PublicMediaUrlGuard::sanitizeNullableUrl($article->cover_image_url),
            'cover_image_alt' => $article->cover_image_alt,
            'cover_image_width' => $article->cover_image_width !== null ? (int) $article->cover_image_width : null,
            'cover_image_height' => $article->cover_image_height !== null ? (int) $article->cover_image_height : null,
            'cover_image_variants' => PublicMediaUrlGuard::sanitizeArrayFields($article->cover_image_variants, ['url']),
            'related_test_slug' => $article->related_test_slug,
            'voice' => $article->voice,
            'voice_order' => $article->voice_order !== null ? (int) $article->voice_order : null,
            'status' => (string) $article->status,
            'is_public' => (bool) $article->is_public,
            'is_indexable' => (bool) $article->is_indexable,
            'published_at' => $article->published_at?->toISOString(),
            'scheduled_at' => $article->scheduled_at?->toISOString(),
            'created_at' => $article->created_at?->toISOString(),
            'updated_at' => $revision->updated_at?->toISOString() ?? $article->updated_at?->toISOString(),
            'category' => $this->scopedCategory($article),
            'tags' => $this->scopedTags($article),
            'seo_meta' => $this->publicSeoMetaSnapshot($article, $revision),
        ];
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
        if (array_key_exists('schema_json', $seoMeta)) {
            $seoMeta['schema_json'] = CanonicalFrontendUrl::normalizeNestedUrls($seoMeta['schema_json']);
        }

        return $seoMeta;
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
            'content_md' => (string) $article->content_md,
            'content_html' => $article->content_html,
            'cover_image_url' => PublicMediaUrlGuard::sanitizeNullableUrl($article->cover_image_url),
            'cover_image_alt' => $article->cover_image_alt,
            'cover_image_width' => $article->cover_image_width !== null ? (int) $article->cover_image_width : null,
            'cover_image_height' => $article->cover_image_height !== null ? (int) $article->cover_image_height : null,
            'cover_image_variants' => PublicMediaUrlGuard::sanitizeArrayFields($article->cover_image_variants, ['url']),
            'related_test_slug' => $article->related_test_slug,
            'voice' => $article->voice,
            'voice_order' => $article->voice_order !== null ? (int) $article->voice_order : null,
            'status' => (string) $article->status,
            'is_public' => (bool) $article->is_public,
            'is_indexable' => (bool) $article->is_indexable,
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
        if (array_key_exists('schema_json', $seoMeta)) {
            $seoMeta['schema_json'] = CanonicalFrontendUrl::normalizeNestedUrls($seoMeta['schema_json']);
        }

        return $seoMeta;
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
