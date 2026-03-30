<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\MethodPage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class MethodPageService
{
    public function listPublicMethods(int $orgId, string $locale, int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        return $this->basePublicQuery($orgId, $locale)
            ->with('seoMeta')
            ->orderBy('sort_order')
            ->orderByDesc('published_at')
            ->orderBy('method_code')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    public function getPublicMethodBySlug(string $slug, int $orgId, string $locale): ?MethodPage
    {
        return $this->basePublicQuery($orgId, $locale)
            ->forSlug($this->normalizeSlug($slug))
            ->with('seoMeta')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function listPayload(MethodPage $page): array
    {
        return [
            'id' => (int) $page->id,
            'org_id' => (int) $page->org_id,
            'method_code' => (string) $page->method_code,
            'slug' => (string) $page->slug,
            'locale' => (string) $page->locale,
            'title' => (string) $page->title,
            'subtitle' => $page->subtitle,
            'excerpt' => $page->excerpt,
            'hero_kicker' => $page->hero_kicker,
            'is_public' => (bool) $page->is_public,
            'is_indexable' => (bool) $page->is_indexable,
            'published_at' => $page->published_at?->toISOString(),
            'updated_at' => $page->updated_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detailPayload(MethodPage $page): array
    {
        return [
            'id' => (int) $page->id,
            'org_id' => (int) $page->org_id,
            'method_code' => (string) $page->method_code,
            'slug' => (string) $page->slug,
            'locale' => (string) $page->locale,
            'title' => (string) $page->title,
            'subtitle' => $page->subtitle,
            'excerpt' => $page->excerpt,
            'hero_kicker' => $page->hero_kicker,
            'body_md' => $page->body_md,
            'body_html' => $page->body_html,
            'definition_summary_md' => $page->definition_summary_md,
            'boundary_notes_md' => $page->boundary_notes_md,
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
    public function seoMetaPayload(MethodPage $page): ?array
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
        return MethodPage::query()
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
