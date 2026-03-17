<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CareerRecommendationService
{
    private const AUTHORITY_SOURCE = 'career_recommendation_service.v1';

    public function __construct(
        private readonly PersonalityProfileService $personalityProfileService,
        private readonly CareerJobService $careerJobService,
        private readonly CareerGuideService $careerGuideService,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function listPublicRecommendations(int $orgId, string $locale): array
    {
        $items = [];

        foreach ($this->listPublishedProfilesWithVariants($orgId, $locale) as $profile) {
            if (! $profile instanceof PersonalityProfile) {
                continue;
            }

            foreach ($profile->variants as $variant) {
                if (! $variant instanceof PersonalityProfileVariant) {
                    continue;
                }

                $projection = $this->personalityProfileService->buildPublicProjection($profile, $variant);

                $items[] = [
                    'runtime_type_code' => data_get($projection, 'runtime_type_code'),
                    'canonical_type_code' => data_get($projection, 'canonical_type_code'),
                    'display_type' => data_get($projection, 'display_type'),
                    'variant_code' => data_get($projection, 'variant_code'),
                    'public_route_slug' => $this->resolvePublicRouteSlug($profile, $variant),
                    'type_name' => $this->fallbackText(
                        data_get($projection, 'profile.type_name'),
                        (string) $profile->type_name,
                        (string) $profile->title
                    ),
                    'nickname' => $this->fallbackText(
                        data_get($projection, 'profile.nickname'),
                        (string) $profile->nickname
                    ),
                    'hero_summary' => $this->fallbackText(
                        data_get($projection, 'profile.hero_summary'),
                        data_get($projection, 'summary_card.summary'),
                        (string) $profile->excerpt
                    ),
                ];
            }
        }

        return $items;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPublicRecommendationByType(string $typeLookup, int $orgId, string $locale): ?array
    {
        $routeProfile = $this->personalityProfileService->getPublicDetailRouteProfileByType(
            $typeLookup,
            $orgId,
            PersonalityProfile::SCALE_CODE_MBTI,
            $this->normalizeLocale($locale),
        );

        if (! is_array($routeProfile)) {
            return null;
        }

        /** @var PersonalityProfile $profile */
        $profile = $routeProfile['profile'];
        /** @var PersonalityProfileVariant|null $variant */
        $variant = $routeProfile['variant'];

        if (! $variant instanceof PersonalityProfileVariant) {
            $variant = $this->personalityProfileService->getDefaultPublishedVariantForCanonicalRoute($profile);
        }

        if (! $variant instanceof PersonalityProfileVariant) {
            return null;
        }

        $projection = $this->personalityProfileService->buildPublicProjection($profile, $variant);
        $canonicalTypeCode = strtoupper((string) data_get(
            $projection,
            'canonical_type_code',
            (string) ($profile->canonical_type_code ?: $profile->type_code)
        ));
        $publicRouteSlug = $this->resolvePublicRouteSlug($profile, $variant);

        if ($canonicalTypeCode === '' || $publicRouteSlug === '') {
            return null;
        }

        return [
            'runtime_type_code' => data_get($projection, 'runtime_type_code'),
            'canonical_type_code' => $canonicalTypeCode,
            'display_type' => data_get($projection, 'display_type'),
            'variant_code' => data_get($projection, 'variant_code'),
            'public_route_slug' => $publicRouteSlug,
            'graph_type_code' => $canonicalTypeCode,
            'type_name' => $this->fallbackText(
                data_get($projection, 'profile.type_name'),
                (string) $profile->type_name,
                (string) $profile->title
            ),
            'nickname' => $this->fallbackText(
                data_get($projection, 'profile.nickname'),
                (string) $profile->nickname
            ),
            'hero_summary' => $this->fallbackText(
                data_get($projection, 'profile.hero_summary'),
                data_get($projection, 'summary_card.summary'),
                (string) $profile->excerpt
            ),
            'keywords' => $this->stringList(data_get($projection, 'profile.keywords')),
            'career' => [
                'summary' => $this->summarySectionPayload($projection),
                'advantages' => $this->bulletSectionPayload($projection, 'career.advantages', 'description'),
                'weaknesses' => $this->bulletSectionPayload($projection, 'career.weaknesses', 'description'),
                'preferred_roles' => $this->preferredRolesPayload($projection),
                'upgrade_suggestions' => $this->upgradeSuggestionsPayload($projection),
            ],
            'matched_jobs' => $this->careerJobService->findPublishedJobsForMbtiGraph(
                $canonicalTypeCode,
                $locale,
                $orgId,
            ),
            'matched_guides' => $this->careerGuideService->findPublishedGuidesForMbtiGraph(
                $canonicalTypeCode,
                $locale,
                $orgId,
            ),
            'seo' => $this->seoPayload($projection, $locale, $publicRouteSlug),
            '_meta' => [
                'public_route_type' => '32-type',
                'route_mode' => 'public_variant',
                'authority_source' => self::AUTHORITY_SOURCE,
            ],
        ];
    }

    /**
     * @return Collection<int, PersonalityProfile>
     */
    private function listPublishedProfilesWithVariants(int $orgId, string $locale): Collection
    {
        return PersonalityProfile::query()
            ->withoutGlobalScopes()
            ->where('org_id', max(0, $orgId))
            ->where('scale_code', PersonalityProfile::SCALE_CODE_MBTI)
            ->whereIn('type_code', PersonalityProfile::TYPE_CODES)
            ->forLocale($this->normalizeLocale($locale))
            ->publishedPublic()
            ->where(static function (Builder $query): void {
                $query->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->with([
                'sections' => static function (HasMany $query): void {
                    $query->where('is_enabled', true)
                        ->orderBy('sort_order')
                        ->orderBy('id');
                },
                'seoMeta',
                'variants' => static function (HasMany $query): void {
                    $query->where('is_published', true)
                        ->where(static function (Builder $builder): void {
                            $builder->whereNull('published_at')
                                ->orWhere('published_at', '<=', now());
                        })
                        ->with([
                            'sections' => static function (HasMany $builder): void {
                                $builder->orderBy('sort_order')
                                    ->orderBy('id');
                            },
                            'seoMeta',
                        ])
                        ->orderByRaw("case when variant_code = 'A' then 0 when variant_code = 'T' then 1 else 9 end")
                        ->orderBy('runtime_type_code')
                        ->orderBy('id');
                },
            ])
            ->orderBy('canonical_type_code')
            ->orderBy('type_code')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function summarySectionPayload(array $projection): array
    {
        $section = $this->sectionByKey($projection, 'career.summary');

        return [
            'title' => $this->nullableText($section['title'] ?? null),
            'paragraphs' => $this->paragraphsFromMarkdown($section['body_md'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bulletSectionPayload(array $projection, string $sectionKey, string $bodyField): array
    {
        $section = $this->sectionByKey($projection, $sectionKey);
        $items = [];

        foreach (data_get($section, 'payload.items', []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $items[] = array_filter([
                'title' => $this->nullableText($item['title'] ?? null),
                $bodyField => $this->nullableText($item['body'] ?? $item['description'] ?? null),
            ], static fn (mixed $value): bool => $value !== null);
        }

        return [
            'title' => $this->nullableText($section['title'] ?? null),
            'items' => array_values($items),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function preferredRolesPayload(array $projection): array
    {
        $section = $this->sectionByKey($projection, 'career.preferred_roles');
        $groups = [];

        foreach (data_get($section, 'payload.groups', []) as $group) {
            if (! is_array($group)) {
                continue;
            }

            $groups[] = array_filter([
                'group_title' => $this->nullableText($group['group_title'] ?? $group['title'] ?? null),
                'description' => $this->nullableText($group['description'] ?? null),
                'examples' => $this->stringList($group['examples'] ?? []),
            ], static fn (mixed $value): bool => $value !== null);
        }

        return [
            'title' => $this->nullableText($section['title'] ?? null),
            'intro' => $this->nullableText(data_get($section, 'payload.intro')),
            'groups' => $groups,
            'outro' => $this->nullableText(data_get($section, 'payload.outro')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function upgradeSuggestionsPayload(array $projection): array
    {
        $section = $this->sectionByKey($projection, 'career.upgrade_suggestions');
        $bullets = [];

        foreach (data_get($section, 'payload.items', []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $bullets[] = array_filter([
                'label' => $this->nullableText($item['title'] ?? $item['label'] ?? null),
                'content' => $this->nullableText($item['body'] ?? $item['content'] ?? null),
            ], static fn (mixed $value): bool => $value !== null);
        }

        return [
            'title' => $this->nullableText($section['title'] ?? null),
            'paragraphs' => $this->paragraphsFromMarkdown($section['body_md'] ?? null),
            'bullets' => array_values($bullets),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function seoPayload(array $projection, string $locale, string $publicRouteSlug): array
    {
        $displayType = $this->fallbackText(
            $this->nullableText(data_get($projection, 'display_type')),
            $this->nullableText(data_get($projection, 'runtime_type_code')),
            $this->nullableText(data_get($projection, 'canonical_type_code'))
        ) ?? $publicRouteSlug;
        $typeName = $this->nullableText(data_get($projection, 'profile.type_name'));
        $heroSummary = $this->fallbackText(
            $this->nullableText(data_get($projection, 'profile.hero_summary')),
            $this->nullableText(data_get($projection, 'summary_card.summary'))
        );
        $canonical = $this->careerRecommendationPath($locale, $publicRouteSlug);

        return [
            'title' => $this->fallbackText(
                $this->nullableText(data_get($projection, 'seo.title')),
                $this->localeCopy(
                    $locale,
                    trim($displayType.' Career Recommendations | FermatMind'),
                    trim($displayType.' 职业推荐 | FermatMind'),
                )
            ),
            'description' => $this->fallbackText(
                $this->nullableText(data_get($projection, 'seo.description')),
                $heroSummary,
                $this->localeCopy(
                    $locale,
                    trim('Career recommendations, role fit, and growth paths for '.($typeName ?? $displayType).'.'),
                    trim(($typeName ?? $displayType).' 的职业匹配、发展方向与成长建议。'),
                )
            ),
            'canonical' => $canonical,
            'alternates' => [
                'en' => $this->careerRecommendationPath('en', $publicRouteSlug),
                'zh-CN' => $this->careerRecommendationPath('zh-CN', $publicRouteSlug),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sectionByKey(array $projection, string $sectionKey): array
    {
        foreach (data_get($projection, 'sections', []) as $section) {
            if (! is_array($section)) {
                continue;
            }

            if ((string) ($section['key'] ?? '') === $sectionKey) {
                return $section;
            }
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function paragraphsFromMarkdown(mixed $bodyMd): array
    {
        if (! is_string($bodyMd)) {
            return [];
        }

        $normalized = trim(str_replace("\r", '', $bodyMd));
        if ($normalized === '') {
            return [];
        }

        $parts = preg_split("/\n{2,}/", $normalized) ?: [];

        return array_values(array_filter(array_map(static function (string $paragraph): string {
            return trim(preg_replace("/\n+/", ' ', $paragraph) ?? $paragraph);
        }, $parts), static fn (string $paragraph): bool => $paragraph !== ''));
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $normalized = [];

        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $text = trim((string) $value);
            if ($text === '') {
                continue;
            }

            $normalized[] = $text;
        }

        return array_values($normalized);
    }

    private function normalizeLocale(string $locale): string
    {
        return trim($locale) === 'zh-CN' ? 'zh-CN' : 'en';
    }

    private function careerRecommendationPath(string $locale, string $publicRouteSlug): string
    {
        return '/'
            .$this->mapBackendLocaleToFrontendSegment($locale)
            .'/career/recommendations/mbti/'
            .rawurlencode(strtolower(trim($publicRouteSlug)));
    }

    private function resolvePublicRouteSlug(PersonalityProfile $profile, PersonalityProfileVariant $variant): string
    {
        $baseSlug = strtolower(trim((string) $profile->slug));
        $variantCode = strtoupper(trim((string) $variant->variant_code));

        if ($baseSlug === '') {
            return '';
        }

        if (! in_array($variantCode, ['A', 'T'], true)) {
            return $baseSlug;
        }

        return strtolower($baseSlug.'-'.$variantCode);
    }

    private function mapBackendLocaleToFrontendSegment(string $locale): string
    {
        return $this->normalizeLocale($locale) === 'zh-CN' ? 'zh' : 'en';
    }

    private function localeCopy(string $locale, string $en, string $zhCn): string
    {
        return $this->normalizeLocale($locale) === 'zh-CN' ? $zhCn : $en;
    }

    private function nullableText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function fallbackText(?string ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if ($candidate === null) {
                continue;
            }

            $normalized = trim($candidate);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }
}
