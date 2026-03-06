<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Cms;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Topic;
use App\Support\Cms\TopicReferenceCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TopicController extends Controller
{
    /**
     * GET /api/v0.5/topics
     */
    public function index(Request $request): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);

        $items = Topic::query()
            ->withoutGlobalScopes()
            ->where('org_id', $orgId)
            ->withCount(['articleMaps as articles_count', 'careers', 'personalities'])
            ->orderBy('name')
            ->get()
            ->map(fn (Topic $topic): array => $this->topicListPayload($topic))
            ->values()
            ->all();

        return response()->json([
            'ok' => true,
            'items' => $items,
        ]);
    }

    /**
     * GET /api/v0.5/topics/{slug}
     */
    public function show(Request $request, string $slug): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        $normalizedSlug = trim($slug);

        if ($normalizedSlug === '') {
            return response()->json([
                'ok' => false,
                'error_code' => 'NOT_FOUND',
                'message' => 'topic not found.',
            ], 404);
        }

        $topic = Topic::query()
            ->withoutGlobalScopes()
            ->where('org_id', $orgId)
            ->where('slug', $normalizedSlug)
            ->with([
                'articles' => function ($query) use ($orgId): void {
                    $query
                        ->withoutGlobalScopes()
                        ->where('articles.org_id', $orgId)
                        ->published()
                        ->where('is_indexable', true)
                        ->orderByDesc('published_at')
                        ->orderByDesc('articles.id');
                },
                'careers',
                'personalities',
            ])
            ->first();

        if (! $topic instanceof Topic) {
            return response()->json([
                'ok' => false,
                'error_code' => 'NOT_FOUND',
                'message' => 'topic not found.',
            ], 404);
        }

        $articles = $topic->articles
            ->map(fn (Article $article): array => [
                'title' => (string) $article->title,
                'slug' => (string) $article->slug,
                'url' => sprintf('/articles/%s', $article->slug),
            ])
            ->values()
            ->all();

        if ($articles === []) {
            $articles = TopicReferenceCatalog::fallbackArticlesForTopic((string) $topic->slug);
        }

        $careers = $topic->careers
            ->map(fn ($item): ?array => TopicReferenceCatalog::careerById((int) $item->career_id))
            ->filter()
            ->values()
            ->all();

        if ($careers === []) {
            $careers = TopicReferenceCatalog::fallbackCareersForTopic((string) $topic->slug);
        }

        $personalities = $topic->personalities
            ->map(fn ($item): ?array => TopicReferenceCatalog::personalityByType((string) $item->personality_type))
            ->filter()
            ->values()
            ->all();

        if ($personalities === []) {
            $personalities = TopicReferenceCatalog::fallbackPersonalitiesForTopic((string) $topic->slug);
        }

        return response()->json([
            'ok' => true,
            'topic' => $this->topicPayload($topic),
            'articles' => $articles,
            'careers' => $careers,
            'personalities' => $personalities,
        ]);
    }

    private function resolveOrgId(Request $request): int
    {
        $raw = trim((string) $request->query('org_id', '0'));

        return preg_match('/^\d+$/', $raw) === 1 ? (int) $raw : 0;
    }

    /**
     * @return array<string,mixed>
     */
    private function topicListPayload(Topic $topic): array
    {
        $articlesCount = (int) ($topic->articles_count ?? 0);
        $careersCount = (int) ($topic->careers_count ?? 0);
        $personalitiesCount = (int) ($topic->personalities_count ?? 0);

        if ($articlesCount === 0) {
            $articlesCount = count(TopicReferenceCatalog::fallbackArticlesForTopic((string) $topic->slug));
        }

        if ($careersCount === 0) {
            $careersCount = count(TopicReferenceCatalog::fallbackCareersForTopic((string) $topic->slug));
        }

        if ($personalitiesCount === 0) {
            $personalitiesCount = count(TopicReferenceCatalog::fallbackPersonalitiesForTopic((string) $topic->slug));
        }

        return [
            'id' => (int) $topic->id,
            'org_id' => (int) $topic->org_id,
            'name' => (string) $topic->name,
            'slug' => (string) $topic->slug,
            'description' => $topic->description,
            'seo_title' => $topic->seo_title,
            'seo_description' => $topic->seo_description,
            'articles_count' => $articlesCount,
            'careers_count' => $careersCount,
            'personalities_count' => $personalitiesCount,
            'url' => sprintf('/topics/%s', $topic->slug),
            'created_at' => $topic->created_at?->toISOString(),
            'updated_at' => $topic->updated_at?->toISOString(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function topicPayload(Topic $topic): array
    {
        return [
            'id' => (int) $topic->id,
            'org_id' => (int) $topic->org_id,
            'name' => (string) $topic->name,
            'slug' => (string) $topic->slug,
            'description' => $topic->description,
            'seo_title' => $topic->seo_title,
            'seo_description' => $topic->seo_description,
            'created_at' => $topic->created_at?->toISOString(),
            'updated_at' => $topic->updated_at?->toISOString(),
        ];
    }
}
