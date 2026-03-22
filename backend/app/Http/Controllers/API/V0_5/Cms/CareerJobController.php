<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Cms;

use App\Http\Controllers\Concerns\RespondsWithNotFound;
use App\Http\Controllers\Controller;
use App\Models\CareerJob;
use App\Models\CareerJobSection;
use App\Services\Cms\CareerJobSeoService;
use App\Services\Cms\CareerJobService;
use App\Services\PublicSurface\SeoSurfaceContractService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class CareerJobController extends Controller
{
    use RespondsWithNotFound;

    public function __construct(
        private readonly CareerJobService $careerJobService,
        private readonly CareerJobSeoService $careerJobSeoService,
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

        $meta = $this->careerJobSeoService->buildMeta($job, $validated['locale']);
        $jsonLd = $this->careerJobSeoService->buildJsonLd($job, $validated['locale']);

        return response()->json([
            'ok' => true,
            'job' => $this->careerJobService->detailPayload($job),
            'sections' => array_map(
                fn (CareerJobSection $section): array => $this->careerJobService->sectionPayload($section),
                $job->sections->all()
            ),
            'seo_meta' => $this->careerJobService->seoMetaPayload($job->seoMeta),
            'seo_surface_v1' => $this->buildSeoSurface($meta, $jsonLd, 'career_job_public_detail'),
        ]);
    }

    public function seo(Request $request, string $slug): JsonResponse
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
            return response()->json(['error' => 'not found'], 404);
        }

        $meta = $this->careerJobSeoService->buildMeta($job, $validated['locale']);
        $jsonLd = $this->careerJobSeoService->buildJsonLd($job, $validated['locale']);

        return response()->json([
            'meta' => $meta,
            'jsonld' => $jsonLd,
            'seo_surface_v1' => $this->buildSeoSurface($meta, $jsonLd, 'career_job_public_detail'),
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
