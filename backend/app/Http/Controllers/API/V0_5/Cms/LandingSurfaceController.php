<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Cms;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\LandingSurface;
use App\Models\PageBlock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class LandingSurfaceController extends Controller
{
    public function show(Request $request, string $surfaceKey): JsonResponse
    {
        $validated = $this->validateReadQuery($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $surface = LandingSurface::query()
            ->withoutGlobalScopes()
            ->with(['blocks' => fn ($query) => $query->where('is_enabled', true)])
            ->where('org_id', $validated['org_id'])
            ->where('surface_key', $this->normalizeKey($surfaceKey))
            ->where('locale', $validated['locale'])
            ->publishedPublic()
            ->first();

        if (! $surface instanceof LandingSurface) {
            return response()->json([
                'ok' => false,
                'error_code' => 'NOT_FOUND',
                'message' => 'landing surface not found.',
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'surface' => $this->surfacePayload($surface),
        ]);
    }

    public function internalIndex(Request $request): JsonResponse
    {
        $validated = $this->validateReadQuery($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $query = LandingSurface::query()
            ->withoutGlobalScopes()
            ->where('org_id', $validated['org_id'])
            ->where('locale', $validated['locale'])
            ->orderBy('surface_key');

        $surfaceKey = trim((string) $request->query('surface_key', ''));
        if ($surfaceKey !== '') {
            $query->where('surface_key', $this->normalizeKey($surfaceKey));
        }

        return response()->json([
            'ok' => true,
            'items' => $query
                ->get()
                ->map(fn (LandingSurface $surface): array => $this->surfaceSummaryPayload($surface))
                ->values()
                ->all(),
        ]);
    }

    public function internalUpdate(Request $request, string $surfaceKey): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'org_id' => ['nullable', 'integer', 'min:0'],
            'locale' => ['required', 'string', Rule::in(['en', 'zh-CN', 'zh'])],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:4000'],
            'schema_version' => ['nullable', 'string', 'max:32'],
            'payload_json' => ['nullable', 'array'],
            'status' => ['required', Rule::in([LandingSurface::STATUS_DRAFT, LandingSurface::STATUS_PUBLISHED])],
            'is_public' => ['required', 'boolean'],
            'is_indexable' => ['required', 'boolean'],
            'published_at' => ['nullable', 'date'],
            'scheduled_at' => ['nullable', 'date'],
            'page_blocks' => ['nullable', 'array'],
            'page_blocks.*.block_key' => ['required_with:page_blocks', 'string', 'max:128'],
            'page_blocks.*.block_type' => ['nullable', 'string', 'max:64'],
            'page_blocks.*.title' => ['nullable', 'string', 'max:255'],
            'page_blocks.*.payload_json' => ['nullable', 'array'],
            'page_blocks.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'page_blocks.*.is_enabled' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok' => false,
                'error_code' => 'VALIDATION_FAILED',
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        $validated = $validator->validated();
        $orgId = (int) ($validated['org_id'] ?? 0);
        $locale = $this->normalizeLocale((string) $validated['locale']);
        $normalizedKey = $this->normalizeKey($surfaceKey);

        $surface = DB::transaction(function () use ($orgId, $locale, $normalizedKey, $validated): LandingSurface {
            $surface = LandingSurface::query()
                ->withoutGlobalScopes()
                ->firstOrNew([
                    'org_id' => $orgId,
                    'surface_key' => $normalizedKey,
                    'locale' => $locale,
                ]);

            $surface->fill([
                'title' => $this->nullableString($validated['title'] ?? null),
                'description' => $this->nullableString($validated['description'] ?? null),
                'schema_version' => $this->nullableString($validated['schema_version'] ?? null) ?? 'v1',
                'payload_json' => $validated['payload_json'] ?? [],
                'status' => (string) $validated['status'],
                'is_public' => (bool) $validated['is_public'],
                'is_indexable' => (bool) $validated['is_indexable'],
                'published_at' => $validated['published_at'] ?? null,
                'scheduled_at' => $validated['scheduled_at'] ?? null,
            ]);
            $surface->save();

            if (array_key_exists('page_blocks', $validated)) {
                $surface->blocks()->delete();
                foreach ($validated['page_blocks'] ?? [] as $index => $block) {
                    $surface->blocks()->create([
                        'block_key' => $this->normalizeKey((string) $block['block_key']),
                        'block_type' => $this->nullableString($block['block_type'] ?? null) ?? 'json',
                        'title' => $this->nullableString($block['title'] ?? null),
                        'payload_json' => $block['payload_json'] ?? [],
                        'sort_order' => (int) ($block['sort_order'] ?? $index),
                        'is_enabled' => (bool) ($block['is_enabled'] ?? true),
                    ]);
                }
            }

            return $surface->load('blocks');
        });

        return response()->json([
            'ok' => true,
            'surface' => $this->surfacePayload($surface),
        ]);
    }

    private function validateReadQuery(Request $request): array|JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'locale' => ['nullable', 'string', Rule::in(['en', 'zh-CN', 'zh'])],
            'org_id' => ['nullable', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok' => false,
                'error_code' => 'VALIDATION_FAILED',
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        $validated = $validator->validated();

        return [
            'locale' => $this->normalizeLocale((string) ($validated['locale'] ?? 'en')),
            'org_id' => (int) ($validated['org_id'] ?? 0),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function surfacePayload(LandingSurface $surface): array
    {
        return [
            'surface_key' => (string) $surface->surface_key,
            'locale' => (string) $surface->locale,
            'title' => $surface->title,
            'description' => $surface->description,
            'schema_version' => (string) $surface->schema_version,
            'payload_json' => is_array($surface->payload_json) ? $surface->payload_json : [],
            'status' => (string) $surface->status,
            'is_public' => (bool) $surface->is_public,
            'is_indexable' => (bool) $surface->is_indexable,
            'published_at' => $surface->published_at?->toIso8601String(),
            'scheduled_at' => $surface->scheduled_at?->toIso8601String(),
            'page_blocks' => $surface->blocks
                ->map(fn (PageBlock $block): array => $this->blockPayload(
                    $block,
                    $surface->locale,
                    (int) $surface->org_id
                ))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, array{published_revision_id: int, published_revision: array{id: int}}>
     */
    private function recommendedArticlePayloadMap(PageBlock $block, string $locale, int $orgId): array
    {
        if ((string) $block->block_key !== 'recommended_articles') {
            return [];
        }

        $payload = is_array($block->payload_json) ? $block->payload_json : [];
        $items = $payload['items'] ?? null;
        if (! is_array($items) || $items === []) {
            return [];
        }

        $slugs = collect($items)
            ->map(fn (mixed $item): ?string => is_array($item) ? $this->recommendedArticleSlug($item) : null)
            ->filter(fn (?string $slug): bool => $slug !== null)
            ->unique()
            ->values()
            ->all();

        if ($slugs === []) {
            return [];
        }

        /** @var Collection<int, Article> $articles */
        $articles = Article::query()
            ->withoutGlobalScopes()
            ->where('org_id', $orgId)
            ->where('locale', $locale)
            ->whereIn('slug', $slugs)
            ->publiclyReadable()
            ->get(['slug', 'published_revision_id']);

        return $articles
            ->filter(fn (Article $article): bool => $article->published_revision_id !== null)
            ->mapWithKeys(fn (Article $article): array => [
                (string) $article->slug => [
                    'published_revision_id' => (int) $article->published_revision_id,
                    'published_revision' => [
                        'id' => (int) $article->published_revision_id,
                    ],
                ],
            ])
            ->all();
    }

    private function recommendedArticleSlug(array $item): ?string
    {
        $article = $item['article'] ?? null;
        if (is_array($article)) {
            return $this->normalizeRecommendedArticleSlug($article['slug'] ?? null);
        }

        return $this->normalizeRecommendedArticleSlug($item['slug'] ?? null);
    }

    private function normalizeRecommendedArticleSlug(mixed $slug): ?string
    {
        if (! is_scalar($slug)) {
            return null;
        }

        $normalized = trim((string) $slug);
        if ($normalized === '' || strlen($normalized) > 127) {
            return null;
        }

        return preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,125}[a-z0-9])?\z/', $normalized) === 1
            ? $normalized
            : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, array{published_revision_id: int, published_revision: array{id: int}}>  $articleMap
     * @return array<string, mixed>
     */
    private function enrichRecommendedArticlesPayload(array $payload, array $articleMap): array
    {
        $items = $payload['items'] ?? null;
        if (! is_array($items) || $items === [] || $articleMap === []) {
            return $payload;
        }

        $payload['items'] = array_map(function (mixed $item) use ($articleMap): mixed {
            if (! is_array($item)) {
                return $item;
            }

            $slug = $this->recommendedArticleSlug($item);
            if ($slug === null || ! array_key_exists($slug, $articleMap)) {
                return $item;
            }

            $articlePatch = $articleMap[$slug];
            $article = is_array($item['article'] ?? null) ? $item['article'] : [];
            $item['article'] = array_merge($article, $articlePatch);

            return $item;
        }, $items);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function blockPayload(PageBlock $block, ?string $surfaceLocale = null, ?int $surfaceOrgId = null): array
    {
        $payload = is_array($block->payload_json) ? $block->payload_json : [];

        if ($surfaceLocale !== null && $surfaceOrgId !== null) {
            $payload = $this->enrichRecommendedArticlesPayload(
                $payload,
                $this->recommendedArticlePayloadMap($block, $surfaceLocale, $surfaceOrgId)
            );
        }

        return [
            'block_key' => (string) $block->block_key,
            'block_type' => (string) $block->block_type,
            'title' => $block->title,
            'payload_json' => $payload,
            'sort_order' => (int) $block->sort_order,
            'is_enabled' => (bool) $block->is_enabled,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function surfaceSummaryPayload(LandingSurface $surface): array
    {
        return array_intersect_key($this->surfacePayload($surface), array_flip([
            'surface_key',
            'locale',
            'title',
            'description',
            'schema_version',
            'status',
            'is_public',
            'is_indexable',
            'published_at',
            'scheduled_at',
        ]));
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizeLocale(string $locale): string
    {
        $normalized = strtolower(str_replace('_', '-', trim($locale)));

        return str_starts_with($normalized, 'zh') ? 'zh-CN' : 'en';
    }

    private function normalizeKey(string $key): string
    {
        return strtolower(trim($key));
    }

    private function nullableString(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
