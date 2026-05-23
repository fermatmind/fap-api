<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Cms;

use App\Http\Controllers\Controller;
use App\Models\ResearchReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class ResearchReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $this->validateReadQuery($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $query = ResearchReport::query()
            ->withoutGlobalScopes()
            ->where('org_id', $validated['org_id'])
            ->where('locale', $validated['locale'])
            ->publiclyReadable()
            ->orderByDesc('published_at')
            ->orderByDesc('id');

        return response()->json([
            'ok' => true,
            'items' => $query->get()->map(fn (ResearchReport $report): array => $this->publicPayload($report))->values()->all(),
            'page_entity_type' => ResearchReport::PAGE_ENTITY_TYPE,
            'exposure_gate' => 'published_approved_public_indexable_only',
        ]);
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $validated = $this->validateReadQuery($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $report = ResearchReport::query()
            ->withoutGlobalScopes()
            ->where('org_id', $validated['org_id'])
            ->where('slug', $this->normalizeSlug($slug))
            ->where('locale', $validated['locale'])
            ->publiclyReadable()
            ->first();

        if (! $report instanceof ResearchReport) {
            return response()->json([
                'ok' => false,
                'error_code' => 'NOT_FOUND',
                'message' => 'research report not found.',
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'report' => $this->publicPayload($report),
        ]);
    }

    public function internalIndex(Request $request): JsonResponse
    {
        $validated = $this->validateInternalReadQuery($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $query = ResearchReport::query()
            ->withoutGlobalScopes()
            ->where('org_id', $validated['org_id'])
            ->where('locale', $validated['locale'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        return response()->json([
            'ok' => true,
            'items' => $query->get()->map(fn (ResearchReport $report): array => $this->internalPayload($report))->values()->all(),
        ]);
    }

    public function internalShow(Request $request, string $slug): JsonResponse
    {
        $validated = $this->validateInternalReadQuery($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $report = ResearchReport::query()
            ->withoutGlobalScopes()
            ->where('org_id', $validated['org_id'])
            ->where('slug', $this->normalizeSlug($slug))
            ->where('locale', $validated['locale'])
            ->first();

        if (! $report instanceof ResearchReport) {
            return response()->json([
                'ok' => false,
                'error_code' => 'NOT_FOUND',
                'message' => 'research report not found.',
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'report' => $this->internalPayload($report),
        ]);
    }

    public function internalUpdate(Request $request, string $slug): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => ['required', 'string', 'max:255'],
            'executive_summary' => ['nullable', 'string', 'max:4000'],
            'body_md' => ['nullable', 'string'],
            'research_type' => ['required', Rule::in(ResearchReport::RESEARCH_TYPES)],
            'methodology' => ['required', 'string', 'max:12000'],
            'sample_disclaimer' => ['required', 'string', 'max:4000'],
            'claim_boundary' => ['required', 'string', 'max:4000'],
            'author_name' => ['nullable', 'string', 'max:128'],
            'reviewer_name' => ['nullable', 'string', 'max:128'],
            'references' => ['nullable', 'array'],
            'references.*' => ['string', 'max:1000'],
            'downloadable_asset_placeholder' => ['nullable', 'string', 'max:255'],
            'locale' => ['required', 'string', Rule::in(['en', 'zh-CN'])],
            'status' => ['required', Rule::in([
                ResearchReport::STATUS_DRAFT,
                ResearchReport::STATUS_PUBLISHED,
                ResearchReport::STATUS_ARCHIVED,
            ])],
            'review_state' => ['required', Rule::in([
                ResearchReport::REVIEW_DRAFT,
                ResearchReport::REVIEW_RESEARCH,
                ResearchReport::REVIEW_CLAIM,
                ResearchReport::REVIEW_APPROVED,
                ResearchReport::REVIEW_CHANGES_REQUESTED,
            ])],
            'is_public' => ['sometimes', 'boolean'],
            'is_indexable' => ['sometimes', 'boolean'],
            'last_reviewed_at' => ['nullable', 'date'],
            'published_at' => ['nullable', 'date'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:2000'],
            'canonical_path' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok' => false,
                'error_code' => 'VALIDATION_FAILED',
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        $validated = $validator->validated();
        $status = (string) $validated['status'];
        $reviewState = (string) $validated['review_state'];
        $isPublic = (bool) ($validated['is_public'] ?? false);
        $isIndexable = (bool) ($validated['is_indexable'] ?? false);

        if ($status === ResearchReport::STATUS_PUBLISHED && $reviewState !== ResearchReport::REVIEW_APPROVED) {
            return response()->json([
                'ok' => false,
                'error_code' => 'VALIDATION_FAILED',
                'errors' => ['review_state' => ['published research reports must be approved.']],
            ], 422);
        }

        if ($status !== ResearchReport::STATUS_PUBLISHED && ($isPublic || $isIndexable)) {
            return response()->json([
                'ok' => false,
                'error_code' => 'VALIDATION_FAILED',
                'errors' => ['status' => ['draft or archived research reports cannot be public or indexable.']],
            ], 422);
        }

        $orgId = $this->trustedOrgId($request);
        $locale = (string) $validated['locale'];
        $normalizedSlug = $this->normalizeSlug($slug);

        $report = ResearchReport::query()
            ->withoutGlobalScopes()
            ->where('org_id', $orgId)
            ->where('slug', $normalizedSlug)
            ->where('locale', $locale)
            ->first() ?? new ResearchReport([
                'org_id' => $orgId,
                'slug' => $normalizedSlug,
                'locale' => $locale,
            ]);

        $report->fill([
            'title' => trim((string) $validated['title']),
            'executive_summary' => $this->nullableString($validated['executive_summary'] ?? null),
            'body_md' => $this->nullableString($validated['body_md'] ?? null),
            'research_type' => (string) $validated['research_type'],
            'methodology' => trim((string) $validated['methodology']),
            'sample_disclaimer' => trim((string) $validated['sample_disclaimer']),
            'claim_boundary' => trim((string) $validated['claim_boundary']),
            'author_name' => $this->nullableString($validated['author_name'] ?? null),
            'reviewer_name' => $this->nullableString($validated['reviewer_name'] ?? null),
            'references' => array_values((array) ($validated['references'] ?? [])),
            'downloadable_asset_placeholder' => $this->nullableString($validated['downloadable_asset_placeholder'] ?? null),
            'status' => $status,
            'review_state' => $reviewState,
            'is_public' => $isPublic,
            'is_indexable' => $isIndexable,
            'last_reviewed_at' => $validated['last_reviewed_at'] ?? null,
            'published_at' => $validated['published_at'] ?? null,
            'seo_title' => $this->nullableString($validated['seo_title'] ?? null),
            'seo_description' => $this->nullableString($validated['seo_description'] ?? null),
            'canonical_path' => $this->nullableString($validated['canonical_path'] ?? null) ?? '/research/'.$normalizedSlug,
        ]);
        $report->save();

        return response()->json([
            'ok' => true,
            'report' => $this->internalPayload($report),
        ]);
    }

    /**
     * @return array<string,mixed>|JsonResponse
     */
    private function validateInternalReadQuery(Request $request): array|JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'locale' => ['nullable', 'string', Rule::in(['en', 'zh-CN'])],
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
            'locale' => (string) ($validated['locale'] ?? 'en'),
            'org_id' => $this->trustedOrgId($request),
        ];
    }

    /**
     * @return array<string,mixed>|JsonResponse
     */
    private function validateReadQuery(Request $request): array|JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'locale' => ['nullable', 'string', Rule::in(['en', 'zh-CN'])],
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
            'locale' => (string) ($validated['locale'] ?? 'en'),
            'org_id' => (int) ($validated['org_id'] ?? 0),
        ];
    }

    private function trustedOrgId(Request $request): int
    {
        $candidates = [
            $request->attributes->get('fm_org_id'),
            $request->attributes->get('org_id'),
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

        return 0;
    }

    /**
     * @return array<string,mixed>
     */
    private function publicPayload(ResearchReport $report): array
    {
        return [
            'id' => (int) $report->id,
            'slug' => (string) $report->slug,
            'locale' => (string) $report->locale,
            'page_entity_type' => ResearchReport::PAGE_ENTITY_TYPE,
            'title' => (string) $report->title,
            'executive_summary' => $report->executive_summary,
            'body_md' => $report->body_md,
            'research_type' => (string) $report->research_type,
            'methodology' => $report->methodology,
            'sample_disclaimer' => $report->sample_disclaimer,
            'claim_boundary' => $report->claim_boundary,
            'author_name' => $report->author_name,
            'reviewer_name' => $report->reviewer_name,
            'references' => $report->references ?? [],
            'downloadable_asset_placeholder' => $report->downloadable_asset_placeholder,
            'last_reviewed_at' => $report->last_reviewed_at?->toAtomString(),
            'published_at' => $report->published_at?->toAtomString(),
            'seo_title' => $report->seo_title,
            'seo_description' => $report->seo_description,
            'canonical_path' => $report->canonical_path,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function internalPayload(ResearchReport $report): array
    {
        return array_merge($this->publicPayload($report), [
            'status' => (string) $report->status,
            'review_state' => (string) $report->review_state,
            'is_public' => (bool) $report->is_public,
            'is_indexable' => (bool) $report->is_indexable,
            'search_channel_eligible' => false,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
        ]);
    }

    private function normalizeSlug(string $slug): string
    {
        return strtolower(trim($slug));
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized === '' ? null : $normalized;
    }
}
