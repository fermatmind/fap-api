<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleTag;
use App\Models\CareerGuide;
use App\Models\CareerJob;
use App\Models\InterpretationGuide;
use App\Models\SupportArticle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Single read-only inventory contract for SEO operations.
 *
 * Public Article, CareerGuide and CareerJob authority is global (org_id = 0).
 * The selected Ops tenant never changes that content boundary.
 */
final class SeoContentScopeViewModel
{
    public const GLOBAL_ORG_ID = 0;

    public const SCOPE_GLOBAL_ARTICLES = 'global_articles';

    public const SCOPE_GLOBAL_CAREER = 'global_career';

    public const SCOPE_COMBINED = 'combined';

    public const SOURCE_ARTICLES = 'primary.articles';

    public const SOURCE_CAREER_GUIDES = 'primary.career_guides';

    public const SOURCE_CAREER_JOBS = 'primary.career_jobs';

    /** @return Builder<Article> */
    public function articles(string $locale = 'all', string $status = 'all'): Builder
    {
        return $this->applyFilters(
            Article::query()->withoutGlobalScopes()->where('org_id', self::GLOBAL_ORG_ID),
            $locale,
            $status,
        );
    }

    /** @return Builder<ArticleCategory> */
    public function articleCategories(): Builder
    {
        return ArticleCategory::query()
            ->withoutGlobalScopes()
            ->where('org_id', self::GLOBAL_ORG_ID);
    }

    /** @return Builder<ArticleTag> */
    public function articleTags(): Builder
    {
        return ArticleTag::query()
            ->withoutGlobalScopes()
            ->where('org_id', self::GLOBAL_ORG_ID);
    }

    /**
     * @return array{
     *     articles:array{total:int,draft:int,published:int},
     *     career_guides:array{total:int,draft:int,published:int},
     *     career_jobs:array{total:int,draft:int,published:int},
     *     categories:int,
     *     tags:int,
     *     interpretation_guides:int,
     *     support_articles:int,
     *     draft_handoff:int,
     *     published:int
     * }
     */
    public function workspaceMetrics(): array
    {
        $articles = $this->statusCounts($this->articles(), 'draft', 'published');
        $guides = $this->statusCounts($this->careerGuides(), CareerGuide::STATUS_DRAFT, CareerGuide::STATUS_PUBLISHED);
        $jobs = $this->statusCounts($this->careerJobs(), CareerJob::STATUS_DRAFT, CareerJob::STATUS_PUBLISHED);

        return [
            'articles' => $articles,
            'career_guides' => $guides,
            'career_jobs' => $jobs,
            'categories' => $this->articleCategories()->count(),
            'tags' => $this->articleTags()->count(),
            'interpretation_guides' => InterpretationGuide::query()->withoutGlobalScopes()->where('org_id', self::GLOBAL_ORG_ID)->count(),
            'support_articles' => SupportArticle::query()->withoutGlobalScopes()->where('org_id', self::GLOBAL_ORG_ID)->count(),
            'draft_handoff' => $articles['draft'] + $guides['draft'] + $jobs['draft'],
            'published' => $articles['published'] + $guides['published'] + $jobs['published'],
        ];
    }

    /** @return Builder<CareerGuide> */
    public function careerGuides(string $locale = 'all', string $status = 'all'): Builder
    {
        return $this->applyFilters(
            CareerGuide::query()->withoutGlobalScopes()->where('org_id', self::GLOBAL_ORG_ID),
            $locale,
            $status,
        );
    }

    /** @return Builder<CareerJob> */
    public function careerJobs(string $locale = 'all', string $status = 'all'): Builder
    {
        return $this->applyFilters(
            CareerJob::query()->withoutGlobalScopes()->where('org_id', self::GLOBAL_ORG_ID),
            $locale,
            $status,
        );
    }

    /**
     * @return array{articles:Collection<int,Article>,guides:Collection<int,CareerGuide>,jobs:Collection<int,CareerJob>}
     */
    public function inventory(string $locale = 'all', string $status = 'all'): array
    {
        return [
            'articles' => $this->articles($locale, $status)->with('seoMeta')->latest('updated_at')->get(),
            'guides' => $this->careerGuides($locale, $status)->with('seoMeta')->latest('updated_at')->get(),
            'jobs' => $this->careerJobs($locale, $status)->with('seoMeta')->latest('updated_at')->get(),
        ];
    }

    /**
     * @return array{scope:string,source:string,collected_at:string,source_updated_at:?string,freshness:string}
     */
    public function metricContract(string $scope, string $source, Collection $records, ?Carbon $collectedAt = null): array
    {
        $collectedAt ??= now();
        $latest = $records
            ->map(static fn (object $record): mixed => data_get($record, 'updated_at'))
            ->filter()
            ->max();
        $latestAt = $latest instanceof Carbon ? $latest : ($latest !== null ? Carbon::parse((string) $latest) : null);

        return [
            'scope' => $scope,
            'source' => $source,
            'collected_at' => $collectedAt->toAtomString(),
            'source_updated_at' => $latestAt?->toAtomString(),
            'freshness' => match (true) {
                $latestAt === null => 'empty',
                $latestAt->gte($collectedAt->copy()->subDay()) => 'fresh',
                $latestAt->gte($collectedAt->copy()->subDays(7)) => 'aging',
                default => 'stale',
            },
        ];
    }

    /** @param Builder<*> $query */
    private function applyFilters(Builder $query, string $locale, string $status): Builder
    {
        $locale = trim($locale);
        $status = trim($status);

        if ($locale !== '' && $locale !== 'all') {
            $query->where('locale', $locale);
        }

        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        return $query;
    }

    /**
     * @param  Builder<*>  $query
     * @return array{total:int,draft:int,published:int}
     */
    private function statusCounts(Builder $query, string $draftStatus, string $publishedStatus): array
    {
        $row = (clone $query)
            ->selectRaw(
                'COUNT(*) AS total_count, '
                .'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS draft_count, '
                .'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS published_count',
                [$draftStatus, $publishedStatus],
            )
            ->first();

        return [
            'total' => (int) ($row?->total_count ?? 0),
            'draft' => (int) ($row?->draft_count ?? 0),
            'published' => (int) ($row?->published_count ?? 0),
        ];
    }
}
