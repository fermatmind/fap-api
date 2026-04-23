<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Cms;

use App\Filament\Ops\Support\ContentReleaseAudit;
use App\Http\Controllers\Controller;
use App\Models\InterpretationGuide;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class InterpretationGuideController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $this->validateReadQuery($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $query = InterpretationGuide::query()
            ->withoutGlobalScopes()
            ->where('org_id', $validated['org_id'])
            ->where('locale', $validated['locale'])
            ->published()
            ->orderBy('test_family')
            ->orderBy('result_context')
            ->orderBy('id');

        return response()->json([
            'ok' => true,
            'items' => $query->get()->map(fn (InterpretationGuide $guide): array => $this->payload($guide))->values()->all(),
            'search_scope' => [
                'included_models' => ['interpretation_guides'],
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

        $guide = InterpretationGuide::query()
            ->withoutGlobalScopes()
            ->where('org_id', $validated['org_id'])
            ->where('slug', $this->normalizeSlug($slug))
            ->where('locale', $validated['locale'])
            ->published()
            ->first();

        if (! $guide instanceof InterpretationGuide) {
            return response()->json([
                'ok' => false,
                'error_code' => 'NOT_FOUND',
                'message' => 'interpretation guide not found.',
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'guide' => $this->payload($guide),
        ]);
    }

    public function internalIndex(Request $request): JsonResponse
    {
        $validated = $this->validateReadQuery($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $query = InterpretationGuide::query()
            ->withoutGlobalScopes()
            ->where('org_id', $validated['org_id'])
            ->where('locale', $validated['locale'])
            ->orderBy('test_family')
            ->orderBy('result_context')
            ->orderBy('id');

        return response()->json([
            'ok' => true,
            'items' => $query->get()->map(fn (InterpretationGuide $guide): array => $this->payload($guide))->values()->all(),
        ]);
    }

    public function internalShow(Request $request, string $slug): JsonResponse
    {
        $validated = $this->validateReadQuery($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $guide = InterpretationGuide::query()
            ->withoutGlobalScopes()
            ->where('org_id', $validated['org_id'])
            ->where('slug', $this->normalizeSlug($slug))
            ->where('locale', $validated['locale'])
            ->first();

        if (! $guide instanceof InterpretationGuide) {
            return response()->json([
                'ok' => false,
                'error_code' => 'NOT_FOUND',
                'message' => 'interpretation guide not found.',
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'guide' => $this->payload($guide),
        ]);
    }

    public function internalUpdate(Request $request, string $slug): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'body_md' => ['nullable', 'string'],
            'body_html' => ['nullable', 'string'],
            'test_family' => ['required', Rule::in(InterpretationGuide::TEST_FAMILIES)],
            'result_context' => ['required', Rule::in(InterpretationGuide::RESULT_CONTEXTS)],
            'audience' => ['nullable', 'string', 'max:96'],
            'locale' => ['required', 'string', Rule::in(['en', 'zh-CN'])],
            'status' => ['required', Rule::in([
                InterpretationGuide::STATUS_DRAFT,
                InterpretationGuide::STATUS_SCHEDULED,
                InterpretationGuide::STATUS_PUBLISHED,
                InterpretationGuide::STATUS_ARCHIVED,
            ])],
            'review_state' => ['required', Rule::in([
                InterpretationGuide::REVIEW_DRAFT,
                InterpretationGuide::REVIEW_CONTENT,
                InterpretationGuide::REVIEW_SCIENCE_OR_PRODUCT,
                InterpretationGuide::REVIEW_APPROVED,
                InterpretationGuide::REVIEW_CHANGES_REQUESTED,
            ])],
            'related_guide_ids' => ['nullable', 'array'],
            'related_guide_ids.*' => ['integer', 'min:1'],
            'related_methodology_page_ids' => ['nullable', 'array'],
            'related_methodology_page_ids.*' => ['integer', 'min:1'],
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
            in_array($validated['status'], [InterpretationGuide::STATUS_SCHEDULED, InterpretationGuide::STATUS_PUBLISHED], true)
            && $validated['review_state'] !== InterpretationGuide::REVIEW_APPROVED
        ) {
            return response()->json([
                'ok' => false,
                'error_code' => 'VALIDATION_FAILED',
                'errors' => ['review_state' => ['scheduled or published interpretation guides must be approved.']],
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

        $guide = InterpretationGuide::query()
            ->withoutGlobalScopes()
            ->firstOrNew([
                'org_id' => $orgId,
                'slug' => $normalizedSlug,
                'locale' => (string) $validated['locale'],
            ]);

        $guide->fill([
            'title' => trim((string) $validated['title']),
            'summary' => $this->nullableString($validated['summary'] ?? null),
            'body_md' => $bodyMd,
            'body_html' => $bodyHtml,
            'test_family' => (string) $validated['test_family'],
            'result_context' => (string) $validated['result_context'],
            'audience' => $this->nullableString($validated['audience'] ?? null) ?? 'general',
            'status' => (string) $validated['status'],
            'review_state' => (string) $validated['review_state'],
            'related_guide_ids' => array_values((array) ($validated['related_guide_ids'] ?? [])),
            'related_methodology_page_ids' => array_values((array) ($validated['related_methodology_page_ids'] ?? [])),
            'last_reviewed_at' => $validated['last_reviewed_at'] ?? null,
            'published_at' => $validated['published_at'] ?? null,
            'seo_title' => $this->nullableString($validated['seo_title'] ?? null),
            'seo_description' => $this->nullableString($validated['seo_description'] ?? null),
            'canonical_path' => $this->nullableString($validated['canonical_path'] ?? null) ?? '/support/guides/'.$normalizedSlug,
        ]);
        $guide->save();
        $shouldDispatchRelease = ContentReleaseAudit::shouldDispatchPublishedFollowUp('interpretation_guide', $guide, [
            'title',
            'summary',
            'body_md',
            'body_html',
            'seo_title',
            'seo_description',
            'test_family',
            'result_context',
            'audience',
        ]);
        if ($shouldDispatchRelease) {
            ContentReleaseAudit::log('interpretation_guide', $guide->fresh(), 'interpretation_guide_internal_update');
        }

        return response()->json([
            'ok' => true,
            'guide' => $this->payload($guide),
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
    private function payload(InterpretationGuide $guide): array
    {
        return [
            'id' => (int) $guide->id,
            'slug' => (string) $guide->slug,
            'title' => (string) $guide->title,
            'summary' => $guide->summary,
            'body_md' => (string) ($guide->body_md ?? ''),
            'body_html' => (string) ($guide->body_html ?? ''),
            'test_family' => (string) $guide->test_family,
            'result_context' => (string) $guide->result_context,
            'audience' => (string) $guide->audience,
            'locale' => (string) $guide->locale,
            'status' => (string) $guide->status,
            'review_state' => (string) $guide->review_state,
            'related_guide_ids' => is_array($guide->related_guide_ids) ? $guide->related_guide_ids : [],
            'related_methodology_page_ids' => is_array($guide->related_methodology_page_ids) ? $guide->related_methodology_page_ids : [],
            'last_reviewed_at' => $this->dateString($guide->last_reviewed_at),
            'published_at' => $this->dateString($guide->published_at),
            'updated_at' => $this->dateString($guide->updated_at),
            'seo_title' => $guide->seo_title,
            'seo_description' => $guide->seo_description,
            'canonical_path' => $guide->canonical_path ?: '/support/guides/'.(string) $guide->slug,
            'searchable_model' => 'interpretation_guides',
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
