<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\DataPage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class DataPageService
{
    public function listPublicDataPages(int $orgId, string $locale, int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        return $this->basePublicQuery($orgId, $locale)
            ->with('seoMeta')
            ->orderBy('sort_order')
            ->orderByDesc('published_at')
            ->orderBy('data_code')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    public function getPublicDataPageBySlug(string $slug, int $orgId, string $locale): ?DataPage
    {
        return $this->basePublicQuery($orgId, $locale)
            ->forSlug($this->normalizeSlug($slug))
            ->with('seoMeta')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function listPayload(DataPage $page): array
    {
        return [
            'id' => (int) $page->id,
            'org_id' => (int) $page->org_id,
            'data_code' => (string) $page->data_code,
            'slug' => (string) $page->slug,
            'locale' => (string) $page->locale,
            'title' => (string) $page->title,
            'subtitle' => $page->subtitle,
            'excerpt' => $page->excerpt,
            'hero_kicker' => $page->hero_kicker,
            'sample_size_label' => $page->sample_size_label,
            'time_window_label' => $page->time_window_label,
            'is_public' => (bool) $page->is_public,
            'is_indexable' => (bool) $page->is_indexable,
            'published_at' => $page->published_at?->toISOString(),
            'updated_at' => $page->updated_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detailPayload(DataPage $page): array
    {
        return [
            'id' => (int) $page->id,
            'org_id' => (int) $page->org_id,
            'data_code' => (string) $page->data_code,
            'slug' => (string) $page->slug,
            'locale' => (string) $page->locale,
            'title' => (string) $page->title,
            'subtitle' => $page->subtitle,
            'excerpt' => $page->excerpt,
            'hero_kicker' => $page->hero_kicker,
            'body_md' => $page->body_md,
            'body_html' => $page->body_html,
            'sample_size_label' => $page->sample_size_label,
            'time_window_label' => $page->time_window_label,
            'methodology_md' => $page->methodology_md,
            'limitations_md' => $page->limitations_md,
            'summary_statement_md' => $page->summary_statement_md,
            'cover_image_url' => $page->cover_image_url,
            'is_public' => (bool) $page->is_public,
            'is_indexable' => (bool) $page->is_indexable,
            'published_at' => $page->published_at?->toISOString(),
            'updated_at' => $page->updated_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function seoMetaPayload(DataPage $page): ?array
    {
        $seoMeta = $page->relationLoaded('seoMeta') ? $page->seoMeta : $page->seoMeta()->first();
        if (! $seoMeta) {
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

    private function basePublicQuery(int $orgId, string $locale): Builder
    {
        return DataPage::query()
            ->withoutGlobalScopes()
            ->where('org_id', max(0, $orgId))
            ->forLocale($this->normalizeLocale($locale))
            ->publishedPublic()
            ->where(static function (Builder $query): void {
                $query->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            });
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
