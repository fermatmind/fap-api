<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Cms;

use App\Http\Controllers\Concerns\RespondsWithNotFound;
use App\Http\Controllers\Controller;
use App\Services\Cms\CareerRecommendationService;
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

        return response()->json($payload);
    }

    private function frontendLocaleSegment(string $locale): string
    {
        return $locale === 'zh-CN' ? 'zh' : 'en';
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
