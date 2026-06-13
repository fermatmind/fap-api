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
use App\Services\Cms\PersonalityProfileSeoService;
use App\Services\Cms\PersonalityProfileService;
use App\Services\PublicSurface\AnswerSurfaceContractService;
use App\Services\PublicSurface\LandingSurfaceContractService;
use App\Services\PublicSurface\SeoSurfaceContractService;
use App\Support\CanonicalFrontendUrl;
use App\Support\Mbti\MbtiCanonicalSectionRegistry;
use App\Support\PublicMediaUrlGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PersonalityController extends Controller
{
    use RespondsWithNotFound;

    public function __construct(
        private readonly PersonalityProfileService $personalityProfileService,
        private readonly PersonalityProfileSeoService $personalityProfileSeoService,
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

        $routeProfile = $this->personalityProfileService->getPublicDetailRouteProfileByType(
            $type,
            $validated['org_id'],
            $validated['scale_code'],
            $validated['locale'],
        );

        if (! is_array($routeProfile)) {
            return $this->notFoundResponse('personality profile not found.');
        }

        /** @var PersonalityProfile $profile */
        $profile = $routeProfile['profile'];
        /** @var PersonalityProfileVariant|null $variant */
        $variant = $routeProfile['variant'];
        $projection = $this->personalityProfileService->buildPublicProjection($profile, $variant);
        $meta = PublicMediaUrlGuard::sanitizeSeoMeta(
            $this->personalityProfileSeoService->buildMeta($profile, $variant)
        );
        $jsonLd = $this->personalityProfileSeoService->buildJsonLd($profile, $variant);
        $sections = $this->publicSectionPayloads($profile, $variant);
        $seoSurface = $this->buildSeoSurface($meta, $jsonLd, $this->personalitySeoSurfaceType($profile));
        $landingSurface = $this->buildDetailLandingSurface($profile, $variant, $projection, $validated['locale']);

        $payload = [
            'ok' => true,
            'profile' => $this->profileDetailPayload($profile, $variant),
            'sections' => $sections,
            'seo_meta' => $this->seoMetaPayload($profile, $variant),
            'personality_public_projection_v1' => $projection,
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
            ),
        ];

        if ($this->isMbtiProfile($profile)) {
            $payload['mbti_public_projection_v1'] = $projection;
        }

        return response()->json($payload);
    }

    public function seo(Request $request, string $type): JsonResponse
    {
        $validated = $this->validateReadQuery($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $routeProfile = $this->personalityProfileService->getPublicDetailRouteProfileByType(
            $type,
            $validated['org_id'],
            $validated['scale_code'],
            $validated['locale'],
        );

        if (! is_array($routeProfile)) {
            return response()->json(['error' => 'not found'], 404);
        }

        /** @var PersonalityProfile $profile */
        $profile = $routeProfile['profile'];
        /** @var PersonalityProfileVariant|null $variant */
        $variant = $routeProfile['variant'];
        $meta = PublicMediaUrlGuard::sanitizeSeoMeta(
            $this->personalityProfileSeoService->buildMeta($profile, $variant)
        );
        $jsonLd = $this->personalityProfileSeoService->buildJsonLd($profile, $variant);

        return response()->json([
            'meta' => $meta,
            'jsonld' => $jsonLd,
            'seo_surface_v1' => $this->buildSeoSurface($meta, $jsonLd, $this->personalitySeoSurfaceType($profile)),
        ]);
    }

    public function comparison(Request $request, string $comparison): JsonResponse
    {
        $validated = $this->validateReadQuery($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
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

        $assertiveProjection = $this->personalityProfileService->buildPublicProjection($assertiveProfile, $assertiveVariant);
        $turbulentProjection = $this->personalityProfileService->buildPublicProjection($turbulentProfile, $turbulentVariant);
        $assertiveSections = $this->publicSectionPayloads($assertiveProfile, $assertiveVariant);
        $turbulentSections = $this->publicSectionPayloads($turbulentProfile, $turbulentVariant);
        $meta = $this->comparisonMeta($baseTypeCode, $validated['locale']);
        $jsonLd = $this->comparisonJsonLd($baseTypeCode, $validated['locale'], $meta);
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
        $seoSurface = $this->buildSeoSurface($meta, $jsonLd, 'mbti_personality_at_comparison');
        $landingSurface = $this->buildComparisonLandingSurface($baseTypeCode, $validated['locale'], $meta);

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
                $landingSurface
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
    private function comparisonMeta(string $baseTypeCode, string $locale): array
    {
        $title = $locale === 'zh-CN'
            ? $baseTypeCode.'-A 和 '.$baseTypeCode.'-T 区别：特点、职业、爱情与稀有度'
            : $baseTypeCode.'-A vs '.$baseTypeCode.'-T: Traits, Careers, Love & Rarity';
        $description = $locale === 'zh-CN'
            ? '对比 '.$baseTypeCode.'-A 与 '.$baseTypeCode.'-T 的 A/T 区别、核心特点、爱情关系、适合职业、优势盲点、稀有度，并通过 MBTI 测试确认自己的类型。'
            : 'Compare '.$baseTypeCode.'-A and '.$baseTypeCode.'-T traits, A/T differences, strengths, blind spots, relationships, career fit, rarity, and how to confirm your type with an MBTI test.';
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
            'robots' => 'index,follow',
        ];
    }

    /**
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>
     */
    private function comparisonJsonLd(string $baseTypeCode, string $locale, array $meta): array
    {
        $segment = $this->frontendLocaleSegment($locale);
        $baseUrl = CanonicalFrontendUrl::fromConfig();
        $hubUrl = $baseUrl !== '' ? $baseUrl.'/'.$segment.'/personality' : null;
        $canonicalUrl = is_string($meta['canonical'] ?? null) ? $meta['canonical'] : null;
        $variantUrls = [
            'A' => $baseUrl !== '' ? $baseUrl.'/'.$segment.'/personality/'.strtolower($baseTypeCode).'-a' : null,
            'T' => $baseUrl !== '' ? $baseUrl.'/'.$segment.'/personality/'.strtolower($baseTypeCode).'-t' : null,
        ];

        return CanonicalFrontendUrl::normalizeNestedUrls([
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
        ]);
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
    private function buildComparisonLandingSurface(string $baseTypeCode, string $locale, array $meta): array
    {
        $segment = $this->frontendLocaleSegment($locale);
        $baseSlug = strtolower($baseTypeCode);
        $startTestPath = '/'.$segment.'/tests/mbti-personality-test-16-personality-types';

        return $this->landingSurfaceContractService->build([
            'landing_scope' => 'public_indexable_detail',
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
            'cta_bundle' => [
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
            ],
            'indexability_state' => 'indexable',
            'attribution_scope' => 'public_personality_landing',
            'seo_surface_ref' => $this->comparisonSlug($baseTypeCode),
            'surface_family' => 'personality',
            'primary_content_ref' => $baseTypeCode.':A:T',
            'related_surface_keys' => ['personality_detail', 'topic_cluster', 'start_test'],
            'fingerprint_seed' => [
                'comparison_slug' => $this->comparisonSlug($baseTypeCode),
                'locale' => $locale,
                'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
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
        array $landingSurface
    ): array {
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

        return $this->answerSurfaceContractService->build([
            'answer_scope' => 'public_indexable_detail',
            'surface_type' => 'personality_comparison_public_detail',
            'summary_blocks' => $summaryBlocks,
            'compare_blocks' => $compareBlocks,
            'next_step_blocks' => $this->answerSurfaceContractService->buildNextStepBlocksFromCtas(
                is_array($landingSurface['cta_bundle'] ?? null) ? $landingSurface['cta_bundle'] : [],
                3
            ),
            'answer_bundle' => [
                ['key' => 'summary', 'title' => 'summary', 'count' => count($summaryBlocks)],
                ['key' => 'compare', 'title' => 'compare', 'count' => count($compareBlocks)],
            ],
            'evidence_refs' => array_values(array_filter([
                (string) ($seoSurface['metadata_fingerprint'] ?? ''),
                (string) ($landingSurface['landing_fingerprint'] ?? ''),
                'comparison_public_projection_v1',
                'personality_variant_sections',
                'personality_variant_seo_meta',
            ])),
            'public_safety_state' => 'public_indexable',
            'indexability_state' => 'indexable',
            'attribution_scope' => 'public_personality_answer',
            'seo_surface_ref' => (string) ($seoSurface['metadata_fingerprint'] ?? ''),
            'landing_surface_ref' => (string) ($landingSurface['landing_fingerprint'] ?? ''),
            'primary_content_ref' => $baseTypeCode.':A:T',
            'related_surface_keys' => ['personality_detail', 'topic_cluster', 'start_test'],
            'fingerprint_seed' => [
                'comparison_slug' => $this->comparisonSlug($baseTypeCode),
                'locale' => $locale,
                'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
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
        string $locale
    ): array {
        $segment = $this->frontendLocaleSegment($locale);
        $routeSlug = $this->resolveRouteSlug($profile, $variant);
        $isMbtiScale = $this->isMbtiProfile($profile);
        $careerPath = $isMbtiScale ? '/'.$segment.'/career/recommendations/mbti/'.$routeSlug : null;
        $topicPath = '/'.$segment.'/topics/'.$this->personalityTopicSlug($profile);
        $startTestPath = $this->personalityStartTestPath($profile, $segment);

        return $this->landingSurfaceContractService->build([
            'landing_scope' => 'public_indexable_detail',
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
            'indexability_state' => $profile->is_indexable ? 'indexable' : 'noindex',
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
        string $locale
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
            'answer_scope' => ($profile->is_indexable ?? false) ? 'public_indexable_detail' : 'public_noindex_detail',
            'surface_type' => 'personality_public_detail',
            'summary_blocks' => $summaryBlocks,
            'faq_blocks' => $this->answerSurfaceContractService->extractFaqBlocksFromSectionPayloads($sections),
            'compare_blocks' => $compareBlocks,
            'scene_summary_blocks' => $sceneSummaryBlocks,
            'next_step_blocks' => $this->answerSurfaceContractService->buildNextStepBlocksFromCtas(
                is_array($landingSurface['cta_bundle'] ?? null) ? $landingSurface['cta_bundle'] : [],
                3
            ),
            'answer_bundle' => [
                ['key' => 'summary', 'title' => 'summary', 'count' => count($summaryBlocks)],
                ['key' => 'compare', 'title' => 'compare', 'count' => count($compareBlocks)],
            ],
            'evidence_refs' => array_values(array_filter([
                (string) ($seoSurface['metadata_fingerprint'] ?? ''),
                (string) ($landingSurface['landing_fingerprint'] ?? ''),
                'personality_public_projection_v1',
                $isMbtiScale ? 'mbti_public_projection_v1' : '',
                count($compareBlocks) > 0 ? 'projection_dimensions' : '',
                count($sections) > 0 ? 'personality_sections' : '',
                $sceneSummaryBlocks !== [] ? 'scene_summary_blocks' : '',
            ])),
            'public_safety_state' => 'public_indexable',
            'indexability_state' => ($profile->is_indexable ?? false) ? 'indexable' : 'noindex',
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

        $projection = $this->personalityProfileService->buildPublicProjection($profile, $variant);
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
            'is_indexable' => (bool) $profile->is_indexable,
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
    private function profileDetailPayload(PersonalityProfile $profile, ?PersonalityProfileVariant $variant = null): array
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
            'hero_kicker' => $profile->hero_kicker,
            'hero_quote' => $profile->hero_quote,
            'hero_image_url' => PublicMediaUrlGuard::sanitizeNullableUrl($profile->hero_image_url),
            'status' => (string) $profile->status,
            'is_public' => (bool) $profile->is_public,
            'is_indexable' => (bool) $profile->is_indexable,
            'published_at' => $profile->published_at?->toISOString(),
            'updated_at' => $profile->updated_at?->toISOString(),
        ], $this->personalityProfileService->publicCanonicalFields($profile, $variant));
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

                if (! (bool) $section->is_enabled) {
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
                    'body_md' => $section->body_md ?? $baseSection['body_md'] ?? null,
                    'body_html' => $section->body_html ?? $baseSection['body_html'] ?? null,
                    'payload_json' => $payload,
                    'sort_order' => (int) ($section->sort_order ?? $baseSection['sort_order'] ?? 0),
                    'is_enabled' => true,
                ]);
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

    private function invalidArgument(string $message): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error_code' => 'INVALID_ARGUMENT',
            'message' => $message,
        ], 422);
    }
}
