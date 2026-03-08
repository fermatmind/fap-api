<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\TopicProfile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class TopicProfileService
{
    public function listPublicTopics(int $orgId, string $locale, int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        return $this->basePublicQuery($orgId, $locale)
            ->with('seoMeta')
            ->orderBy('sort_order')
            ->orderBy('topic_code')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    public function getPublicTopicBySlug(string $slug, int $orgId, string $locale): ?TopicProfile
    {
        return $this->basePublicQuery($orgId, $locale)
            ->forSlug($this->normalizeSlug($slug))
            ->with([
                'sections' => static function (HasMany $query): void {
                    $query->where('is_enabled', true)
                        ->orderBy('sort_order')
                        ->orderBy('id');
                },
                'entries' => static function (HasMany $query): void {
                    $query->where('is_enabled', true)
                        ->orderBy('sort_order')
                        ->orderBy('id');
                },
                'seoMeta',
            ])
            ->first();
    }

    private function basePublicQuery(int $orgId, string $locale): Builder
    {
        return TopicProfile::query()
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
        return trim($locale);
    }

    private function normalizeSlug(string $slug): string
    {
        return strtolower(trim($slug));
    }
}
