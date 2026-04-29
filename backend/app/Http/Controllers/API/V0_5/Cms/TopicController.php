<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Cms;

use App\Http\Controllers\Concerns\RespondsWithNotFound;
use App\Http\Controllers\Controller;
use App\Models\TopicProfile;
use App\Models\TopicProfileSection;
use App\Models\TopicProfileSeoMeta;
use App\Services\Cms\TopicEntryResolverService;
use App\Services\Cms\TopicProfileSeoService;
use App\Services\Cms\TopicProfileService;
use App\Services\PublicSurface\AnswerSurfaceContractService;
use App\Services\PublicSurface\LandingSurfaceContractService;
use App\Services\PublicSurface\SeoSurfaceContractService;
use App\Support\CanonicalFrontendUrl;
use App\Support\PublicMediaUrlGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class TopicController extends Controller
{
    use RespondsWithNotFound;

    public function __construct(
        private readonly TopicProfileService $topicProfileService,
        private readonly TopicEntryResolverService $topicEntryResolverService,
        private readonly TopicProfileSeoService $topicProfileSeoService,
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

        $paginator = $this->topicProfileService->listPublicTopics(
            $validated['org_id'],
            $validated['locale'],
            $validated['page'],
            $validated['per_page'],
        );

        $items = [];
        foreach ($paginator->items() as $profile) {
            if (! $profile instanceof TopicProfile) {
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

    public function show(Request $request, string $slug): JsonResponse
    {
        $validated = $this->validateReadQuery($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $profile = $this->topicProfileService->getPublicTopicBySlug(
            $slug,
            $validated['org_id'],
            $validated['locale'],
        );

        if (! $profile instanceof TopicProfile) {
            return $this->notFoundResponse('topic not found.');
        }

        $meta = PublicMediaUrlGuard::sanitizeSeoMeta(
            $this->topicProfileSeoService->buildMeta($profile, $validated['locale'])
        );
        $jsonLd = $this->topicProfileSeoService->buildJsonLd($profile, $validated['locale']);
        $entryGroups = $this->topicEntryResolverService->resolveGroupedEntries($profile, $validated['locale']);
        $sections = array_map(
            fn (TopicProfileSection $section): array => $this->sectionPayload($section),
            $profile->sections->all()
        );
        $seoSurface = $this->buildSeoSurface($meta, $jsonLd, 'topic_public_detail');
        $landingSurface = $this->buildDetailLandingSurface($profile, $entryGroups, $validated['locale']);

        return response()->json([
            'ok' => true,
            'profile' => $this->profileDetailPayload($profile),
            'sections' => $sections,
            'entry_groups' => $entryGroups,
            'seo_meta' => $this->seoMetaPayload($profile->seoMeta),
            'seo_surface_v1' => $seoSurface,
            'landing_surface_v1' => $landingSurface,
            'answer_surface_v1' => $this->buildDetailAnswerSurface(
                $profile,
                $sections,
                $entryGroups,
                $seoSurface,
                $landingSurface,
                $validated['locale'],
            ),
        ]);
    }

    public function seo(Request $request, string $slug): JsonResponse
    {
        $validated = $this->validateReadQuery($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $profile = $this->topicProfileService->getPublicTopicBySlug(
            $slug,
            $validated['org_id'],
            $validated['locale'],
        );

        if (! $profile instanceof TopicProfile) {
            return response()->json(['error' => 'not found'], 404);
        }

        $meta = PublicMediaUrlGuard::sanitizeSeoMeta(
            $this->topicProfileSeoService->buildMeta($profile, $validated['locale'])
        );
        $jsonLd = $this->topicProfileSeoService->buildJsonLd($profile, $validated['locale']);

        return response()->json([
            'meta' => $meta,
            'jsonld' => $jsonLd,
            'seo_surface_v1' => $this->buildSeoSurface($meta, $jsonLd, 'topic_public_detail'),
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
     * @param  array<string,list<array<string,mixed>>>  $entryGroups
     * @return array<string,mixed>
     */
    private function buildDetailLandingSurface(TopicProfile $profile, array $entryGroups, string $locale): array
    {
        $segment = $this->frontendLocaleSegment($locale);
        $firstFeatured = $this->firstEntryForGroups($entryGroups, ['featured', 'articles', 'personalities', 'related']);
        $firstTest = $this->firstEntryForGroups($entryGroups, ['tests']);

        return $this->landingSurfaceContractService->build([
            'landing_scope' => 'public_indexable_detail',
            'entry_surface' => 'topic_detail',
            'entry_type' => 'topic_profile',
            'summary_blocks' => [
                [
                    'key' => 'hero',
                    'title' => (string) ($profile->title ?? ''),
                    'body' => trim((string) ($profile->excerpt ?? $profile->subtitle ?? '')),
                    'kind' => 'answer_first',
                ],
            ],
            'discoverability_keys' => array_keys($entryGroups),
            'continue_reading_keys' => array_keys($entryGroups),
            'start_test_target' => is_array($firstTest) ? trim((string) ($firstTest['url'] ?? '')) : '/'.$segment.'/tests/mbti-personality-test-16-personality-types',
            'result_resume_target' => null,
            'content_continue_target' => is_array($firstFeatured) ? trim((string) ($firstFeatured['url'] ?? '')) : '/'.$segment.'/personality',
            'cta_bundle' => array_values(array_filter([
                [
                    'key' => 'start_test',
                    'label' => $locale === 'zh-CN' ? '开始测试' : 'Take the test',
                    'href' => is_array($firstTest) ? trim((string) ($firstTest['url'] ?? '')) : '/'.$segment.'/tests/mbti-personality-test-16-personality-types',
                    'kind' => 'start_test',
                ],
                is_array($firstFeatured)
                    ? [
                        'key' => 'continue_public_content',
                        'label' => trim((string) ($firstFeatured['cta_label'] ?? '')) ?: ($locale === 'zh-CN' ? '继续阅读' : 'Continue reading'),
                        'href' => trim((string) ($firstFeatured['url'] ?? '')),
                        'kind' => 'content_continue',
                    ]
                    : null,
                [
                    'key' => 'personality_hub',
                    'label' => $locale === 'zh-CN' ? '人格画像' : 'Personality hub',
                    'href' => '/'.$segment.'/personality',
                    'kind' => 'discover',
                ],
            ])),
            'indexability_state' => $profile->is_indexable ? 'indexable' : 'noindex',
            'attribution_scope' => 'public_topic_landing',
            'surface_family' => 'topic',
            'primary_content_ref' => (string) ($profile->slug ?? $profile->topic_code ?? ''),
            'related_surface_keys' => array_keys($entryGroups),
            'fingerprint_seed' => [
                'slug' => (string) ($profile->slug ?? ''),
                'locale' => $locale,
                'entry_group_count' => count($entryGroups),
            ],
        ]);
    }

    /**
     * @param  array<int,array<string,mixed>>  $sections
     * @param  array<string,list<array<string,mixed>>>  $entryGroups
     * @param  array<string,mixed>  $seoSurface
     * @param  array<string,mixed>  $landingSurface
     * @return array<string,mixed>
     */
    private function buildDetailAnswerSurface(
        TopicProfile $profile,
        array $sections,
        array $entryGroups,
        array $seoSurface,
        array $landingSurface,
        string $locale
    ): array {
        $summaryBlocks = [
            [
                'key' => 'topic_summary',
                'title' => (string) ($profile->title ?? ''),
                'body' => trim((string) ($profile->excerpt ?? $profile->subtitle ?? '')),
                'kind' => 'answer_first',
            ],
        ];

        $nextStepBlocks = [];
        foreach ($entryGroups as $groupKey => $entries) {
            $first = is_array($entries[0] ?? null) ? $entries[0] : null;
            if ($first === null) {
                continue;
            }

            $title = trim((string) ($first['title'] ?? ''));
            $url = trim((string) ($first['url'] ?? ''));
            if ($title === '' || $url === '') {
                continue;
            }

            $nextStepBlocks[] = [
                'key' => (string) $groupKey,
                'title' => $title,
                'body' => trim((string) ($first['excerpt'] ?? '')),
                'href' => $url,
                'kind' => 'content_continue',
            ];
        }

        $compareBlocks = [];
        foreach (['featured', 'articles', 'personalities', 'tests'] as $groupKey) {
            $count = count($entryGroups[$groupKey] ?? []);
            if ($count <= 0) {
                continue;
            }

            $compareBlocks[] = [
                'key' => $groupKey,
                'title' => $groupKey,
                'body' => $count.' available entries',
                'kind' => 'entry_group',
            ];
        }

        $isMbtiTopic = strtolower((string) ($profile->topic_code ?? $profile->slug ?? '')) === 'mbti';
        $sceneSummaryBlocks = $isMbtiTopic ? $this->buildMbtiSceneSummaryBlocks($locale) : [];

        return $this->answerSurfaceContractService->build([
            'answer_scope' => $profile->is_indexable ? 'public_indexable_detail' : 'public_noindex_detail',
            'surface_type' => 'topic_public_detail',
            'summary_blocks' => $summaryBlocks,
            'faq_blocks' => $this->answerSurfaceContractService->extractFaqBlocksFromSectionPayloads($sections),
            'compare_blocks' => $compareBlocks,
            'scene_summary_blocks' => $sceneSummaryBlocks,
            'next_step_blocks' => array_slice($nextStepBlocks, 0, 4),
            'evidence_refs' => array_values(array_filter([
                (string) ($seoSurface['metadata_fingerprint'] ?? ''),
                (string) ($landingSurface['landing_fingerprint'] ?? ''),
                count($entryGroups) > 0 ? 'topic_entry_groups' : '',
                count($sections) > 0 ? 'topic_sections' : '',
                $sceneSummaryBlocks !== [] ? 'scene_summary_blocks' : '',
            ])),
            'public_safety_state' => 'public_indexable',
            'indexability_state' => $profile->is_indexable ? 'indexable' : 'noindex',
            'attribution_scope' => 'public_topic_answer',
            'seo_surface_ref' => (string) ($seoSurface['metadata_fingerprint'] ?? ''),
            'landing_surface_ref' => (string) ($landingSurface['landing_fingerprint'] ?? ''),
            'primary_content_ref' => (string) ($profile->slug ?? $profile->topic_code ?? ''),
            'related_surface_keys' => array_keys($entryGroups),
            'fingerprint_seed' => [
                'slug' => (string) ($profile->slug ?? ''),
                'locale' => $locale,
                'entry_group_count' => count($entryGroups),
            ],
        ]);
    }

    /**
     * @return list<array<string,string>>
     */
    private function buildMbtiSceneSummaryBlocks(string $locale): array
    {
        $segment = $this->frontendLocaleSegment($locale);
        $startTestPath = '/'.$segment.'/tests/mbti-personality-test-16-personality-types';

        return [
            [
                'key' => 'career_direction',
                'title' => $locale === 'zh-CN' ? '职业方向' : 'Career direction',
                'body' => $locale === 'zh-CN'
                    ? '先看职业推荐入口，快速判断高匹配岗位。'
                    : 'Start from career recommendations to identify higher-fit roles quickly.',
                'href' => '/'.$segment.'/career/recommendations',
                'kind' => 'scene_entry',
            ],
            [
                'key' => 'major_selection',
                'title' => $locale === 'zh-CN' ? '专业选择' : 'Major selection',
                'body' => $locale === 'zh-CN'
                    ? '先建立 MBTI 框架，再把专业方向放进同一判断路径。'
                    : 'Build an MBTI frame first, then evaluate majors against the same path.',
                'href' => '/'.$segment.'/topics/mbti',
                'kind' => 'scene_entry',
            ],
            [
                'key' => 'team_collaboration',
                'title' => $locale === 'zh-CN' ? '团队协作' : 'Team collaboration',
                'body' => $locale === 'zh-CN'
                    ? '回到人格类型索引，比较协作风格差异。'
                    : 'Return to personality types to compare collaboration styles.',
                'href' => '/'.$segment.'/personality',
                'kind' => 'scene_entry',
            ],
            [
                'key' => 'relationship_patterns',
                'title' => $locale === 'zh-CN' ? '关系相处' : 'Relationship patterns',
                'body' => $locale === 'zh-CN'
                    ? '从 MBTI 主题页继续阅读关系线索。'
                    : 'Continue from the MBTI topic hub for relationship pattern cues.',
                'href' => '/'.$segment.'/topics/mbti',
                'kind' => 'scene_entry',
            ],
            [
                'key' => 'growth_planning',
                'title' => $locale === 'zh-CN' ? '成长建议' : 'Growth planning',
                'body' => $locale === 'zh-CN'
                    ? '直接开始测试，获取个性化成长线索。'
                    : 'Start the test directly to unlock personalized growth cues.',
                'href' => $startTestPath,
                'kind' => 'scene_entry',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildIndexLandingSurface(string $locale): array
    {
        $segment = $this->frontendLocaleSegment($locale);

        return $this->landingSurfaceContractService->build([
            'landing_scope' => 'public_indexable_index',
            'entry_surface' => 'topic_index',
            'entry_type' => 'topic_family_index',
            'summary_blocks' => [
                [
                    'key' => 'hero',
                    'title' => $locale === 'zh-CN' ? '主题内容聚合' : 'Topic clusters',
                    'body' => $locale === 'zh-CN'
                        ? '围绕测试、人格与职业内容组织结构化主题入口。'
                        : 'Structured topic hubs that connect tests, personality profiles, and career content.',
                    'kind' => 'hero',
                ],
            ],
            'discoverability_keys' => ['topic_index', 'personality_detail', 'tests_detail', 'article_detail'],
            'continue_reading_keys' => ['topic_detail', 'personality_detail', 'tests_detail'],
            'start_test_target' => '/'.$segment.'/tests/mbti-personality-test-16-personality-types',
            'result_resume_target' => null,
            'content_continue_target' => '/'.$segment.'/personality',
            'cta_bundle' => [
                [
                    'key' => 'start_test',
                    'label' => $locale === 'zh-CN' ? '开始测试' : 'Take the test',
                    'href' => '/'.$segment.'/tests/mbti-personality-test-16-personality-types',
                    'kind' => 'start_test',
                ],
                [
                    'key' => 'personality_hub',
                    'label' => $locale === 'zh-CN' ? '人格画像' : 'Personality hub',
                    'href' => '/'.$segment.'/personality',
                    'kind' => 'discover',
                ],
            ],
            'indexability_state' => 'indexable',
            'attribution_scope' => 'public_topic_landing',
            'surface_family' => 'topic',
            'primary_content_ref' => 'topic:index',
            'related_surface_keys' => ['topic_detail', 'personality_detail', 'tests_detail'],
            'fingerprint_seed' => ['locale' => $locale],
        ]);
    }

    /**
     * @param  array<string,list<array<string,mixed>>>  $entryGroups
     * @param  list<string>  $groups
     * @return array<string,mixed>|null
     */
    private function firstEntryForGroups(array $entryGroups, array $groups): ?array
    {
        foreach ($groups as $group) {
            $items = $entryGroups[$group] ?? [];
            $first = is_array($items[0] ?? null) ? $items[0] : null;
            if ($first !== null) {
                return $first;
            }
        }

        return null;
    }

    private function frontendLocaleSegment(string $locale): string
    {
        return $locale === 'zh-CN' ? 'zh' : 'en';
    }

    /**
     * @return array<string, mixed>
     */
    private function profileListPayload(TopicProfile $profile): array
    {
        return [
            'id' => (int) $profile->id,
            'org_id' => (int) $profile->org_id,
            'topic_code' => (string) $profile->topic_code,
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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function profileDetailPayload(TopicProfile $profile): array
    {
        return [
            'id' => (int) $profile->id,
            'org_id' => (int) $profile->org_id,
            'topic_code' => (string) $profile->topic_code,
            'slug' => (string) $profile->slug,
            'locale' => (string) $profile->locale,
            'title' => (string) $profile->title,
            'subtitle' => $profile->subtitle,
            'excerpt' => $profile->excerpt,
            'hero_kicker' => $profile->hero_kicker,
            'hero_quote' => $profile->hero_quote,
            'cover_image_url' => PublicMediaUrlGuard::sanitizeNullableUrl($profile->cover_image_url),
            'status' => (string) $profile->status,
            'is_public' => (bool) $profile->is_public,
            'is_indexable' => (bool) $profile->is_indexable,
            'published_at' => $profile->published_at?->toISOString(),
            'updated_at' => $profile->updated_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sectionPayload(TopicProfileSection $section): array
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
    private function seoMetaPayload(?TopicProfileSeoMeta $seoMeta): ?array
    {
        if (! $seoMeta instanceof TopicProfileSeoMeta) {
            return null;
        }

        return [
            'seo_title' => $seoMeta->seo_title,
            'seo_description' => $seoMeta->seo_description,
            'canonical_url' => CanonicalFrontendUrl::normalizeAbsoluteUrl($seoMeta->canonical_url),
            'og_title' => $seoMeta->og_title,
            'og_description' => $seoMeta->og_description,
            'og_image_url' => PublicMediaUrlGuard::sanitizeNullableUrl($seoMeta->og_image_url),
            'twitter_title' => $seoMeta->twitter_title,
            'twitter_description' => $seoMeta->twitter_description,
            'twitter_image_url' => PublicMediaUrlGuard::sanitizeNullableUrl($seoMeta->twitter_image_url),
            'robots' => $seoMeta->robots,
            'jsonld_overrides_json' => CanonicalFrontendUrl::normalizeNestedUrls($seoMeta->jsonld_overrides_json),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function seoMetaSummaryPayload(?TopicProfileSeoMeta $seoMeta): ?array
    {
        if (! $seoMeta instanceof TopicProfileSeoMeta) {
            return null;
        }

        return [
            'seo_title' => $seoMeta->seo_title,
            'seo_description' => $seoMeta->seo_description,
        ];
    }

    /**
     * @return array{org_id:int,locale:string,page:int,per_page:int}|JsonResponse
     */
    private function validateListQuery(Request $request): array|JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'org_id' => ['nullable', 'integer', 'min:0'],
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
            'locale' => (string) $validated['locale'],
            'page' => (int) ($validated['page'] ?? 1),
            'per_page' => (int) ($validated['per_page'] ?? 20),
        ];
    }

    /**
     * @return array{org_id:int,locale:string}|JsonResponse
     */
    private function validateReadQuery(Request $request): array|JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'org_id' => ['nullable', 'integer', 'min:0'],
            'locale' => ['required', 'in:en,zh-CN'],
        ]);

        if ($validator->fails()) {
            return $this->invalidArgument($validator->errors()->first());
        }

        $validated = $validator->validated();

        return [
            'org_id' => (int) ($validated['org_id'] ?? 0),
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
