<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Cms;

use App\Exceptions\OrgContextMissingException;
use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Services\Cms\ArticlePublishService;
use App\Services\Cms\ArticleSeoService;
use App\Services\Cms\ArticleService;
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
            ->published()
            ->with($this->articleRelations());

        if ($validated['locale'] !== null) {
            $query->where('locale', $validated['locale']);
        }

        $paginator = $query
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(20, ['*'], 'page', $validated['page']);

        $items = [];
        foreach ($paginator->items() as $article) {
            if (! $article instanceof Article) {
                continue;
            }

            $items[] = $this->articlePayload($article);
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

        $article = null;

        try {
            $article = Article::findBySlug($normalizedSlug, $locale);
        } catch (OrgContextMissingException) {
            $article = null;
        }

        if (! $article instanceof Article || (int) $article->org_id !== $orgId) {
            $article = Article::query()
                ->withoutGlobalScopes()
                ->where('org_id', $orgId)
                ->where('slug', $normalizedSlug)
                ->where('locale', $locale)
                ->first();
        }

        if (! $article instanceof Article || (string) $article->status !== 'published' || ! (bool) $article->is_public) {
            return response()->json([
                'ok' => false,
                'error_code' => 'NOT_FOUND',
                'message' => 'article not found.',
            ], 404);
        }

        $article->loadMissing($this->articleRelations());

        return response()->json([
            'ok' => true,
            'article' => $this->articlePayload($article),
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

        $article = null;

        try {
            $article = Article::findBySlug($normalizedSlug, $locale);
        } catch (OrgContextMissingException) {
            $article = null;
        }

        if (! $article instanceof Article || (int) $article->org_id !== $orgId) {
            $article = Article::query()
                ->withoutGlobalScopes()
                ->where('org_id', $orgId)
                ->where('slug', $normalizedSlug)
                ->where('locale', $locale)
                ->first();
        }

        if (! $article instanceof Article || (string) $article->status !== 'published' || ! (bool) $article->is_public) {
            return response()->json(['error' => 'not found'], 404);
        }

        return response()->json([
            'meta' => $this->articleSeoService->buildSeoPayload($article),
            'jsonld' => $this->articleSeoService->generateJsonLd($article),
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
            'excerpt' => ['nullable', 'string'],
            'content_html' => ['nullable', 'string'],
            'cover_image_url' => ['nullable', 'string', 'max:255'],
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

    /**
     * @return array{org_id:int,locale:?string,page:int}|JsonResponse
     */
    private function validateListQuery(Request $request): array|JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'org_id' => ['nullable', 'integer', 'min:0'],
            'locale' => ['nullable', 'in:en,zh-CN'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return $this->invalidArgumentMessage($validator->errors()->first());
        }

        $validated = $validator->validated();

        return [
            'org_id' => (int) ($validated['org_id'] ?? 0),
            'locale' => isset($validated['locale']) ? (string) $validated['locale'] : null,
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
            'slug' => (string) $article->slug,
            'locale' => (string) $article->locale,
            'title' => (string) $article->title,
            'excerpt' => $article->excerpt,
            'content_md' => (string) $article->content_md,
            'content_html' => $article->content_html,
            'cover_image_url' => $article->cover_image_url,
            'status' => (string) $article->status,
            'is_public' => (bool) $article->is_public,
            'is_indexable' => (bool) $article->is_indexable,
            'published_at' => $article->published_at?->toISOString(),
            'scheduled_at' => $article->scheduled_at?->toISOString(),
            'created_at' => $article->created_at?->toISOString(),
            'updated_at' => $article->updated_at?->toISOString(),
            'category' => $article->relationLoaded('category') ? $article->category : null,
            'tags' => $article->relationLoaded('tags') ? $article->tags : [],
            'seo_meta' => $article->relationLoaded('seoMeta') ? $article->seoMeta : null,
        ];
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
