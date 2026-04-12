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

        $paginator = $this->personalityProfileService->listPublicProfiles(
            $validated['org_id'],
            $validated['scale_code'],
            $validated['locale'],
            $validated['page'],
            $validated['per_page'],
        );

        $items = [];
        foreach ($paginator->items() as $profile) {
            if (! $profile instanceof PersonalityProfile) {
                continue;
            }

            $items[] = $this->profileListPayload($profile);
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
        $seoSurface = $this->buildSeoSurface($meta, $jsonLd, 'mbti_personality_public_detail');
        $landingSurface = $this->buildDetailLandingSurface($profile, $variant, $projection, $validated['locale']);

        return response()->json([
            'ok' => true,
            'profile' => $this->profileDetailPayload($profile, $variant),
            'sections' => $sections,
            'seo_meta' => $this->seoMetaPayload($profile, $variant),
            'mbti_public_projection_v1' => $projection,
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
        ]);
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
            'seo_surface_v1' => $this->buildSeoSurface($meta, $jsonLd, 'mbti_personality_public_detail'),
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
        $careerPath = '/'.$segment.'/career/recommendations/mbti/'.$routeSlug;
        $topicPath = '/'.$segment.'/topics/mbti';
        $startTestPath = '/'.$segment.'/tests/mbti-personality-test-16-personality-types';

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
            'discoverability_keys' => [
                'personality_detail',
                'topic_cluster',
                'career_recommendation',
                'start_test',
            ],
            'continue_reading_keys' => [
                'career_recommendation',
                'topic_cluster',
                'related_content',
            ],
            'start_test_target' => $startTestPath,
            'result_resume_target' => null,
            'content_continue_target' => $careerPath,
            'cta_bundle' => [
                [
                    'key' => 'start_test',
                    'label' => $locale === 'zh-CN' ? '开始测试' : 'Take the test',
                    'href' => $startTestPath,
                    'kind' => 'start_test',
                ],
                [
                    'key' => 'career_recommendation',
                    'label' => $locale === 'zh-CN' ? '查看职业推荐' : 'View career recommendations',
                    'href' => $careerPath,
                    'kind' => 'content_continue',
                ],
                [
                    'key' => 'topic_cluster',
                    'label' => $locale === 'zh-CN' ? '查看主题聚合' : 'Browse topic hub',
                    'href' => $topicPath,
                    'kind' => 'discover',
                ],
            ],
            'indexability_state' => $profile->is_indexable ? 'indexable' : 'noindex',
            'attribution_scope' => 'public_personality_landing',
            'seo_surface_ref' => (string) ($profile->slug ?? ''),
            'surface_family' => 'personality',
            'primary_content_ref' => (string) ($variant?->runtime_type_code ?? $profile->type_code ?? $profile->slug ?? ''),
            'related_surface_keys' => ['career_recommendation', 'topic_cluster'],
            'fingerprint_seed' => [
                'slug' => (string) ($profile->slug ?? ''),
                'runtime_type_code' => (string) ($variant?->runtime_type_code ?? ''),
                'locale' => $locale,
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
        $isMbtiScale = strtoupper((string) ($profile->scale_code ?? '')) === 'MBTI';
        $sceneSummaryBlocks = $isMbtiScale ? $this->buildMbtiSceneSummaryBlocks($locale, $routeSlug) : [];

        return $this->answerSurfaceContractService->build([
            'answer_scope' => ($profile->is_indexable ?? false) ? 'public_indexable_detail' : 'public_noindex_detail',
            'surface_type' => 'personality_public_detail',
            'summary_blocks' => array_values(array_filter([
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
            ])),
            'faq_blocks' => $this->answerSurfaceContractService->extractFaqBlocksFromSectionPayloads($sections),
            'compare_blocks' => $compareBlocks,
            'scene_summary_blocks' => $sceneSummaryBlocks,
            'next_step_blocks' => $this->answerSurfaceContractService->buildNextStepBlocksFromCtas(
                is_array($landingSurface['cta_bundle'] ?? null) ? $landingSurface['cta_bundle'] : [],
                3
            ),
            'answer_bundle' => [
                ['key' => 'summary', 'title' => 'summary', 'count' => 2],
                ['key' => 'compare', 'title' => 'compare', 'count' => count($compareBlocks)],
            ],
            'evidence_refs' => array_values(array_filter([
                (string) ($seoSurface['metadata_fingerprint'] ?? ''),
                (string) ($landingSurface['landing_fingerprint'] ?? ''),
                'mbti_public_projection_v1',
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
            'related_surface_keys' => ['career_recommendation', 'topic_cluster', 'start_test'],
            'fingerprint_seed' => [
                'slug' => (string) ($profile->slug ?? ''),
                'locale' => $locale,
                'runtime_type_code' => (string) ($variant?->runtime_type_code ?? ''),
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
            'jsonld_overrides_json' => $seoMeta?->jsonld_overrides_json,
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
                $meta['jsonld_overrides_json'] = $variantSeoMeta->jsonld_overrides_json;
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
                $sections->put($sectionKey, [
                    'section_key' => $sectionKey,
                    'title' => $section->title ?? $baseSection['title'] ?? null,
                    'render_variant' => (string) ($section->render_variant ?: ($baseSection['render_variant'] ?? 'rich_text')),
                    'body_md' => $section->body_md ?? $baseSection['body_md'] ?? null,
                    'body_html' => $section->body_html ?? $baseSection['body_html'] ?? null,
                    'payload_json' => is_array($section->payload_json)
                        ? $section->payload_json
                        : ($baseSection['payload_json'] ?? null),
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
     * @return array{org_id:int,scale_code:string,locale:string,page:int,per_page:int}|JsonResponse
     */
    private function validateListQuery(Request $request): array|JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'org_id' => ['nullable', 'integer', 'min:0'],
            'scale_code' => ['nullable', 'in:MBTI'],
            'locale' => ['required', 'in:en,zh-CN'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
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
