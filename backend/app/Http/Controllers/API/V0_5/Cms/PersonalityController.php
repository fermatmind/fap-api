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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PersonalityController extends Controller
{
    use RespondsWithNotFound;

    public function __construct(
        private readonly PersonalityProfileService $personalityProfileService,
        private readonly PersonalityProfileSeoService $personalityProfileSeoService,
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

        return response()->json([
            'ok' => true,
            'profile' => $this->profileDetailPayload($profile, $variant),
            'sections' => $this->publicSectionPayloads($profile, $variant),
            'seo_meta' => $this->seoMetaPayload($profile, $variant),
            'mbti_public_projection_v1' => $projection,
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

        return response()->json([
            'meta' => $this->personalityProfileSeoService->buildMeta($profile, $variant),
            'jsonld' => $this->personalityProfileSeoService->buildJsonLd($profile, $variant),
        ]);
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
            'hero_image_url' => $profile->hero_image_url,
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
            'canonical_url' => $this->personalityProfileSeoService->buildCanonicalUrl($profile, (string) $profile->locale),
            'og_title' => $seoMeta?->og_title,
            'og_description' => $seoMeta?->og_description,
            'og_image_url' => $seoMeta?->og_image_url,
            'twitter_title' => $seoMeta?->twitter_title,
            'twitter_description' => $seoMeta?->twitter_description,
            'twitter_image_url' => $seoMeta?->twitter_image_url,
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
                    $meta[$field] = $variantSeoMeta->{$field};
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
