<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Cms;

use App\DTO\Career\CareerJobDetailBundle;
use App\Http\Controllers\Concerns\RespondsWithNotFound;
use App\Http\Controllers\Controller;
use App\Models\CareerJob;
use App\Models\CareerJobSection;
use App\Services\Career\Bundles\CareerJobDetailBundleBuilder;
use App\Services\Career\StructuredData\CareerStructuredDataBuilder;
use App\Services\Cms\CareerJobSeoService;
use App\Services\Cms\CareerJobService;
use App\Services\PublicSurface\AnswerSurfaceContractService;
use App\Services\PublicSurface\LandingSurfaceContractService;
use App\Services\PublicSurface\SeoSurfaceContractService;
use App\Support\PublicMediaUrlGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class CareerJobController extends Controller
{
    use RespondsWithNotFound;

    public function __construct(
        private readonly CareerJobService $careerJobService,
        private readonly CareerJobSeoService $careerJobSeoService,
        private readonly CareerJobDetailBundleBuilder $careerJobDetailBundleBuilder,
        private readonly CareerStructuredDataBuilder $careerStructuredDataBuilder,
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

        $paginator = $this->careerJobService->listPublicJobs(
            $validated['org_id'],
            $validated['locale'],
            $validated['page'],
            $validated['per_page'],
        );

        $items = [];
        foreach ($paginator->items() as $job) {
            if (! $job instanceof CareerJob) {
                continue;
            }

            $items[] = $this->careerJobService->listPayload($job);
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

        $job = $this->careerJobService->getPublicJobBySlug(
            $slug,
            $validated['org_id'],
            $validated['locale'],
        );

        if (! $job instanceof CareerJob) {
            return $this->notFoundResponse('career job not found.');
        }

        $frontendDetailAvailable = $this->careerJobSeoService->isFrontendDetailAvailable($job, $validated['locale']);
        $meta = PublicMediaUrlGuard::sanitizeSeoMeta(
            $this->careerJobSeoService->buildMeta($job, $validated['locale'], $frontendDetailAvailable)
        );
        $jsonLd = $this->careerJobSeoService->buildJsonLd($job, $validated['locale'], $frontendDetailAvailable);
        $sections = array_map(
            fn (CareerJobSection $section): array => $this->careerJobService->sectionPayload($section),
            $job->sections->all()
        );
        $publicIndexable = $this->careerJobSeoService->isPublicIndexable($job, $validated['locale'], $frontendDetailAvailable);
        $seoSurface = $this->buildSeoSurface($meta, $jsonLd, 'career_job_public_detail', $publicIndexable);
        $landingSurface = $this->buildLandingSurface($job, $validated['locale'], $publicIndexable);

        return response()->json([
            'ok' => true,
            'job' => $this->careerJobService->detailPayload($job),
            'sections' => $sections,
            'seo_meta' => $this->careerJobService->seoMetaPayload($job->seoMeta),
            'seo_surface_v1' => $seoSurface,
            'landing_surface_v1' => $landingSurface,
            'answer_surface_v1' => $this->buildAnswerSurface(
                $job,
                $sections,
                $seoSurface,
                $landingSurface,
                $validated['locale'],
                $publicIndexable
            ),
        ]);
    }

    public function seo(Request $request, string $slug): JsonResponse
    {
        $validated = $this->validateReadQuery($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $bundle = $this->careerJobDetailBundleBuilder->buildBySlug($slug, $validated['locale']);
        if ($bundle instanceof CareerJobDetailBundle) {
            return response()->json($this->bundleSeoAuthorityPayload($bundle, $validated['locale']));
        }

        // The career detail page is the public runtime authority. If the route cannot
        // render from the same runtime gate, the SEO endpoint must fail closed too.
        return response()->json(['error' => 'not found'], 404);
    }

    /**
     * @return array<string,mixed>
     */
    private function bundleSeoAuthorityPayload(CareerJobDetailBundle $bundle, string $locale): array
    {
        $seoContract = $bundle->seoContract;
        $canonical = $this->canonicalFromSeoContract($seoContract, $bundle, $locale);
        $title = $this->bundleTitle($bundle, $locale);
        $description = $this->bundleDescription($bundle, $title);
        $robots = $this->seoContractRobots($seoContract);
        $publicIndexable = ! $this->containsNoindex($robots)
            && (bool) ($seoContract['index_eligible'] ?? false);
        $jsonLd = $this->careerStructuredDataBuilder->build('career_job_detail', $bundle);

        $meta = PublicMediaUrlGuard::sanitizeSeoMeta([
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'alternates' => [
                'en' => '/en/career/jobs/'.rawurlencode((string) ($bundle->identity['canonical_slug'] ?? '')),
                'zh-CN' => '/zh/career/jobs/'.rawurlencode((string) ($bundle->identity['canonical_slug'] ?? '')),
            ],
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
            'robots' => $robots,
        ]);

        return [
            'meta' => $meta,
            'jsonld' => $jsonLd,
            'seo_surface_v1' => $this->buildSeoSurface($meta, $jsonLd, 'career_job_public_detail', $publicIndexable),
        ];
    }

    /**
     * @param  array<string,mixed>  $seoContract
     */
    private function canonicalFromSeoContract(array $seoContract, CareerJobDetailBundle $bundle, string $locale): string
    {
        $canonical = trim((string) ($seoContract['canonical_target'] ?? $seoContract['canonical_path'] ?? ''));
        if ($canonical !== '') {
            return $canonical;
        }

        $segment = $locale === 'zh-CN' ? 'zh' : 'en';
        $slug = rawurlencode((string) ($bundle->identity['canonical_slug'] ?? ''));

        return '/'.$segment.'/career/jobs/'.$slug;
    }

    private function bundleTitle(CareerJobDetailBundle $bundle, string $locale): string
    {
        $title = $locale === 'zh-CN'
            ? ($bundle->titles['search_h1_zh'] ?? $bundle->titles['canonical_zh'] ?? null)
            : ($bundle->titles['canonical_en'] ?? null);

        return trim((string) ($title ?: ($bundle->identity['canonical_slug'] ?? 'Career job')));
    }

    private function bundleDescription(CareerJobDetailBundle $bundle, string $title): string
    {
        $summary = $bundle->truthLayer['summary'] ?? null;
        if (is_string($summary) && trim($summary) !== '') {
            return trim($summary);
        }

        if (is_string($bundle->contentBodyMd) && trim($bundle->contentBodyMd) !== '') {
            return str(trim($bundle->contentBodyMd))->squish()->limit(160, '')->toString();
        }

        return 'Career overview and next steps for '.$title.'.';
    }

    /**
     * @param  array<string,mixed>  $seoContract
     */
    private function seoContractRobots(array $seoContract): string
    {
        $robots = trim((string) ($seoContract['robots_policy'] ?? ''));
        if ($robots !== '') {
            return $robots;
        }

        return (bool) ($seoContract['index_eligible'] ?? false) ? 'index,follow' : 'noindex,follow';
    }

    private function containsNoindex(string $robots): bool
    {
        $tokens = array_map(
            static fn (string $token): string => strtolower(trim($token)),
            explode(',', $robots)
        );

        return in_array('noindex', $tokens, true);
    }

    /**
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>
     */
    private function buildSeoSurface(array $meta, array $jsonLd, string $surfaceType, bool $publicIndexable): array
    {
        $surface = $this->seoSurfaceContractService->build([
            'metadata_scope' => $publicIndexable ? 'public_indexable_detail' : 'public_noindex_detail',
            'surface_type' => $surfaceType,
            'canonical_url' => $meta['canonical'] ?? null,
            'robots_policy' => $meta['robots'] ?? null,
            'title' => $meta['title'] ?? null,
            'description' => $meta['description'] ?? null,
            'og_payload' => is_array($meta['og'] ?? null) ? $meta['og'] : [],
            'twitter_payload' => is_array($meta['twitter'] ?? null) ? $meta['twitter'] : [],
            'alternates' => is_array($meta['alternates'] ?? null) ? $meta['alternates'] : [],
            'structured_data' => $jsonLd,
            'indexability_state' => $publicIndexable ? 'indexable' : 'noindex',
            'sitemap_state' => $publicIndexable ? 'included' : 'excluded',
            'llms_exposure_state' => $publicIndexable ? 'allow' : 'withhold',
        ]);

        $surface['index_eligible'] = $publicIndexable;
        $surface['index_state'] = $publicIndexable ? 'indexable' : 'noindex';

        return $surface;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildLandingSurface(CareerJob $job, string $locale, bool $publicIndexable): array
    {
        $segment = $this->frontendLocaleSegment($locale);
        $personalityCodes = array_values(array_filter(array_map(
            static fn (mixed $value): string => strtoupper(trim((string) $value)),
            array_merge(
                (array) ($job->fit_personality_codes ?? []),
                (array) ($job->mbti_primary_codes ?? []),
                (array) ($job->mbti_secondary_codes ?? [])
            )
        )));
        $personalitySlug = strtolower((string) ($personalityCodes[0] ?? ''));

        return $this->landingSurfaceContractService->build([
            'landing_scope' => $publicIndexable ? 'public_indexable_detail' : 'public_noindex_detail',
            'entry_surface' => 'career_job_detail',
            'entry_type' => 'career_job',
            'summary_blocks' => [
                [
                    'key' => 'hero',
                    'title' => (string) ($job->title ?? ''),
                    'body' => trim((string) ($job->excerpt ?? '')),
                    'kind' => 'answer_first',
                ],
            ],
            'discoverability_keys' => ['career_job', 'personality_profile', 'career_guide', 'start_test'],
            'continue_reading_keys' => ['personality_profile', 'career_guide'],
            'start_test_target' => '/'.$segment.'/tests/mbti-personality-test-16-personality-types',
            'result_resume_target' => null,
            'content_continue_target' => $personalitySlug !== '' ? '/'.$segment.'/personality/'.$personalitySlug : '/'.$segment.'/career/guides',
            'cta_bundle' => array_values(array_filter([
                [
                    'key' => 'start_test',
                    'label' => $locale === 'zh-CN' ? '开始测试' : 'Take the test',
                    'href' => '/'.$segment.'/tests/mbti-personality-test-16-personality-types',
                    'kind' => 'start_test',
                ],
                $personalitySlug !== ''
                    ? [
                        'key' => 'personality_profile',
                        'label' => $locale === 'zh-CN' ? '查看人格画像' : 'View personality profile',
                        'href' => '/'.$segment.'/personality/'.$personalitySlug,
                        'kind' => 'content_continue',
                    ]
                    : null,
                [
                    'key' => 'career_guides',
                    'label' => $locale === 'zh-CN' ? '阅读职业指南' : 'Read career guides',
                    'href' => '/'.$segment.'/career/guides',
                    'kind' => 'discover',
                ],
            ])),
            'indexability_state' => $publicIndexable ? 'indexable' : 'noindex',
            'attribution_scope' => 'public_career_job_landing',
            'surface_family' => 'career_job',
            'primary_content_ref' => (string) ($job->slug ?? ''),
            'related_surface_keys' => ['personality_profile', 'career_guide'],
            'fingerprint_seed' => [
                'slug' => (string) ($job->slug ?? ''),
                'locale' => $locale,
            ],
        ]);
    }

    /**
     * @param  array<int,array<string,mixed>>  $sections
     * @param  array<string,mixed>  $seoSurface
     * @param  array<string,mixed>  $landingSurface
     * @return array<string,mixed>
     */
    private function buildAnswerSurface(
        CareerJob $job,
        array $sections,
        array $seoSurface,
        array $landingSurface,
        string $locale,
        bool $publicIndexable
    ): array {
        $fitCodes = array_values(array_filter(array_unique(array_map(
            static fn (mixed $value): string => strtoupper(trim((string) $value)),
            array_merge(
                (array) ($job->fit_personality_codes ?? []),
                (array) ($job->mbti_primary_codes ?? []),
                (array) ($job->mbti_secondary_codes ?? [])
            )
        ))));
        $riasec = is_array($job->riasec_profile ?? null) ? $job->riasec_profile : [];
        $riasecText = implode(' · ', array_values(array_filter(array_map(
            static function (string $key) use ($riasec): ?string {
                $value = $riasec[$key] ?? null;

                return is_numeric($value) ? $key.' '.(string) $value : null;
            },
            ['R', 'I', 'A', 'S', 'E', 'C']
        ))));

        $compareBlocks = array_values(array_filter([
            $fitCodes !== []
                ? [
                    'key' => 'fit_personality_codes',
                    'title' => $locale === 'zh-CN' ? '适配人格' : 'Fit personality codes',
                    'body' => implode(', ', array_slice($fitCodes, 0, 4)),
                    'kind' => 'fit_compare',
                ]
                : null,
            $riasecText !== ''
                ? [
                    'key' => 'riasec_vector',
                    'title' => 'RIASEC',
                    'body' => $riasecText,
                    'kind' => 'fit_compare',
                ]
                : null,
        ]));

        return $this->answerSurfaceContractService->build([
            'answer_scope' => $publicIndexable ? 'public_indexable_detail' : 'public_noindex_detail',
            'surface_type' => 'career_job_public_detail',
            'summary_blocks' => [
                [
                    'key' => 'job_summary',
                    'title' => (string) ($job->title ?? ''),
                    'body' => trim((string) ($job->excerpt ?? '')),
                    'kind' => 'answer_first',
                ],
            ],
            'faq_blocks' => $this->answerSurfaceContractService->extractFaqBlocksFromSectionPayloads($sections),
            'compare_blocks' => $compareBlocks,
            'next_step_blocks' => $this->answerSurfaceContractService->buildNextStepBlocksFromCtas(
                is_array($landingSurface['cta_bundle'] ?? null) ? $landingSurface['cta_bundle'] : [],
                3
            ),
            'evidence_refs' => array_values(array_filter([
                (string) ($seoSurface['metadata_fingerprint'] ?? ''),
                (string) ($landingSurface['landing_fingerprint'] ?? ''),
                $fitCodes !== [] ? 'fit_personality_codes' : '',
                $riasecText !== '' ? 'riasec_profile' : '',
                $sections !== [] ? 'career_job_sections' : '',
            ])),
            'public_safety_state' => $publicIndexable ? 'public_indexable' : 'public_noindex',
            'indexability_state' => $publicIndexable ? 'indexable' : 'noindex',
            'attribution_scope' => 'public_career_job_answer',
            'seo_surface_ref' => (string) ($seoSurface['metadata_fingerprint'] ?? ''),
            'landing_surface_ref' => (string) ($landingSurface['landing_fingerprint'] ?? ''),
            'primary_content_ref' => (string) ($job->slug ?? ''),
            'related_surface_keys' => ['personality_profile', 'career_guide', 'start_test'],
            'fingerprint_seed' => [
                'slug' => (string) ($job->slug ?? ''),
                'locale' => $locale,
            ],
        ]);
    }

    private function frontendLocaleSegment(string $locale): string
    {
        return $locale === 'zh-CN' ? 'zh' : 'en';
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
