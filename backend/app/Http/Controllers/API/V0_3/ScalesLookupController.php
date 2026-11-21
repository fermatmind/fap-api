<?php

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\Controller;
use App\Services\Assessment\Jung\JungBank;
use App\Services\PublicSurface\LandingSurfaceContractService;
use App\Services\Scale\PublicScaleCatalogCache;
use App\Services\Scale\PublicScaleCatalogUnavailable;
use App\Services\Scale\PublicScaleFormsProjector;
use App\Services\Scale\PublicScaleInputGuard;
use App\Services\Scale\ScaleCodeResponseProjector;
use App\Services\Scale\ScaleDiscoverabilityPolicy;
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

    private const FREE_ONLY_ASSESSMENT_CODES = [
        'JUNG_ARCHETYPES',
        'JUNG_COGNITIVE_FUNCTIONS',
        'MBTI',
        'BIG5_OCEAN',
        'ENNEAGRAM',
        'RIASEC',
        'IQ_RAVEN',
        'EQ_60',
    ];

    public function __construct(
        private ScaleRegistry $registry,
        private ScaleIdentityResolver $identityResolver,
        private ScaleCodeResponseProjector $responseProjector,
        private PublicScaleFormsProjector $publicScaleFormsProjector,
        private PublicScaleInputGuard $publicInputGuard,
        private ScaleDiscoverabilityPolicy $scaleDiscoverabilityPolicy,
        private OrgContext $orgContext,
        private LandingSurfaceContractService $landingSurfaceContractService,
        private PublicScaleCatalogCache $publicScaleCatalogCache,
    ) {}

    /**
     * GET /api/v0.3/scales/lookup?slug=xxx
     */
    public function lookup(Request $request): JsonResponse
    {
        $orgId = $this->orgContext->orgId();
        $requestedSlug = $this->publicInputGuard->normalizeSlug($request->query('slug', ''));
        if ($requestedSlug === null) {
            return response()->json([
                'ok' => false,
                'error_code' => 'SLUG_REQUIRED',
                'message' => 'slug is required.',
            ], 400);
        }

        $allowAlias = config('scales_lookup.alias_mode', 'compat') !== 'canonical_only';
        $requestedLocale = $this->resolveRequestedLocale(
            $request,
            (string) config('content_packs.default_locale', 'en')
        );
        $generation = $this->publicScaleCatalogCache->generation($orgId);
        $cacheKey = CacheKeys::publicScaleLookup(
            $orgId,
            $requestedSlug,
            $requestedLocale,
            $allowAlias,
            $generation
        );
        $configuredCacheStore = trim((string) config('content_packs.public_scale_cache_store', ''));
        $cacheStore = Cache::store($configuredCacheStore !== ''
            ? $configuredCacheStore
            : (string) config('content_packs.mbti_response_cache_store', 'hot_redis'));
        $cacheTtl = max(1, (int) config(
            'content_packs.public_scale_lookup_cache_ttl_seconds',
            config('content_packs.mbti_lookup_cache_ttl_seconds', 600)
        ));
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
        $isIndexable = $this->scaleDiscoverabilityPolicy->isIndexable($row);
        $forms = $this->publicScaleFormsProjector->projectForRegistryRow($row, $locale);
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
            'view_policy' => $this->projectPublicViewPolicy($row),
            'capabilities' => $this->projectPublicCapabilities($row, $forms),
            'commercial' => $this->projectPublicCommercial($row),
            'forms' => $forms,
            'seo_title' => $seo['title'],
            'seo_description' => $seo['description'],
            'og_image_url' => $seo['og_image_url'],
            'is_public' => (bool) ($row['is_public'] ?? false),
            'is_indexable' => $isIndexable,
            'content_i18n_json' => $legacyScaleCode === 'BIG5_OCEAN'
                ? $this->projectBigFiveLandingContent($row['content_i18n_json'] ?? null)
                : ($row['content_i18n_json'] ?? null),
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

        $cacheStore->put($cacheKey, $payload, $cacheTtl);
        $this->logCacheEvent('lookup_miss', $cacheKey, [
            'org_id' => $orgId,
            'slug' => $requestedSlug,
            'locale' => $locale,
            'ttl' => $cacheTtl,
        ]);

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
        try {
            $result = $this->publicScaleCatalogCache->read(
                0,
                $locale,
                function () use ($locale): array {
                    $rows = $this->registry->listActivePublicForCatalog(0);
                    $items = [];

                    foreach ($rows as $row) {
                        if (! is_array($row)) {
                            $row = (array) $row;
                        }

                        $primarySlug = trim((string) ($row['primary_slug'] ?? ''));
                        if ($primarySlug === '' || ! $this->hasCatalogMetadata($row, $locale)) {
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

                    return [
                        'ok' => true,
                        'locale' => $locale,
                        'items' => $items,
                    ];
                }
            );

            return $this->cacheableJson($result['payload'], $result['state']);
        } catch (PublicScaleCatalogUnavailable) {
            return response()->json([
                'ok' => false,
                'error_code' => 'TEMPORARILY_UNAVAILABLE',
                'message' => 'scale catalog is temporarily unavailable.',
            ], 503);
        }
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
     * @return array<string,mixed>|null
     */
    private function projectPublicCommercial(array $row): ?array
    {
        $commercial = $this->toArray($row['commercial_json'] ?? null);
        $scaleCode = strtoupper(trim((string) ($row['code'] ?? '')));
        if (! $this->isFreeOnlyAssessment($scaleCode)) {
            if ($commercial === []) {
                return null;
            }

            return $commercial;
        }

        return [
            'price_tier' => 'FREE',
            'report_benefit_code' => null,
            'credit_benefit_code' => null,
            'report_unlock_sku' => null,
            'upgrade_sku' => null,
            'upgrade_sku_anchor' => null,
            'offers' => [],
        ];
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>|null
     */
    private function projectPublicViewPolicy(array $row): ?array
    {
        $viewPolicy = $this->toArray($row['view_policy_json'] ?? null);
        $scaleCode = strtoupper(trim((string) ($row['code'] ?? '')));
        if (! $this->isFreeOnlyAssessment($scaleCode)) {
            return $viewPolicy !== [] ? $viewPolicy : null;
        }

        $viewPolicy['blur_others'] = false;
        $viewPolicy['teaser_percent'] = 0.0;
        $viewPolicy['upgrade_sku'] = null;

        return $viewPolicy;
    }

    /**
     * @param  array<string,mixed>  $row
     * @param  list<array<string,mixed>>  $forms
     * @return array<string,mixed>|null
     */
    private function projectPublicCapabilities(array $row, array $forms): ?array
    {
        $capabilities = $this->toArray($row['capabilities_json'] ?? null);
        $scaleCode = strtoupper(trim((string) ($row['code'] ?? '')));
        if (! $this->isFreeOnlyAssessment($scaleCode)) {
            return $capabilities !== [] ? $capabilities : null;
        }

        $capabilities['paywall_mode'] = 'free_only';
        $capabilities['forms'] = array_values(array_map(
            static fn (array $form): string => (string) ($form['form_code'] ?? ''),
            $forms
        ));
        foreach ($forms as $form) {
            if (($form['is_default'] ?? false) === true) {
                $capabilities['default_form_code'] = (string) ($form['form_code'] ?? '');
                break;
            }
        }

        return $capabilities;
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
        $defaultFormEstimatedMinutes = $this->publicScaleFormsProjector->defaultEstimatedMinutes($row, $locale);
        $isFreeOnlyAssessment = $this->isFreeOnlyAssessment(strtoupper(trim((string) ($row['code'] ?? ''))));

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
            'questions_count' => $defaultFormQuestionCount
                ?: $this->positiveInt($catalog['questions_count'] ?? $fallbackCatalog['questions_count'] ?? null),
            'time_minutes' => $defaultFormEstimatedMinutes
                ?: $this->positiveInt($catalog['time_minutes'] ?? $fallbackCatalog['time_minutes'] ?? null),
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
            'highlight_rating' => $isFreeOnlyAssessment
                ? 0
                : $this->positiveInt($highlight['rating'] ?? $fallbackHighlight['rating'] ?? null),
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
            'is_indexable' => $this->scaleDiscoverabilityPolicy->isIndexable($row),
        ];
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function hasCatalogMetadata(array $row, string $locale): bool
    {
        if (JungBank::supports((string) ($row['code'] ?? ''))) {
            return $this->publicScaleFormsProjector->defaultQuestionCount($row, $locale) > 0
                && $this->publicScaleFormsProjector->defaultEstimatedMinutes($row, $locale) > 0;
        }

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

    private function projectBigFiveLandingContent(mixed $content): array
    {
        $content = $this->toArray($content);
        foreach (['zh', 'en'] as $language) {
            if (! is_array($content[$language] ?? null)) {
                continue;
            }

            $zh = $language === 'zh';
            $duration = $zh ? '90 题，预计约 11 分钟。' : 'The 90-question test takes about 11 minutes.';
            $landing = $content[$language];
            unset($landing['version_comparison']);
            $landing['when_to_use'] = $duration;
            $landing['how_it_works'][0] = $zh
                ? '请按自己平时的情况完成全部 90 题，预计约 11 分钟。'
                : 'Answer all 90 items based on what is typical for you. Allow about 11 minutes.';

            if (is_array($landing['why_choose'] ?? null)) {
                if (str_contains((string) ($landing['why_choose']['intro'] ?? ''), '120')) {
                    $landing['why_choose']['intro'] = $zh
                        ? '用五个连续维度理解日常倾向，再用具体经历核对。'
                        : 'Explore five continuous traits and compare the results with specific experiences.';
                }
                $landing['why_choose']['items'] = array_values(array_filter(
                    $landing['why_choose']['items'] ?? [],
                    static fn (mixed $item): bool => is_array($item) && ($item['id'] ?? null) !== 'versions'
                ));
                foreach ($landing['why_choose']['items'] as &$item) {
                    if (($item['id'] ?? null) === 'big5-method' && str_contains((string) ($item['body'] ?? ''), '120')) {
                        $item['body'] = $zh
                            ? '90 题题本有对应的计分规则与常模。模型研究、计分核查和本题本的测量验证分别需要证据。'
                            : 'The 90-question form has its own scoring rules and norms. Evidence for the model, scoring and questionnaire validation addresses different questions.';
                    }
                }
                unset($item);
            }

            foreach ($landing['faq'] ?? [] as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }
                $id = $item['id'] ?? null;
                $mentionsRetiredForm = str_contains((string) ($item['a'] ?? ''), '120');
                if ($id === 'faq-big5-forms') {
                    $item['q'] = $zh ? '大五人格测试需要多久？' : 'How long does the Big Five test take?';
                    $item['a'] = $duration;
                    unset($item['related_links']);
                } elseif ($id === 'faq-big5-evidence' && $mentionsRetiredForm) {
                    $item['a'] = $zh
                        ? '大五人格模型已有量表研究。费马 90 题题本的信度、效度与常模适用性，需要查看该题本自己的证据。'
                        : 'Research supports the Big Five framework. The reliability, validity and norm coverage of FermatMind’s 90-question form require evidence for that specific form.';
                } elseif ($id === 'faq-big5-free' && $mentionsRetiredForm) {
                    $item['a'] = $zh ? '可以免费完成 90 题并查看结果。' : 'You can answer all 90 questions and view the results for free.';
                } elseif ($id === 'faq-big5-method' && $mentionsRetiredForm) {
                    $item['a'] = $zh
                        ? '90 题覆盖五个维度和 30 个细分面向。分数按该题本的规则换算，结果页会说明参照信息。'
                        : 'The 90 questions cover five domains and 30 facets. Scores use the rules for this form, with reference information in the report.';
                }
                $landing['faq'][$index] = $item;
            }
            $content[$language] = $landing;
        }

        return $content;
    }

    private function isFreeOnlyAssessment(string $scaleCode): bool
    {
        return in_array($scaleCode, self::FREE_ONLY_ASSESSMENT_CODES, true);
    }

    private function resolveRequestedLocale(Request $request, string $defaultLocale): string
    {
        return $this->publicInputGuard->normalizeRequestedLocale($request, $defaultLocale);
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
