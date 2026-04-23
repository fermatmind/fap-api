<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Cms;

use App\Filament\Ops\Support\ContentReleaseAudit;
use App\Http\Controllers\Controller;
use App\Models\ContentPage;
use App\Services\Cms\RowBackedRevisionWorkspace;
use App\Services\Cms\SiblingTranslationWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class ContentPageController extends Controller
{
    public function __construct(
        private readonly RowBackedRevisionWorkspace $workspace,
    ) {}

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
            'page_type' => ['nullable', Rule::in(ContentPage::PAGE_TYPES)],
            'template' => ['required', Rule::in(['company', 'charter', 'foundation', 'careers', 'brand', 'policy', 'help'])],
            'animation_profile' => ['required', Rule::in(['mission', 'principles', 'editorial', 'brand', 'policy', 'none'])],
            'locale' => ['required', 'string', Rule::in(['en', 'zh-CN'])],
            'status' => ['nullable', Rule::in([
                ContentPage::STATUS_DRAFT,
                ContentPage::STATUS_SCHEDULED,
                ContentPage::STATUS_PUBLISHED,
                ContentPage::STATUS_ARCHIVED,
            ])],
            'review_state' => ['nullable', Rule::in(ContentPage::REVIEW_STATES)],
            'owner' => ['nullable', 'string', 'max:128'],
            'legal_review_required' => ['nullable', 'boolean'],
            'science_review_required' => ['nullable', 'boolean'],
            'last_reviewed_at' => ['nullable', 'date'],
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

        $existing = ContentPage::query()
            ->withoutGlobalScopes()
            ->where([
                'org_id' => $orgId,
                'slug' => $normalizedSlug,
                'locale' => (string) $validated['locale'],
            ])->first();

        $kind = (string) $validated['kind'];
        $pageType = (string) ($validated['page_type'] ?? $this->defaultPageType($kind, $normalizedSlug));
        $publicPath = $this->publicPathFor($normalizedSlug, $kind);
        $canonicalPath = $this->nullableString($validated['canonical_path'] ?? null) ?? $publicPath;

        $page = $existing ?? new ContentPage([
            'org_id' => $orgId,
            'slug' => $normalizedSlug,
            'locale' => (string) $validated['locale'],
        ]);

        $page->fill([
            'path' => $publicPath,
            'kind' => $kind,
            'page_type' => $pageType,
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
            'review_state' => (string) ($validated['review_state'] ?? 'draft'),
            'owner' => $this->nullableString($validated['owner'] ?? null),
            'legal_review_required' => (bool) ($validated['legal_review_required'] ?? false),
            'science_review_required' => (bool) ($validated['science_review_required'] ?? false),
            'last_reviewed_at' => $validated['last_reviewed_at'] ?? null,
            'headings_json' => $this->extractHeadings($contentMd),
            'content_md' => $contentMd,
            'content_html' => $contentHtml,
            'seo_title' => $this->nullableString($validated['seo_title'] ?? null),
            'meta_description' => $this->nullableString($validated['meta_description'] ?? null),
            'seo_description' => $this->nullableString($validated['seo_description'] ?? null) ?? $this->nullableString($validated['meta_description'] ?? null),
            'canonical_path' => $canonicalPath,
            'status' => (string) ($validated['status'] ?? ((bool) $validated['is_public'] ? ContentPage::STATUS_PUBLISHED : ContentPage::STATUS_DRAFT)),
        ]);
        $page->save();

        $payload = [
            'title' => trim((string) $validated['title']),
            'summary' => $this->nullableString($validated['summary'] ?? null),
            'body_md' => $contentMd,
            'body_html' => $contentHtml,
            'seo_title' => $this->nullableString($validated['seo_title'] ?? null),
            'seo_description' => $this->nullableString($validated['seo_description'] ?? null) ?? $this->nullableString($validated['meta_description'] ?? null),
            'path' => $publicPath,
            'kind' => $kind,
            'page_type' => $pageType,
            'kicker' => $this->nullableString($validated['kicker'] ?? null),
            'template' => (string) $validated['template'],
            'animation_profile' => (string) $validated['animation_profile'],
            'owner' => $this->nullableString($validated['owner'] ?? null),
            'legal_review_required' => (bool) ($validated['legal_review_required'] ?? false),
            'science_review_required' => (bool) ($validated['science_review_required'] ?? false),
            'source_doc' => $this->nullableString($validated['source_doc'] ?? null),
            'headings_json' => $this->extractHeadings($contentMd),
            'meta_description' => $this->nullableString($validated['meta_description'] ?? null),
            'canonical_path' => $canonicalPath,
            'is_public' => (bool) $validated['is_public'],
            'is_indexable' => (bool) $validated['is_indexable'],
        ];
        $status = (string) ($validated['status'] ?? ((bool) $validated['is_public'] ? ContentPage::STATUS_PUBLISHED : ContentPage::STATUS_DRAFT));
        $reviewState = (string) ($validated['review_state'] ?? 'draft');
        $revisionStatus = $this->revisionStatus($status, $reviewState, $page->isSourceContent());
        $page = $this->workspace->saveWorkingDraft(
            'content_page',
            $page,
            $payload,
            $revisionStatus,
            [
                'org_id' => SiblingTranslationWorkflowService::PUBLIC_EDITORIAL_ORG_ID,
                'status' => $status,
                'review_state' => $reviewState,
                'published_at' => $validated['published_at'] ?? null,
                'source_updated_at' => $validated['updated_at'] ?? null,
                'effective_at' => $validated['effective_at'] ?? null,
                'last_reviewed_at' => $validated['last_reviewed_at'] ?? null,
            ],
        );
        if ($status === ContentPage::STATUS_PUBLISHED && (bool) $validated['is_public']) {
            $page = $this->workspace->publishWorkingRevision('content_page', $page);
        }

        $shouldDispatchRelease = ContentReleaseAudit::shouldDispatchPublishedFollowUp('content_page', $page, [
            'title',
            'kicker',
            'summary',
            'content_md',
            'content_html',
            'seo_title',
            'seo_description',
            'meta_description',
            'kind',
            'page_type',
            'template',
            'animation_profile',
        ]);
        if ($shouldDispatchRelease) {
            ContentReleaseAudit::log('content_page', $page->fresh(), 'content_page_internal_update');
        }

        return response()->json([
            'ok' => true,
            'page' => $this->pagePayload($this->workspace->editorRecord('content_page', $page)),
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
            'page_type' => (string) ($page->page_type ?: $this->defaultPageType((string) $page->kind, (string) $page->slug)),
            'title' => (string) $page->title,
            'kicker' => $page->kicker,
            'summary' => $page->summary,
            'template' => (string) $page->template,
            'animation_profile' => (string) $page->animation_profile,
            'locale' => (string) $page->locale,
            'status' => (string) $page->status,
            'review_state' => (string) ($page->review_state ?: 'draft'),
            'owner' => $page->owner,
            'legal_review_required' => (bool) $page->legal_review_required,
            'science_review_required' => (bool) $page->science_review_required,
            'last_reviewed_at' => $this->dateString($page->last_reviewed_at),
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
            'seo_description' => $page->seo_description ?: $page->meta_description,
            'canonical_path' => $page->canonical_path ?: (string) $page->path,
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
            'page_type',
            'title',
            'kicker',
            'summary',
            'template',
            'animation_profile',
            'locale',
            'status',
            'review_state',
            'owner',
            'legal_review_required',
            'science_review_required',
            'last_reviewed_at',
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

    private function publicPathFor(string $slug, string $kind): string
    {
        $normalizedSlug = strtolower(trim($slug));
        if ($kind === ContentPage::KIND_HELP && str_starts_with($normalizedSlug, 'help-')) {
            return '/help/'.substr($normalizedSlug, 5);
        }

        return '/'.$normalizedSlug;
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

    private function defaultPageType(string $kind, string $slug): string
    {
        return match ($slug) {
            'privacy' => 'privacy',
            'terms' => 'terms',
            'refund' => 'refund',
            'about' => 'about',
            default => $kind === ContentPage::KIND_HELP
                ? 'support_static'
                : ($kind === ContentPage::KIND_POLICY ? 'policy' : 'company'),
        };
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

    private function revisionStatus(string $status, string $reviewState, bool $isSource): string
    {
        if ($isSource) {
            return ContentPage::TRANSLATION_STATUS_SOURCE;
        }
        if ($status === ContentPage::STATUS_PUBLISHED) {
            return ContentPage::TRANSLATION_STATUS_PUBLISHED;
        }
        if ($reviewState === 'approved') {
            return ContentPage::TRANSLATION_STATUS_APPROVED;
        }
        if ($reviewState !== 'draft') {
            return ContentPage::TRANSLATION_STATUS_HUMAN_REVIEW;
        }

        return ContentPage::TRANSLATION_STATUS_DRAFT;
    }
}
