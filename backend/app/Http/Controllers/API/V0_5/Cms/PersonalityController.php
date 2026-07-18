<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Cms;

use App\Http\Controllers\Concerns\RespondsWithNotFound;
use App\Http\Controllers\Controller;
use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileSection;
use App\Models\PersonalityProfileSeoMeta;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantSection;
use App\Models\PersonalityProfileVariantSeoMeta;
use App\Services\Cms\Mbti64CrossTypeComparisonPublicReadModel;
use App\Services\Cms\PersonalityProfileSeoService;
use App\Services\Cms\PersonalityProfileService;
use App\Services\Cms\PersonalityPublicReadModelCache;
use App\Services\PublicSurface\AnswerSurfaceContractService;
use App\Services\PublicSurface\LandingSurfaceContractService;
use App\Services\PublicSurface\SeoSurfaceContractService;
use App\Support\CanonicalFrontendUrl;
use App\Support\Mbti\MbtiCanonicalSectionRegistry;
use App\Support\PublicMediaUrlGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

class PersonalityController extends Controller
{
    private const PUBLIC_READ_CACHE_HEADER = 'X-Fermat-Public-Read-Cache';

    private const MBTI64_V85_SECTION_PREFIX = 'v8_5_';

    private const MBTI64_V85_DUPLICATE_LEGACY_SECTION_KEYS = [
        'quick_answer',
        'meaning',
        'a_t_difference',
        'core_traits',
        'careers_work_style',
        'relationships_communication',
        'strengths_blind_spots',
        'common_misreads',
        'similar_types',
        'faq',
    ];

    private const MBTI64_V85_DUPLICATE_LEGACY_SECTION_PREFIXES = [
        'career.',
        'growth.',
        'relationships.',
    ];

    use RespondsWithNotFound;

    public function __construct(
        private readonly PersonalityProfileService $personalityProfileService,
        private readonly PersonalityProfileSeoService $personalityProfileSeoService,
        private readonly PersonalityPublicReadModelCache $personalityPublicReadModelCache,
        private readonly Mbti64CrossTypeComparisonPublicReadModel $crossTypeComparisonReadModel,
        private readonly AnswerSurfaceContractService $answerSurfaceContractService,
        private readonly LandingSurfaceContractService $landingSurfaceContractService,
        private readonly SeoSurfaceContractService $seoSurfaceContractService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $this->validateListQuery($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $items = [];

        if ($validated['include_variants']) {
            $paginator = $this->personalityProfileService->listPublicProfileVariants(
                $validated['org_id'],
                $validated['scale_code'],
                $validated['locale'],
                $validated['page'],
                $validated['per_page'],
            );

            foreach ($paginator->items() as $variant) {
                if (! $variant instanceof PersonalityProfileVariant) {
                    continue;
                }

                $items[] = $this->variantListPayload($variant);
            }
        } else {
            $paginator = $this->personalityProfileService->listPublicProfiles(
                $validated['org_id'],
                $validated['scale_code'],
                $validated['locale'],
                $validated['page'],
                $validated['per_page'],
            );

            foreach ($paginator->items() as $profile) {
                if (! $profile instanceof PersonalityProfile) {
                    continue;
                }

                $items[] = $this->profileListPayload($profile);
            }
        }

        return response()->json([
            'ok' => true,
            'items' => $items,
            'pagination' => [
                'current_page' => (int) $paginator->currentPage(),
                'per_page' => (int) $paginator->perPage(),
                'total' => (int) $paginator->total(),
                'last_page' => (int) $paginator->lastPage(),
            ],
            'landing_surface_v1' => $this->buildIndexLandingSurface($validated['locale']),
        ]);
    }

    public function show(Request $request, string $type): JsonResponse
    {
        $validated = $this->validateReadQuery($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        try {
            $routeProfile = $this->personalityProfileService->getPublicDetailRouteProfileByType(
                $type,
                $validated['org_id'],
                $validated['scale_code'],
                $validated['locale'],
                false,
            );
        } catch (Throwable $throwable) {
            return $this->stalePublicReadResponseOrThrow('detail', $type, $validated, $throwable);
        }

        if (! is_array($routeProfile)) {
            $this->personalityPublicReadModelCache->forgetType(
                $type, $validated['locale'], $validated['org_id'], $validated['scale_code']
            );

            return $this->notFoundResponse('personality profile not found.')
                ->header(self::PUBLIC_READ_CACHE_HEADER, 'miss');
        }

        /** @var PersonalityProfile $profile */
        $profile = $routeProfile['profile'];
        /** @var PersonalityProfileVariant|null $variant */
        $variant = $routeProfile['variant'];
        try {
            $cacheVersion = $this->publicReadModelVersion($profile, $variant, $type, $validated);
        } catch (Throwable $throwable) {
            return $this->stalePublicReadResponseOrThrow('detail', $type, $validated, $throwable);
        }
        $cachedRead = $this->personalityPublicReadModelCache->read(
            'detail', $type, $validated['locale'], $validated['org_id'], $validated['scale_code'], $cacheVersion
        );
        if (is_array($cachedRead['payload'])) {
            return $this->publicReadResponse($cachedRead['payload'], $cachedRead['state']);
        }

        try {
            $this->personalityProfileService->loadPublicDetailRouteContentRelations($profile, $variant);
            $projection = $this->sanitizePublicProjection(
                $this->personalityProfileService->buildPublicProjection($profile, $variant)
            );
            $meta = PublicMediaUrlGuard::sanitizeSeoMeta(
                $this->personalityProfileSeoService->buildMeta($profile, $variant)
            );
            $isIndexable = $this->profileAllowsIndexing($profile, $meta);
            $jsonLd = $this->personalityProfileSeoService->buildJsonLd($profile, $variant);
            $sections = $this->publicSectionPayloads($profile, $variant);
            $internalLinks = $this->content15ProfileInternalLinks($sections, $validated['locale']);
            $seoSurface = $this->buildSeoSurface($meta, $jsonLd, $this->personalitySeoSurfaceType($profile));
            $landingSurface = $this->buildDetailLandingSurface(
                $profile,
                $variant,
                $projection,
                $validated['locale'],
                $isIndexable,
            );

            $payload = [
                'ok' => true,
                'profile' => $this->profileDetailPayload($profile, $variant, $isIndexable),
                'sections' => $sections,
                'internal_links' => $internalLinks,
                'seo_meta' => $this->seoMetaPayload($profile, $variant),
                'seo_surface_v1' => $seoSurface,
                'landing_surface_v1' => $landingSurface,
                'answer_surface_v1' => $this->buildDetailAnswerSurface(
                    $profile,
                    $variant,
                    $projection,
                    $sections,
                    $seoSurface,
                    $landingSurface,
                    $validated['locale'],
                    $internalLinks,
                    $isIndexable,
                ),
            ];

            if ($this->isMbtiProfile($profile)) {
                $payload['mbti_public_projection_v1'] = $projection;
            }

            if (! $variant instanceof PersonalityProfileVariant) {
                $payload['personality_public_projection_v1'] = $projection;
            }
        } catch (Throwable $throwable) {
            return $this->stalePublicReadResponseOrThrow('detail', $type, $validated, $throwable);
        }

        $this->personalityPublicReadModelCache->put(
            'detail',
            $type,
            $validated['locale'],
            $validated['org_id'],
            $validated['scale_code'],
            $cacheVersion,
            $payload,
        );

        return $this->publicReadResponse($payload, $cachedRead['state']);
    }

    public function seo(Request $request, string $type): JsonResponse
    {
        $validated = $this->validateReadQuery($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        try {
            $routeProfile = $this->personalityProfileService->getPublicDetailRouteProfileByType(
                $type,
                $validated['org_id'],
                $validated['scale_code'],
                $validated['locale'],
                false,
            );
        } catch (Throwable $throwable) {
            return $this->stalePublicReadResponseOrThrow('seo', $type, $validated, $throwable);
        }

        if (! is_array($routeProfile)) {
            $this->personalityPublicReadModelCache->forgetType(
                $type, $validated['locale'], $validated['org_id'], $validated['scale_code']
            );

            return response()->json(['error' => 'not found'], 404)
                ->header(self::PUBLIC_READ_CACHE_HEADER, 'miss');
        }

        /** @var PersonalityProfile $profile */
        $profile = $routeProfile['profile'];
        /** @var PersonalityProfileVariant|null $variant */
        $variant = $routeProfile['variant'];
        try {
            $cacheVersion = $this->publicReadModelVersion($profile, $variant, $type, $validated);
        } catch (Throwable $throwable) {
            return $this->stalePublicReadResponseOrThrow('seo', $type, $validated, $throwable);
        }
        $cachedRead = $this->personalityPublicReadModelCache->read(
            'seo', $type, $validated['locale'], $validated['org_id'], $validated['scale_code'], $cacheVersion
        );
        if (is_array($cachedRead['payload'])) {
            return $this->publicReadResponse($cachedRead['payload'], $cachedRead['state']);
        }

        try {
            $this->personalityProfileService->loadPublicDetailRouteContentRelations($profile, $variant);
            $meta = PublicMediaUrlGuard::sanitizeSeoMeta(
                $this->personalityProfileSeoService->buildMeta($profile, $variant)
            );
            $jsonLd = $this->personalityProfileSeoService->buildJsonLd($profile, $variant);

            $payload = [
                'meta' => $meta,
                'jsonld' => $jsonLd,
                'seo_surface_v1' => $this->buildSeoSurface($meta, $jsonLd, $this->personalitySeoSurfaceType($profile)),
            ];
        } catch (Throwable $throwable) {
            return $this->stalePublicReadResponseOrThrow('seo', $type, $validated, $throwable);
        }
        $this->personalityPublicReadModelCache->put(
            'seo',
            $type,
            $validated['locale'],
            $validated['org_id'],
            $validated['scale_code'],
            $cacheVersion,
            $payload,
        );

        return $this->publicReadResponse($payload, $cachedRead['state']);
    }

    public function comparisonIndex(Request $request): JsonResponse
    {
        $validated = $this->validateReadQuery($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $paginator = $this->personalityProfileService->listPublicProfileVariants(
            $validated['org_id'],
            $validated['scale_code'],
            $validated['locale'],
            1,
            100,
        );
        $variantsByBase = [];

        foreach ($paginator->items() as $variant) {
            if (! $variant instanceof PersonalityProfileVariant) {
                continue;
            }

            $profile = $variant->profile;
            if (! $profile instanceof PersonalityProfile || ! $this->isMbtiProfile($profile)) {
                continue;
            }

            $baseTypeCode = strtoupper((string) ($variant->canonical_type_code ?: $profile->type_code));
            $variantCode = strtoupper((string) $variant->variant_code);
            if (! in_array($baseTypeCode, PersonalityProfile::BASE_TYPE_CODES, true) || ! in_array($variantCode, ['A', 'T'], true)) {
                continue;
            }

            $variantsByBase[$baseTypeCode] ??= [
                'profile' => $profile,
                'variants' => [],
            ];
            $variantsByBase[$baseTypeCode]['variants'][strtolower($variantCode)] = $variant;
        }

        $items = [];
        foreach (PersonalityProfile::BASE_TYPE_CODES as $baseTypeCode) {
            $entry = $variantsByBase[$baseTypeCode] ?? null;
            $profile = $entry['profile'] ?? null;
            $variants = is_array($entry['variants'] ?? null) ? $entry['variants'] : [];
            if (! $profile instanceof PersonalityProfile
                || ! ($variants['a'] ?? null) instanceof PersonalityProfileVariant
                || ! ($variants['t'] ?? null) instanceof PersonalityProfileVariant
            ) {
                continue;
            }

            $items[] = $this->comparisonListItemPayload($baseTypeCode, $validated['locale'], $profile);
        }

        $crossTypeItems = $this->crossTypeComparisonReadModel->list($validated['locale']);
        $projection = [
            'comparison_list_contract_version' => 'mbti.comparison_list.v1',
            'locale' => $validated['locale'],
            'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
            'groups' => [
                [
                    'key' => 'at_comparisons',
                    'comparison_type' => 'mbti_at_comparison',
                    'title' => $validated['locale'] === 'zh-CN' ? 'A/T 人格差异' : 'A/T personality differences',
                    'description' => $validated['locale'] === 'zh-CN'
                        ? '同一 16 型人格核心下，比较 A 与 T 在压力反馈、自我确认和行动节奏上的差异。'
                        : 'Compare A and T variants within the same 16-type personality core across stress feedback, self-confirmation, and action rhythm.',
                    'items' => $items,
                ],
                [
                    'key' => 'cross_type_comparisons',
                    'comparison_type' => 'mbti_cross_type',
                    'title' => $validated['locale'] === 'zh-CN' ? '易混淆人格对比' : 'Commonly confused personality types',
                    'description' => $validated['locale'] === 'zh-CN'
                        ? '比较容易混淆的 16 型人格组合，帮助用户从思维入口、行动节奏和协作方式上做区分。'
                        : 'Compare commonly confused 16-type personality pairs through thinking patterns, action rhythm, and collaboration style.',
                    'items' => $crossTypeItems,
                ],
            ],
        ];

        return response()->json([
            'ok' => true,
            'comparison_list_public_projection_v1' => $projection,
            'at_comparisons' => $items,
            'cross_type_comparisons' => $crossTypeItems,
        ]);
    }

    public function comparison(Request $request, string $comparison): JsonResponse
    {
        $validated = $this->validateReadQuery($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $crossTypeComparison = $this->crossTypeComparisonReadModel->find($comparison, $validated['locale']);
        if ($crossTypeComparison !== null) {
            $meta = $this->crossTypeComparisonMeta($crossTypeComparison);
            $jsonLd = $this->crossTypeComparisonJsonLd($crossTypeComparison, $meta);
            $seoSurface = $this->buildSeoSurface($meta, $jsonLd, 'mbti_personality_cross_type_comparison');

            return response()->json([
                'ok' => true,
                'comparison' => $crossTypeComparison,
                'comparison_public_projection_v1' => $crossTypeComparison,
                'seo_meta' => $this->comparisonSeoMetaPayload($meta),
                'jsonld' => $jsonLd,
                'seo_surface_v1' => $seoSurface,
            ]);
        }

        $baseTypeCode = $this->comparisonBaseTypeCode($comparison);
        if ($baseTypeCode === null) {
            return $this->notFoundResponse('personality comparison not found.');
        }

        $assertiveRoute = $this->personalityProfileService->getPublicDetailRouteProfileByType(
            strtolower($baseTypeCode).'-a',
            $validated['org_id'],
            $validated['scale_code'],
            $validated['locale'],
        );
        $turbulentRoute = $this->personalityProfileService->getPublicDetailRouteProfileByType(
            strtolower($baseTypeCode).'-t',
            $validated['org_id'],
            $validated['scale_code'],
            $validated['locale'],
        );

        if (! is_array($assertiveRoute) || ! is_array($turbulentRoute)) {
            return $this->notFoundResponse('personality comparison not found.');
        }

        /** @var PersonalityProfile $assertiveProfile */
        $assertiveProfile = $assertiveRoute['profile'];
        /** @var PersonalityProfile $turbulentProfile */
        $turbulentProfile = $turbulentRoute['profile'];
        /** @var PersonalityProfileVariant|null $assertiveVariant */
        $assertiveVariant = $assertiveRoute['variant'];
        /** @var PersonalityProfileVariant|null $turbulentVariant */
        $turbulentVariant = $turbulentRoute['variant'];

        if (
            ! $assertiveVariant instanceof PersonalityProfileVariant
            || ! $turbulentVariant instanceof PersonalityProfileVariant
            || ! $this->isMbtiProfile($assertiveProfile)
            || ! $this->isMbtiProfile($turbulentProfile)
        ) {
            return $this->notFoundResponse('personality comparison not found.');
        }

        $assertiveProjection = $this->sanitizePublicProjection(
            $this->personalityProfileService->buildPublicProjection($assertiveProfile, $assertiveVariant)
        );
        $turbulentProjection = $this->sanitizePublicProjection(
            $this->personalityProfileService->buildPublicProjection($turbulentProfile, $turbulentVariant)
        );
        $assertiveSections = $this->publicSectionPayloads($assertiveProfile, $assertiveVariant);
        $turbulentSections = $this->publicSectionPayloads($turbulentProfile, $turbulentVariant);
        $comparisonOverlay = $this->mbti64PromotedComparisonOverlay($assertiveProfile);
        $meta = $this->comparisonMeta($baseTypeCode, $validated['locale'], $comparisonOverlay);
        $comparisonProjection = $this->comparisonProjectionPayload(
            $baseTypeCode,
            $validated['locale'],
            $assertiveProfile,
            $assertiveVariant,
            $assertiveProjection,
            $assertiveSections,
            $turbulentProfile,
            $turbulentVariant,
            $turbulentProjection,
            $turbulentSections,
            $meta
        );
        $comparisonProjection = $this->applyMbti64PromotedComparisonOverlay(
            $comparisonProjection,
            $comparisonOverlay,
            $baseTypeCode,
            $validated['locale']
        );
        $jsonLd = $this->comparisonJsonLd(
            $baseTypeCode,
            $validated['locale'],
            $meta,
            is_array($comparisonProjection['faq'] ?? null) ? $comparisonProjection['faq'] : []
        );
        $seoSurface = $this->buildSeoSurface($meta, $jsonLd, 'mbti_personality_at_comparison');
        $landingSurface = $this->buildComparisonLandingSurface($baseTypeCode, $validated['locale'], $meta, $comparisonOverlay);

        return response()->json([
            'ok' => true,
            'comparison' => $comparisonProjection,
            'comparison_public_projection_v1' => $comparisonProjection,
            'seo_meta' => $this->comparisonSeoMetaPayload($meta),
            'jsonld' => $jsonLd,
            'seo_surface_v1' => $seoSurface,
            'landing_surface_v1' => $landingSurface,
            'answer_surface_v1' => $this->buildComparisonAnswerSurface(
                $baseTypeCode,
                $validated['locale'],
                $comparisonProjection,
                $seoSurface,
                $landingSurface,
                $comparisonOverlay
            ),
        ]);
    }

    private function comparisonBaseTypeCode(string $comparison): ?string
    {
        $normalized = strtolower(trim($comparison));
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/^(?<base>[a-z]{4})(?:-a-vs-\k<base>-t)?$/', $normalized, $matches) !== 1) {
            return null;
        }

        $baseTypeCode = strtoupper($matches['base']);

        return in_array($baseTypeCode, PersonalityProfile::BASE_TYPE_CODES, true) ? $baseTypeCode : null;
    }

    private function comparisonSlug(string $baseTypeCode): string
    {
        $baseSlug = strtolower($baseTypeCode);

        return $baseSlug.'-a-vs-'.$baseSlug.'-t';
    }

    private function comparisonCanonicalUrl(string $baseTypeCode, string $locale): ?string
    {
        $baseUrl = CanonicalFrontendUrl::fromConfig();
        if ($baseUrl === '') {
            return null;
        }

        return $baseUrl
            .'/'.$this->frontendLocaleSegment($locale)
            .'/personality/'
            .$this->comparisonSlug($baseTypeCode);
    }

    /**
     * @return array<string,mixed>
     */
    private function comparisonListItemPayload(string $baseTypeCode, string $locale, PersonalityProfile $profile): array
    {
        $comparisonOverlay = $this->mbti64PromotedComparisonOverlay($profile);
        $meta = $this->comparisonMeta($baseTypeCode, $locale, $comparisonOverlay);
        $slug = $this->comparisonSlug($baseTypeCode);

        return [
            'slug' => $slug,
            'comparison_type' => 'mbti_at_comparison',
            'base_type_code' => $baseTypeCode,
            'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
            'locale' => $locale,
            'public_route_type' => 'at-comparison',
            'title' => (string) ($meta['title'] ?? ($baseTypeCode.'-A vs '.$baseTypeCode.'-T')),
            'description' => (string) ($meta['description'] ?? ''),
            'public_url' => $meta['canonical'] ?? $this->comparisonCanonicalUrl($baseTypeCode, $locale),
            'canonical_url' => $meta['canonical'] ?? $this->comparisonCanonicalUrl($baseTypeCode, $locale),
            'is_public' => (bool) $profile->is_public,
            'is_indexable' => ! $this->comparisonIndexabilityHeld($comparisonOverlay) && (bool) $profile->is_indexable,
            'status' => $this->comparisonIndexabilityHeld($comparisonOverlay) ? 'held_for_mbti_index_24' : (string) $profile->status,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function comparisonMeta(string $baseTypeCode, string $locale, ?array $comparisonOverlay = null): array
    {
        $overlaySeo = is_array($comparisonOverlay['seo'] ?? null) ? $comparisonOverlay['seo'] : [];
        $title = $this->normalizedString($overlaySeo['seo_title'] ?? null)
            ?? $this->normalizedString($overlaySeo['title'] ?? null)
            ?? ($locale === 'zh-CN'
            ? $baseTypeCode.'-A 和 '.$baseTypeCode.'-T 区别：特点、职业、爱情与稀有度'
            : $baseTypeCode.'-A vs '.$baseTypeCode.'-T: Traits, Careers, Love & Rarity');
        $description = $this->normalizedString($overlaySeo['seo_description'] ?? null)
            ?? $this->normalizedString($overlaySeo['meta_description'] ?? null)
            ?? $this->normalizedString($overlaySeo['description'] ?? null)
            ?? $this->normalizedString($overlaySeo['quick_answer_summary'] ?? null)
            ?? ($locale === 'zh-CN'
            ? '对比 '.$baseTypeCode.'-A 与 '.$baseTypeCode.'-T 的 A/T 区别、核心特点、爱情关系、适合职业、优势盲点、稀有度，并通过 MBTI 测试确认自己的类型。'
            : 'Compare '.$baseTypeCode.'-A and '.$baseTypeCode.'-T traits, A/T differences, strengths, blind spots, relationships, career fit, rarity, and how to confirm your type with an MBTI test.');
        $canonical = $this->comparisonCanonicalUrl($baseTypeCode, $locale);
        $alternates = [
            'en' => $this->comparisonCanonicalUrl($baseTypeCode, 'en'),
            'zh-CN' => $this->comparisonCanonicalUrl($baseTypeCode, 'zh-CN'),
        ];

        return [
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'alternates' => $alternates,
            'og' => [
                'title' => $title,
                'description' => $description,
                'image' => null,
                'type' => 'article',
                'url' => $canonical,
            ],
            'twitter' => [
                'card' => 'summary_large_image',
                'title' => $title,
                'description' => $description,
                'image' => null,
            ],
            'robots' => $this->comparisonIndexabilityHeld($comparisonOverlay) ? 'noindex,follow' : 'index,follow',
        ];
    }

    /**
     * @param  array<string,mixed>|null  $comparisonOverlay
     */
    private function comparisonIndexabilityHeld(?array $comparisonOverlay): bool
    {
        return $comparisonOverlay !== null && ($comparisonOverlay['indexability_held'] ?? false) === true;
    }

    /**
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>
     */
    private function comparisonJsonLd(string $baseTypeCode, string $locale, array $meta, array $faq = []): array
    {
        $segment = $this->frontendLocaleSegment($locale);
        $baseUrl = CanonicalFrontendUrl::fromConfig();
        $hubUrl = $baseUrl !== '' ? $baseUrl.'/'.$segment.'/personality' : null;
        $canonicalUrl = is_string($meta['canonical'] ?? null) ? $meta['canonical'] : null;
        $variantUrls = [
            'A' => $baseUrl !== '' ? $baseUrl.'/'.$segment.'/personality/'.strtolower($baseTypeCode).'-a' : null,
            'T' => $baseUrl !== '' ? $baseUrl.'/'.$segment.'/personality/'.strtolower($baseTypeCode).'-t' : null,
        ];

        $jsonLd = [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => $meta['title'] ?? null,
            'description' => $meta['description'] ?? null,
            'url' => $canonicalUrl,
            'mainEntity' => [
                '@type' => 'ItemList',
                'itemListElement' => [
                    [
                        '@type' => 'ListItem',
                        'position' => 1,
                        'name' => $baseTypeCode.'-A',
                        'url' => $variantUrls['A'],
                    ],
                    [
                        '@type' => 'ListItem',
                        'position' => 2,
                        'name' => $baseTypeCode.'-T',
                        'url' => $variantUrls['T'],
                    ],
                ],
            ],
            'breadcrumb' => [
                '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    [
                        '@type' => 'ListItem',
                        'position' => 1,
                        'name' => $locale === 'zh-CN' ? '人格类型' : 'Personality types',
                        'item' => $hubUrl,
                    ],
                    [
                        '@type' => 'ListItem',
                        'position' => 2,
                        'name' => $baseTypeCode.'-A vs '.$baseTypeCode.'-T',
                        'item' => $canonicalUrl,
                    ],
                ],
            ],
        ];

        $faqPage = $this->comparisonFaqJsonLd($faq);
        if ($faqPage !== null) {
            $jsonLd['hasPart'] = $faqPage;
        }

        return CanonicalFrontendUrl::normalizeNestedUrls($jsonLd);
    }

    /**
     * @param  array<string,mixed>  $comparison
     * @return array<string,mixed>
     */
    private function crossTypeComparisonMeta(array $comparison): array
    {
        $title = (string) ($comparison['seo_title'] ?? $comparison['title'] ?? '');
        $description = (string) ($comparison['seo_description'] ?? $comparison['description'] ?? $comparison['summary'] ?? '');
        $canonical = (string) ($comparison['canonical_url'] ?? '');
        $isIndexable = ($comparison['is_indexable'] ?? false) === true;

        return [
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'alternates' => is_array($comparison['alternates'] ?? null) ? $comparison['alternates'] : [],
            'og' => [
                'title' => $title,
                'description' => $description,
                'image' => null,
                'type' => 'article',
                'url' => $canonical,
            ],
            'twitter' => [
                'card' => 'summary_large_image',
                'title' => $title,
                'description' => $description,
                'image' => null,
            ],
            'robots' => $isIndexable ? 'index,follow' : 'noindex,follow',
        ];
    }

    /**
     * @param  array<string,mixed>  $comparison
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>
     */
    private function crossTypeComparisonJsonLd(array $comparison, array $meta): array
    {
        $locale = (string) ($comparison['locale'] ?? 'zh-CN');
        $segment = $this->frontendLocaleSegment($locale);
        $baseUrl = CanonicalFrontendUrl::fromConfig();
        $canonicalUrl = (string) ($meta['canonical'] ?? '');
        $leftType = strtoupper((string) ($comparison['left_type'] ?? ''));
        $rightType = strtoupper((string) ($comparison['right_type'] ?? ''));
        $jsonLd = [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => $meta['title'] ?? null,
            'description' => $meta['description'] ?? null,
            'url' => $canonicalUrl,
            'mainEntity' => [
                '@type' => 'ItemList',
                'itemListElement' => [
                    [
                        '@type' => 'ListItem',
                        'position' => 1,
                        'name' => $leftType,
                        'url' => $baseUrl !== '' ? $baseUrl.'/'.$segment.'/personality/'.strtolower($leftType).'-a' : null,
                    ],
                    [
                        '@type' => 'ListItem',
                        'position' => 2,
                        'name' => $rightType,
                        'url' => $baseUrl !== '' ? $baseUrl.'/'.$segment.'/personality/'.strtolower($rightType).'-a' : null,
                    ],
                ],
            ],
            'breadcrumb' => [
                '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    [
                        '@type' => 'ListItem',
                        'position' => 1,
                        'name' => $locale === 'zh-CN' ? '人格类型' : 'Personality types',
                        'item' => $baseUrl !== '' ? $baseUrl.'/'.$segment.'/personality' : null,
                    ],
                    [
                        '@type' => 'ListItem',
                        'position' => 2,
                        'name' => $leftType.' vs '.$rightType,
                        'item' => $canonicalUrl,
                    ],
                ],
            ],
        ];

        $faqPage = $this->comparisonFaqJsonLd(
            is_array($comparison['faq'] ?? null) ? $comparison['faq'] : []
        );
        if ($faqPage !== null) {
            $jsonLd['hasPart'] = $faqPage;
        }

        return CanonicalFrontendUrl::normalizeNestedUrls($jsonLd);
    }

    /**
     * @param  list<array<string,mixed>>  $faq
     * @return array<string,mixed>|null
     */
    private function comparisonFaqJsonLd(array $faq): ?array
    {
        $questions = [];
        foreach ($faq as $item) {
            if (! is_array($item)) {
                continue;
            }

            $question = $this->normalizedString($item['question'] ?? null);
            $answer = $this->normalizedString($item['answer'] ?? null);
            if ($question === null || $answer === null) {
                continue;
            }

            $questions[] = [
                '@type' => 'Question',
                'name' => $question,
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $answer,
                ],
            ];
        }

        return $questions === [] ? null : [
            '@type' => 'FAQPage',
            'mainEntity' => $questions,
        ];
    }

    /**
     * @param  array<string,mixed>  $assertiveProjection
     * @param  list<array<string,mixed>>  $assertiveSections
     * @param  array<string,mixed>  $turbulentProjection
     * @param  list<array<string,mixed>>  $turbulentSections
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>
     */
    private function comparisonProjectionPayload(
        string $baseTypeCode,
        string $locale,
        PersonalityProfile $assertiveProfile,
        PersonalityProfileVariant $assertiveVariant,
        array $assertiveProjection,
        array $assertiveSections,
        PersonalityProfile $turbulentProfile,
        PersonalityProfileVariant $turbulentVariant,
        array $turbulentProjection,
        array $turbulentSections,
        array $meta
    ): array {
        return [
            'comparison_contract_version' => 'mbti.at_comparison.v1',
            'comparison_slug' => $this->comparisonSlug($baseTypeCode),
            'base_type_code' => $baseTypeCode,
            'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
            'locale' => $locale,
            'public_route_type' => 'at-comparison',
            'title' => $meta['title'] ?? null,
            'description' => $meta['description'] ?? null,
            'canonical_url' => $meta['canonical'] ?? null,
            'alternates' => $meta['alternates'] ?? [],
            'variants' => [
                'a' => $this->comparisonVariantPayload($assertiveProfile, $assertiveVariant, $assertiveProjection, $locale),
                't' => $this->comparisonVariantPayload($turbulentProfile, $turbulentVariant, $turbulentProjection, $locale),
            ],
            'comparison_blocks' => $this->comparisonBlocks(
                $baseTypeCode,
                $locale,
                $assertiveSections,
                $turbulentSections,
                $assertiveProjection,
                $turbulentProjection
            ),
            'source_refs' => [
                'personality_public_projection_v1',
                'mbti_public_projection_v1',
                'personality_variant_sections',
                'personality_variant_seo_meta',
            ],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function mbti64PromotedComparisonOverlay(PersonalityProfile $profile): ?array
    {
        $section = PersonalityProfileSection::query()
            ->withoutGlobalScopes()
            ->where('profile_id', (int) $profile->id)
            ->where('section_key', 'mbti64_comparison_a_vs_t')
            ->where('is_enabled', true)
            ->first();

        if (! $section instanceof PersonalityProfileSection) {
            return null;
        }

        $payload = is_array($section->payload_json) ? $section->payload_json : [];
        $content = is_array($payload['content'] ?? null) ? $payload['content'] : [];
        if (is_array($payload['sections'] ?? null)) {
            $content['sections'] = array_values($payload['sections']);
        }
        $seo = is_array($content['seo'] ?? null) ? $content['seo'] : (is_array($payload['seo'] ?? null) ? $payload['seo'] : []);

        if ($seo === [] && $content === []) {
            return null;
        }

        return [
            'section' => $this->sectionPayload($section),
            'seo' => $seo,
            'content' => $content,
            'faq' => is_array($content['faq'] ?? null) ? array_values((array) $content['faq']) : (is_array($payload['faq'] ?? null) ? array_values((array) $payload['faq']) : []),
            'internal_links' => is_array($content['internal_links'] ?? null) ? array_values((array) $content['internal_links']) : (is_array($payload['internal_links'] ?? null) ? array_values((array) $payload['internal_links']) : []),
            'source' => $this->normalizedString($payload['source'] ?? null) ?? 'mbti64_comparison_a_vs_t',
            'snapshot_key' => $this->normalizedString($payload['snapshot_key'] ?? null),
            'indexability_held' => (bool) ($payload['indexability_held'] ?? false),
        ];
    }

    /**
     * @param  array<string,mixed>  $projection
     * @return array<string,mixed>
     */
    private function applyMbti64PromotedComparisonOverlay(
        array $projection,
        ?array $comparisonOverlay,
        string $baseTypeCode,
        string $locale,
    ): array {
        if ($comparisonOverlay === null) {
            return $projection;
        }

        $seo = is_array($comparisonOverlay['seo'] ?? null) ? $comparisonOverlay['seo'] : [];
        $content = is_array($comparisonOverlay['content'] ?? null) ? $comparisonOverlay['content'] : [];
        $blocks = $this->mbti64PromotedComparisonBlocks($content);
        $sections = $this->mbti64PromotedComparisonSections($content);
        $title = $this->normalizedString($seo['h1'] ?? null)
            ?? $this->normalizedString($seo['seo_title'] ?? null)
            ?? $this->normalizedString($seo['title'] ?? null);
        $description = $this->normalizedString($seo['quick_answer_summary'] ?? null)
            ?? $this->normalizedString($content['quick_answer'] ?? null)
            ?? $this->normalizedString($seo['seo_description'] ?? null)
            ?? $this->normalizedString($seo['description'] ?? null);
        $faq = $this->mbti64PromotedComparisonFaq($comparisonOverlay);
        $internalLinks = $this->mbti64PromotedComparisonInternalLinks($comparisonOverlay, $locale);

        if ($blocks === [] && $sections === [] && $title === null && $description === null && $faq === [] && $internalLinks === []) {
            return $projection;
        }

        $snapshotKey = $this->normalizedString($comparisonOverlay['snapshot_key'] ?? null)
            ?? 'mbti64_comparison_draft_v2_1';

        $projection['comparison_contract_version'] = 'mbti.at_comparison.v1.mbti64_overlay';
        $projection['title'] = $title ?? ($projection['title'] ?? null);
        $projection['description'] = $description ?? ($projection['description'] ?? null);
        if ($blocks !== []) {
            $projection['comparison_blocks'] = $blocks;
        }
        if ($sections !== []) {
            $projection['sections'] = $sections;
        }
        $projection['source_refs'] = array_values(array_unique(array_merge(
            (array) ($projection['source_refs'] ?? []),
            [
                'personality_profile_sections.mbti64_comparison_a_vs_t',
                $snapshotKey,
            ]
        )));
        $projection['faq'] = $faq;
        $projection['internal_links'] = $internalLinks;
        $projection['overlay_source'] = [
            'section_key' => 'mbti64_comparison_a_vs_t',
            'source' => $comparisonOverlay['source'] ?? 'mbti64_comparison_a_vs_t',
            'snapshot_key' => $snapshotKey,
            'base_type_code' => $baseTypeCode,
        ];
        if ($this->comparisonIndexabilityHeld($comparisonOverlay)) {
            $projection['is_indexable'] = false;
            $projection['sitemap_eligible'] = false;
            $projection['llms_eligible'] = false;
            $projection['indexability_status'] = 'held_for_mbti_index_24';
        }

        return $projection;
    }

    /**
     * @param  array<string,mixed>  $content
     * @return list<array<string,mixed>>
     */
    private function mbti64PromotedComparisonBlocks(array $content): array
    {
        $contentSections = $this->mbti64PromotedComparisonSections($content);
        if ($contentSections !== []) {
            return array_values(array_map(function (array $section): array {
                $body = implode("\n\n", (array) ($section['body'] ?? []));

                return [
                    'key' => (string) ($section['id'] ?? ''),
                    'title' => (string) ($section['title'] ?? ''),
                    'source' => 'personality_profile_sections.mbti64_comparison_a_vs_t',
                    'variants' => ['a' => $body, 't' => $body],
                    'body_md' => $body,
                    'rows' => is_array($section['rows'] ?? null) ? $section['rows'] : [],
                ];
            }, $contentSections));
        }

        $blocks = [];
        $summary = is_array($content['side_by_side_summary'] ?? null) ? $content['side_by_side_summary'] : [];
        $rows = is_array($summary['rows'] ?? null) ? array_values((array) $summary['rows']) : [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $title = $this->normalizedString($row['dimension'] ?? null) ?? 'Comparison '.((string) ($index + 1));
            $assertive = $this->normalizedString($row['a_variant'] ?? null);
            $turbulent = $this->normalizedString($row['t_variant'] ?? null);
            if ($assertive === null && $turbulent === null) {
                continue;
            }

            $blocks[] = [
                'key' => $this->comparisonBlockKey($title, 'side_by_side_'.$index),
                'title' => $title,
                'source' => 'personality_profile_sections.mbti64_comparison_a_vs_t',
                'variants' => [
                    'a' => $assertive ?? '',
                    't' => $turbulent ?? '',
                ],
                'body_md' => implode("\n\n", array_values(array_filter([
                    $assertive !== null ? 'A: '.$assertive : null,
                    $turbulent !== null ? 'T: '.$turbulent : null,
                ]))),
            ];
        }

        foreach ($content as $key => $value) {
            if ($key === 'side_by_side_summary' || $key === 'quick_answer') {
                continue;
            }

            if (! is_array($value)) {
                continue;
            }

            $body = $this->normalizedString($value['body'] ?? null);
            if ($body === null) {
                continue;
            }

            $blocks[] = [
                'key' => $this->comparisonBlockKey((string) $key, (string) $key),
                'title' => $this->normalizedString($value['h2'] ?? null) ?? str_replace('_', ' ', (string) $key),
                'source' => 'personality_profile_sections.mbti64_comparison_a_vs_t',
                'variants' => [
                    'a' => $body,
                    't' => $body,
                ],
                'body_md' => $body,
            ];
        }

        return $blocks;
    }

    /**
     * @param  array<string,mixed>  $content
     * @return list<array<string,mixed>>
     */
    private function mbti64PromotedComparisonSections(array $content): array
    {
        $sections = [];
        foreach (array_values((array) ($content['sections'] ?? [])) as $index => $section) {
            if (! is_array($section)) {
                continue;
            }

            $key = $this->normalizedString($section['key'] ?? null) ?? 'section-'.($index + 1);
            $title = $this->normalizedString($section['title'] ?? null);
            $body = $this->normalizedString($section['body'] ?? null);
            $rows = is_array($section['rows'] ?? null) ? array_values((array) $section['rows']) : [];
            if ($title === null || ($body === null && $rows === [])) {
                continue;
            }

            $projection = [
                'id' => $key,
                'title' => $title,
                'body' => $body === null ? [] : [$body],
            ];
            if ($rows !== []) {
                $projection['rows'] = $rows;
            }
            $sections[] = $projection;
        }

        return $sections;
    }

    private function comparisonBlockKey(string $value, string $fallback): string
    {
        $normalized = strtolower(trim(preg_replace('/[^a-zA-Z0-9_]+/', '_', $value) ?? '', '_'));

        return substr($normalized !== '' ? $normalized : $fallback, 0, 90);
    }

    /**
     * @return list<array<string,string|null>>
     */
    private function mbti64PromotedComparisonFaq(?array $comparisonOverlay): array
    {
        if ($comparisonOverlay === null || ! is_array($comparisonOverlay['faq'] ?? null)) {
            return [];
        }

        $items = [];
        foreach ((array) $comparisonOverlay['faq'] as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $question = $this->normalizedString($item['question'] ?? null);
            $answer = $this->normalizedString($item['answer'] ?? null);
            if ($question === null && $answer === null) {
                continue;
            }

            $items[] = [
                'key' => $this->normalizedString($item['id'] ?? $item['key'] ?? null) ?? 'mbti64-comparison-faq-'.$index,
                'question' => $question,
                'answer' => $answer,
            ];
        }

        return $items;
    }

    /**
     * @return list<array<string,string|null>>
     */
    private function mbti64PromotedComparisonInternalLinks(?array $comparisonOverlay, string $locale): array
    {
        if ($comparisonOverlay === null || ! is_array($comparisonOverlay['internal_links'] ?? null)) {
            return [];
        }

        $links = [];
        foreach ((array) $comparisonOverlay['internal_links'] as $index => $link) {
            if (! is_array($link) || ($link['safe_public_route'] ?? null) !== true) {
                continue;
            }

            $href = $this->normalizedString($link['href'] ?? null);
            $label = $this->normalizedString($link['anchor_text'] ?? null);
            if ($href === null || $label === null || $this->containsForbiddenPublicRoute($href)) {
                continue;
            }

            $links[] = [
                'key' => $this->normalizedString($link['role'] ?? null) ?? 'mbti64-comparison-link-'.$index,
                'label' => $label,
                'href' => $href,
                'kind' => $this->normalizedString($link['role'] ?? null) ?? 'content_continue',
                'locale' => $locale,
            ];
        }

        return $links;
    }

    /**
     * @param  list<array<string,mixed>>  $sections
     * @return list<array<string,string|null>>
     */
    private function content15ProfileInternalLinks(array $sections, string $locale): array
    {
        $links = [];
        foreach ($sections as $section) {
            if (! is_array($section) || (string) ($section['section_key'] ?? '') !== 'mbti_content15_internal_links') {
                continue;
            }

            foreach ((array) data_get($section, 'payload_json.items', []) as $index => $link) {
                if (! is_array($link) || ($link['safe_public_route'] ?? null) !== true) {
                    continue;
                }

                $href = $this->normalizedString($link['href'] ?? null);
                $label = $this->normalizedString($link['anchor_text'] ?? null);
                if ($href === null || $label === null || $this->containsForbiddenPublicRoute($href)) {
                    continue;
                }

                $links[] = [
                    'key' => $this->normalizedString($link['role'] ?? null) ?? 'mbti-content15-profile-link-'.$index,
                    'label' => $label,
                    'href' => $href,
                    'kind' => $this->normalizedString($link['role'] ?? null) ?? 'content_continue',
                    'locale' => $locale,
                ];
            }
        }

        return $links;
    }

    private function containsForbiddenPublicRoute(string $href): bool
    {
        return preg_match('#/(?:results|orders|share|pay|payment|history|private|account)(?:/|$)|(?:token|session|result_id|report_id|order_no)=#i', $href) === 1;
    }

    private function normalizedString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @param  array<string,mixed>  $projection
     * @return array<string,mixed>
     */
    private function comparisonVariantPayload(
        PersonalityProfile $profile,
        PersonalityProfileVariant $variant,
        array $projection,
        string $locale
    ): array {
        $routeSlug = $this->resolveRouteSlug($profile, $variant);
        $baseUrl = CanonicalFrontendUrl::fromConfig();
        $publicUrl = $baseUrl !== '' ? $baseUrl.'/'.$this->frontendLocaleSegment($locale).'/personality/'.$routeSlug : null;

        return [
            'profile_id' => (int) $profile->id,
            'variant_id' => (int) $variant->id,
            'base_type_code' => (string) data_get($projection, 'canonical_type_code', $profile->type_code),
            'runtime_type_code' => (string) data_get($projection, 'runtime_type_code', $variant->runtime_type_code),
            'variant_code' => (string) $variant->variant_code,
            'public_route_slug' => $routeSlug,
            'public_url' => $publicUrl,
            'display_type' => data_get($projection, 'display_type'),
            'type_name' => data_get($projection, 'profile.type_name'),
            'nickname' => data_get($projection, 'profile.nickname'),
            'rarity' => data_get($projection, 'profile.rarity'),
            'keywords' => is_array(data_get($projection, 'profile.keywords'))
                ? array_values(data_get($projection, 'profile.keywords'))
                : [],
            'hero_summary' => data_get($projection, 'profile.hero_summary'),
            'summary_card' => is_array(data_get($projection, 'summary_card')) ? data_get($projection, 'summary_card') : [],
            'seo' => is_array(data_get($projection, 'seo')) ? data_get($projection, 'seo') : [],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $assertiveSections
     * @param  list<array<string,mixed>>  $turbulentSections
     * @param  array<string,mixed>  $assertiveProjection
     * @param  array<string,mixed>  $turbulentProjection
     * @return list<array<string,mixed>>
     */
    private function comparisonBlocks(
        string $baseTypeCode,
        string $locale,
        array $assertiveSections,
        array $turbulentSections,
        array $assertiveProjection,
        array $turbulentProjection
    ): array {
        return array_values(array_filter([
            $this->comparisonBlock(
                'at_difference',
                $locale === 'zh-CN'
                    ? $baseTypeCode.'-A 和 '.$baseTypeCode.'-T 有什么区别？'
                    : $baseTypeCode.'-A vs '.$baseTypeCode.'-T: what is the difference?',
                $this->sectionBody($assertiveSections, 'traits.at_difference'),
                $this->sectionBody($turbulentSections, 'traits.at_difference'),
                'section_pair'
            ),
            $this->comparisonBlock(
                'traits',
                $locale === 'zh-CN' ? '核心特点对比' : 'Core traits comparison',
                (string) data_get($assertiveProjection, 'summary_card.summary', ''),
                (string) data_get($turbulentProjection, 'summary_card.summary', ''),
                'summary_pair'
            ),
            $this->comparisonBlock(
                'career',
                $locale === 'zh-CN' ? '适合职业与工作风格' : 'Career fit and work style',
                $this->sectionBody($assertiveSections, 'career.summary', 'career.fit'),
                $this->sectionBody($turbulentSections, 'career.summary', 'career.fit'),
                'section_pair'
            ),
            $this->comparisonBlock(
                'relationships',
                $locale === 'zh-CN' ? '爱情关系与相处方式' : 'Relationships and love style',
                $this->sectionBody($assertiveSections, 'relationships.summary', 'relationships'),
                $this->sectionBody($turbulentSections, 'relationships.summary', 'relationships'),
                'section_pair'
            ),
            $this->comparisonBlock(
                'rarity',
                $locale === 'zh-CN' ? '稀有度与识别线索' : 'Rarity and identification cues',
                (string) data_get($assertiveProjection, 'profile.rarity', ''),
                (string) data_get($turbulentProjection, 'profile.rarity', ''),
                'profile_field_pair'
            ),
        ]));
    }

    private function comparisonBlock(
        string $key,
        string $title,
        ?string $assertiveBody,
        ?string $turbulentBody,
        string $source
    ): ?array {
        $assertiveBody = trim((string) $assertiveBody);
        $turbulentBody = trim((string) $turbulentBody);
        if ($assertiveBody === '' && $turbulentBody === '') {
            return null;
        }

        return [
            'key' => $key,
            'title' => $title,
            'source' => $source,
            'variants' => [
                'a' => $assertiveBody,
                't' => $turbulentBody,
            ],
            'body_md' => implode("\n\n", array_values(array_filter([
                $assertiveBody !== '' ? 'A: '.$assertiveBody : null,
                $turbulentBody !== '' ? 'T: '.$turbulentBody : null,
            ]))),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $sections
     */
    private function sectionBody(array $sections, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            foreach ($sections as $section) {
                if (! is_array($section) || (string) ($section['section_key'] ?? '') !== $key) {
                    continue;
                }

                $body = trim((string) ($section['body_md'] ?? ''));
                if ($body !== '') {
                    return $body;
                }

                $items = data_get($section, 'payload_json.items');
                if (is_array($items) && $items !== []) {
                    $text = collect($items)
                        ->map(static function (mixed $item): string {
                            if (is_string($item)) {
                                return trim($item);
                            }

                            if (! is_array($item)) {
                                return '';
                            }

                            return trim(implode(' ', array_filter([
                                (string) ($item['title'] ?? ''),
                                (string) ($item['body'] ?? $item['summary'] ?? $item['description'] ?? ''),
                            ])));
                        })
                        ->filter(static fn (string $value): bool => $value !== '')
                        ->implode(' ');
                    if ($text !== '') {
                        return $text;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>
     */
    private function comparisonSeoMetaPayload(array $meta): array
    {
        return [
            'seo_title' => $meta['title'] ?? null,
            'seo_description' => $meta['description'] ?? null,
            'canonical_url' => $meta['canonical'] ?? null,
            'og_title' => data_get($meta, 'og.title'),
            'og_description' => data_get($meta, 'og.description'),
            'og_image_url' => data_get($meta, 'og.image'),
            'twitter_title' => data_get($meta, 'twitter.title'),
            'twitter_description' => data_get($meta, 'twitter.description'),
            'twitter_image_url' => data_get($meta, 'twitter.image'),
            'robots' => $meta['robots'] ?? null,
            'jsonld_overrides_json' => null,
        ];
    }

    /**
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>
     */
    private function buildComparisonLandingSurface(
        string $baseTypeCode,
        string $locale,
        array $meta,
        ?array $comparisonOverlay = null
    ): array {
        $segment = $this->frontendLocaleSegment($locale);
        $baseSlug = strtolower($baseTypeCode);
        $startTestPath = '/'.$segment.'/tests/mbti-personality-test-16-personality-types';
        $overlayLinks = $this->mbti64PromotedComparisonInternalLinks($comparisonOverlay, $locale);
        $fallbackCtas = [
            [
                'key' => 'assertive_detail',
                'label' => $baseTypeCode.'-A',
                'href' => '/'.$segment.'/personality/'.$baseSlug.'-a',
                'kind' => 'content_continue',
            ],
            [
                'key' => 'turbulent_detail',
                'label' => $baseTypeCode.'-T',
                'href' => '/'.$segment.'/personality/'.$baseSlug.'-t',
                'kind' => 'content_continue',
            ],
            [
                'key' => 'start_test',
                'label' => $locale === 'zh-CN' ? '开始测试' : 'Take the test',
                'href' => $startTestPath,
                'kind' => 'start_test',
            ],
        ];
        $ctaBundle = array_values(array_merge($overlayLinks, $fallbackCtas));
        $seenCtas = [];
        $ctaBundle = array_values(array_filter($ctaBundle, static function (array $cta) use (&$seenCtas): bool {
            $href = (string) ($cta['href'] ?? '');
            if ($href === '' || isset($seenCtas[$href])) {
                return false;
            }

            $seenCtas[$href] = true;

            return true;
        }));
        $indexabilityHeld = $this->comparisonIndexabilityHeld($comparisonOverlay);

        return $this->landingSurfaceContractService->build([
            'landing_scope' => $indexabilityHeld ? 'public_noindex_detail' : 'public_indexable_detail',
            'entry_surface' => 'personality_comparison',
            'entry_type' => 'mbti_at_pair',
            'summary_blocks' => [
                [
                    'key' => 'hero',
                    'title' => (string) ($meta['title'] ?? ''),
                    'body' => (string) ($meta['description'] ?? ''),
                    'kind' => 'answer_first',
                ],
            ],
            'discoverability_keys' => ['personality_comparison', 'personality_detail', 'start_test'],
            'continue_reading_keys' => ['personality_detail', 'topic_cluster'],
            'start_test_target' => $startTestPath,
            'content_continue_target' => '/'.$segment.'/personality/'.$baseSlug.'-a',
            'cta_bundle' => $ctaBundle,
            'indexability_state' => $indexabilityHeld ? 'noindex' : 'indexable',
            'attribution_scope' => 'public_personality_landing',
            'seo_surface_ref' => $this->comparisonSlug($baseTypeCode),
            'surface_family' => 'personality',
            'primary_content_ref' => $baseTypeCode.':A:T',
            'related_surface_keys' => ['personality_detail', 'topic_cluster', 'start_test'],
            'fingerprint_seed' => [
                'comparison_slug' => $this->comparisonSlug($baseTypeCode),
                'locale' => $locale,
                'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
                'overlay_source' => $comparisonOverlay !== null ? 'mbti64_comparison_a_vs_t' : null,
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>  $projection
     * @param  array<string,mixed>  $seoSurface
     * @param  array<string,mixed>  $landingSurface
     * @return array<string,mixed>
     */
    private function buildComparisonAnswerSurface(
        string $baseTypeCode,
        string $locale,
        array $projection,
        array $seoSurface,
        array $landingSurface,
        ?array $comparisonOverlay = null
    ): array {
        $indexabilityHeld = $this->comparisonIndexabilityHeld($comparisonOverlay);
        $summaryBlocks = [
            [
                'key' => 'comparison_summary',
                'title' => (string) ($projection['title'] ?? ''),
                'body' => (string) ($projection['description'] ?? ''),
                'kind' => 'answer_first',
            ],
        ];
        $compareBlocks = collect((array) ($projection['comparison_blocks'] ?? []))
            ->map(static fn (array $block): array => [
                'key' => (string) ($block['key'] ?? ''),
                'title' => (string) ($block['title'] ?? ''),
                'body' => (string) ($block['body_md'] ?? ''),
                'kind' => (string) ($block['source'] ?? 'comparison_pair'),
            ])
            ->values()
            ->all();
        $faqBlocks = $this->mbti64PromotedComparisonFaq($comparisonOverlay);
        $answerBundle = [
            ['key' => 'summary', 'title' => 'summary', 'count' => count($summaryBlocks)],
            ['key' => 'compare', 'title' => 'compare', 'count' => count($compareBlocks)],
        ];
        if ($faqBlocks !== []) {
            $answerBundle[] = ['key' => 'faq', 'title' => 'faq', 'count' => count($faqBlocks)];
        }

        return $this->answerSurfaceContractService->build([
            'answer_scope' => $indexabilityHeld ? 'public_noindex_detail' : 'public_indexable_detail',
            'surface_type' => 'personality_comparison_public_detail',
            'summary_blocks' => $summaryBlocks,
            'compare_blocks' => $compareBlocks,
            'next_step_blocks' => $this->answerSurfaceContractService->buildNextStepBlocksFromCtas(
                is_array($landingSurface['cta_bundle'] ?? null) ? $landingSurface['cta_bundle'] : [],
                3
            ),
            'faq_blocks' => $faqBlocks,
            'answer_bundle' => $answerBundle,
            'evidence_refs' => array_values(array_filter([
                (string) ($seoSurface['metadata_fingerprint'] ?? ''),
                (string) ($landingSurface['landing_fingerprint'] ?? ''),
                'comparison_public_projection_v1',
                'personality_variant_sections',
                'personality_variant_seo_meta',
                $comparisonOverlay !== null ? 'personality_profile_sections.mbti64_comparison_a_vs_t' : null,
            ])),
            'public_safety_state' => $indexabilityHeld ? 'public_noindex' : 'public_indexable',
            'indexability_state' => $indexabilityHeld ? 'noindex' : 'indexable',
            'attribution_scope' => 'public_personality_answer',
            'seo_surface_ref' => (string) ($seoSurface['metadata_fingerprint'] ?? ''),
            'landing_surface_ref' => (string) ($landingSurface['landing_fingerprint'] ?? ''),
            'primary_content_ref' => $baseTypeCode.':A:T',
            'related_surface_keys' => ['personality_detail', 'topic_cluster', 'start_test'],
            'fingerprint_seed' => [
                'comparison_slug' => $this->comparisonSlug($baseTypeCode),
                'locale' => $locale,
                'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
                'overlay_source' => $comparisonOverlay !== null ? 'mbti64_comparison_a_vs_t' : null,
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>
     */
    private function buildSeoSurface(array $meta, array $jsonLd, string $surfaceType): array
    {
        return $this->seoSurfaceContractService->build([
            'metadata_scope' => 'public_indexable_detail',
            'surface_type' => $surfaceType,
            'canonical_url' => $meta['canonical'] ?? null,
            'robots_policy' => $meta['robots'] ?? null,
            'title' => $meta['title'] ?? null,
            'description' => $meta['description'] ?? null,
            'og_payload' => is_array($meta['og'] ?? null) ? $meta['og'] : [],
            'twitter_payload' => is_array($meta['twitter'] ?? null) ? $meta['twitter'] : [],
            'alternates' => is_array($meta['alternates'] ?? null) ? $meta['alternates'] : [],
            'structured_data' => $jsonLd,
        ]);
    }

    /**
     * @param  array<string,mixed>  $projection
     * @return array<string,mixed>
     */
    private function buildDetailLandingSurface(
        PersonalityProfile $profile,
        ?PersonalityProfileVariant $variant,
        array $projection,
        string $locale,
        bool $isIndexable,
    ): array {
        $segment = $this->frontendLocaleSegment($locale);
        $routeSlug = $this->resolveRouteSlug($profile, $variant);
        $isMbtiScale = $this->isMbtiProfile($profile);
        $careerPath = $isMbtiScale ? '/'.$segment.'/career/recommendations/mbti/'.$routeSlug : null;
        $topicPath = '/'.$segment.'/topics/'.$this->personalityTopicSlug($profile);
        $startTestPath = $this->personalityStartTestPath($profile, $segment);

        return $this->landingSurfaceContractService->build([
            'landing_scope' => $isIndexable ? 'public_indexable_detail' : 'public_noindex_detail',
            'entry_surface' => 'personality_detail',
            'entry_type' => 'personality_profile',
            'summary_blocks' => [
                [
                    'key' => 'hero',
                    'title' => (string) ($profile->title ?? ''),
                    'body' => trim((string) ($projection['summary_card']['summary'] ?? $profile->excerpt ?? '')),
                    'kind' => 'answer_first',
                ],
            ],
            'discoverability_keys' => array_values(array_filter([
                'personality_detail',
                'topic_cluster',
                $isMbtiScale ? 'career_recommendation' : null,
                'start_test',
            ])),
            'continue_reading_keys' => array_values(array_filter([
                $isMbtiScale ? 'career_recommendation' : null,
                'topic_cluster',
                'related_content',
            ])),
            'start_test_target' => $startTestPath,
            'result_resume_target' => null,
            'content_continue_target' => $careerPath ?? $topicPath,
            'cta_bundle' => array_values(array_filter([
                [
                    'key' => 'start_test',
                    'label' => $locale === 'zh-CN' ? '开始测试' : 'Take the test',
                    'href' => $startTestPath,
                    'kind' => 'start_test',
                ],
                $isMbtiScale ? [
                    'key' => 'career_recommendation',
                    'label' => $locale === 'zh-CN' ? '查看职业推荐' : 'View career recommendations',
                    'href' => (string) $careerPath,
                    'kind' => 'content_continue',
                ] : null,
                [
                    'key' => 'topic_cluster',
                    'label' => $locale === 'zh-CN' ? '查看主题聚合' : 'Browse topic hub',
                    'href' => $topicPath,
                    'kind' => 'discover',
                ],
            ])),
            'indexability_state' => $isIndexable ? 'indexable' : 'noindex',
            'attribution_scope' => 'public_personality_landing',
            'seo_surface_ref' => (string) ($profile->slug ?? ''),
            'surface_family' => 'personality',
            'primary_content_ref' => (string) ($variant?->runtime_type_code ?? $profile->type_code ?? $profile->slug ?? ''),
            'related_surface_keys' => array_values(array_filter([
                $isMbtiScale ? 'career_recommendation' : null,
                'topic_cluster',
            ])),
            'fingerprint_seed' => [
                'slug' => (string) ($profile->slug ?? ''),
                'runtime_type_code' => (string) ($variant?->runtime_type_code ?? ''),
                'locale' => $locale,
                'scale_code' => $this->normalizedProfileScaleCode($profile),
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>  $projection
     * @param  array<int,array<string,mixed>>  $sections
     * @param  array<string,mixed>  $seoSurface
     * @param  array<string,mixed>  $landingSurface
     * @return array<string,mixed>
     */
    private function buildDetailAnswerSurface(
        PersonalityProfile $profile,
        ?PersonalityProfileVariant $variant,
        array $projection,
        array $sections,
        array $seoSurface,
        array $landingSurface,
        string $locale,
        array $internalLinks = [],
        bool $isIndexable = false,
    ): array {
        $summary = trim((string) ($projection['summary_card']['summary'] ?? $profile->excerpt ?? ''));
        $subtitle = trim((string) ($projection['summary_card']['subtitle'] ?? $profile->subtitle ?? ''));
        $compareBlocks = $this->answerSurfaceContractService->buildCompareBlocksFromDimensionPayloads(
            is_array($projection['dimensions'] ?? null) ? $projection['dimensions'] : [],
            2
        );
        $routeSlug = $this->resolveRouteSlug($profile, $variant);
        $isMbtiScale = $this->isMbtiProfile($profile);
        $sceneSummaryBlocks = $isMbtiScale ? $this->buildMbtiSceneSummaryBlocks($locale, $routeSlug) : [];
        $summaryBlocks = array_values(array_filter([
            [
                'key' => 'type_summary',
                'title' => (string) ($profile->title ?? ''),
                'body' => $summary,
                'kind' => 'answer_first',
            ],
            $subtitle !== ''
                ? [
                    'key' => 'type_context',
                    'title' => (string) (($variant?->runtime_type_code ?? $profile->type_code ?? '')),
                    'body' => $subtitle,
                    'kind' => 'context',
                ]
                : null,
        ]));

        return $this->answerSurfaceContractService->build([
            'answer_scope' => $isIndexable ? 'public_indexable_detail' : 'public_noindex_detail',
            'surface_type' => 'personality_public_detail',
            'summary_blocks' => $summaryBlocks,
            'faq_blocks' => $this->profileFaqBlocks($sections),
            'compare_blocks' => $compareBlocks,
            'scene_summary_blocks' => $sceneSummaryBlocks,
            'next_step_blocks' => $this->answerSurfaceContractService->buildNextStepBlocksFromCtas(
                array_merge(
                    $internalLinks,
                    is_array($landingSurface['cta_bundle'] ?? null) ? $landingSurface['cta_bundle'] : [],
                ),
                5
            ),
            'answer_bundle' => [
                ['key' => 'summary', 'title' => 'summary', 'count' => count($summaryBlocks)],
                ['key' => 'compare', 'title' => 'compare', 'count' => count($compareBlocks)],
            ],
            'evidence_refs' => array_values(array_filter([
                (string) ($seoSurface['metadata_fingerprint'] ?? ''),
                (string) ($landingSurface['landing_fingerprint'] ?? ''),
                $variant instanceof PersonalityProfileVariant ? '' : 'personality_public_projection_v1',
                $isMbtiScale ? 'mbti_public_projection_v1' : '',
                count($compareBlocks) > 0 ? 'projection_dimensions' : '',
                count($sections) > 0 ? 'personality_sections' : '',
                $internalLinks !== [] ? 'personality_profile_variant_sections.mbti_content15_internal_links' : '',
                $sceneSummaryBlocks !== [] ? 'scene_summary_blocks' : '',
            ])),
            'public_safety_state' => $isIndexable ? 'public_indexable' : 'public_noindex',
            'indexability_state' => $isIndexable ? 'indexable' : 'noindex',
            'attribution_scope' => 'public_personality_answer',
            'seo_surface_ref' => (string) ($seoSurface['metadata_fingerprint'] ?? ''),
            'landing_surface_ref' => (string) ($landingSurface['landing_fingerprint'] ?? ''),
            'primary_content_ref' => (string) ($variant?->runtime_type_code ?? $profile->type_code ?? $profile->slug ?? ''),
            'related_surface_keys' => array_values(array_filter([
                $isMbtiScale ? 'career_recommendation' : null,
                'topic_cluster',
                'start_test',
            ])),
            'fingerprint_seed' => [
                'slug' => (string) ($profile->slug ?? ''),
                'locale' => $locale,
                'runtime_type_code' => (string) ($variant?->runtime_type_code ?? ''),
                'scale_code' => $this->normalizedProfileScaleCode($profile),
            ],
        ]);
    }

    /**
     * @param  array<int,array<string,mixed>>  $sections
     * @return list<array<string,string|null>>
     */
    private function profileFaqBlocks(array $sections, int $limit = 4): array
    {
        $faqBlocks = $this->answerSurfaceContractService->extractFaqBlocksFromSectionPayloads($sections, $limit);
        if ($faqBlocks !== [] || $limit <= 0) {
            return $faqBlocks;
        }

        foreach ($sections as $section) {
            if (! is_array($section) || strtolower((string) ($section['section_key'] ?? '')) !== 'mbti64_promotion_metadata') {
                continue;
            }

            $items = data_get($section, 'payload_json.raw_row.faq', []);
            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }

                $question = $this->normalizedString($item['question'] ?? $item['q'] ?? null);
                $answer = $this->normalizedString($item['answer'] ?? $item['a'] ?? null);
                if ($question === null && $answer === null) {
                    continue;
                }

                $faqBlocks[] = [
                    'key' => $this->normalizedString($item['key'] ?? $item['id'] ?? null) ?? 'mbti64-promotion-faq-'.$index,
                    'question' => $question,
                    'answer' => $answer,
                ];

                if (count($faqBlocks) >= $limit) {
                    return $faqBlocks;
                }
            }
        }

        return $faqBlocks;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildIndexLandingSurface(string $locale): array
    {
        $segment = $this->frontendLocaleSegment($locale);

        return $this->landingSurfaceContractService->build([
            'landing_scope' => 'public_indexable_index',
            'entry_surface' => 'personality_index',
            'entry_type' => 'personality_family_index',
            'summary_blocks' => [
                [
                    'key' => 'hero',
                    'title' => $locale === 'zh-CN' ? '人格类型' : 'Personality types',
                    'body' => $locale === 'zh-CN'
                        ? '浏览 16 型人格的公开画像、职业建议与延伸阅读。'
                        : 'Browse public personality profiles, career guidance, and next reading paths across all 16 types.',
                    'kind' => 'hero',
                ],
            ],
            'discoverability_keys' => ['personality_index', 'topic_cluster', 'career_recommendation', 'start_test'],
            'continue_reading_keys' => ['topic_cluster', 'personality_detail'],
            'start_test_target' => '/'.$segment.'/tests/mbti-personality-test-16-personality-types',
            'result_resume_target' => null,
            'content_continue_target' => '/'.$segment.'/topics/mbti',
            'cta_bundle' => [
                [
                    'key' => 'start_test',
                    'label' => $locale === 'zh-CN' ? '开始测试' : 'Take the test',
                    'href' => '/'.$segment.'/tests/mbti-personality-test-16-personality-types',
                    'kind' => 'start_test',
                ],
                [
                    'key' => 'topic_cluster',
                    'label' => $locale === 'zh-CN' ? '查看主题聚合' : 'Browse topic hub',
                    'href' => '/'.$segment.'/topics/mbti',
                    'kind' => 'discover',
                ],
            ],
            'indexability_state' => 'indexable',
            'attribution_scope' => 'public_personality_landing',
            'surface_family' => 'personality',
            'primary_content_ref' => 'personality:index',
            'related_surface_keys' => ['personality_detail', 'topic_cluster'],
            'fingerprint_seed' => ['locale' => $locale],
        ]);
    }

    /**
     * @return list<array<string,string>>
     */
    private function buildMbtiSceneSummaryBlocks(string $locale, string $routeSlug): array
    {
        $segment = $this->frontendLocaleSegment($locale);
        $startTestPath = '/'.$segment.'/tests/mbti-personality-test-16-personality-types';
        $profilePath = '/'.$segment.'/personality/'.rawurlencode($routeSlug);

        return [
            [
                'key' => 'career_direction',
                'title' => $locale === 'zh-CN' ? '职业方向' : 'Career direction',
                'body' => $locale === 'zh-CN'
                    ? '先看职业推荐入口，再回到类型页做验证。'
                    : 'Review career recommendations first, then validate from the type page.',
                'href' => '/'.$segment.'/career/recommendations',
                'kind' => 'scene_entry',
            ],
            [
                'key' => 'major_selection',
                'title' => $locale === 'zh-CN' ? '专业选择' : 'Major selection',
                'body' => $locale === 'zh-CN'
                    ? '先回 MBTI 主题页整理判断框架，再做路径选择。'
                    : 'Use the MBTI topic hub as the decision frame before choosing a major path.',
                'href' => '/'.$segment.'/topics/mbti',
                'kind' => 'scene_entry',
            ],
            [
                'key' => 'team_collaboration',
                'title' => $locale === 'zh-CN' ? '团队协作' : 'Team collaboration',
                'body' => $locale === 'zh-CN'
                    ? '保留当前类型页，继续对照团队协作特征。'
                    : 'Keep this type page as reference when comparing collaboration patterns.',
                'href' => $profilePath,
                'kind' => 'scene_entry',
            ],
            [
                'key' => 'relationship_patterns',
                'title' => $locale === 'zh-CN' ? '关系相处' : 'Relationship patterns',
                'body' => $locale === 'zh-CN'
                    ? '从类型页回到主题页，补齐关系场景解释。'
                    : 'Jump from type detail back to topic context for relationship cues.',
                'href' => '/'.$segment.'/topics/mbti',
                'kind' => 'scene_entry',
            ],
            [
                'key' => 'growth_planning',
                'title' => $locale === 'zh-CN' ? '成长建议' : 'Growth planning',
                'body' => $locale === 'zh-CN'
                    ? '直接开始测试，拿到结构化成长建议。'
                    : 'Start the test directly to generate structured growth suggestions.',
                'href' => $startTestPath,
                'kind' => 'scene_entry',
            ],
        ];
    }

    private function resolveRouteSlug(PersonalityProfile $profile, ?PersonalityProfileVariant $variant): string
    {
        $runtimeType = strtoupper(trim((string) ($variant?->runtime_type_code ?? '')));
        if ($runtimeType !== '') {
            return strtolower($runtimeType);
        }

        return strtolower(trim((string) ($profile->slug ?? $profile->type_code ?? '')));
    }

    private function frontendLocaleSegment(string $locale): string
    {
        return $locale === 'zh-CN' ? 'zh' : 'en';
    }

    private function normalizedProfileScaleCode(PersonalityProfile $profile): string
    {
        $scaleCode = strtoupper(trim((string) ($profile->scale_code ?? '')));

        return $scaleCode !== '' ? $scaleCode : PersonalityProfile::SCALE_CODE_MBTI;
    }

    private function isMbtiProfile(PersonalityProfile $profile): bool
    {
        return $this->normalizedProfileScaleCode($profile) === PersonalityProfile::SCALE_CODE_MBTI;
    }

    private function personalitySeoSurfaceType(PersonalityProfile $profile): string
    {
        return match ($this->normalizedProfileScaleCode($profile)) {
            PersonalityProfile::SCALE_CODE_MBTI => 'mbti_personality_public_detail',
            'ENNEAGRAM' => 'enneagram_personality_public_detail',
            default => 'personality_public_detail',
        };
    }

    private function personalityTopicSlug(PersonalityProfile $profile): string
    {
        return match ($this->normalizedProfileScaleCode($profile)) {
            PersonalityProfile::SCALE_CODE_MBTI => 'mbti',
            'ENNEAGRAM' => 'enneagram',
            default => strtolower($this->normalizedProfileScaleCode($profile)),
        };
    }

    private function personalityStartTestPath(PersonalityProfile $profile, string $segment): string
    {
        return match ($this->normalizedProfileScaleCode($profile)) {
            PersonalityProfile::SCALE_CODE_MBTI => '/'.$segment.'/tests/mbti-personality-test-16-personality-types',
            'ENNEAGRAM' => '/'.$segment.'/tests/enneagram-personality-test-nine-types',
            default => '/'.$segment.'/tests',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function profileListPayload(PersonalityProfile $profile): array
    {
        return array_merge([
            'id' => (int) $profile->id,
            'org_id' => (int) $profile->org_id,
            'scale_code' => (string) $profile->scale_code,
            'type_code' => (string) $profile->type_code,
            'slug' => (string) $profile->slug,
            'locale' => (string) $profile->locale,
            'title' => (string) $profile->title,
            'subtitle' => $profile->subtitle,
            'excerpt' => $profile->excerpt,
            'hero_image_url' => PublicMediaUrlGuard::sanitizeNullableUrl($profile->hero_image_url),
            'status' => (string) $profile->status,
            'is_public' => (bool) $profile->is_public,
            'is_indexable' => (bool) $profile->is_indexable,
            'published_at' => $profile->published_at?->toISOString(),
            'updated_at' => $profile->updated_at?->toISOString(),
            'seo_meta' => $this->seoMetaSummaryPayload($profile->seoMeta),
        ], $this->personalityProfileService->publicCanonicalFields($profile));
    }

    /**
     * @return array<string, mixed>
     */
    private function variantListPayload(PersonalityProfileVariant $variant): array
    {
        $profile = $variant->profile;
        if (! $profile instanceof PersonalityProfile) {
            return [];
        }

        $projection = $this->sanitizePublicProjection(
            $this->personalityProfileService->buildPublicProjection($profile, $variant)
        );
        $meta = PublicMediaUrlGuard::sanitizeSeoMeta(
            $this->personalityProfileSeoService->buildMeta($profile, $variant)
        );
        $isIndexable = $this->profileAllowsIndexing($profile, $meta);
        $runtimeTypeCode = strtoupper(trim((string) ($variant->runtime_type_code ?? '')));
        $canonicalTypeCode = strtoupper(trim((string) ($variant->canonical_type_code ?: $profile->canonical_type_code ?: $profile->type_code)));
        $routeSlug = strtolower($runtimeTypeCode);

        return array_merge([
            'id' => (int) $variant->id,
            'variant_id' => (int) $variant->id,
            'profile_id' => (int) $profile->id,
            'org_id' => (int) $profile->org_id,
            'scale_code' => (string) $profile->scale_code,
            'type_code' => $runtimeTypeCode,
            'base_type_code' => $canonicalTypeCode,
            'runtime_type_code' => $runtimeTypeCode,
            'variant_code' => (string) $variant->variant_code,
            'slug' => $routeSlug,
            'base_slug' => (string) $profile->slug,
            'locale' => (string) $profile->locale,
            'title' => (string) $profile->title,
            'subtitle' => $profile->subtitle,
            'excerpt' => $profile->excerpt,
            'hero_image_url' => PublicMediaUrlGuard::sanitizeNullableUrl($profile->hero_image_url),
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => $isIndexable,
            'published_at' => $variant->published_at?->toISOString(),
            'updated_at' => $variant->updated_at?->toISOString(),
            'seo_meta' => $this->variantSeoMetaSummaryPayload($profile, $variant),
            'display_type' => data_get($projection, 'display_type'),
            'public_route_slug' => data_get($projection, 'public_route_slug', $routeSlug),
            'public_route_type' => data_get($projection, '_meta.public_route_type', '32-type'),
        ], $this->personalityProfileService->publicCanonicalFields($profile, $variant));
    }

    /**
     * @return array<string, mixed>
     */
    private function profileDetailPayload(
        PersonalityProfile $profile,
        ?PersonalityProfileVariant $variant = null,
        bool $isIndexable = false,
    ): array {
        return array_merge([
            'id' => (int) $profile->id,
            'org_id' => (int) $profile->org_id,
            'scale_code' => (string) $profile->scale_code,
            'type_code' => (string) $profile->type_code,
            'slug' => (string) $profile->slug,
            'locale' => (string) $profile->locale,
            'title' => (string) $profile->title,
            'subtitle' => $profile->subtitle,
            'excerpt' => $profile->excerpt,
            'hero_kicker' => $profile->hero_kicker,
            'hero_quote' => $profile->hero_quote,
            'hero_image_url' => PublicMediaUrlGuard::sanitizeNullableUrl($profile->hero_image_url),
            'status' => (string) $profile->status,
            'is_public' => (bool) $profile->is_public,
            'is_indexable' => $isIndexable,
            'published_at' => $profile->published_at?->toISOString(),
            'updated_at' => $profile->updated_at?->toISOString(),
        ], $this->personalityProfileService->publicCanonicalFields($profile, $variant));
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    private function profileAllowsIndexing(PersonalityProfile $profile, array $meta): bool
    {
        if (! (bool) $profile->is_indexable) {
            return false;
        }

        $robots = strtolower(trim((string) ($meta['robots'] ?? '')));

        return $robots !== '' && ! str_contains($robots, 'noindex');
    }

    /**
     * @return array<string, mixed>
     */
    private function sectionPayload(PersonalityProfileSection $section): array
    {
        return [
            'section_key' => (string) $section->section_key,
            'title' => $section->title,
            'render_variant' => (string) $section->render_variant,
            'body_md' => $section->body_md,
            'body_html' => $section->body_html,
            'payload_json' => $section->payload_json,
            'sort_order' => (int) $section->sort_order,
            'is_enabled' => (bool) $section->is_enabled,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function seoMetaPayload(PersonalityProfile $profile, ?PersonalityProfileVariant $variant = null): ?array
    {
        $seoMeta = $profile->seoMeta;

        if (! $seoMeta instanceof PersonalityProfileSeoMeta && ! ($variant?->seoMeta instanceof PersonalityProfileVariantSeoMeta)) {
            return null;
        }

        $meta = [
            'seo_title' => $seoMeta?->seo_title,
            'seo_description' => $seoMeta?->seo_description,
            'canonical_url' => $this->personalityProfileSeoService->buildCanonicalUrl($profile, (string) $profile->locale, $variant),
            'og_title' => $seoMeta?->og_title,
            'og_description' => $seoMeta?->og_description,
            'og_image_url' => PublicMediaUrlGuard::sanitizeNullableUrl($seoMeta?->og_image_url),
            'twitter_title' => $seoMeta?->twitter_title,
            'twitter_description' => $seoMeta?->twitter_description,
            'twitter_image_url' => PublicMediaUrlGuard::sanitizeNullableUrl($seoMeta?->twitter_image_url),
            'robots' => $seoMeta?->robots,
            'jsonld_overrides_json' => CanonicalFrontendUrl::normalizeNestedUrls($seoMeta?->jsonld_overrides_json),
        ];

        $variantSeoMeta = $variant?->seoMeta;
        if ($variantSeoMeta instanceof PersonalityProfileVariantSeoMeta) {
            foreach ([
                'seo_title',
                'seo_description',
                'og_title',
                'og_description',
                'og_image_url',
                'twitter_title',
                'twitter_description',
                'twitter_image_url',
                'robots',
            ] as $field) {
                if ($variantSeoMeta->{$field} !== null) {
                    $meta[$field] = in_array($field, ['og_image_url', 'twitter_image_url'], true)
                        ? PublicMediaUrlGuard::sanitizeNullableUrl($variantSeoMeta->{$field})
                        : $variantSeoMeta->{$field};
                }
            }

            if (is_array($variantSeoMeta->jsonld_overrides_json) && $variantSeoMeta->jsonld_overrides_json !== []) {
                $meta['jsonld_overrides_json'] = CanonicalFrontendUrl::normalizeNestedUrls(
                    $variantSeoMeta->jsonld_overrides_json
                );
            }
        }

        return $meta;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function publicSectionPayloads(PersonalityProfile $profile, ?PersonalityProfileVariant $variant = null): array
    {
        $sections = $profile->sections
            ->filter(fn (PersonalityProfileSection $section): bool => $this->isPublicSection(
                (bool) $section->is_enabled,
                (string) $section->render_variant,
            ))
            ->mapWithKeys(fn (PersonalityProfileSection $section): array => [
                (string) $section->section_key => $this->sectionPayload($section),
            ]);

        if ($variant instanceof PersonalityProfileVariant) {
            foreach ($variant->sections as $section) {
                if (! $section instanceof PersonalityProfileVariantSection) {
                    continue;
                }

                $sectionKey = trim((string) $section->section_key);
                if ($sectionKey === '') {
                    continue;
                }

                if (! $this->isPublicSection((bool) $section->is_enabled, (string) $section->render_variant)) {
                    $sections->forget($sectionKey);

                    continue;
                }

                /** @var array<string, mixed>|null $baseSection */
                $baseSection = $sections->get($sectionKey);
                $definition = $this->mbtiCanonicalSectionDefinition($sectionKey);
                $payload = is_array($section->payload_json)
                    ? $section->payload_json
                    : ($baseSection['payload_json'] ?? null);
                $sections->put($sectionKey, [
                    'section_key' => $sectionKey,
                    'title' => data_get($payload, 'title') ?? $baseSection['title'] ?? ($definition['title'] ?? null),
                    'render_variant' => (string) ($section->render_variant ?: ($baseSection['render_variant'] ?? 'rich_text')),
                    'body_md' => $this->sanitizeV85PublicBody(
                        $sectionKey,
                        $section->body_md ?? $baseSection['body_md'] ?? null
                    ),
                    'body_html' => $section->body_html ?? $baseSection['body_html'] ?? null,
                    'payload_json' => $this->sanitizeV85PublicPayload(
                        $sectionKey,
                        is_array($payload) ? $payload : null
                    ),
                    'sort_order' => (int) ($section->sort_order ?? $baseSection['sort_order'] ?? 0),
                    'is_enabled' => true,
                ]);
            }
        }

        if ($this->hasV85FirstClassSections($sections->keys()->all())) {
            foreach ($sections->keys()->all() as $sectionKey) {
                if (! is_string($sectionKey)) {
                    continue;
                }

                if (! $this->shouldSuppressV85DuplicateSection($sectionKey)) {
                    continue;
                }

                $sections->forget($sectionKey);
            }
        }

        return $sections
            ->sortBy([
                ['sort_order', 'asc'],
                ['section_key', 'asc'],
            ])
            ->values()
            ->all();
    }

    private function isPublicSection(bool $isEnabled, string $renderVariant): bool
    {
        return $isEnabled && $renderVariant !== 'premium_teaser';
    }

    /**
     * @param  array<string,mixed>  $projection
     * @return array<string,mixed>
     */
    private function sanitizePublicProjection(array $projection): array
    {
        $projection['sections'] = array_values(array_filter(
            (array) ($projection['sections'] ?? []),
            fn (mixed $section): bool => is_array($section) && $this->isPublicSection(
                (bool) ($section['is_enabled'] ?? false),
                (string) ($section['render'] ?? ''),
            )
        ));

        return $projection;
    }

    /**
     * @param  list<mixed>  $sectionKeys
     */
    private function hasV85FirstClassSections(array $sectionKeys): bool
    {
        foreach ($sectionKeys as $sectionKey) {
            if (str_starts_with((string) $sectionKey, self::MBTI64_V85_SECTION_PREFIX)) {
                return true;
            }
        }

        return false;
    }

    private function shouldSuppressV85DuplicateSection(string $sectionKey): bool
    {
        if (in_array($sectionKey, self::MBTI64_V85_DUPLICATE_LEGACY_SECTION_KEYS, true)) {
            return true;
        }

        foreach (self::MBTI64_V85_DUPLICATE_LEGACY_SECTION_PREFIXES as $prefix) {
            if (str_starts_with($sectionKey, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function sanitizeV85PublicBody(string $sectionKey, mixed $body): mixed
    {
        if (! str_starts_with($sectionKey, 'v8_5_module_') || ! is_string($body)) {
            return $body;
        }

        return preg_replace('/\n{2,}Evidence boundary:\n(?:[-*] .+(?:\n|$))+$/u', '', $body) ?? $body;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    private function sanitizeV85PublicPayload(string $sectionKey, ?array $payload): ?array
    {
        if (! str_starts_with($sectionKey, 'v8_5_module_') || $payload === null) {
            return $payload;
        }

        if (array_key_exists('body', $payload)) {
            $payload['body'] = $this->sanitizeV85PublicBody($sectionKey, $payload['body']);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function mbtiCanonicalSectionDefinition(string $sectionKey): array
    {
        try {
            return MbtiCanonicalSectionRegistry::definition($sectionKey);
        } catch (\InvalidArgumentException) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function seoMetaSummaryPayload(?PersonalityProfileSeoMeta $seoMeta): ?array
    {
        if (! $seoMeta instanceof PersonalityProfileSeoMeta) {
            return null;
        }

        return [
            'seo_title' => $seoMeta->seo_title,
            'seo_description' => $seoMeta->seo_description,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function variantSeoMetaSummaryPayload(
        PersonalityProfile $profile,
        PersonalityProfileVariant $variant
    ): ?array {
        $profileSeoMeta = $profile->seoMeta;
        $variantSeoMeta = $variant->seoMeta;

        if (! $profileSeoMeta instanceof PersonalityProfileSeoMeta && ! $variantSeoMeta instanceof PersonalityProfileVariantSeoMeta) {
            return null;
        }

        return [
            'seo_title' => $variantSeoMeta?->seo_title ?? $profileSeoMeta?->seo_title,
            'seo_description' => $variantSeoMeta?->seo_description ?? $profileSeoMeta?->seo_description,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function publicReadResponse(array $payload, string $cacheState): JsonResponse
    {
        return response()->json($payload)
            ->header(self::PUBLIC_READ_CACHE_HEADER, $cacheState);
    }

    /**
     * @param  array{org_id:int,scale_code:string,locale:string}  $validated
     */
    private function stalePublicReadResponseOrThrow(
        string $surface,
        string $type,
        array $validated,
        Throwable $throwable,
    ): JsonResponse {
        $staleRead = $this->personalityPublicReadModelCache->stale(
            $surface,
            $type,
            $validated['locale'],
            $validated['org_id'],
            $validated['scale_code'],
        );
        if (is_array($staleRead['payload'])) {
            return $this->publicReadResponse($staleRead['payload'], $staleRead['state']);
        }

        throw $throwable;
    }

    /**
     * @return array{org_id:int,scale_code:string,locale:string,page:int,per_page:int,include_variants:bool}|JsonResponse
     */
    private function validateListQuery(Request $request): array|JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'org_id' => ['nullable', 'integer', 'min:0'],
            'scale_code' => ['nullable', 'in:MBTI'],
            'locale' => ['required', 'in:en,zh-CN'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'include_variants' => ['nullable', 'in:0,1'],
        ]);

        if ($validator->fails()) {
            return $this->invalidArgument($validator->errors()->first());
        }

        $validated = $validator->validated();

        return [
            'org_id' => (int) ($validated['org_id'] ?? 0),
            'scale_code' => (string) ($validated['scale_code'] ?? PersonalityProfile::SCALE_CODE_MBTI),
            'locale' => (string) $validated['locale'],
            'page' => (int) ($validated['page'] ?? 1),
            'per_page' => (int) ($validated['per_page'] ?? 20),
            'include_variants' => filter_var($validated['include_variants'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /**
     * @return array{org_id:int,scale_code:string,locale:string}|JsonResponse
     */
    private function validateReadQuery(Request $request): array|JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'org_id' => ['nullable', 'integer', 'min:0'],
            'scale_code' => ['nullable', 'in:MBTI'],
            'locale' => ['required', 'in:en,zh-CN'],
        ]);

        if ($validator->fails()) {
            return $this->invalidArgument($validator->errors()->first());
        }

        $validated = $validator->validated();

        return [
            'org_id' => (int) ($validated['org_id'] ?? 0),
            'scale_code' => (string) ($validated['scale_code'] ?? PersonalityProfile::SCALE_CODE_MBTI),
            'locale' => (string) $validated['locale'],
        ];
    }

    /** @param array{org_id:int,scale_code:string,locale:string} $validated */
    private function publicReadModelVersion(
        PersonalityProfile $profile,
        ?PersonalityProfileVariant $variant,
        string $type,
        array $validated,
    ): string {
        return hash('xxh3', json_encode([
            'profile' => $profile->getAttributes(),
            'variant' => $variant?->getAttributes(),
            'content_generation' => $this->personalityPublicReadModelCache->versionToken(
                $type,
                $validated['locale'],
                $validated['org_id'],
                $validated['scale_code'],
            ),
        ], JSON_THROW_ON_ERROR));
    }

    private function invalidArgument(string $message): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error_code' => 'INVALID_ARGUMENT',
            'message' => $message,
        ], 422);
    }
}
