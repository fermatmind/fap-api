<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Cms;

use App\Http\Controllers\Controller;
use App\Models\SupportArticle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class SupportArticleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $this->validateReadQuery($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $query = SupportArticle::query()
            ->withoutGlobalScopes()
            ->where('org_id', $validated['org_id'])
            ->where('locale', $validated['locale'])
            ->published()
            ->orderBy('support_category')
            ->orderBy('support_intent')
            ->orderBy('id');

        return response()->json([
            'ok' => true,
            'items' => $query->get()->map(fn (SupportArticle $article): array => $this->payload($article))->values()->all(),
            'search_scope' => [
                'included_models' => ['support_articles'],
                'excluded_models' => ['articles'],
            ],
        ]);
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $validated = $this->validateReadQuery($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $article = SupportArticle::query()
            ->withoutGlobalScopes()
            ->where('org_id', $validated['org_id'])
            ->where('slug', $this->normalizeSlug($slug))
            ->where('locale', $validated['locale'])
            ->published()
            ->first();

        if (! $article instanceof SupportArticle) {
            return response()->json([
                'ok' => false,
                'error_code' => 'NOT_FOUND',
                'message' => 'support article not found.',
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'article' => $this->payload($article),
        ]);
    }

    public function internalIndex(Request $request): JsonResponse
    {
        $validated = $this->validateReadQuery($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $query = SupportArticle::query()
            ->withoutGlobalScopes()
            ->where('org_id', $validated['org_id'])
            ->where('locale', $validated['locale'])
            ->orderBy('support_category')
            ->orderBy('support_intent')
            ->orderBy('id');

        return response()->json([
            'ok' => true,
            'items' => $query->get()->map(fn (SupportArticle $article): array => $this->payload($article))->values()->all(),
        ]);
    }

    public function internalShow(Request $request, string $slug): JsonResponse
    {
        $validated = $this->validateReadQuery($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $article = SupportArticle::query()
            ->withoutGlobalScopes()
            ->where('org_id', $validated['org_id'])
            ->where('slug', $this->normalizeSlug($slug))
            ->where('locale', $validated['locale'])
            ->first();

        if (! $article instanceof SupportArticle) {
            return response()->json([
                'ok' => false,
                'error_code' => 'NOT_FOUND',
                'message' => 'support article not found.',
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'article' => $this->payload($article),
        ]);
    }

    public function internalUpdate(Request $request, string $slug): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'body_md' => ['nullable', 'string'],
            'body_html' => ['nullable', 'string'],
            'support_category' => ['required', Rule::in(SupportArticle::CATEGORIES)],
            'support_intent' => ['required', Rule::in(SupportArticle::INTENTS)],
            'locale' => ['required', 'string', Rule::in(['en', 'zh-CN'])],
            'status' => ['required', Rule::in([
                SupportArticle::STATUS_DRAFT,
                SupportArticle::STATUS_SCHEDULED,
                SupportArticle::STATUS_PUBLISHED,
                SupportArticle::STATUS_ARCHIVED,
            ])],
            'review_state' => ['required', Rule::in([
                SupportArticle::REVIEW_DRAFT,
                SupportArticle::REVIEW_SUPPORT,
                SupportArticle::REVIEW_PRODUCT_OR_POLICY,
                SupportArticle::REVIEW_APPROVED,
                SupportArticle::REVIEW_CHANGES_REQUESTED,
            ])],
            'primary_cta_label' => ['nullable', 'string', 'max:128'],
            'primary_cta_url' => ['nullable', 'string', 'max:255'],
            'related_support_article_ids' => ['nullable', 'array'],
            'related_support_article_ids.*' => ['integer', 'min:1'],
            'related_content_page_ids' => ['nullable', 'array'],
            'related_content_page_ids.*' => ['integer', 'min:1'],
            'last_reviewed_at' => ['nullable', 'date'],
            'published_at' => ['nullable', 'date'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:2000'],
            'canonical_path' => ['nullable', 'string', 'max:255'],
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
        if (
            in_array($validated['status'], [SupportArticle::STATUS_SCHEDULED, SupportArticle::STATUS_PUBLISHED], true)
            && $validated['review_state'] !== SupportArticle::REVIEW_APPROVED
        ) {
            return response()->json([
                'ok' => false,
                'error_code' => 'VALIDATION_FAILED',
                'errors' => ['review_state' => ['scheduled or published support articles must be approved.']],
            ], 422);
        }

        $bodyMd = trim((string) ($validated['body_md'] ?? ''));
        $bodyHtml = trim((string) ($validated['body_html'] ?? ''));

        if ($bodyMd === '' && $bodyHtml === '') {
            return response()->json([
                'ok' => false,
                'error_code' => 'VALIDATION_FAILED',
                'errors' => ['body_md' => ['body_md or body_html is required.']],
            ], 422);
        }

        $orgId = (int) ($validated['org_id'] ?? 0);
        $normalizedSlug = $this->normalizeSlug($slug);

        $article = SupportArticle::query()
            ->withoutGlobalScopes()
            ->firstOrNew([
                'org_id' => $orgId,
                'slug' => $normalizedSlug,
                'locale' => (string) $validated['locale'],
            ]);

        $article->fill([
            'title' => trim((string) $validated['title']),
            'summary' => $this->nullableString($validated['summary'] ?? null),
            'body_md' => $bodyMd,
            'body_html' => $bodyHtml,
            'support_category' => (string) $validated['support_category'],
            'support_intent' => (string) $validated['support_intent'],
            'status' => (string) $validated['status'],
            'review_state' => (string) $validated['review_state'],
            'primary_cta_label' => $this->nullableString($validated['primary_cta_label'] ?? null),
            'primary_cta_url' => $this->nullableString($validated['primary_cta_url'] ?? null),
            'related_support_article_ids' => array_values((array) ($validated['related_support_article_ids'] ?? [])),
            'related_content_page_ids' => array_values((array) ($validated['related_content_page_ids'] ?? [])),
            'last_reviewed_at' => $validated['last_reviewed_at'] ?? null,
            'published_at' => $validated['published_at'] ?? null,
            'seo_title' => $this->nullableString($validated['seo_title'] ?? null),
            'seo_description' => $this->nullableString($validated['seo_description'] ?? null),
            'canonical_path' => $this->nullableString($validated['canonical_path'] ?? null) ?? '/support/'.$normalizedSlug,
        ]);
        $article->save();

        return response()->json([
            'ok' => true,
            'article' => $this->payload($article),
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
    private function payload(SupportArticle $article): array
    {
        return [
            'id' => (int) $article->id,
            'slug' => (string) $article->slug,
            'title' => (string) $article->title,
            'summary' => $article->summary,
            'body_md' => (string) ($article->body_md ?? ''),
            'body_html' => (string) ($article->body_html ?? ''),
            'support_category' => (string) $article->support_category,
            'support_intent' => (string) $article->support_intent,
            'locale' => (string) $article->locale,
            'status' => (string) $article->status,
            'review_state' => (string) $article->review_state,
            'primary_cta_label' => $article->primary_cta_label,
            'primary_cta_url' => $article->primary_cta_url,
            'related_support_article_ids' => is_array($article->related_support_article_ids) ? $article->related_support_article_ids : [],
            'related_content_page_ids' => is_array($article->related_content_page_ids) ? $article->related_content_page_ids : [],
            'last_reviewed_at' => $this->dateString($article->last_reviewed_at),
            'published_at' => $this->dateString($article->published_at),
            'updated_at' => $this->dateString($article->updated_at),
            'seo_title' => $article->seo_title,
            'seo_description' => $article->seo_description,
            'canonical_path' => $article->canonical_path ?: '/support/'.(string) $article->slug,
            'searchable_model' => 'support_articles',
        ];
    }

    private function normalizeLocale(string $locale): string
    {
        $normalized = strtolower(str_replace('_', '-', trim($locale)));

        return str_starts_with($normalized, 'zh') ? 'zh-CN' : 'en';
    }

    private function normalizeSlug(string $slug): string
    {
        return strtolower(trim($slug));
    }

    private function nullableString(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function dateString(mixed $date): ?string
    {
        if ($date instanceof \DateTimeInterface) {
            return $date->format(DATE_ATOM);
        }

        $value = trim((string) $date);

        return $value !== '' ? $value : null;
    }
}
