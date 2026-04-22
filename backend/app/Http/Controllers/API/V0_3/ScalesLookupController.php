<?php

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\Controller;
use App\Services\PublicSurface\LandingSurfaceContractService;
use App\Services\Scale\PublicScaleFormsProjector;
use App\Services\Scale\ScaleCodeResponseProjector;
use App\Services\Scale\ScaleIdentityResolver;
use App\Services\Scale\ScaleRegistry;
use App\Support\CacheKeys;
use App\Support\OrgContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ScalesLookupController extends Controller
{
    private const CACHE_DEBUG_HEADER = 'X-FAP-Cache';

    private const CATALOG_FALLBACK_IMAGE = 'https://api.fermatmind.com/static/share/mbti_square_600x600.png';

    public function __construct(
        private ScaleRegistry $registry,
        private ScaleIdentityResolver $identityResolver,
        private ScaleCodeResponseProjector $responseProjector,
        private PublicScaleFormsProjector $publicScaleFormsProjector,
        private OrgContext $orgContext,
        private LandingSurfaceContractService $landingSurfaceContractService,
    ) {}

    /**
     * GET /api/v0.3/scales/lookup?slug=xxx
     */
    public function lookup(Request $request): JsonResponse
    {
        $orgId = $this->orgContext->orgId();
        $requestedSlug = (string) $request->query('slug', '');
        $requestedSlug = trim(strtolower($requestedSlug));
        if ($requestedSlug === '') {
            return response()->json([
                'ok' => false,
                'error_code' => 'SLUG_REQUIRED',
                'message' => 'slug is required.',
            ], 400);
        }
        if (! preg_match('/^[a-z0-9-]{0,127}$/', $requestedSlug)) {
            return response()->json([
                'ok' => false,
                'error_code' => 'NOT_FOUND',
                'message' => 'scale not found.',
            ], 404);
        }

        $allowAlias = config('scales_lookup.alias_mode', 'compat') !== 'canonical_only';
        $requestedLocale = $this->resolveRequestedLocale(
            $request,
            (string) config('content_packs.default_locale', 'en')
        );
        // We cannot reliably derive content version before registry resolution, so keep
        // a request-shape key here and pair it with a short TTL.
        $cacheKey = CacheKeys::mbtiLookup($orgId, $requestedSlug, $requestedLocale, $allowAlias);
        $cacheStore = Cache::store((string) config('content_packs.mbti_response_cache_store', 'hot_redis'));
        $cacheTtl = max(1, (int) config('content_packs.mbti_lookup_cache_ttl_seconds', 600));
        $cached = $cacheStore->get($cacheKey);
        if (is_array($cached)) {
            $this->logCacheEvent('lookup_hit', $cacheKey, [
                'org_id' => $orgId,
                'slug' => $requestedSlug,
                'locale' => $requestedLocale,
            ]);

            return $this->cacheableJson($cached, 'hit');
        }

        $row = $this->registry->lookupBySlug($requestedSlug, $orgId, $allowAlias);
        if (! $row) {
            return response()->json([
                'ok' => false,
                'error_code' => 'NOT_FOUND',
                'message' => 'scale not found.',
            ], 404);
        }

        $legacyScaleCode = strtoupper(trim((string) ($row['code'] ?? '')));
        if (! $this->identityResolver->shouldAllowDemoScale($legacyScaleCode)) {
            return $this->deprecatedScaleResponse(
                $legacyScaleCode,
                $requestedSlug,
                trim((string) ($row['primary_slug'] ?? ''))
            );
        }

        $primarySlug = trim((string) ($row['primary_slug'] ?? ''));
        $resolvedFromAlias = $primarySlug !== '' && $requestedSlug !== $primarySlug;
        $scaleCodeMeta = $this->resolveScaleCodeMeta($row);
        $locale = $this->resolveRequestedLocale($request, (string) ($row['default_locale'] ?? 'en'));
        $seo = $this->resolveSeoByLocale($row, $locale);
        $isIndexable = $this->resolveIsIndexable($row);
        $payload = [
            'ok' => true,
            'scale_code' => $scaleCodeMeta['scale_code'],
            'scale_code_legacy' => $scaleCodeMeta['scale_code_legacy'],
            'scale_code_v2' => $scaleCodeMeta['scale_code_v2'],
            'scale_uid' => $scaleCodeMeta['scale_uid'],
            'primary_slug' => $primarySlug,
            'slug' => $primarySlug,
            'requested_slug' => $requestedSlug,
            'resolved_from_alias' => $resolvedFromAlias,
            'pack_id' => $row['default_pack_id'] ?? null,
            'dir_version' => $row['default_dir_version'] ?? null,
            'pack_id_v2' => $scaleCodeMeta['pack_id_v2'],
            'dir_version_v2' => $scaleCodeMeta['dir_version_v2'],
            'region' => $row['default_region'] ?? null,
            'locale' => $locale,
            'driver_type' => $row['driver_type'] ?? '',
            'view_policy' => $row['view_policy_json'] ?? null,
            'capabilities' => $row['capabilities_json'] ?? null,
            'commercial' => $row['commercial_json'] ?? null,
            'forms' => $this->publicScaleFormsProjector->projectForRegistryRow($row, $locale),
            'seo_title' => $seo['title'],
            'seo_description' => $seo['description'],
            'og_image_url' => $seo['og_image_url'],
            'is_indexable' => $isIndexable,
            'content_i18n_json' => $row['content_i18n_json'] ?? null,
            'report_summary_i18n_json' => $row['report_summary_i18n_json'] ?? null,
            'seo_schema_json' => $row['seo_schema_json'] ?? null,
            'seo_schema' => $row['seo_schema_json'] ?? null,
            'landing_surface_v1' => $this->buildLandingSurface(
                $primarySlug,
                $locale,
                $scaleCodeMeta['scale_code'],
                $seo,
                $isIndexable
            ),
        ];

        if (($payload['scale_code'] ?? null) === 'MBTI') {
            $cacheStore->put($cacheKey, $payload, $cacheTtl);
            $this->logCacheEvent('lookup_miss', $cacheKey, [
                'org_id' => $orgId,
                'slug' => $requestedSlug,
                'locale' => $locale,
                'ttl' => $cacheTtl,
            ]);
        }

        return $this->cacheableJson($payload, 'miss');
    }

    /**
     * GET /api/v0.3/scales/catalog?locale=en|zh
     */
    public function catalog(Request $request): JsonResponse
    {
        $locale = $this->resolveRequestedLocale(
            $request,
            (string) config('content_packs.default_locale', 'en')
        );
        $rows = $this->registry->listActivePublic(0);
        $items = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                $row = (array) $row;
            }

            $primarySlug = trim((string) ($row['primary_slug'] ?? ''));
            if ($primarySlug === '') {
                continue;
            }

            if (! $this->hasCatalogMetadata($row, $locale)) {
                continue;
            }

            $items[] = $this->projectCatalogItem($row, $locale);
        }

        usort($items, static function (array $a, array $b): int {
            $priorityA = (int) ($a['highlight_priority'] ?? 0);
            $priorityB = (int) ($b['highlight_priority'] ?? 0);
            if ($priorityA !== $priorityB) {
                return $priorityB <=> $priorityA;
            }

            return strcmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''));
        });

        return response()->json([
            'ok' => true,
            'locale' => $locale,
            'items' => $items,
        ]);
    }

    /**
     * GET /api/v0.3/scales/sitemap-source?locale=en|zh
     */
    public function sitemapSource(Request $request): JsonResponse
    {
        return app(ScalesSitemapSourceController::class)->index($request);
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array{title:?string,description:?string,og_image_url:?string}
     */
    private function resolveSeoByLocale(array $row, string $locale): array
    {
        $seoI18n = $this->toArray($row['seo_i18n_json'] ?? null);
        $lang = $this->localeToLanguage($locale);
        $defaultLocale = (string) ($row['default_locale'] ?? 'en');
        $defaultLang = $this->localeToLanguage($defaultLocale);

        $byLang = $this->toArray($seoI18n[$lang] ?? null);
        if ($byLang === [] && $defaultLang !== '') {
            $byLang = $this->toArray($seoI18n[$defaultLang] ?? null);
        }
        if ($byLang === []) {
            $byLang = $this->toArray($seoI18n['en'] ?? null);
        }

        $legacy = $this->toArray($row['seo_schema_json'] ?? null);
        $legacyOg = $this->toArray($legacy['og'] ?? null);

        $title = $this->trimOrNull($byLang['title'] ?? null)
            ?? $this->trimOrNull($legacy['title'] ?? null)
            ?? $this->trimOrNull($legacy['name'] ?? null);
        $description = $this->trimOrNull($byLang['description'] ?? null) ?? $this->trimOrNull($legacy['description'] ?? null);
        $ogImage = $this->trimOrNull($byLang['og_image_url'] ?? null)
            ?? $this->trimOrNull($legacyOg['image'] ?? null)
            ?? $this->trimOrNull($legacy['og_image_url'] ?? null);

        return [
            'title' => $title,
            'description' => $description,
            'og_image_url' => $ogImage,
        ];
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function resolveIsIndexable(array $row): bool
    {
        if (array_key_exists('is_indexable', $row)) {
            return (bool) $row['is_indexable'];
        }

        $policy = $this->toArray($row['view_policy_json'] ?? null);
        if (array_key_exists('indexable', $policy)) {
            return (bool) $policy['indexable'];
        }

        $robots = strtolower(trim((string) ($policy['robots'] ?? '')));
        if ($robots !== '' && str_contains($robots, 'noindex')) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function projectCatalogItem(array $row, string $locale): array
    {
        $scaleCodeMeta = $this->resolveScaleCodeMeta($row);
        $seo = $this->resolveSeoByLocale($row, $locale);
        $contentI18n = $this->toArray($row['content_i18n_json'] ?? null);
        $lang = $this->localeToLanguage($locale);
        $localizedContent = $this->toArray($contentI18n[$lang] ?? null);
        $englishContent = $this->toArray($contentI18n['en'] ?? null);
        $zhContent = $this->toArray($contentI18n['zh'] ?? null);
        $catalog = $this->toArray($localizedContent['catalog'] ?? null);
        $fallbackCatalog = $this->toArray($englishContent['catalog'] ?? null);
        $card = $this->toArray($localizedContent['card'] ?? null);
        $fallbackCard = $this->toArray($englishContent['card'] ?? null);
        $highlight = $this->toArray($localizedContent['highlight'] ?? null);
        $fallbackHighlight = $this->toArray($englishContent['highlight'] ?? null);
        $forms = $this->publicScaleFormsProjector->projectForRegistryRow($row, $locale);
        $defaultFormQuestionCount = $this->publicScaleFormsProjector->defaultQuestionCount($row, $locale);

        $title = $this->trimOrNull($localizedContent['title'] ?? null)
            ?? $seo['title']
            ?? $this->trimOrNull($englishContent['title'] ?? null)
            ?? $scaleCodeMeta['scale_code'];
        $description = $this->trimOrNull($localizedContent['description'] ?? null)
            ?? $seo['description']
            ?? $this->trimOrNull($englishContent['description'] ?? null)
            ?? '';

        return [
            'slug' => trim((string) ($row['primary_slug'] ?? '')),
            'scale_code' => $scaleCodeMeta['scale_code_legacy'],
            'scale_code_v2' => $scaleCodeMeta['scale_code_v2'],
            'scale_uid' => $scaleCodeMeta['scale_uid'],
            'title' => $title,
            'title_i18n' => [
                'en' => $this->trimOrNull($englishContent['title'] ?? null) ?? $title,
                'zh' => $this->trimOrNull($zhContent['title'] ?? null) ?? $title,
            ],
            'description' => $description,
            'description_i18n' => [
                'en' => $this->trimOrNull($englishContent['description'] ?? null) ?? $description,
                'zh' => $this->trimOrNull($zhContent['description'] ?? null) ?? $description,
            ],
            'cover_image' => $this->trimOrNull($catalog['cover_image'] ?? null)
                ?? $this->trimOrNull($fallbackCatalog['cover_image'] ?? null)
                ?? $seo['og_image_url']
                ?? self::CATALOG_FALLBACK_IMAGE,
            'questions_count' => $this->positiveInt($catalog['questions_count'] ?? $fallbackCatalog['questions_count'] ?? null)
                ?: $defaultFormQuestionCount,
            'time_minutes' => $this->positiveInt($catalog['time_minutes'] ?? $fallbackCatalog['time_minutes'] ?? null),
            'forms' => $forms,
            'card_visual' => $this->trimOrNull($card['visual'] ?? null) ?? $this->trimOrNull($fallbackCard['visual'] ?? null),
            'card_tone' => $this->trimOrNull($card['tone'] ?? null) ?? $this->trimOrNull($fallbackCard['tone'] ?? null),
            'card_seed' => $this->trimOrNull($card['seed'] ?? null) ?? $this->trimOrNull($fallbackCard['seed'] ?? null),
            'card_density' => $this->trimOrNull($card['density'] ?? null) ?? $this->trimOrNull($fallbackCard['density'] ?? null),
            'card_tagline_i18n' => [
                'en' => $this->trimOrNull($this->toArray($englishContent['card'] ?? null)['tagline'] ?? null) ?? '',
                'zh' => $this->trimOrNull($this->toArray($zhContent['card'] ?? null)['tagline'] ?? null) ?? '',
            ],
            'highlight_priority' => $this->positiveInt($highlight['priority'] ?? $fallbackHighlight['priority'] ?? null),
            'highlight_rating' => $this->positiveInt($highlight['rating'] ?? $fallbackHighlight['rating'] ?? null),
            'highlight_excerpt_i18n' => [
                'en' => $this->trimOrNull($this->toArray($englishContent['highlight'] ?? null)['excerpt'] ?? null) ?? '',
                'zh' => $this->trimOrNull($this->toArray($zhContent['highlight'] ?? null)['excerpt'] ?? null) ?? '',
            ],
            'highlight_seo_copy_i18n' => [
                'en' => $this->trimOrNull($this->toArray($englishContent['highlight'] ?? null)['seo_copy'] ?? null) ?? '',
                'zh' => $this->trimOrNull($this->toArray($zhContent['highlight'] ?? null)['seo_copy'] ?? null) ?? '',
            ],
            'is_public' => (bool) ($row['is_public'] ?? true),
            'is_active' => (bool) ($row['is_active'] ?? true),
            'is_indexable' => $this->resolveIsIndexable($row),
        ];
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function hasCatalogMetadata(array $row, string $locale): bool
    {
        $contentI18n = $this->toArray($row['content_i18n_json'] ?? null);
        $lang = $this->localeToLanguage($locale);
        $localizedCatalog = $this->toArray($this->toArray($contentI18n[$lang] ?? null)['catalog'] ?? null);
        $fallbackCatalog = $this->toArray($this->toArray($contentI18n['en'] ?? null)['catalog'] ?? null);

        return $this->positiveInt($localizedCatalog['questions_count'] ?? $fallbackCatalog['questions_count'] ?? null) > 0
            && $this->positiveInt($localizedCatalog['time_minutes'] ?? $fallbackCatalog['time_minutes'] ?? null) > 0;
    }

    private function positiveInt(mixed $value): int
    {
        $int = (int) $value;

        return $int > 0 ? $int : 0;
    }

    private function resolveRequestedLocale(Request $request, string $defaultLocale): string
    {
        $raw = trim((string) (
            $request->query('locale')
            ?? $request->header('X-FAP-Locale')
            ?? $request->attributes->get('locale')
            ?? ''
        ));

        if ($raw === '') {
            $raw = trim($defaultLocale);
        }
        if ($raw === '') {
            $raw = 'en';
        }

        $normalized = str_replace('_', '-', $raw);
        $parts = array_values(array_filter(explode('-', $normalized), static fn ($p) => $p !== ''));
        if ($parts === []) {
            return 'en';
        }

        $lang = strtolower((string) ($parts[0] ?? 'en'));
        $region = trim((string) ($parts[1] ?? ''));

        if ($lang === 'zh') {
            return 'zh-CN';
        }
        if ($lang === 'en') {
            return $region !== '' ? 'en-'.strtoupper($region) : 'en';
        }

        return $lang;
    }

    private function localeToLanguage(string $locale): string
    {
        $locale = strtolower(trim($locale));
        if ($locale === '') {
            return 'en';
        }

        $parts = explode('-', $locale);

        return strtolower((string) ($parts[0] ?? 'en'));
    }

    /**
     * @return array<string,mixed>
     */
    private function toArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function trimOrNull(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * @param  array{title:?string,description:?string,og_image_url:?string}  $seo
     * @return array<string,mixed>
     */
    private function buildLandingSurface(
        string $primarySlug,
        string $locale,
        string $scaleCode,
        array $seo,
        bool $isIndexable
    ): array {
        $segment = $locale === 'zh-CN' ? 'zh' : 'en';
        $canonicalPath = '/'.$segment.'/tests/'.rawurlencode($primarySlug);

        return $this->landingSurfaceContractService->build([
            'landing_scope' => 'public_indexable_detail',
            'entry_surface' => 'test_detail',
            'entry_type' => 'test_landing',
            'summary_blocks' => [
                [
                    'key' => 'hero',
                    'title' => $seo['title'] ?? $scaleCode,
                    'body' => $seo['description'] ?? null,
                    'kind' => 'answer_first',
                ],
            ],
            'discoverability_keys' => ['test_landing', 'start_test', 'related_content'],
            'continue_reading_keys' => ['related_content', 'faq'],
            'start_test_target' => $canonicalPath.'/take',
            'result_resume_target' => null,
            'content_continue_target' => $scaleCode === 'MBTI' ? '/'.$segment.'/topics/mbti' : '/'.$segment.'/articles',
            'cta_bundle' => [
                [
                    'key' => 'start_test',
                    'label' => $locale === 'zh-CN' ? '开始测试' : 'Start test',
                    'href' => $canonicalPath.'/take',
                    'kind' => 'start_test',
                ],
                [
                    'key' => 'back_to_tests',
                    'label' => $locale === 'zh-CN' ? '返回测试列表' : 'Back to tests',
                    'href' => '/'.$segment.'/tests',
                    'kind' => 'discover',
                ],
                [
                    'key' => 'continue_public_content',
                    'label' => $locale === 'zh-CN' ? '继续阅读' : 'Continue reading',
                    'href' => $scaleCode === 'MBTI' ? '/'.$segment.'/topics/mbti' : '/'.$segment.'/articles',
                    'kind' => 'content_continue',
                ],
            ],
            'indexability_state' => $isIndexable ? 'indexable' : 'noindex',
            'attribution_scope' => 'public_test_landing',
            'surface_family' => 'test',
            'primary_content_ref' => $primarySlug,
            'related_surface_keys' => ['test_take', 'topic_cluster'],
            'fingerprint_seed' => [
                'primary_slug' => $primarySlug,
                'locale' => $locale,
                'scale_code' => $scaleCode,
            ],
        ]);
    }

    private function deprecatedScaleResponse(string $legacyScaleCode, string $requestedSlug, string $primarySlug): JsonResponse
    {
        $replacementLegacy = $this->identityResolver->demoReplacement($legacyScaleCode);
        $replacementV2 = null;
        if ($replacementLegacy !== null) {
            $replacementIdentity = $this->identityResolver->resolveByAnyCode($replacementLegacy);
            if (is_array($replacementIdentity) && ((bool) ($replacementIdentity['is_known'] ?? false))) {
                $resolved = strtoupper(trim((string) ($replacementIdentity['scale_code_v2'] ?? '')));
                if ($resolved !== '') {
                    $replacementV2 = $resolved;
                }
            }
        }

        return response()->json([
            'ok' => false,
            'error_code' => 'SCALE_DEPRECATED',
            'message' => 'scale is deprecated.',
            'details' => [
                'requested_slug' => $requestedSlug,
                'primary_slug' => $primarySlug !== '' ? $primarySlug : null,
                'scale_code_legacy' => $legacyScaleCode,
                'replacement_scale_code' => $replacementLegacy,
                'replacement_scale_code_v2' => $replacementV2,
            ],
        ], 410);
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array{
     *     scale_code:string,
     *     scale_code_legacy:string,
     *     scale_code_v2:string,
     *     scale_uid:?string,
     *     pack_id_v2:?string,
     *     dir_version_v2:?string
     * }
     */
    private function resolveScaleCodeMeta(array $row): array
    {
        $legacyCode = strtoupper(trim((string) ($row['code'] ?? '')));
        $identity = $this->identityResolver->resolveByAnyCode($legacyCode);
        $isKnown = is_array($identity) && ((bool) ($identity['is_known'] ?? false));
        $scaleUid = $isKnown ? trim((string) ($identity['scale_uid'] ?? '')) : '';
        $scaleCodeV2 = $isKnown
            ? strtoupper(trim((string) ($identity['scale_code_v2'] ?? $legacyCode)))
            : $legacyCode;
        $packIdV2 = $isKnown ? $this->trimOrNull($identity['pack_id_v2'] ?? null) : null;
        $dirVersionV2 = $isKnown ? $this->trimOrNull($identity['dir_version_v2'] ?? null) : null;
        $responseCodes = $this->responseProjector->project(
            $legacyCode,
            $scaleCodeV2,
            $scaleUid !== '' ? $scaleUid : null
        );

        return [
            'scale_code' => $responseCodes['scale_code'],
            'scale_code_legacy' => $responseCodes['scale_code_legacy'],
            'scale_code_v2' => $responseCodes['scale_code_v2'],
            'scale_uid' => $responseCodes['scale_uid'],
            'pack_id_v2' => $packIdV2 ?? $this->trimOrNull($row['default_pack_id'] ?? null),
            'dir_version_v2' => $dirVersionV2 ?? $this->trimOrNull($row['default_dir_version'] ?? null),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function cacheableJson(array $payload, string $state): JsonResponse
    {
        $response = response()->json($payload);
        if ($this->shouldExposeCacheState()) {
            $response->headers->set(self::CACHE_DEBUG_HEADER, $state);
        }

        return $response;
    }

    private function shouldExposeCacheState(): bool
    {
        return app()->environment(['local', 'testing']) || (bool) config('app.debug', false);
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function logCacheEvent(string $event, string $cacheKey, array $context = []): void
    {
        if (! (bool) config('content_packs.debug_log', false)) {
            return;
        }

        Log::info('mbti.lookup.cache.'.$event, ['cache_key' => $cacheKey] + $context);
    }
}
