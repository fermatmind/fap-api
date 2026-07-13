<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Cms;

use App\Http\Controllers\Controller;
use App\Models\PersonalityPublicContentAsset;
use App\Support\PublicSeoTitleNormalizer;
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
            return $this->notFoundResponse();
        }

        return response()->json([
            'ok' => true,
            'asset' => $this->assetPayload($asset),
            'personality_public_content_asset_v1' => $this->assetPayload($asset),
        ]);
    }

    public function showByCode(Request $request, string $framework, string $entityType, string $code): JsonResponse
    {
        $validated = $this->validateReadQuery($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $normalizedFramework = PersonalityPublicContentAsset::normalizeToken($framework);
        $normalizedEntityType = PersonalityPublicContentAsset::normalizeToken($entityType);
        $normalizedCode = PersonalityPublicContentAsset::normalizeEntityKey($code);

        if (! in_array($normalizedFramework, PersonalityPublicContentAsset::FRAMEWORKS, true)) {
            return $this->notFoundResponse();
        }

        if (! in_array($normalizedEntityType, PersonalityPublicContentAsset::ENTITY_TYPES, true)) {
            return $this->notFoundResponse();
        }

        $asset = PersonalityPublicContentAsset::query()
            ->withoutGlobalScopes()
            ->where('org_id', $validated['org_id'])
            ->where('framework', $normalizedFramework)
            ->where('entity_type', $normalizedEntityType)
            ->where('entity_key', $normalizedCode)
            ->forLocale($validated['locale'])
            ->publiclyReadable()
            ->first();

        if (! $asset instanceof PersonalityPublicContentAsset) {
            return $this->notFoundResponse();
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
        $contentSections = is_array($asset->content_sections_json) ? $asset->content_sections_json : [];
        $canonical = is_array($asset->canonical_json) ? $asset->canonical_json : [];
        $schemaRuntimeEligible = $this->isSchemaRuntimeEligible($asset);
        $seo = is_array($asset->seo_json) ? $asset->seo_json : [];
        $title = (string) $asset->title;
        if ((string) $asset->framework === PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE) {
            $title = PublicSeoTitleNormalizer::withoutTrailingBrand($title);
            $seo = PublicSeoTitleNormalizer::normalizeSeoPayload($seo);
        }

        return [
            'id' => (int) $asset->id,
            'org_id' => (int) $asset->org_id,
            'contract_version' => (string) $asset->contract_version,
            'framework' => (string) $asset->framework,
            'entity_type' => (string) $asset->entity_type,
            'code' => (string) $asset->entity_key,
            'entity_key' => (string) $asset->entity_key,
            'slug' => (string) $asset->slug,
            'locale' => (string) $asset->locale,
            'title' => $title,
            'summary' => $asset->summary,
            'sections' => $contentSections,
            'content_sections' => $contentSections,
            'seo' => $seo,
            'robots' => PersonalityPublicContentAsset::normalizeRobots((string) $asset->robots),
            'canonical_path' => (string) data_get($canonical, 'path', ''),
            'canonical' => $canonical,
            'hreflang' => is_array($asset->hreflang_json) ? $asset->hreflang_json : [],
            'faq' => $this->canonicalFaq(is_array($asset->faq_json) ? $asset->faq_json : []),
            'media' => is_array($asset->media_json) ? $asset->media_json : [],
            'schema' => $schemaRuntimeEligible && is_array($asset->schema_json) ? $asset->schema_json : [],
            'schema_runtime_eligible' => $schemaRuntimeEligible,
            'method_boundary' => is_array($asset->method_boundary_json) ? $asset->method_boundary_json : [],
            'evidence_notes' => is_array($asset->evidence_notes_json) ? $asset->evidence_notes_json : [],
            'internal_links' => $this->canonicalInternalLinks(
                is_array($asset->internal_links_json) ? $asset->internal_links_json : []
            ),
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

    /**
     * @param  array<int,mixed>  $items
     * @return list<array<string,mixed>>
     */
    private function canonicalFaq(array $items): array
    {
        $canonical = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $question = $this->firstNonEmptyString($item['question'] ?? null, $item['q'] ?? null);
            $answer = $this->firstNonEmptyString($item['answer'] ?? null, $item['a'] ?? null);
            if ($question === null || $answer === null) {
                continue;
            }

            unset($item['q'], $item['a']);
            $item['question'] = $question;
            $item['answer'] = $answer;
            $canonical[] = $item;
        }

        return $canonical;
    }

    /**
     * @param  array<int,mixed>  $items
     * @return list<array<string,mixed>>
     */
    private function canonicalInternalLinks(array $items): array
    {
        $canonical = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $label = $this->firstNonEmptyString($item['label'] ?? null);
            $href = $this->canonicalInternalHref(
                $this->firstNonEmptyString($item['href'] ?? null, $item['url'] ?? null)
            );
            if ($label === null || $href === null) {
                continue;
            }

            unset($item['url']);
            $item['label'] = $label;
            $item['href'] = $href;
            $canonical[] = $item;
        }

        return $canonical;
    }

    private function firstNonEmptyString(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (! is_string($value) && ! is_numeric($value)) {
                continue;
            }

            $normalized = trim((string) $value);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    private function canonicalInternalHref(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $withoutControlCharacters = preg_replace('/[\x00-\x1F\x7F-\x9F]/u', '', (string) $value);
        if (! is_string($withoutControlCharacters)) {
            return null;
        }

        $href = trim($withoutControlCharacters);
        $compact = strtolower((string) preg_replace('/\s+/', '', $href));
        if ($href === '' || preg_match('/[<>\\\\]/', $href) === 1 || str_starts_with($compact, '//')) {
            return null;
        }

        if (str_starts_with($href, '#')) {
            return preg_match('/^#[A-Za-z0-9][\w:.-]{0,127}$/', $href) === 1 ? $href : null;
        }

        $parts = parse_url($href);
        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        if (str_starts_with($href, '/')) {
            if (isset($parts['scheme']) || isset($parts['host'])) {
                return null;
            }

            return $this->pathFromUrlParts($parts);
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if (! in_array($scheme, ['http', 'https'], true)
            || ! in_array($host, ['fermatmind.com', 'www.fermatmind.com'], true)) {
            return null;
        }

        return $this->pathFromUrlParts($parts);
    }

    /**
     * @param  array<string,mixed>  $parts
     */
    private function pathFromUrlParts(array $parts): ?string
    {
        $path = (string) ($parts['path'] ?? '/');
        if (! str_starts_with($path, '/')) {
            return null;
        }

        return $path
            .(isset($parts['query']) ? '?'.(string) $parts['query'] : '')
            .(isset($parts['fragment']) ? '#'.(string) $parts['fragment'] : '');
    }

    private function isSchemaRuntimeEligible(PersonalityPublicContentAsset $asset): bool
    {
        return (string) $asset->launch_state === PersonalityPublicContentAsset::LAUNCH_PUBLISHED
            && (bool) $asset->index_eligible
            && PersonalityPublicContentAsset::normalizeRobots((string) $asset->robots) === PersonalityPublicContentAsset::ROBOTS_INDEX_FOLLOW;
    }

    private function notFoundResponse(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error_code' => 'NOT_FOUND',
            'message' => 'personality content asset not found.',
        ], 404);
    }
}
