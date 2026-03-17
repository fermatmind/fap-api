<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\Article;
use App\Models\CareerGuide;
use App\Models\CareerJob;
use App\Models\PersonalityProfile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class CareerGuideService
{
    public function __construct(
        private readonly CareerGuideSeoService $careerGuideSeoService,
    ) {}

    public function listPublicGuides(
        int $orgId,
        string $locale,
        int $page = 1,
        int $perPage = 20,
        ?string $category = null,
    ): LengthAwarePaginator {
        return $this->basePublicQuery($orgId, $locale, $category)
            ->orderBy('sort_order')
            ->orderByDesc('published_at')
            ->orderBy('guide_code')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    public function getPublicGuideBySlug(string $slug, int $orgId, string $locale): ?CareerGuide
    {
        return $this->basePublicQuery($orgId, $locale)
            ->forSlug($this->normalizeSlug($slug))
            ->with('seoMeta')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function findPublishedGuidesForMbtiGraph(string $graphTypeCode, string $locale, int $orgId = 0): array
    {
        $normalizedGraphTypeCode = strtoupper(trim($graphTypeCode));

        if ($normalizedGraphTypeCode === '') {
            return [];
        }

        return $this->basePublicQuery($orgId, $locale)
            ->with([
                'relatedPersonalityProfiles' => function (BelongsToMany $query) use ($orgId, $locale): void {
                    $query->withoutGlobalScopes()
                        ->where('personality_profiles.org_id', max(0, $orgId))
                        ->where('personality_profiles.scale_code', PersonalityProfile::SCALE_CODE_MBTI)
                        ->whereIn('personality_profiles.type_code', PersonalityProfile::TYPE_CODES)
                        ->where('personality_profiles.locale', $this->normalizeLocale($locale))
                        ->where('personality_profiles.status', 'published')
                        ->where('personality_profiles.is_public', true)
                        ->where(static function (Builder $builder): void {
                            $builder->whereNull('personality_profiles.published_at')
                                ->orWhere('personality_profiles.published_at', '<=', now());
                        })
                        ->orderBy('career_guide_personality_map.sort_order')
                        ->orderBy('personality_profiles.id');
                },
            ])
            ->orderBy('sort_order')
            ->orderByDesc('published_at')
            ->orderBy('guide_code')
            ->orderBy('id')
            ->get()
            ->map(function (CareerGuide $guide) use ($normalizedGraphTypeCode): ?array {
                $fitPersonalityCodes = $guide->relatedPersonalityProfiles
                    ->filter(static fn (mixed $profile): bool => $profile instanceof PersonalityProfile)
                    ->map(static fn (PersonalityProfile $profile): string => strtoupper((string) $profile->type_code))
                    ->filter(static fn (string $typeCode): bool => $typeCode !== '')
                    ->unique()
                    ->values()
                    ->all();

                if (! in_array($normalizedGraphTypeCode, $fitPersonalityCodes, true)) {
                    return null;
                }

                return [
                    'slug' => (string) $guide->slug,
                    'title' => (string) $guide->title,
                    'summary' => $this->fallbackText($guide->excerpt, $guide->title),
                    'fit_personality_codes' => $fitPersonalityCodes,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function listPayload(CareerGuide $guide): array
    {
        return [
            'id' => (int) $guide->id,
            'org_id' => (int) $guide->org_id,
            'guide_code' => (string) $guide->guide_code,
            'slug' => (string) $guide->slug,
            'locale' => (string) $guide->locale,
            'title' => (string) $guide->title,
            'excerpt' => $guide->excerpt,
            'category_slug' => $guide->category_slug,
            'is_public' => (bool) $guide->is_public,
            'is_indexable' => (bool) $guide->is_indexable,
            'published_at' => $guide->published_at?->toISOString(),
            'updated_at' => $guide->updated_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detailPayload(CareerGuide $guide): array
    {
        return [
            'id' => (int) $guide->id,
            'org_id' => (int) $guide->org_id,
            'guide_code' => (string) $guide->guide_code,
            'slug' => (string) $guide->slug,
            'locale' => (string) $guide->locale,
            'title' => (string) $guide->title,
            'excerpt' => $guide->excerpt,
            'category_slug' => $guide->category_slug,
            'body_md' => $guide->body_md,
            'body_html' => $guide->body_html,
            'is_public' => (bool) $guide->is_public,
            'is_indexable' => (bool) $guide->is_indexable,
            'published_at' => $guide->published_at?->toISOString(),
            'updated_at' => $guide->updated_at?->toISOString(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function relatedJobsPayload(CareerGuide $guide): array
    {
        return CareerJob::query()
            ->withoutGlobalScopes()
            ->select('career_jobs.*')
            ->join('career_guide_job_map', 'career_guide_job_map.career_job_id', '=', 'career_jobs.id')
            ->where('career_guide_job_map.career_guide_id', (int) $guide->id)
            ->where('career_jobs.org_id', (int) $guide->org_id)
            ->where('career_jobs.locale', $this->normalizeLocale((string) $guide->locale))
            ->where('career_jobs.status', CareerJob::STATUS_PUBLISHED)
            ->where('career_jobs.is_public', true)
            ->where(static function (Builder $query): void {
                $query->whereNull('career_jobs.published_at')
                    ->orWhere('career_jobs.published_at', '<=', now());
            })
            ->orderBy('career_guide_job_map.sort_order')
            ->orderBy('career_jobs.id')
            ->get()
            ->map(static fn (CareerJob $job): array => [
                'id' => (int) $job->id,
                'job_code' => (string) $job->job_code,
                'slug' => (string) $job->slug,
                'locale' => (string) $job->locale,
                'title' => (string) $job->title,
                'excerpt' => $job->excerpt,
                'industry_slug' => $job->industry_slug,
                'industry_label' => $job->industry_label,
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function relatedIndustriesPayload(CareerGuide $guide): array
    {
        $slugs = $guide->related_industry_slugs_json;
        if (! is_array($slugs)) {
            return [];
        }

        $normalized = [];
        foreach ($slugs as $slug) {
            if (! is_string($slug)) {
                continue;
            }

            $trimmed = strtolower(trim($slug));
            if ($trimmed === '') {
                continue;
            }

            $normalized[$trimmed] = $trimmed;
        }

        return array_values($normalized);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function relatedArticlesPayload(CareerGuide $guide): array
    {
        return Article::query()
            ->withoutGlobalScopes()
            ->select('articles.*')
            ->join('career_guide_article_map', 'career_guide_article_map.article_id', '=', 'articles.id')
            ->where('career_guide_article_map.career_guide_id', (int) $guide->id)
            ->where('articles.org_id', (int) $guide->org_id)
            ->where('articles.locale', $this->normalizeLocale((string) $guide->locale))
            ->where('articles.status', 'published')
            ->where('articles.is_public', true)
            ->where(static function (Builder $query): void {
                $query->whereNull('articles.published_at')
                    ->orWhere('articles.published_at', '<=', now());
            })
            ->orderBy('career_guide_article_map.sort_order')
            ->orderBy('articles.id')
            ->get()
            ->map(static fn (Article $article): array => [
                'id' => (int) $article->id,
                'slug' => (string) $article->slug,
                'locale' => (string) $article->locale,
                'title' => (string) $article->title,
                'excerpt' => $article->excerpt,
                'published_at' => $article->published_at?->toISOString(),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function relatedPersonalityProfilesPayload(CareerGuide $guide): array
    {
        return PersonalityProfile::query()
            ->withoutGlobalScopes()
            ->select('personality_profiles.*')
            ->join(
                'career_guide_personality_map',
                'career_guide_personality_map.personality_profile_id',
                '=',
                'personality_profiles.id'
            )
            ->where('career_guide_personality_map.career_guide_id', (int) $guide->id)
            ->where('personality_profiles.org_id', (int) $guide->org_id)
            ->where('personality_profiles.locale', $this->normalizeLocale((string) $guide->locale))
            ->where('personality_profiles.status', 'published')
            ->where('personality_profiles.is_public', true)
            ->where(static function (Builder $query): void {
                $query->whereNull('personality_profiles.published_at')
                    ->orWhere('personality_profiles.published_at', '<=', now());
            })
            ->orderBy('career_guide_personality_map.sort_order')
            ->orderBy('personality_profiles.id')
            ->get()
            ->map(static fn (PersonalityProfile $profile): array => [
                'id' => (int) $profile->id,
                'type_code' => (string) $profile->type_code,
                'slug' => (string) $profile->slug,
                'locale' => (string) $profile->locale,
                'title' => (string) $profile->title,
                'excerpt' => $profile->excerpt,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function seoMetaPayload(CareerGuide $guide): ?array
    {
        return $this->careerGuideSeoService->detailSeoMetaPayload($guide);
    }

    private function basePublicQuery(int $orgId, string $locale, ?string $category = null): Builder
    {
        $query = CareerGuide::query()
            ->withoutGlobalScopes()
            ->where('org_id', max(0, $orgId))
            ->forLocale($this->normalizeLocale($locale))
            ->publishedPublic()
            ->where(static function (Builder $builder): void {
                $builder->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            });

        $normalizedCategory = $this->normalizeCategory($category);
        if ($normalizedCategory !== null) {
            $query->where('category_slug', $normalizedCategory);
        }

        return $query;
    }

    private function normalizeLocale(string $locale): string
    {
        return trim($locale) === 'zh-CN' ? 'zh-CN' : 'en';
    }

    private function normalizeSlug(string $slug): string
    {
        return strtolower(trim($slug));
    }

    private function normalizeCategory(?string $category): ?string
    {
        if ($category === null) {
            return null;
        }

        $normalized = strtolower(trim($category));

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
