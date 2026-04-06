<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Cms;

use App\Http\Controllers\Concerns\RespondsWithNotFound;
use App\Http\Controllers\Controller;
use App\Services\Cms\CareerRecommendationService;
use App\Services\PublicSurface\AnswerSurfaceContractService;
use App\Services\PublicSurface\LandingSurfaceContractService;
use App\Services\PublicSurface\SeoSurfaceContractService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class CareerRecommendationController extends Controller
{
    use RespondsWithNotFound;

    public function __construct(
        private readonly CareerRecommendationService $careerRecommendationService,
        private readonly AnswerSurfaceContractService $answerSurfaceContractService,
        private readonly LandingSurfaceContractService $landingSurfaceContractService,
        private readonly SeoSurfaceContractService $seoSurfaceContractService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $this->validateReadQuery($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        return response()->json([
            'items' => $this->careerRecommendationService->listPublicRecommendations(
                $validated['org_id'],
                $validated['locale'],
            ),
        ]);
    }

    public function show(Request $request, string $type): JsonResponse
    {
        $validated = $this->validateReadQuery($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $payload = $this->careerRecommendationService->getPublicRecommendationByType(
            $type,
            $validated['org_id'],
            $validated['locale'],
        );

        if (! is_array($payload)) {
            return $this->notFoundResponse('career recommendation not found.');
        }

        $payload['seo_surface_v1'] = $this->seoSurfaceContractService->build([
            'metadata_scope' => 'public_indexable_detail',
            'surface_type' => 'career_recommendation_public_detail',
            'canonical_url' => data_get($payload, 'seo.canonical'),
            'robots_policy' => 'index,follow',
            'title' => data_get($payload, 'seo.title'),
            'description' => data_get($payload, 'seo.description'),
            'og_payload' => [
                'title' => data_get($payload, 'seo.title'),
                'description' => data_get($payload, 'seo.description'),
                'type' => 'article',
                'url' => data_get($payload, 'seo.canonical'),
            ],
            'twitter_payload' => [
                'card' => 'summary_large_image',
                'title' => data_get($payload, 'seo.title'),
                'description' => data_get($payload, 'seo.description'),
            ],
            'alternates' => is_array(data_get($payload, 'seo.alternates')) ? data_get($payload, 'seo.alternates') : [],
            'structured_data' => [],
        ]);
        $payload['landing_surface_v1'] = $this->landingSurfaceContractService->build([
            'landing_scope' => 'public_indexable_detail',
            'entry_surface' => 'career_recommendation_detail',
            'entry_type' => 'career_recommendation',
            'summary_blocks' => [
                [
                    'key' => 'answer_first',
                    'title' => trim((string) data_get($payload, 'display_type', '')),
                    'body' => trim((string) data_get($payload, 'hero_summary', '')),
                    'kind' => 'answer_first',
                ],
            ],
            'discoverability_keys' => ['career_recommendation', 'matched_jobs', 'matched_guides', 'personality_profile'],
            'continue_reading_keys' => ['matched_jobs', 'matched_guides', 'personality_profile'],
            'start_test_target' => '/'.$this->frontendLocaleSegment($validated['locale']).'/tests/mbti-personality-test-16-personality-types',
            'result_resume_target' => null,
            'content_continue_target' => trim((string) (data_get($payload, 'matched_guides.0.href') ?? data_get($payload, 'matched_jobs.0.href') ?? '')),
            'cta_bundle' => array_values(array_filter([
                [
                    'key' => 'start_test',
                    'label' => $validated['locale'] === 'zh-CN' ? '开始测试' : 'Take the test',
                    'href' => '/'.$this->frontendLocaleSegment($validated['locale']).'/tests/mbti-personality-test-16-personality-types',
                    'kind' => 'start_test',
                ],
                data_get($payload, 'matched_jobs.0.slug')
                    ? [
                        'key' => 'matched_job',
                        'label' => $validated['locale'] === 'zh-CN' ? '查看匹配职业' : 'View matching role',
                        'href' => '/'.$this->frontendLocaleSegment($validated['locale']).'/career/jobs/'.rawurlencode((string) data_get($payload, 'matched_jobs.0.slug')),
                        'kind' => 'content_continue',
                    ]
                    : null,
                data_get($payload, 'matched_guides.0.slug')
                    ? [
                        'key' => 'matched_guide',
                        'label' => $validated['locale'] === 'zh-CN' ? '阅读职业指南' : 'Read career guide',
                        'href' => '/'.$this->frontendLocaleSegment($validated['locale']).'/career/guides/'.rawurlencode((string) data_get($payload, 'matched_guides.0.slug')),
                        'kind' => 'discover',
                    ]
                    : null,
            ])),
            'indexability_state' => 'indexable',
            'attribution_scope' => 'public_career_recommendation_landing',
            'seo_surface_ref' => (string) data_get($payload, 'seo_surface_v1.metadata_fingerprint', ''),
            'surface_family' => 'career_recommendation',
            'primary_content_ref' => trim((string) data_get($payload, 'public_route_slug', '')),
            'related_surface_keys' => ['career_job', 'career_guide', 'personality_profile'],
            'fingerprint_seed' => [
                'type' => trim((string) data_get($payload, 'public_route_slug', '')),
                'locale' => $validated['locale'],
            ],
        ]);
        $payload['answer_surface_v1'] = $this->buildAnswerSurface(
            $payload,
            is_array($payload['seo_surface_v1'] ?? null) ? $payload['seo_surface_v1'] : [],
            is_array($payload['landing_surface_v1'] ?? null) ? $payload['landing_surface_v1'] : [],
            $validated['locale'],
        );

        return response()->json($payload);
    }

    private function frontendLocaleSegment(string $locale): string
    {
        return $locale === 'zh-CN' ? 'zh' : 'en';
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $seoSurface
     * @param  array<string,mixed>  $landingSurface
     * @return array<string,mixed>
     */
    private function buildAnswerSurface(array $payload, array $seoSurface, array $landingSurface, string $locale): array
    {
        $displayType = trim((string) data_get($payload, 'display_type', ''));
        $heroSummary = trim((string) data_get($payload, 'hero_summary', ''));
        $graphTypeCode = trim((string) data_get($payload, 'graph_type_code', ''));
        $matchedJobs = is_array(data_get($payload, 'matched_jobs')) ? data_get($payload, 'matched_jobs') : [];
        $matchedGuides = is_array(data_get($payload, 'matched_guides')) ? data_get($payload, 'matched_guides') : [];
        $preferredRoles = is_array(data_get($payload, 'career.preferred_roles.groups')) ? data_get($payload, 'career.preferred_roles.groups') : [];
        $primaryJobTitles = array_values(array_filter(array_map(
            static fn (mixed $job): string => is_array($job) ? trim((string) ($job['title'] ?? '')) : '',
            $matchedJobs
        )));
        $guideTitles = array_values(array_filter(array_map(
            static fn (mixed $guide): string => is_array($guide) ? trim((string) ($guide['title'] ?? '')) : '',
            $matchedGuides
        )));

        $faqBlocks = [];
        $faqBlocks[] = [
            'key' => 'route_graph_key',
            'question' => $locale === 'zh-CN' ? $displayType.' 的职业匹配按什么 key 计算？' : 'Which key drives career matching for '.$displayType.'?',
            'answer' => $locale === 'zh-CN'
                ? '公开路由使用 '.$displayType.'，但 graph match 继续回落到 '.$graphTypeCode.'。'
                : 'The public route uses '.$displayType.', while graph matching still falls back to '.$graphTypeCode.'.',
        ];
        if ($primaryJobTitles !== []) {
            $faqBlocks[] = [
                'key' => 'primary_roles',
                'question' => $locale === 'zh-CN' ? $displayType.' 现在优先看哪些方向？' : 'Which roles should '.$displayType.' review first?',
                'answer' => $locale === 'zh-CN'
                    ? implode('、', array_slice($primaryJobTitles, 0, 4)).' 是当前优先方向。'
                    : implode(', ', array_slice($primaryJobTitles, 0, 4)).' are the current first-pass directions.',
            ];
        }
        if ($guideTitles !== []) {
            $faqBlocks[] = [
                'key' => 'guide_followup',
                'question' => $locale === 'zh-CN' ? '除了岗位，还应该继续看什么？' : 'What should you validate beyond the job list?',
                'answer' => $locale === 'zh-CN'
                    ? implode('、', array_slice($guideTitles, 0, 3)).' 可以继续补齐路径判断。'
                    : implode(', ', array_slice($guideTitles, 0, 3)).' help validate the path beyond the role list.',
            ];
        }

        $compareBlocks = [
            [
                'key' => 'authority_route',
                'title' => $locale === 'zh-CN' ? '公开路由 key' : 'Public route key',
                'body' => $displayType,
                'kind' => 'authority_compare',
            ],
            [
                'key' => 'graph_key',
                'title' => $locale === 'zh-CN' ? '内部 graph key' : 'Internal graph key',
                'body' => $graphTypeCode,
                'kind' => 'authority_compare',
            ],
        ];

        $nextStepBlocks = $this->answerSurfaceContractService->buildNextStepBlocksFromCtas(
            is_array($landingSurface['cta_bundle'] ?? null) ? $landingSurface['cta_bundle'] : [],
            3
        );
        $sceneSummaryBlocks = $this->buildMbtiSceneSummaryBlocks($locale, trim((string) data_get($payload, 'public_route_slug', '')));

        if ($nextStepBlocks === [] && $preferredRoles !== []) {
            foreach ($preferredRoles as $index => $group) {
                if (! is_array($group)) {
                    continue;
                }
                $title = trim((string) ($group['group_title'] ?? ''));
                $description = trim((string) ($group['description'] ?? ''));
                if ($title === '' && $description === '') {
                    continue;
                }
                $nextStepBlocks[] = [
                    'key' => 'preferred_role_'.$index,
                    'title' => $title !== '' ? $title : ($locale === 'zh-CN' ? '优先方向' : 'Preferred direction'),
                    'body' => $description,
                    'href' => null,
                    'kind' => 'preferred_role',
                ];
            }
        }

        return $this->answerSurfaceContractService->build([
            'answer_scope' => 'public_indexable_detail',
            'surface_type' => 'career_recommendation_public_detail',
            'summary_blocks' => [
                [
                    'key' => 'answer_first',
                    'title' => $displayType,
                    'body' => $heroSummary,
                    'kind' => 'answer_first',
                ],
                [
                    'key' => 'career_summary',
                    'title' => trim((string) data_get($payload, 'career.summary.title', '')),
                    'body' => trim((string) ((data_get($payload, 'career.summary.paragraphs.0') ?? ''))),
                    'kind' => 'career_summary',
                ],
            ],
            'faq_blocks' => $faqBlocks,
            'compare_blocks' => $compareBlocks,
            'scene_summary_blocks' => $sceneSummaryBlocks,
            'next_step_blocks' => $nextStepBlocks,
            'evidence_refs' => array_values(array_filter([
                (string) ($seoSurface['metadata_fingerprint'] ?? ''),
                (string) ($landingSurface['landing_fingerprint'] ?? ''),
                'career.summary',
                $matchedJobs !== [] ? 'matched_jobs' : '',
                $matchedGuides !== [] ? 'matched_guides' : '',
                $sceneSummaryBlocks !== [] ? 'scene_summary_blocks' : '',
            ])),
            'public_safety_state' => 'public_indexable',
            'indexability_state' => 'indexable',
            'attribution_scope' => 'public_career_recommendation_answer',
            'seo_surface_ref' => (string) ($seoSurface['metadata_fingerprint'] ?? ''),
            'landing_surface_ref' => (string) ($landingSurface['landing_fingerprint'] ?? ''),
            'primary_content_ref' => trim((string) data_get($payload, 'public_route_slug', '')),
            'related_surface_keys' => ['career_job', 'career_guide', 'personality_profile'],
            'fingerprint_seed' => [
                'public_route_slug' => trim((string) data_get($payload, 'public_route_slug', '')),
                'graph_type_code' => $graphTypeCode,
                'locale' => $locale,
            ],
        ]);
    }

    /**
     * @return list<array<string,string>>
     */
    private function buildMbtiSceneSummaryBlocks(string $locale, string $publicRouteSlug): array
    {
        $segment = $this->frontendLocaleSegment($locale);
        $startTestPath = '/'.$segment.'/tests/mbti-personality-test-16-personality-types';
        $recommendationPath = $publicRouteSlug !== ''
            ? '/'.$segment.'/career/recommendations/mbti/'.rawurlencode($publicRouteSlug)
            : '/'.$segment.'/career/recommendations';

        return [
            [
                'key' => 'career_direction',
                'title' => $locale === 'zh-CN' ? '职业方向' : 'Career direction',
                'body' => $locale === 'zh-CN'
                    ? '保留当前职业推荐页，继续对照高匹配岗位。'
                    : 'Keep this recommendation page open while comparing highest-fit roles.',
                'href' => $recommendationPath,
                'kind' => 'scene_entry',
            ],
            [
                'key' => 'major_selection',
                'title' => $locale === 'zh-CN' ? '专业选择' : 'Major selection',
                'body' => $locale === 'zh-CN'
                    ? '先回 MBTI 主题页搭建判断框架，再看职业路径。'
                    : 'Use the MBTI topic hub to frame major decisions before role-level choices.',
                'href' => '/'.$segment.'/topics/mbti',
                'kind' => 'scene_entry',
            ],
            [
                'key' => 'team_collaboration',
                'title' => $locale === 'zh-CN' ? '团队协作' : 'Team collaboration',
                'body' => $locale === 'zh-CN'
                    ? '结合人格类型页，补齐协作偏好线索。'
                    : 'Pair this with personality type detail to validate collaboration preferences.',
                'href' => '/'.$segment.'/personality',
                'kind' => 'scene_entry',
            ],
            [
                'key' => 'relationship_patterns',
                'title' => $locale === 'zh-CN' ? '关系相处' : 'Relationship patterns',
                'body' => $locale === 'zh-CN'
                    ? '从主题页继续阅读关系与沟通场景。'
                    : 'Continue from topic-level guidance for relationship and communication scenarios.',
                'href' => '/'.$segment.'/topics/mbti',
                'kind' => 'scene_entry',
            ],
            [
                'key' => 'growth_planning',
                'title' => $locale === 'zh-CN' ? '成长建议' : 'Growth planning',
                'body' => $locale === 'zh-CN'
                    ? '开始测试，拿到完整个性化成长建议。'
                    : 'Start the test to unlock complete personalized growth guidance.',
                'href' => $startTestPath,
                'kind' => 'scene_entry',
            ],
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
