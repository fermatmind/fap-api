<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

use App\Models\Article;
use App\Services\Cms\ArticleReleaseCloseoutService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ArticleWeeklySeoObservationExportService
{
    public const SCHEMA_VERSION = 'article_weekly_seo_observation_export.v4';

    public function __construct(
        private readonly ArticleReleaseCloseoutService $closeout,
    ) {}

    /**
     * @param  list<int>  $articleIds
     * @param  array<int,string>  $expectedSlugsById
     * @return array<string,mixed>
     */
    public function export(
        array $articleIds,
        array $expectedSlugsById,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $locale = '',
        int $limit = 25,
    ): array {
        $explicitSelection = $articleIds !== [];
        $articles = $this->loadArticles($articleIds, $from, $to, $locale, $limit);
        $rows = [];

        foreach ($articles as $article) {
            $expectedSlug = $expectedSlugsById[(int) $article->id] ?? (string) $article->slug;
            $closeout = $this->closeout->inspect((int) $article->id, $expectedSlug);
            $canonicalUrl = (string) ($closeout['canonical_url'] ?? $this->canonicalUrl($article));
            $canonicalPath = (string) ($closeout['canonical_path'] ?? $this->canonicalPath($article));

            $rows[] = [
                'article_id' => (int) $article->id,
                'slug' => (string) $article->slug,
                'locale' => (string) $article->locale,
                'title' => (string) $article->title,
                'canonical_path' => $canonicalPath,
                'canonical_url' => $canonicalUrl,
                'published_at' => optional($article->published_at)->toIso8601String(),
                'export_eligibility' => [
                    'selection_mode' => $explicitSelection ? 'explicit_article_ids' : 'default_indexable_scan',
                    'is_public' => (bool) $article->is_public,
                    'is_indexable' => (bool) $article->is_indexable,
                    'status' => (bool) $article->is_indexable
                        ? 'public_indexable'
                        : 'explicit_public_non_indexable',
                ],
                'release_closeout' => [
                    'decision' => (string) ($closeout['decision'] ?? 'UNKNOWN'),
                    'ok' => (bool) ($closeout['ok'] ?? false),
                    'remaining_operator_inputs' => $closeout['remaining_operator_inputs'] ?? [],
                    'issue_codes' => $this->issueCodes((array) ($closeout['issues'] ?? [])),
                ],
                'observation_windows' => $this->observationWindows($article, $to),
                'gsc' => $this->gscMetrics($canonicalUrl, $from, $to),
                'site_conversion' => $this->siteConversionMetrics((int) $article->id, $canonicalPath, $from, $to),
            ];
        }

        return [
            'ok' => true,
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => now()->toIso8601String(),
            'read_only' => true,
            'external_search_submission_attempted' => false,
            'cms_content_write_attempted' => false,
            'publish_attempted' => false,
            'schema_hreflang_write_attempted' => false,
            'sitemap_llms_mutation_attempted' => false,
            'date_range' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'gsc_timezone' => (string) config('seo_intel.gsc_reporting_timezone', 'America/Los_Angeles'),
                'site_timezone' => (string) config('analytics.funnel_daily.reporting_timezone', 'Asia/Shanghai'),
                'event_storage_timezone' => (string) config('analytics.funnel_daily.storage_timezone', 'UTC'),
            ],
            'filters' => [
                'article_ids' => $articleIds,
                'locale' => $locale,
                'limit' => $limit,
            ],
            'summary' => $this->summary($rows, $articleIds),
            'articles' => $rows,
            'deferred_actions' => [
                'content_updates' => 'not_performed_by_this_read_only_export',
                'cms_publish_or_promote' => 'not_performed_by_this_read_only_export',
                'search_submission' => 'not_performed_by_this_read_only_export',
                'schema_hreflang_writes' => 'not_performed_by_this_read_only_export',
                'sitemap_llms_mutation' => 'not_performed_by_this_read_only_export',
            ],
        ];
    }

    /**
     * @param  list<int>  $articleIds
     * @return Collection<int,Article>
     */
    private function loadArticles(array $articleIds, CarbonImmutable $from, CarbonImmutable $to, string $locale, int $limit): Collection
    {
        $query = Article::query()
            ->withoutGlobalScopes()
            ->with(['seoMeta' => static fn ($relation) => $relation->withoutGlobalScopes()])
            ->where('org_id', 0)
            ->whereNull('deleted_at')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->where('status', 'published')
            ->where('is_public', true)
            ->where(static function ($lifecycleQuery): void {
                $lifecycleQuery
                    ->whereNull('lifecycle_state')
                    ->orWhereNotIn('lifecycle_state', [
                        Article::LIFECYCLE_ARCHIVED,
                        Article::LIFECYCLE_SOFT_DELETED,
                    ]);
            });

        if ($articleIds !== []) {
            $query->whereIn('id', $articleIds);
        } else {
            $query->where('is_indexable', true)
                ->whereBetween('published_at', [$from->startOfDay(), $to->endOfDay()])
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->limit(max(1, min($limit, 100)));
        }

        if ($locale !== '') {
            $query->where('locale', $locale);
        }

        /** @var Collection<int,Article> $articles */
        $articles = $query->get();

        if ($articleIds !== []) {
            $positions = array_flip($articleIds);

            /** @var Collection<int,Article> $articles */
            $articles = $articles
                ->sortBy(static fn (Article $article): int => (int) ($positions[(int) $article->id] ?? PHP_INT_MAX))
                ->values();
        }

        return $articles;
    }

    /**
     * @return array<string,mixed>
     */
    private function observationWindows(Article $article, CarbonImmutable $asOf): array
    {
        $publishedAt = $article->published_at ? CarbonImmutable::parse($article->published_at) : null;
        if (! $publishedAt) {
            return [
                'd1' => ['date' => null, 'state' => 'published_at_missing'],
                'd7' => ['date' => null, 'state' => 'published_at_missing'],
                'd14' => ['date' => null, 'state' => 'published_at_missing'],
            ];
        }

        $windows = [];
        foreach ([1, 7, 14] as $days) {
            $date = $publishedAt->addDays($days)->toDateString();
            $windows['d'.$days] = [
                'date' => $date,
                'state' => $asOf->toDateString() >= $date ? 'due_or_ready_to_record' : 'scheduled',
            ];
        }

        return $windows;
    }

    /**
     * @return array<string,mixed>
     */
    private function gscMetrics(string $canonicalUrl, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $connection = (string) config('seo_intel.connection', 'seo_intel');
        if (! Schema::connection($connection)->hasTable('seo_gsc_daily')) {
            return [
                'table_available' => false,
                'warnings' => ['seo_gsc_daily_missing'],
                'observation_state' => 'unavailable',
                'observed_rows' => null,
                'clicks' => null,
                'impressions' => null,
                'ctr' => null,
                'average_position' => null,
                'top_queries' => [],
            ];
        }

        $rows = DB::connection($connection)
            ->table('seo_gsc_daily')
            ->where('canonical_url_hash', hash('sha256', $canonicalUrl))
            ->whereBetween('report_date', [$from->toDateString(), $to->toDateString()])
            ->get();

        $clicks = (int) $rows->sum('clicks');
        $impressions = (int) $rows->sum('impressions');
        $positionWeighted = 0;
        $positionWeight = 0;
        foreach ($rows as $row) {
            [$numerator, $denominator] = GscMetricWeights::fromRow($row);
            $positionWeighted += $numerator;
            $positionWeight += $denominator;
        }

        $topQueries = $rows
            ->groupBy(static fn (object $row): string => (string) ($row->query_display_masked ?? ''))
            ->map(static function (Collection $queryRows, string $query): array {
                return [
                    'query_display_masked' => $query,
                    'clicks' => (int) $queryRows->sum('clicks'),
                    'impressions' => (int) $queryRows->sum('impressions'),
                ];
            })
            ->filter(static fn (array $row): bool => (string) $row['query_display_masked'] !== '')
            ->sortByDesc('impressions')
            ->take(5)
            ->values()
            ->all();

        return [
            'table_available' => true,
            'warnings' => $rows->isEmpty() ? ['gsc_no_observation_rows'] : [],
            'observation_state' => $rows->isEmpty() ? 'unobserved' : 'observed',
            'observed_rows' => $rows->count(),
            'first_observed_date' => $rows->min('report_date'),
            'last_observed_date' => $rows->max('report_date'),
            'observed_days' => $rows->pluck('report_date')->unique()->count(),
            'clicks' => $rows->isEmpty() ? null : $clicks,
            'impressions' => $rows->isEmpty() ? null : $impressions,
            'ctr' => $impressions > 0 ? round($clicks / $impressions, 6) : null,
            'average_position' => $positionWeight > 0 ? round(($positionWeighted / $positionWeight) / 1000, 2) : null,
            'top_queries' => $topQueries,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function siteConversionMetrics(int $articleId, string $canonicalPath, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! Schema::hasTable('analytics_seo_conversion_daily')) {
            return [
                'table_available' => false,
                'warnings' => ['analytics_seo_conversion_daily_missing'],
                'observation_state' => 'unavailable',
                'observed_rows' => null,
                'landing_pv_count' => null,
                'article_to_test_click_count' => null,
                'start_test_count' => null,
                'complete_test_count' => null,
                'result_ready_count' => null,
                'view_result_count' => null,
            ];
        }

        $hasSourceArticleId = Schema::hasColumn('analytics_seo_conversion_daily', 'source_article_id');
        $hasResultReadyCount = Schema::hasColumn('analytics_seo_conversion_daily', 'result_ready_count');
        $query = DB::table('analytics_seo_conversion_daily')
            ->selectRaw('COUNT(*) AS observed_rows')
            ->selectRaw('COUNT(DISTINCT day) AS observed_days')
            ->selectRaw('MIN(day) AS first_observed_date')
            ->selectRaw('MAX(day) AS last_observed_date')
            ->selectRaw('SUM(landing_pv_count) AS landing_pv_count')
            ->selectRaw('SUM(article_to_test_click_count) AS article_to_test_click_count')
            ->selectRaw('SUM(start_test_count) AS start_test_count')
            ->selectRaw('SUM(complete_test_count) AS complete_test_count')
            ->selectRaw('SUM(view_result_count) AS view_result_count')
            ->where('org_id', 0)
            ->whereIn('lang', str_starts_with($canonicalPath, '/zh/') ? ['zh', 'zh-CN', 'zh-cn'] : ['en', 'en-US', 'en-us'])
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()])
            ->where(function ($query) use ($articleId, $canonicalPath, $hasSourceArticleId): void {
                if ($hasSourceArticleId) {
                    $query->where('source_article_id', $articleId)
                        ->orWhere('url', $canonicalPath);
                } else {
                    $query->where('url', $canonicalPath);
                }
                $query
                    ->orWhere('source_article', $canonicalPath)
                    ->orWhere('source_url', $canonicalPath);
            });
        if ($hasResultReadyCount) {
            $query->selectRaw('SUM(result_ready_count) AS result_ready_count');
        }
        if (Schema::hasColumn('analytics_seo_conversion_daily', 'last_refreshed_at')) {
            $query->selectRaw('MAX(last_refreshed_at) AS last_refreshed_at');
        }
        $row = $query->first();
        $observed = (int) ($row->observed_rows ?? 0) > 0;

        return [
            'table_available' => true,
            'warnings' => array_merge($hasResultReadyCount ? [] : ['result_ready_count_unavailable'], $observed ? [] : ['site_conversion_no_observation_rows']),
            'observation_state' => $observed ? 'observed' : 'unobserved',
            'observed_rows' => (int) ($row->observed_rows ?? 0),
            'last_refreshed_at' => $row->last_refreshed_at ?? null,
            'observed_days' => (int) ($row->observed_days ?? 0),
            'first_observed_date' => $row->first_observed_date ?? null,
            'last_observed_date' => $row->last_observed_date ?? null,
            'landing_pv_count' => $observed ? (int) ($row->landing_pv_count ?? 0) : null,
            'article_to_test_click_count' => $observed ? (int) ($row->article_to_test_click_count ?? 0) : null,
            'start_test_count' => $observed ? (int) ($row->start_test_count ?? 0) : null,
            'complete_test_count' => $observed ? (int) ($row->complete_test_count ?? 0) : null,
            'result_ready_count' => $hasResultReadyCount && $observed ? (int) ($row->result_ready_count ?? 0) : null,
            'view_result_count' => $observed ? (int) ($row->view_result_count ?? 0) : null,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @param  list<int>  $requestedArticleIds
     * @return array<string,mixed>
     */
    private function summary(array $rows, array $requestedArticleIds): array
    {
        $decisions = [];
        $clicks = 0;
        $impressions = 0;
        $returnedArticleIds = [];
        $explicitNonIndexableCount = 0;
        $observedGscArticles = 0;
        foreach ($rows as $row) {
            $observedGscArticles += data_get($row, 'gsc.observation_state') === 'observed' ? 1 : 0;
            $decision = (string) data_get($row, 'release_closeout.decision', 'UNKNOWN');
            $decisions[$decision] = ($decisions[$decision] ?? 0) + 1;
            $clicks += (int) data_get($row, 'gsc.clicks', 0);
            $impressions += (int) data_get($row, 'gsc.impressions', 0);
            $returnedArticleIds[] = (int) ($row['article_id'] ?? 0);
            if (data_get($row, 'export_eligibility.status') === 'explicit_public_non_indexable') {
                $explicitNonIndexableCount++;
            }
        }

        return [
            'article_count' => count($rows),
            'requested_article_count' => count($requestedArticleIds),
            'returned_article_count' => count($rows),
            'returned_article_ids' => $returnedArticleIds,
            'missing_requested_article_ids' => array_values(array_diff($requestedArticleIds, $returnedArticleIds)),
            'explicit_public_non_indexable_count' => $explicitNonIndexableCount,
            'release_closeout_decisions' => $decisions,
            'gsc_observed_article_count' => $observedGscArticles,
            'gsc_unobserved_or_unavailable_article_count' => count($rows) - $observedGscArticles,
            'gsc_clicks' => $observedGscArticles > 0 ? $clicks : null,
            'gsc_impressions' => $observedGscArticles > 0 ? $impressions : null,
            'gsc_ctr' => $impressions > 0 ? round($clicks / $impressions, 6) : null,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $issues
     * @return list<string>
     */
    private function issueCodes(array $issues): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (array $issue): string => (string) ($issue['code'] ?? ''),
            $issues
        ))));
    }

    private function canonicalPath(Article $article): string
    {
        $prefix = str_starts_with((string) $article->locale, 'zh') ? '/zh/articles/' : '/en/articles/';

        return $prefix.(string) $article->slug;
    }

    private function canonicalUrl(Article $article): string
    {
        return 'https://fermatmind.com'.$this->canonicalPath($article);
    }
}
