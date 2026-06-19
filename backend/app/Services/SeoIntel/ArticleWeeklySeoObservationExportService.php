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
    public const SCHEMA_VERSION = 'article_weekly_seo_observation_export.v1';

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
                'release_closeout' => [
                    'decision' => (string) ($closeout['decision'] ?? 'UNKNOWN'),
                    'ok' => (bool) ($closeout['ok'] ?? false),
                    'remaining_operator_inputs' => $closeout['remaining_operator_inputs'] ?? [],
                    'issue_codes' => $this->issueCodes((array) ($closeout['issues'] ?? [])),
                ],
                'observation_windows' => $this->observationWindows($article, $to),
                'gsc' => $this->gscMetrics($canonicalUrl, $from, $to),
                'site_conversion' => $this->siteConversionMetrics($canonicalPath, $from, $to),
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
            ],
            'filters' => [
                'article_ids' => $articleIds,
                'locale' => $locale,
                'limit' => $limit,
            ],
            'summary' => $this->summary($rows),
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
            ->where('status', 'published')
            ->where('is_public', true)
            ->where('is_indexable', true)
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
            $query->whereBetween('published_at', [$from->startOfDay(), $to->endOfDay()])
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
                'clicks' => 0,
                'impressions' => 0,
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
            $rowImpressions = (int) ($row->impressions ?? 0);
            $positionMilli = $row->average_position_milli ?? null;
            if ($rowImpressions > 0 && $positionMilli !== null) {
                $positionWeighted += ((int) $positionMilli) * $rowImpressions;
                $positionWeight += $rowImpressions;
            }
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
            'warnings' => [],
            'clicks' => $clicks,
            'impressions' => $impressions,
            'ctr' => $impressions > 0 ? round($clicks / $impressions, 6) : null,
            'average_position' => $positionWeight > 0 ? round(($positionWeighted / $positionWeight) / 1000, 2) : null,
            'top_queries' => $topQueries,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function siteConversionMetrics(string $canonicalPath, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! Schema::hasTable('analytics_seo_conversion_daily')) {
            return [
                'table_available' => false,
                'warnings' => ['analytics_seo_conversion_daily_missing'],
                'landing_pv_count' => 0,
                'article_to_test_click_count' => 0,
                'start_test_count' => 0,
                'complete_test_count' => 0,
                'view_result_count' => 0,
            ];
        }

        $row = DB::table('analytics_seo_conversion_daily')
            ->selectRaw('SUM(landing_pv_count) AS landing_pv_count')
            ->selectRaw('SUM(article_to_test_click_count) AS article_to_test_click_count')
            ->selectRaw('SUM(start_test_count) AS start_test_count')
            ->selectRaw('SUM(complete_test_count) AS complete_test_count')
            ->selectRaw('SUM(view_result_count) AS view_result_count')
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()])
            ->where(function ($query) use ($canonicalPath): void {
                $query->where('url', $canonicalPath)
                    ->orWhere('source_article', $canonicalPath)
                    ->orWhere('source_url', $canonicalPath);
            })
            ->first();

        return [
            'table_available' => true,
            'warnings' => [],
            'landing_pv_count' => (int) ($row->landing_pv_count ?? 0),
            'article_to_test_click_count' => (int) ($row->article_to_test_click_count ?? 0),
            'start_test_count' => (int) ($row->start_test_count ?? 0),
            'complete_test_count' => (int) ($row->complete_test_count ?? 0),
            'view_result_count' => (int) ($row->view_result_count ?? 0),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    private function summary(array $rows): array
    {
        $decisions = [];
        $clicks = 0;
        $impressions = 0;
        foreach ($rows as $row) {
            $decision = (string) data_get($row, 'release_closeout.decision', 'UNKNOWN');
            $decisions[$decision] = ($decisions[$decision] ?? 0) + 1;
            $clicks += (int) data_get($row, 'gsc.clicks', 0);
            $impressions += (int) data_get($row, 'gsc.impressions', 0);
        }

        return [
            'article_count' => count($rows),
            'release_closeout_decisions' => $decisions,
            'gsc_clicks' => $clicks,
            'gsc_impressions' => $impressions,
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
