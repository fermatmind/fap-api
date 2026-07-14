<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Cms;

use App\Http\Controllers\Controller;
use App\Models\PersonalityPublicContentAsset;
use App\Services\Cms\PersonalityPublicAssetReadModelCache;
use App\Services\Cms\PersonalityPublicContentAssetContract;
use App\Support\PublicMediaUrlGuard;
use App\Support\PublicSeoTitleNormalizer;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use LengthException;
use Throwable;

final class PersonalityPublicContentAssetController extends Controller
{
    public const MAX_DETAIL_PAYLOAD_BYTES = 524288;

    private const PUBLIC_READ_CACHE_HEADER = 'X-Fermat-Public-Read-Cache';

    public function __construct(
        private readonly PersonalityPublicAssetReadModelCache $readModelCache,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $this->validateReadQuery($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $framework = (string) ($validated['framework'] ?? '');
        $entityType = (string) ($validated['entity_type'] ?? 'all');
        $selector = $this->indexSelector($validated['page'], $validated['per_page']);
        $fenceToken = $this->readModelCache->captureFence(
            'index',
            $framework,
            $entityType,
            $selector,
            $validated['locale'],
            $validated['org_id'],
        );

        try {
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

            $paginator = $query->paginate(
                $validated['per_page'],
                ['*'],
                'page',
                $validated['page'],
            );
            $assets = collect($paginator->items())
                ->filter(fn (mixed $item): bool => $item instanceof PersonalityPublicContentAsset)
                ->values()
                ->all();
            $pagination = [
                'current_page' => (int) $paginator->currentPage(),
                'per_page' => (int) $paginator->perPage(),
                'total' => (int) $paginator->total(),
                'last_page' => (int) $paginator->lastPage(),
            ];
            $version = $this->readModelCache->collectionVersion($assets, $pagination);
            $cachedRead = $this->readModelCache->read(
                'index',
                $framework,
                $entityType,
                $selector,
                $validated['locale'],
                $validated['org_id'],
                $version,
                $fenceToken,
            );
            if (is_array($cachedRead['payload'])) {
                return $this->publicReadResponse($cachedRead['payload'], $cachedRead['state']);
            }

            $payload = [
                'ok' => true,
                'items' => array_map(
                    fn (PersonalityPublicContentAsset $asset): array => $this->assetPayload($asset),
                    $assets,
                ),
                'pagination' => $pagination,
            ];
            $this->readModelCache->put(
                'index',
                $framework,
                $entityType,
                $selector,
                $validated['locale'],
                $validated['org_id'],
                $version,
                $payload,
                $fenceToken,
            );

            return $this->publicReadResponse($payload, $cachedRead['state']);
        } catch (Throwable $throwable) {
            return $this->staleResponseOrThrow(
                'index',
                $framework,
                $entityType,
                $selector,
                $validated['locale'],
                $validated['org_id'],
                $throwable,
            );
        }
    }

    public function show(Request $request, string $framework, string $slug): JsonResponse
    {
        $validated = $this->validateReadQuery($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $normalizedFramework = PersonalityPublicContentAsset::normalizeToken($framework);
        $normalizedSlug = PersonalityPublicContentAsset::normalizeSlug($slug);
        $fenceToken = $this->readModelCache->captureFence(
            'detail-slug',
            $normalizedFramework,
            'slug',
            $normalizedSlug,
            $validated['locale'],
            $validated['org_id'],
        );
        try {
            $asset = PersonalityPublicContentAsset::query()
                ->withoutGlobalScopes()
                ->where('org_id', $validated['org_id'])
                ->where('framework', $normalizedFramework)
                ->where('slug', $normalizedSlug)
                ->forLocale($validated['locale'])
                ->publiclyReadable()
                ->first();
        } catch (Throwable $throwable) {
            return $this->staleResponseOrThrow(
                'detail-slug',
                $normalizedFramework,
                'slug',
                $normalizedSlug,
                $validated['locale'],
                $validated['org_id'],
                $throwable,
            );
        }

        if (! $asset instanceof PersonalityPublicContentAsset) {
            $this->readModelCache->invalidate(
                'detail-slug',
                $normalizedFramework,
                'slug',
                $normalizedSlug,
                $validated['locale'],
                $validated['org_id'],
                false,
            );

            return $this->notFoundResponse()->header(self::PUBLIC_READ_CACHE_HEADER, 'miss');
        }

        return $this->cachedDetailResponse(
            $asset,
            'detail-slug',
            'slug',
            $normalizedSlug,
            $validated['locale'],
            $validated['org_id'],
            $fenceToken,
        );
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

        $fenceToken = $this->readModelCache->captureFence(
            'detail-code',
            $normalizedFramework,
            $normalizedEntityType,
            $normalizedCode,
            $validated['locale'],
            $validated['org_id'],
        );

        try {
            $asset = PersonalityPublicContentAsset::query()
                ->withoutGlobalScopes()
                ->where('org_id', $validated['org_id'])
                ->where('framework', $normalizedFramework)
                ->where('entity_type', $normalizedEntityType)
                ->where('entity_key', $normalizedCode)
                ->forLocale($validated['locale'])
                ->publiclyReadable()
                ->first();
        } catch (Throwable $throwable) {
            return $this->staleResponseOrThrow(
                'detail-code',
                $normalizedFramework,
                $normalizedEntityType,
                $normalizedCode,
                $validated['locale'],
                $validated['org_id'],
                $throwable,
            );
        }

        if (! $asset instanceof PersonalityPublicContentAsset) {
            $this->readModelCache->invalidate(
                'detail-code',
                $normalizedFramework,
                $normalizedEntityType,
                $normalizedCode,
                $validated['locale'],
                $validated['org_id'],
                false,
            );

            return $this->notFoundResponse()->header(self::PUBLIC_READ_CACHE_HEADER, 'miss');
        }

        return $this->cachedDetailResponse(
            $asset,
            'detail-code',
            $normalizedEntityType,
            $normalizedCode,
            $validated['locale'],
            $validated['org_id'],
            $fenceToken,
        );
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
            'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V1,
            'framework' => (string) $asset->framework,
            'entity_type' => (string) $asset->entity_type,
            'code' => (string) $asset->entity_key,
            'entity_key' => (string) $asset->entity_key,
            'slug' => (string) $asset->slug,
            'locale' => (string) $asset->locale,
            'title' => $title,
            'summary' => $asset->summary,
            'sections' => $contentSections,
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

    /** @return array<string,mixed> */
    private function detailPayload(PersonalityPublicContentAsset $asset): array
    {
        $v1 = $this->assetPayload($asset);
        $response = [
            'ok' => true,
            'personality_public_content_asset_v1' => $v1,
        ];

        if ((string) $asset->framework === PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE) {
            $response['personality_public_content_asset_v2'] = $this->assetPayloadV2(
                $asset,
                (bool) ($v1['schema_runtime_eligible'] ?? false),
            );
        }

        return $response;
    }

    /**
     * @return array<string,mixed>
     */
    private function assetPayloadV2(
        PersonalityPublicContentAsset $asset,
        bool $schemaRuntimeEligible,
    ): array {
        $authority = is_array($asset->authority_json) ? $asset->authority_json : [];
        $sources = $this->canonicalSources((array) ($authority['sources'] ?? []));
        $sourceIds = array_fill_keys(array_column($sources, 'id'), true);
        $claimMapping = $this->canonicalClaimMapping(
            (array) ($authority['claim_mapping'] ?? []),
            $sourceIds
        );
        $visibleEvidenceEligible = ($authority['visible_evidence_eligible'] ?? false) === true
            && $sources !== []
            && $claimMapping !== [];
        $schemaEligible = ($authority['schema_eligible'] ?? false) === true
            && $visibleEvidenceEligible
            && $schemaRuntimeEligible;

        return [
            'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V2,
            'compatible_v1_contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V1,
            'visible_evidence' => [
                'sources' => $sources,
                'claim_mapping' => $claimMapping,
                'limitations' => $this->canonicalStringList((array) ($authority['limitations'] ?? [])),
                'eligible' => $visibleEvidenceEligible,
            ],
            'editorial_authority' => [
                'author' => $this->canonicalEditorialActor($authority['author'] ?? null),
                'reviewer' => $this->canonicalEditorialActor($authority['reviewer'] ?? null),
                'review_state' => (string) $asset->review_state,
                'last_reviewed_at' => $asset->last_reviewed_at?->toAtomString(),
                'published_at' => $asset->published_at?->toAtomString(),
                'updated_at' => $asset->updated_at?->toAtomString(),
            ],
            'media_authority' => $this->canonicalMediaAuthority(
                is_array($asset->media_json) ? $asset->media_json : []
            ),
            'schema_eligible' => $schemaEligible,
        ];
    }

    /**
     * @param  array<int,mixed>  $items
     * @return list<array<string,mixed>>
     */
    private function canonicalSources(array $items): array
    {
        $sources = [];
        $seen = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = $this->firstNonEmptyString($item['id'] ?? null);
            $title = $this->firstNonEmptyString($item['title'] ?? null);
            $authorOrOrganization = $this->firstNonEmptyString($item['author_or_organization'] ?? null);
            $sourceType = $this->firstNonEmptyString($item['source_type'] ?? null);
            $year = (int) ($item['year'] ?? 0);
            if (
                $id === null
                || isset($seen[$id])
                || $title === null
                || $authorOrOrganization === null
                || ! in_array($sourceType, PersonalityPublicContentAssetContract::SOURCE_TYPES, true)
                || $year < 1800
                || $year > (int) now()->year
            ) {
                continue;
            }

            $publicUrl = $this->publicHttpsUrl($item['public_url'] ?? null);
            $doi = $this->firstNonEmptyString($item['doi'] ?? null);
            if ($doi !== null && preg_match('/^10\.\d{4,9}\/[\-._;()\/:a-z0-9]+$/i', $doi) !== 1) {
                $doi = null;
            }

            $seen[$id] = true;
            $sources[] = [
                'id' => $id,
                'title' => $title,
                'author_or_organization' => $authorOrOrganization,
                'year' => $year,
                'source_type' => $sourceType,
                'doi' => $doi,
                'public_url' => $publicUrl,
                'accessed_at' => $this->dateValue($item['accessed_at'] ?? null),
                'claim_ids' => $this->canonicalStringList((array) ($item['claim_ids'] ?? [])),
                'limitation' => $this->firstNonEmptyString($item['limitation'] ?? null),
            ];
        }

        return $sources;
    }

    /**
     * @param  array<int,mixed>  $items
     * @param  array<string,bool>  $sourceIds
     * @return list<array<string,mixed>>
     */
    private function canonicalClaimMapping(array $items, array $sourceIds): array
    {
        $mapping = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $claimId = $this->firstNonEmptyString($item['claim_id'] ?? null);
            $resolvedSourceIds = array_values(array_filter(
                $this->canonicalStringList((array) ($item['source_ids'] ?? [])),
                static fn (string $sourceId): bool => isset($sourceIds[$sourceId])
            ));
            if ($claimId === null || $resolvedSourceIds === []) {
                continue;
            }

            $mapping[] = [
                'claim_id' => $claimId,
                'source_ids' => $resolvedSourceIds,
                'limitation' => $this->firstNonEmptyString($item['limitation'] ?? null),
            ];
        }

        return $mapping;
    }

    /** @return array<string,string|null>|null */
    private function canonicalEditorialActor(mixed $actor): ?array
    {
        if (! is_array($actor)) {
            return null;
        }

        $name = $this->firstNonEmptyString($actor['name'] ?? null);
        if ($name === null) {
            return null;
        }

        return [
            'name' => $name,
            'organization' => $this->firstNonEmptyString($actor['organization'] ?? null),
            'role' => $this->firstNonEmptyString($actor['role'] ?? null),
        ];
    }

    /**
     * @param  array<string,mixed>  $media
     * @return array{hero:array<string,mixed>|null,inline:list<array<string,mixed>>,og:array<string,mixed>|null}
     */
    private function canonicalMediaAuthority(array $media): array
    {
        $hero = $this->canonicalMediaRecord($media['hero'] ?? null);
        if ($hero === null && (isset($media['image_url']) || isset($media['alt']))) {
            $hero = $this->canonicalMediaRecord([
                'url' => $media['image_url'] ?? null,
                'alt' => $media['alt'] ?? null,
            ]);
        }

        $inline = [];
        foreach ((array) ($media['inline'] ?? []) as $item) {
            $record = $this->canonicalMediaRecord($item);
            if ($record !== null) {
                $inline[] = $record;
            }
        }

        return [
            'hero' => $hero,
            'inline' => $inline,
            'og' => $this->canonicalMediaRecord($media['og'] ?? null),
        ];
    }

    /** @return array<string,mixed>|null */
    private function canonicalMediaRecord(mixed $record): ?array
    {
        if (! is_array($record)) {
            return null;
        }

        $url = PublicMediaUrlGuard::sanitizeNullableUrl($record['url'] ?? null);
        $mediaAssetId = max(0, (int) ($record['media_asset_id'] ?? 0));
        $alt = $this->firstNonEmptyString($record['alt'] ?? null);
        if (($url === null && $mediaAssetId === 0) || $alt === null) {
            return null;
        }

        return [
            'media_asset_id' => $mediaAssetId > 0 ? $mediaAssetId : null,
            'url' => $url,
            'alt' => $alt,
        ];
    }

    /** @param array<int,mixed> $items @return list<string> */
    private function canonicalStringList(array $items): array
    {
        $values = [];
        foreach ($items as $item) {
            $value = $this->firstNonEmptyString($item);
            if ($value !== null) {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values));
    }

    private function publicHttpsUrl(mixed $value): ?string
    {
        $url = $this->firstNonEmptyString($value);
        if ($url === null || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);
        if (
            ! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || (int) ($parts['port'] ?? 443) !== 443
        ) {
            return null;
        }

        $host = strtolower(trim((string) ($parts['host'] ?? '')));
        if (
            $host === ''
            || in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || (
                filter_var($host, FILTER_VALIDATE_IP) !== false
                && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
            )
            || (
                filter_var($host, FILTER_VALIDATE_IP) === false
                && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            )
        ) {
            return null;
        }

        return $url;
    }

    private function dateValue(mixed $value): ?string
    {
        $date = $this->firstNonEmptyString($value);
        if ($date === null) {
            return null;
        }

        try {
            return (new DateTimeImmutable($date))->format(DATE_ATOM);
        } catch (\Throwable) {
            return null;
        }
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

    private function cachedDetailResponse(
        PersonalityPublicContentAsset $asset,
        string $surface,
        string $entityType,
        string $selector,
        string $locale,
        int $orgId,
        string $fenceToken,
    ): JsonResponse {
        $framework = (string) $asset->framework;

        try {
            $version = $this->readModelCache->versionFor($asset);
            $cachedRead = $this->readModelCache->read(
                $surface,
                $framework,
                $entityType,
                $selector,
                $locale,
                $orgId,
                $version,
                $fenceToken,
            );
            if (is_array($cachedRead['payload'])) {
                return $this->publicReadResponse($cachedRead['payload'], $cachedRead['state']);
            }

            $payload = $this->detailPayload($asset);
            if (! $this->detailPayloadWithinBudget($payload)) {
                return $this->staleResponseOrThrow(
                    $surface,
                    $framework,
                    $entityType,
                    $selector,
                    $locale,
                    $orgId,
                    new LengthException('personality content asset detail payload exceeds budget.'),
                );
            }
            $this->readModelCache->put(
                $surface,
                $framework,
                $entityType,
                $selector,
                $locale,
                $orgId,
                $version,
                $payload,
                $fenceToken,
            );

            return $this->publicReadResponse($payload, $cachedRead['state']);
        } catch (Throwable $throwable) {
            return $this->staleResponseOrThrow(
                $surface,
                $framework,
                $entityType,
                $selector,
                $locale,
                $orgId,
                $throwable,
            );
        }
    }

    /** @param array<string,mixed> $payload */
    private function publicReadResponse(array $payload, string $cacheState): JsonResponse
    {
        $canonicalPayload = $this->canonicalPublicReadPayload($payload);
        if (isset($canonicalPayload['personality_public_content_asset_v1'])
            && ! $this->detailPayloadWithinBudget($canonicalPayload)) {
            return $this->payloadBudgetExceededResponse($cacheState);
        }

        return response()->json($canonicalPayload)
            ->header(self::PUBLIC_READ_CACHE_HEADER, $cacheState);
    }

    /** @param array<string,mixed> $payload */
    private function detailPayloadWithinBudget(array $payload): bool
    {
        return strlen((string) response()->json($payload)->getContent())
            <= self::MAX_DETAIL_PAYLOAD_BYTES;
    }

    private function payloadBudgetExceededResponse(string $cacheState): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error_code' => 'PUBLIC_PAYLOAD_BUDGET_EXCEEDED',
            'message' => 'personality content asset temporarily unavailable.',
        ], 503)
            ->header(self::PUBLIC_READ_CACHE_HEADER, $cacheState)
            ->header('Retry-After', '60');
    }

    /**
     * Normalize active/LKG entries written by the previous response projection.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function canonicalPublicReadPayload(array $payload): array
    {
        unset($payload['asset']);

        if (is_array($payload['personality_public_content_asset_v1'] ?? null)) {
            unset($payload['personality_public_content_asset_v1']['content_sections']);
        }

        if (is_array($payload['personality_public_content_asset_v2'] ?? null)) {
            $payload['personality_public_content_asset_v2'] = array_intersect_key(
                $payload['personality_public_content_asset_v2'],
                array_fill_keys([
                    'contract_version',
                    'compatible_v1_contract_version',
                    'visible_evidence',
                    'editorial_authority',
                    'media_authority',
                    'schema_eligible',
                ], true),
            );
        }

        if (is_array($payload['items'] ?? null)) {
            foreach ($payload['items'] as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }

                unset($item['content_sections']);
                $payload['items'][$index] = $item;
            }
        }

        return $payload;
    }

    private function staleResponseOrThrow(
        string $surface,
        string $framework,
        string $entityType,
        string $selector,
        string $locale,
        int $orgId,
        Throwable $throwable,
    ): JsonResponse {
        $staleRead = $this->readModelCache->stale(
            $surface,
            $framework,
            $entityType,
            $selector,
            $locale,
            $orgId,
        );
        if (is_array($staleRead['payload'])) {
            return $this->publicReadResponse($staleRead['payload'], $staleRead['state']);
        }

        if ($throwable instanceof LengthException) {
            return $this->payloadBudgetExceededResponse($staleRead['state']);
        }

        throw $throwable;
    }

    private function indexSelector(int $page, int $perPage): string
    {
        return 'page:'.max(1, $page).':per-page:'.max(1, min(100, $perPage));
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
