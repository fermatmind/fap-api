<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Cms;

use App\Http\Controllers\Controller;
use App\Models\ContentPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class ContentPageController extends Controller
{
    /**
     * GET /api/v0.5/content-pages/{slug}
     */
    public function show(Request $request, string $slug): JsonResponse
    {
        $validated = $this->validateReadQuery($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $page = ContentPage::query()
            ->withoutGlobalScopes()
            ->where('org_id', $validated['org_id'])
            ->where('slug', $this->normalizeSlug($slug))
            ->where('locale', $validated['locale'])
            ->publishedPublic()
            ->first();

        if (! $page instanceof ContentPage) {
            return response()->json([
                'ok' => false,
                'error_code' => 'NOT_FOUND',
                'message' => 'content page not found.',
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'page' => $this->pagePayload($page),
        ]);
    }

    /**
     * GET /api/v0.5/internal/content-pages
     */
    public function internalIndex(Request $request): JsonResponse
    {
        $validated = $this->validateReadQuery($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $query = ContentPage::query()
            ->withoutGlobalScopes()
            ->where('org_id', $validated['org_id'])
            ->where('locale', $validated['locale'])
            ->orderBy('kind')
            ->orderBy('id');

        $kind = trim((string) $request->query('kind', ''));
        if ($kind !== '') {
            $query->where('kind', $kind);
        }

        return response()->json([
            'ok' => true,
            'items' => $query
                ->get()
                ->map(fn (ContentPage $page): array => $this->pageSummaryPayload($page))
                ->values()
                ->all(),
        ]);
    }

    /**
     * PUT /api/v0.5/internal/content-pages/{slug}
     */
    public function internalUpdate(Request $request, string $slug): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => ['required', 'string', 'max:255'],
            'kicker' => ['nullable', 'string', 'max:96'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'kind' => ['required', Rule::in([ContentPage::KIND_COMPANY, ContentPage::KIND_POLICY, ContentPage::KIND_HELP])],
            'template' => ['required', Rule::in(['company', 'charter', 'foundation', 'careers', 'brand', 'policy', 'help'])],
            'animation_profile' => ['required', Rule::in(['mission', 'principles', 'editorial', 'brand', 'policy', 'none'])],
            'locale' => ['required', 'string', Rule::in(['en', 'zh-CN'])],
            'published_at' => ['nullable', 'date'],
            'updated_at' => ['nullable', 'date'],
            'effective_at' => ['nullable', 'date'],
            'source_doc' => ['nullable', 'string', 'max:255'],
            'is_public' => ['required', 'boolean'],
            'is_indexable' => ['required', 'boolean'],
            'content_md' => ['nullable', 'string'],
            'content_html' => ['nullable', 'string'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:2000'],
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
        $orgId = (int) ($validated['org_id'] ?? 0);
        $normalizedSlug = $this->normalizeSlug($slug);
        $contentMd = trim((string) ($validated['content_md'] ?? ''));
        $contentHtml = trim((string) ($validated['content_html'] ?? ''));

        if ($contentMd === '' && $contentHtml === '') {
            return response()->json([
                'ok' => false,
                'error_code' => 'VALIDATION_FAILED',
                'errors' => ['content_md' => ['content_md or content_html is required.']],
            ], 422);
        }

        $page = ContentPage::query()
            ->withoutGlobalScopes()
            ->firstOrNew([
                'org_id' => $orgId,
                'slug' => $normalizedSlug,
                'locale' => (string) $validated['locale'],
            ]);

        $page->fill([
            'path' => '/'.$normalizedSlug,
            'kind' => (string) $validated['kind'],
            'title' => trim((string) $validated['title']),
            'kicker' => $this->nullableString($validated['kicker'] ?? null),
            'summary' => $this->nullableString($validated['summary'] ?? null),
            'template' => (string) $validated['template'],
            'animation_profile' => (string) $validated['animation_profile'],
            'published_at' => $validated['published_at'] ?? null,
            'source_updated_at' => $validated['updated_at'] ?? null,
            'effective_at' => $validated['effective_at'] ?? null,
            'source_doc' => $this->nullableString($validated['source_doc'] ?? null),
            'is_public' => (bool) $validated['is_public'],
            'is_indexable' => (bool) $validated['is_indexable'],
            'headings_json' => $this->extractHeadings($contentMd),
            'content_md' => $contentMd,
            'content_html' => $contentHtml,
            'seo_title' => $this->nullableString($validated['seo_title'] ?? null),
            'meta_description' => $this->nullableString($validated['meta_description'] ?? null),
            'status' => (bool) $validated['is_public'] ? ContentPage::STATUS_PUBLISHED : ContentPage::STATUS_DRAFT,
        ]);
        $page->save();

        return response()->json([
            'ok' => true,
            'page' => $this->pagePayload($page),
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
    private function pagePayload(ContentPage $page): array
    {
        return [
            'slug' => (string) $page->slug,
            'path' => (string) $page->path,
            'kind' => (string) $page->kind,
            'title' => (string) $page->title,
            'kicker' => $page->kicker,
            'summary' => $page->summary,
            'template' => (string) $page->template,
            'animation_profile' => (string) $page->animation_profile,
            'locale' => (string) $page->locale,
            'published_at' => $this->dateString($page->published_at),
            'updated_at' => $this->dateString($page->source_updated_at),
            'effective_at' => $this->dateString($page->effective_at),
            'source_doc' => $page->source_doc,
            'is_public' => (bool) $page->is_public,
            'is_indexable' => (bool) $page->is_indexable,
            'headings' => is_array($page->headings_json) ? $page->headings_json : $this->extractHeadings((string) $page->content_md),
            'content_md' => (string) ($page->content_md ?? ''),
            'content_html' => (string) ($page->content_html ?? ''),
            'seo_title' => $page->seo_title,
            'meta_description' => $page->meta_description,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function pageSummaryPayload(ContentPage $page): array
    {
        return array_intersect_key($this->pagePayload($page), array_flip([
            'slug',
            'path',
            'kind',
            'title',
            'kicker',
            'summary',
            'template',
            'animation_profile',
            'locale',
            'published_at',
            'updated_at',
            'effective_at',
            'is_public',
            'is_indexable',
        ]));
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

    /**
     * @return list<string>
     */
    private function extractHeadings(string $contentMd): array
    {
        preg_match_all('/^#{2,3}\s+(.+)$/m', $contentMd, $matches);

        return array_values(array_filter(array_map(
            static fn (string $heading): string => trim($heading),
            $matches[1] ?? []
        )));
    }

    private function dateString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $trimmed = trim((string) $value);

        return $trimmed !== '' ? substr($trimmed, 0, 10) : null;
    }
}
