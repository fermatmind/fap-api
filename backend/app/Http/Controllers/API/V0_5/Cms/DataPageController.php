<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Cms;

use App\Http\Controllers\Concerns\RespondsWithNotFound;
use App\Http\Controllers\Controller;
use App\Models\DataPage;
use App\Services\Cms\DataPageSeoService;
use App\Services\Cms\DataPageService;
use App\Services\PublicSurface\AnswerSurfaceContractService;
use App\Services\PublicSurface\LandingSurfaceContractService;
use App\Services\PublicSurface\SeoSurfaceContractService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class DataPageController extends Controller
{
    use RespondsWithNotFound;

    public function __construct(
        private readonly DataPageService $dataPageService,
        private readonly DataPageSeoService $dataPageSeoService,
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

        $paginator = $this->dataPageService->listPublicDataPages(
            $validated['org_id'],
            $validated['locale'],
            $validated['page'],
            $validated['per_page'],
        );

        $items = [];
        foreach ($paginator->items() as $page) {
            if (! $page instanceof DataPage) {
                continue;
            }

            $items[] = $this->dataPageService->listPayload($page);
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

        $page = $this->dataPageService->getPublicDataPageBySlug($slug, $validated['org_id'], $validated['locale']);
        if (! $page instanceof DataPage) {
            return $this->notFoundResponse('data page not found.');
        }

        $meta = $this->dataPageSeoService->buildMeta($page, $validated['locale']);
        $jsonLd = $this->dataPageSeoService->buildJsonLd($page, $validated['locale']);
        $seoSurface = $this->buildSeoSurface($meta, $jsonLd, 'data_public_detail');
        $landingSurface = $this->buildDetailLandingSurface($page, $validated['locale']);

        return response()->json([
            'ok' => true,
            'page' => $this->dataPageService->detailPayload($page),
            'seo_meta' => $this->dataPageService->seoMetaPayload($page),
            'seo_surface_v1' => $seoSurface,
            'landing_surface_v1' => $landingSurface,
            'answer_surface_v1' => $this->buildDetailAnswerSurface($page, $seoSurface, $landingSurface, $validated['locale']),
        ]);
    }

    public function seo(Request $request, string $slug): JsonResponse
    {
        $validated = $this->validateReadQuery($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $page = $this->dataPageService->getPublicDataPageBySlug($slug, $validated['org_id'], $validated['locale']);
        if (! $page instanceof DataPage) {
            return response()->json(['error' => 'not found'], 404);
        }

        $meta = $this->dataPageSeoService->buildMeta($page, $validated['locale']);
        $jsonLd = $this->dataPageSeoService->buildJsonLd($page, $validated['locale']);

        return response()->json([
            'meta' => $meta,
            'jsonld' => $jsonLd,
            'seo_surface_v1' => $this->buildSeoSurface($meta, $jsonLd, 'data_public_detail'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
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
     * @return array<string, mixed>
     */
    private function buildIndexLandingSurface(string $locale): array
    {
        $segment = $this->frontendLocaleSegment($locale);

        return $this->landingSurfaceContractService->build([
            'landing_scope' => 'public_indexable_index',
            'entry_surface' => 'data_index',
            'entry_type' => 'data_page',
            'summary_blocks' => [[
                'key' => 'data_summary',
                'title' => $locale === 'zh-CN' ? '数据页中心' : 'Data hub',
                'body' => $locale === 'zh-CN'
                    ? '集中输出样本口径、时间窗口和聚合结论，服务可引用的数据洞察页面。'
                    : 'A public hub for sample framing, time windows, and citation-ready data insights.',
                'kind' => 'answer_first',
            ]],
            'discoverability_keys' => ['data_page', 'method_page', 'test'],
            'continue_reading_keys' => ['data_page', 'method_page'],
            'start_test_target' => '/'.$segment.'/tests/mbti-personality-test-16-personality-types',
            'content_continue_target' => '/'.$segment.'/methods',
            'cta_bundle' => [
                [
                    'key' => 'method_pages',
                    'label' => $locale === 'zh-CN' ? '查看方法页' : 'View methods',
                    'href' => '/'.$segment.'/methods',
                    'kind' => 'content_continue',
                ],
                [
                    'key' => 'start_test',
                    'label' => $locale === 'zh-CN' ? '开始测试' : 'Take the test',
                    'href' => '/'.$segment.'/tests/mbti-personality-test-16-personality-types',
                    'kind' => 'start_test',
                ],
            ],
            'indexability_state' => 'indexable',
            'attribution_scope' => 'public_data_index_landing',
            'surface_family' => 'data',
            'primary_content_ref' => 'data-index',
            'related_surface_keys' => ['method_page', 'test'],
            'fingerprint_seed' => ['locale' => $locale, 'surface' => 'data_index'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDetailLandingSurface(DataPage $page, string $locale): array
    {
        $segment = $this->frontendLocaleSegment($locale);

        return $this->landingSurfaceContractService->build([
            'landing_scope' => 'public_indexable_detail',
            'entry_surface' => 'data_detail',
            'entry_type' => 'data_page',
            'summary_blocks' => [[
                'key' => 'hero',
                'title' => (string) $page->title,
                'body' => trim((string) ($page->summary_statement_md ?: $page->excerpt ?: $page->subtitle ?: '')),
                'kind' => 'answer_first',
            ]],
            'discoverability_keys' => ['data_page', 'method_page', 'test', 'topic'],
            'continue_reading_keys' => ['method_page', 'test', 'topic'],
            'start_test_target' => '/'.$segment.'/tests/mbti-personality-test-16-personality-types',
            'content_continue_target' => '/'.$segment.'/methods',
            'cta_bundle' => [
                [
                    'key' => 'method_pages',
                    'label' => $locale === 'zh-CN' ? '查看方法页' : 'View methods',
                    'href' => '/'.$segment.'/methods',
                    'kind' => 'content_continue',
                ],
                [
                    'key' => 'topics_hub',
                    'label' => $locale === 'zh-CN' ? '查看专题' : 'View hubs',
                    'href' => '/'.$segment.'/topics',
                    'kind' => 'discover',
                ],
                [
                    'key' => 'start_test',
                    'label' => $locale === 'zh-CN' ? '开始测试' : 'Take the test',
                    'href' => '/'.$segment.'/tests/mbti-personality-test-16-personality-types',
                    'kind' => 'start_test',
                ],
            ],
            'indexability_state' => $page->is_indexable ? 'indexable' : 'noindex',
            'attribution_scope' => 'public_data_detail_landing',
            'surface_family' => 'data',
            'primary_content_ref' => (string) $page->slug,
            'related_surface_keys' => ['method_page', 'test', 'topic'],
            'fingerprint_seed' => ['slug' => (string) $page->slug, 'locale' => $locale],
        ]);
    }

    /**
     * @param  array<string, mixed>  $seoSurface
     * @param  array<string, mixed>  $landingSurface
     * @return array<string, mixed>
     */
    private function buildDetailAnswerSurface(DataPage $page, array $seoSurface, array $landingSurface, string $locale): array
    {
        return $this->answerSurfaceContractService->build([
            'surface_scope' => 'public_indexable_detail',
            'surface_type' => 'data_detail',
            'summary_blocks' => [[
                'key' => 'data_summary',
                'title' => (string) $page->title,
                'body' => trim((string) ($page->summary_statement_md ?: $page->excerpt ?: '')),
                'kind' => 'answer_first',
            ]],
            'faq_blocks' => [],
            'next_step_blocks' => $this->answerSurfaceContractService->buildNextStepBlocksFromCtas(
                is_array($landingSurface['cta_bundle'] ?? null) ? $landingSurface['cta_bundle'] : [],
                3
            ),
            'seo_surface' => $seoSurface,
            'landing_surface' => $landingSurface,
            'attribution_scope' => 'public_data_answer',
            'locale' => $locale,
            'primary_content_ref' => (string) $page->slug,
        ]);
    }

    /**
     * @return array<string, int>|JsonResponse
     */
    private function validateListQuery(Request $request): array|JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'locale' => ['nullable', 'string', 'max:16'],
            'org_id' => ['nullable', 'integer', 'min:0'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok' => false,
                'error_code' => 'VALIDATION_ERROR',
                'message' => 'invalid query.',
                'details' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        return [
            'locale' => $this->normalizeLocale((string) ($validated['locale'] ?? 'en')),
            'org_id' => max(0, (int) ($validated['org_id'] ?? 0)),
            'page' => max(1, (int) ($validated['page'] ?? 1)),
            'per_page' => max(1, min(100, (int) ($validated['per_page'] ?? 20))),
        ];
    }

    /**
     * @return array<string, int|string>|JsonResponse
     */
    private function validateReadQuery(Request $request): array|JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'locale' => ['nullable', 'string', 'max:16'],
            'org_id' => ['nullable', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok' => false,
                'error_code' => 'VALIDATION_ERROR',
                'message' => 'invalid query.',
                'details' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        return [
            'locale' => $this->normalizeLocale((string) ($validated['locale'] ?? 'en')),
            'org_id' => max(0, (int) ($validated['org_id'] ?? 0)),
        ];
    }

    private function normalizeLocale(string $locale): string
    {
        return trim($locale) === 'zh-CN' ? 'zh-CN' : 'en';
    }

    private function frontendLocaleSegment(string $locale): string
    {
        return $this->normalizeLocale($locale) === 'zh-CN' ? 'zh' : 'en';
    }
}
