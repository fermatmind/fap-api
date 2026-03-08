<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\CareerJob;
use App\Models\CareerJobSection;
use App\Models\CareerJobSeoMeta;
use App\Models\Scopes\TenantScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CareerJobService
{
    public function listPublicJobs(int $orgId, string $locale, int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        return $this->basePublicQuery($orgId, $locale)
            ->with('seoMeta')
            ->orderBy('sort_order')
            ->orderBy('job_code')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    public function getPublicJobBySlug(string $slug, int $orgId, string $locale): ?CareerJob
    {
        return $this->basePublicQuery($orgId, $locale)
            ->forSlug($this->normalizeSlug($slug))
            ->with([
                'sections' => static function (HasMany $query): void {
                    $query->where('is_enabled', true)
                        ->orderBy('sort_order')
                        ->orderBy('id');
                },
                'seoMeta',
            ])
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function listPayload(CareerJob $job): array
    {
        return [
            'id' => (int) $job->id,
            'org_id' => (int) $job->org_id,
            'job_code' => (string) $job->job_code,
            'slug' => (string) $job->slug,
            'locale' => (string) $job->locale,
            'title' => (string) $job->title,
            'subtitle' => $job->subtitle,
            'excerpt' => $job->excerpt,
            'industry_slug' => $job->industry_slug,
            'industry_label' => $job->industry_label,
            'status' => (string) $job->status,
            'is_public' => (bool) $job->is_public,
            'is_indexable' => (bool) $job->is_indexable,
            'published_at' => $job->published_at?->toISOString(),
            'updated_at' => $job->updated_at?->toISOString(),
            'salary' => $job->salary_json,
            'seo_meta' => $this->seoMetaSummaryPayload($this->resolveSeoMeta($job)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detailPayload(CareerJob $job): array
    {
        return array_merge([
            'id' => (int) $job->id,
            'org_id' => (int) $job->org_id,
            'job_code' => (string) $job->job_code,
            'slug' => (string) $job->slug,
            'locale' => (string) $job->locale,
            'title' => (string) $job->title,
            'subtitle' => $job->subtitle,
            'excerpt' => $job->excerpt,
            'hero_kicker' => $job->hero_kicker,
            'hero_quote' => $job->hero_quote,
            'cover_image_url' => $job->cover_image_url,
            'industry_slug' => $job->industry_slug,
            'industry_label' => $job->industry_label,
            'body_md' => $job->body_md,
            'body_html' => $job->body_html,
            'status' => (string) $job->status,
            'is_public' => (bool) $job->is_public,
            'is_indexable' => (bool) $job->is_indexable,
            'published_at' => $job->published_at?->toISOString(),
            'updated_at' => $job->updated_at?->toISOString(),
        ], $this->structuredPayload($job));
    }

    /**
     * @return array<string, mixed>
     */
    public function sectionPayload(CareerJobSection $section): array
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
    public function seoMetaPayload(?CareerJobSeoMeta $seoMeta): ?array
    {
        if (! $seoMeta instanceof CareerJobSeoMeta) {
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
    public function seoMetaSummaryPayload(?CareerJobSeoMeta $seoMeta): ?array
    {
        if (! $seoMeta instanceof CareerJobSeoMeta) {
            return null;
        }

        return [
            'seo_title' => $seoMeta->seo_title,
            'seo_description' => $seoMeta->seo_description,
        ];
    }

    private function basePublicQuery(int $orgId, string $locale): Builder
    {
        return CareerJob::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('org_id', max(0, $orgId))
            ->forLocale($this->normalizeLocale($locale))
            ->publishedPublic()
            ->where(static function (Builder $query): void {
                $query->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function structuredPayload(CareerJob $job): array
    {
        return [
            'salary' => $job->salary_json,
            'outlook' => $job->outlook_json,
            'skills' => $job->skills_json,
            'work_contents' => $job->work_contents_json,
            'growth_path' => $job->growth_path_json,
            'fit_personality_codes' => $job->fit_personality_codes_json,
            'mbti_primary_codes' => $job->mbti_primary_codes_json,
            'mbti_secondary_codes' => $job->mbti_secondary_codes_json,
            'riasec_profile' => $job->riasec_profile_json,
            'big5_targets' => $job->big5_targets_json,
            'iq_eq_notes' => $job->iq_eq_notes_json,
            'market_demand' => $job->market_demand_json,
        ];
    }

    private function resolveSeoMeta(CareerJob $job): ?CareerJobSeoMeta
    {
        if ($job->relationLoaded('seoMeta') && $job->seoMeta instanceof CareerJobSeoMeta) {
            return $job->seoMeta;
        }

        return CareerJobSeoMeta::query()
            ->where('job_id', (int) $job->id)
            ->first();
    }

    private function normalizeLocale(string $locale): string
    {
        return trim($locale) === 'zh-CN' ? 'zh-CN' : 'en';
    }

    private function normalizeSlug(string $slug): string
    {
        return strtolower(trim($slug));
    }
}
