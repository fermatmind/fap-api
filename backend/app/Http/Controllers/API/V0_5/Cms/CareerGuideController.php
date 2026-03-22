<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Cms;

use App\Http\Controllers\Concerns\RespondsWithNotFound;
use App\Http\Controllers\Controller;
use App\Models\CareerGuide;
use App\Services\Cms\CareerGuideSeoService;
use App\Services\Cms\CareerGuideService;
use App\Services\PublicSurface\AnswerSurfaceContractService;
use App\Services\PublicSurface\LandingSurfaceContractService;
use App\Services\PublicSurface\SeoSurfaceContractService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class CareerGuideController extends Controller
{
    use RespondsWithNotFound;

    public function __construct(
        private readonly CareerGuideService $careerGuideService,
        private readonly CareerGuideSeoService $careerGuideSeoService,
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

        $paginator = $this->careerGuideService->listPublicGuides(
            $validated['org_id'],
            $validated['locale'],
            $validated['page'],
            $validated['per_page'],
            $validated['category'],
        );

        $items = [];
        foreach ($paginator->items() as $guide) {
            if (! $guide instanceof CareerGuide) {
                continue;
            }

            $items[] = $this->careerGuideService->listPayload($guide);
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
        ]);
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $validated = $this->validateReadQuery($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $guide = $this->careerGuideService->getPublicGuideBySlug(
            $slug,
            $validated['org_id'],
            $validated['locale'],
        );

        if (! $guide instanceof CareerGuide) {
            return $this->notFoundResponse('career guide not found.');
        }

        $meta = $this->careerGuideSeoService->buildSeoPayload($guide);
        $jsonLd = $this->careerGuideSeoService->buildJsonLd($guide);
        $relatedJobs = $this->careerGuideService->relatedJobsPayload($guide);
        $relatedIndustries = $this->careerGuideService->relatedIndustriesPayload($guide);
        $relatedArticles = $this->careerGuideService->relatedArticlesPayload($guide);
        $relatedProfiles = $this->careerGuideService->relatedPersonalityProfilesPayload($guide);
        $seoSurface = $this->buildSeoSurface($meta, $jsonLd, 'career_guide_public_detail');
        $landingSurface = $this->buildLandingSurface($guide, $validated['locale']);

        return response()->json([
            'ok' => true,
            'guide' => $this->careerGuideService->detailPayload($guide),
            'related_jobs' => $relatedJobs,
            'related_industries' => $relatedIndustries,
            'related_articles' => $relatedArticles,
            'related_personality_profiles' => $relatedProfiles,
            'seo_meta' => $this->careerGuideService->seoMetaPayload($guide),
            'seo_surface_v1' => $seoSurface,
            'landing_surface_v1' => $landingSurface,
            'answer_surface_v1' => $this->buildAnswerSurface(
                $guide,
                $relatedJobs,
                $relatedArticles,
                $relatedProfiles,
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

        $guide = $this->careerGuideService->getPublicGuideBySlug(
            $slug,
            $validated['org_id'],
            $validated['locale'],
        );

        if (! $guide instanceof CareerGuide) {
            return response()->json(['error' => 'not found'], 404);
        }

        $meta = $this->careerGuideSeoService->buildSeoPayload($guide);
        $jsonLd = $this->careerGuideSeoService->buildJsonLd($guide);

        return response()->json([
            'meta' => $meta,
            'jsonld' => $jsonLd,
            'seo_surface_v1' => $this->buildSeoSurface($meta, $jsonLd, 'career_guide_public_detail'),
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
     * @return array<string,mixed>
     */
    private function buildLandingSurface(CareerGuide $guide, string $locale): array
    {
        $segment = $this->frontendLocaleSegment($locale);
        $relatedJobs = $this->careerGuideService->relatedJobsPayload($guide);
        $relatedArticles = $this->careerGuideService->relatedArticlesPayload($guide);
        $relatedProfiles = $this->careerGuideService->relatedPersonalityProfilesPayload($guide);

        return $this->landingSurfaceContractService->build([
            'landing_scope' => 'public_indexable_detail',
            'entry_surface' => 'career_guide_detail',
            'entry_type' => 'career_guide',
            'summary_blocks' => [
                [
                    'key' => 'hero',
                    'title' => (string) ($guide->title ?? ''),
                    'body' => trim((string) ($guide->excerpt ?? '')),
                    'kind' => 'answer_first',
                ],
            ],
            'discoverability_keys' => ['career_guide', 'career_job', 'article_detail', 'personality_profile'],
            'continue_reading_keys' => ['career_job', 'article_detail', 'personality_profile'],
            'start_test_target' => '/'.$segment.'/tests/mbti-personality-test-16-personality-types',
            'result_resume_target' => null,
            'content_continue_target' => trim((string) (($relatedJobs[0]['slug'] ?? null) !== null
                ? '/'.$segment.'/career/jobs/'.rawurlencode((string) $relatedJobs[0]['slug'])
                : (($relatedArticles[0]['slug'] ?? null) !== null
                    ? '/'.$segment.'/articles/'.rawurlencode((string) $relatedArticles[0]['slug'])
                    : ''))),
            'cta_bundle' => array_values(array_filter([
                [
                    'key' => 'start_test',
                    'label' => $locale === 'zh-CN' ? '开始测试' : 'Take the test',
                    'href' => '/'.$segment.'/tests/mbti-personality-test-16-personality-types',
                    'kind' => 'start_test',
                ],
                ($relatedJobs[0]['slug'] ?? null) !== null
                    ? [
                        'key' => 'related_job',
                        'label' => $locale === 'zh-CN' ? '查看相关职业' : 'View related job',
                        'href' => '/'.$segment.'/career/jobs/'.rawurlencode((string) $relatedJobs[0]['slug']),
                        'kind' => 'content_continue',
                    ]
                    : null,
                ($relatedProfiles[0]['slug'] ?? null) !== null
                    ? [
                        'key' => 'related_personality',
                        'label' => $locale === 'zh-CN' ? '查看人格画像' : 'View personality profile',
                        'href' => '/'.$segment.'/personality/'.rawurlencode((string) $relatedProfiles[0]['slug']),
                        'kind' => 'discover',
                    ]
                    : null,
            ])),
            'indexability_state' => $guide->is_indexable ? 'indexable' : 'noindex',
            'attribution_scope' => 'public_career_guide_landing',
            'surface_family' => 'career_guide',
            'primary_content_ref' => (string) ($guide->slug ?? ''),
            'related_surface_keys' => ['career_job', 'article_detail', 'personality_profile'],
            'fingerprint_seed' => [
                'slug' => (string) ($guide->slug ?? ''),
                'locale' => $locale,
            ],
        ]);
    }

    /**
     * @param  array<int,array<string,mixed>>  $relatedJobs
     * @param  array<int,array<string,mixed>>  $relatedArticles
     * @param  array<int,array<string,mixed>>  $relatedProfiles
     * @param  array<string,mixed>  $seoSurface
     * @param  array<string,mixed>  $landingSurface
     * @return array<string,mixed>
     */
    private function buildAnswerSurface(
        CareerGuide $guide,
        array $relatedJobs,
        array $relatedArticles,
        array $relatedProfiles,
        array $seoSurface,
        array $landingSurface,
        string $locale
    ): array {
        $summaryBlocks = [
            [
                'key' => 'guide_summary',
                'title' => (string) ($guide->title ?? ''),
                'body' => trim((string) ($guide->excerpt ?? '')),
                'kind' => 'answer_first',
            ],
        ];

        $nextStepBlocks = $this->answerSurfaceContractService->buildNextStepBlocksFromCtas(
            is_array($landingSurface['cta_bundle'] ?? null) ? $landingSurface['cta_bundle'] : [],
            3
        );

        if ($nextStepBlocks === []) {
            foreach ([$relatedJobs[0] ?? null, $relatedArticles[0] ?? null, $relatedProfiles[0] ?? null] as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }

                $title = trim((string) ($item['title'] ?? ''));
                $href = trim((string) ($item['href'] ?? ''));
                if ($title === '' || $href === '') {
                    continue;
                }

                $nextStepBlocks[] = [
                    'key' => 'guide_next_'.$index,
                    'title' => $title,
                    'body' => trim((string) ($item['summary'] ?? '')),
                    'href' => $href,
                    'kind' => 'content_continue',
                ];
            }
        }

        return $this->answerSurfaceContractService->build([
            'answer_scope' => $guide->is_indexable ? 'public_indexable_detail' : 'public_noindex_detail',
            'surface_type' => 'career_guide_public_detail',
            'summary_blocks' => $summaryBlocks,
            'faq_blocks' => [],
            'compare_blocks' => [],
            'next_step_blocks' => $nextStepBlocks,
            'evidence_refs' => array_values(array_filter([
                (string) ($seoSurface['metadata_fingerprint'] ?? ''),
                (string) ($landingSurface['landing_fingerprint'] ?? ''),
                $relatedJobs !== [] ? 'related_jobs' : '',
                $relatedArticles !== [] ? 'related_articles' : '',
                $relatedProfiles !== [] ? 'related_personality_profiles' : '',
            ])),
            'public_safety_state' => 'public_indexable',
            'indexability_state' => $guide->is_indexable ? 'indexable' : 'noindex',
            'attribution_scope' => 'public_career_guide_answer',
            'seo_surface_ref' => (string) ($seoSurface['metadata_fingerprint'] ?? ''),
            'landing_surface_ref' => (string) ($landingSurface['landing_fingerprint'] ?? ''),
            'primary_content_ref' => (string) ($guide->slug ?? ''),
            'related_surface_keys' => ['career_job', 'article_detail', 'personality_profile'],
            'fingerprint_seed' => [
                'slug' => (string) ($guide->slug ?? ''),
                'locale' => $locale,
            ],
        ]);
    }

    private function frontendLocaleSegment(string $locale): string
    {
        return $locale === 'zh-CN' ? 'zh' : 'en';
    }

    /**
     * @return array{org_id:int,locale:string,category:?string,page:int,per_page:int}|JsonResponse
     */
    private function validateListQuery(Request $request): array|JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'org_id' => ['nullable', 'integer', 'min:0'],
            'locale' => ['required', 'in:en,zh-CN'],
            'category' => ['nullable', 'string', 'max:128'],
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
            'category' => isset($validated['category']) ? (string) $validated['category'] : null,
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
