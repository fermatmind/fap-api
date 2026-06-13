<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Cms;

use App\Http\Controllers\Controller;
use App\Models\PersonalityPublicContentAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class PersonalityPublicContentAssetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $this->validateReadQuery($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $query = PersonalityPublicContentAsset::query()
            ->withoutGlobalScopes()
            ->where('org_id', $validated['org_id'])
            ->forLocale($validated['locale'])
            ->publiclyReadable()
            ->orderBy('framework')
            ->orderBy('entity_type')
            ->orderBy('entity_key');

        if ($validated['framework'] !== null) {
            $query->where('framework', $validated['framework']);
        }

        if ($validated['entity_type'] !== null) {
            $query->where('entity_type', $validated['entity_type']);
        }

        $perPage = $validated['per_page'];
        $paginator = $query->paginate($perPage, ['*'], 'page', $validated['page']);

        return response()->json([
            'ok' => true,
            'items' => collect($paginator->items())
                ->filter(fn (mixed $item): bool => $item instanceof PersonalityPublicContentAsset)
                ->map(fn (PersonalityPublicContentAsset $asset): array => $this->assetPayload($asset))
                ->values()
                ->all(),
            'pagination' => [
                'current_page' => (int) $paginator->currentPage(),
                'per_page' => (int) $paginator->perPage(),
                'total' => (int) $paginator->total(),
                'last_page' => (int) $paginator->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, string $framework, string $slug): JsonResponse
    {
        $validated = $this->validateReadQuery($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $asset = PersonalityPublicContentAsset::query()
            ->withoutGlobalScopes()
            ->where('org_id', $validated['org_id'])
            ->where('framework', PersonalityPublicContentAsset::normalizeToken($framework))
            ->where('slug', PersonalityPublicContentAsset::normalizeSlug($slug))
            ->forLocale($validated['locale'])
            ->publiclyReadable()
            ->first();

        if (! $asset instanceof PersonalityPublicContentAsset) {
            return response()->json([
                'ok' => false,
                'error_code' => 'NOT_FOUND',
                'message' => 'personality content asset not found.',
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'asset' => $this->assetPayload($asset),
            'personality_public_content_asset_v1' => $this->assetPayload($asset),
        ]);
    }

    private function validateReadQuery(Request $request): array|JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'org_id' => ['nullable', 'integer', 'min:0'],
            'locale' => ['nullable', Rule::in(['en', 'zh', 'zh-CN'])],
            'framework' => ['nullable', Rule::in(PersonalityPublicContentAsset::FRAMEWORKS)],
            'entity_type' => ['nullable', Rule::in(PersonalityPublicContentAsset::ENTITY_TYPES)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
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
            'org_id' => max(0, (int) ($validated['org_id'] ?? 0)),
            'locale' => PersonalityPublicContentAsset::normalizeLocale((string) ($validated['locale'] ?? 'en')),
            'framework' => isset($validated['framework'])
                ? PersonalityPublicContentAsset::normalizeToken((string) $validated['framework'])
                : null,
            'entity_type' => isset($validated['entity_type'])
                ? PersonalityPublicContentAsset::normalizeToken((string) $validated['entity_type'])
                : null,
            'page' => max(1, (int) ($validated['page'] ?? 1)),
            'per_page' => max(1, min(100, (int) ($validated['per_page'] ?? 50))),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function assetPayload(PersonalityPublicContentAsset $asset): array
    {
        return [
            'id' => (int) $asset->id,
            'org_id' => (int) $asset->org_id,
            'contract_version' => (string) $asset->contract_version,
            'framework' => (string) $asset->framework,
            'entity_type' => (string) $asset->entity_type,
            'entity_key' => (string) $asset->entity_key,
            'slug' => (string) $asset->slug,
            'locale' => (string) $asset->locale,
            'title' => (string) $asset->title,
            'summary' => $asset->summary,
            'content_sections' => is_array($asset->content_sections_json) ? $asset->content_sections_json : [],
            'seo' => is_array($asset->seo_json) ? $asset->seo_json : [],
            'canonical' => is_array($asset->canonical_json) ? $asset->canonical_json : [],
            'hreflang' => is_array($asset->hreflang_json) ? $asset->hreflang_json : [],
            'faq' => is_array($asset->faq_json) ? $asset->faq_json : [],
            'media' => is_array($asset->media_json) ? $asset->media_json : [],
            'schema' => is_array($asset->schema_json) ? $asset->schema_json : [],
            'method_boundary' => is_array($asset->method_boundary_json) ? $asset->method_boundary_json : [],
            'evidence_notes' => is_array($asset->evidence_notes_json) ? $asset->evidence_notes_json : [],
            'is_public' => (bool) $asset->is_public,
            'index_eligible' => (bool) $asset->index_eligible,
            'sitemap_eligible' => (bool) $asset->sitemap_eligible,
            'llms_eligible' => (bool) $asset->llms_eligible,
            'launch_state' => (string) $asset->launch_state,
            'review_state' => (string) $asset->review_state,
            'source_package' => $asset->source_package,
            'source_hash' => $asset->source_hash,
            'published_at' => $asset->published_at?->toAtomString(),
            'last_reviewed_at' => $asset->last_reviewed_at?->toAtomString(),
            'updated_at' => $asset->updated_at?->toAtomString(),
        ];
    }
}
