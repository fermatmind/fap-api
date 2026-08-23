<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Models\Article;
use App\Models\CareerGuide;
use App\Models\CareerJob;
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
}
