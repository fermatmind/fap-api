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
use App\Services\PublicSurface\SeoSurfaceContractService;
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

        $meta = $this->topicProfileSeoService->buildMeta($profile, $validated['locale']);
        $jsonLd = $this->topicProfileSeoService->buildJsonLd($profile, $validated['locale']);

        return response()->json([
            'ok' => true,
            'profile' => $this->profileDetailPayload($profile),
            'sections' => array_map(
                fn (TopicProfileSection $section): array => $this->sectionPayload($section),
                $profile->sections->all()
            ),
            'entry_groups' => $this->topicEntryResolverService->resolveGroupedEntries($profile, $validated['locale']),
            'seo_meta' => $this->seoMetaPayload($profile->seoMeta),
            'seo_surface_v1' => $this->buildSeoSurface($meta, $jsonLd, 'topic_public_detail'),
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

        $meta = $this->topicProfileSeoService->buildMeta($profile, $validated['locale']);
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
            'cover_image_url' => $profile->cover_image_url,
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
            'canonical_url' => $seoMeta->canonical_url,
            'og_title' => $seoMeta->og_title,
            'og_description' => $seoMeta->og_description,
            'og_image_url' => $seoMeta->og_image_url,
            'twitter_title' => $seoMeta->twitter_title,
            'twitter_description' => $seoMeta->twitter_description,
            'twitter_image_url' => $seoMeta->twitter_image_url,
            'robots' => $seoMeta->robots,
            'jsonld_overrides_json' => $seoMeta->jsonld_overrides_json,
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
