<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

use App\Services\SeoIntel\OpsDashboard\SeoIssueClusterReadService;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;

final class GscRunCloseoutSummarizer
{
    private const ROOT_CAUSES = [
        'host_canonical_normalization',
        'locale_path_normalization',
        'current_url_truth_missing',
        'historical_url',
        'redirect_alias',
        'query_parameters_or_malformed_url',
        'retired_noindex',
        'not_published',
        'private_deny_path',
        'raw_canonical_missing',
        'unknown',
    ];

    public function __construct(
        private readonly SeoIssueClusterReadService $issueClusters,
    ) {}

    /** @param list<string> $searchTypes @return array<string,mixed> */
    public function readModelSnapshot(
        ConnectionInterface $connection,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
        array $searchTypes,
    ): array {
        $rows = $connection->table('seo_gsc_daily')
            ->whereBetween('report_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->where('source_engine', 'google')
            ->whereIn('search_type', $searchTypes)
            ->get();

        return $this->metricSnapshot($rows);
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @param  list<string>  $searchTypes
     * @param  array<string,mixed>  $before
     * @return array<string,mixed>
     */
    public function summarize(
        ConnectionInterface $connection,
        array $rows,
        CarbonImmutable $requestedStartDate,
        CarbonImmutable $endDate,
        array $searchTypes,
        array $before,
    ): array {
        $fetched = $this->metricSnapshot(collect($rows));
        $after = $this->readModelSnapshot($connection, $requestedStartDate, $endDate, $searchTypes);

        return [
            'gsc_data_quality' => [
                'property' => (string) config('seo_intel.gsc_property_url', 'unknown'),
                'timezone' => 'UTC',
                'requested_window' => [
                    'start_date' => $requestedStartDate->toDateString(),
                    'end_date' => $endDate->toDateString(),
                    'window_days' => (int) $requestedStartDate->diffInDays($endDate) + 1,
                ],
                'filters' => [
                    'country' => null,
                    'device' => null,
                    'search_types' => $searchTypes,
                ],
                'dimensions' => ['date', 'query_hash', 'normalized_canonical_url_hash', 'device', 'country', 'search_type'],
                'fetched' => $fetched,
                'read_model_before' => $before,
                'read_model_after' => $after,
                'overlap_comparison' => [
                    'row_count_delta' => (int) $after['row_count'] - (int) ($before['row_count'] ?? 0),
                    'clicks_delta' => (int) data_get($after, 'metrics.clicks', 0) - (int) data_get($before, 'metrics.clicks', 0),
                    'impressions_delta' => (int) data_get($after, 'metrics.impressions', 0) - (int) data_get($before, 'metrics.impressions', 0),
                    'fetched_matches_read_model_after' => $this->metricsMatch($fetched, $after),
                    'natural_key_duplicate_count' => (int) ($after['natural_key_duplicate_count'] ?? 0),
                ],
                'detail_read_limit' => null,
                'aggregation_strategy' => 'unbounded_database_aggregate',
            ],
            'unmapped_classification' => $this->unmappedClassification($connection, $rows),
            'issue_clusters' => $connection->getSchemaBuilder()->hasTable('seo_issue_queue')
                ? $this->issueClusters->closeoutSummary()
                : ['status' => 'unavailable', 'reason' => 'issue_queue_schema_missing'],
        ];
    }

    /** @param Collection<int,mixed> $rows @return array<string,mixed> */
    private function metricSnapshot(Collection $rows): array
    {
        $dates = $rows->map(fn (mixed $row): string => (string) $this->value($row, 'report_date'))->filter()->unique()->sort()->values();
        $clicks = $rows->sum(fn (mixed $row): int => (int) $this->value($row, 'clicks'));
        $impressions = $rows->sum(fn (mixed $row): int => (int) $this->value($row, 'impressions'));
        $positionWeight = $rows->sum(function (mixed $row): int {
            $position = $this->value($row, 'average_position_milli');
            $impressions = (int) $this->value($row, 'impressions');

            return $position === null ? 0 : (int) $position * $impressions;
        });
        $positionImpressions = $rows->sum(fn (mixed $row): int => $this->value($row, 'average_position_milli') === null ? 0 : (int) $this->value($row, 'impressions'));
        $naturalKeys = $rows->map(fn (mixed $row): string => $this->naturalKey($row));

        return [
            'row_count' => $rows->count(),
            'natural_unique_key_count' => $naturalKeys->unique()->count(),
            'natural_key_duplicate_count' => max(0, $naturalKeys->count() - $naturalKeys->unique()->count()),
            'date_point_count' => $dates->count(),
            'min_report_date' => $dates->first(),
            'max_report_date' => $dates->last(),
            'latest_data_lag_days' => is_string($dates->last())
                ? CarbonImmutable::parse((string) $dates->last(), 'UTC')->diffInDays(CarbonImmutable::now('UTC')->startOfDay())
                : null,
            'metrics' => [
                'clicks' => $clicks,
                'impressions' => $impressions,
                'ctr_percent' => $impressions > 0 ? round(($clicks / $impressions) * 100, 4) : null,
                'average_position' => $positionImpressions > 0 ? round(($positionWeight / $positionImpressions) / 1000, 4) : null,
            ],
        ];
    }

    /** @param list<array<string,mixed>> $rows @return array<string,mixed> */
    private function unmappedClassification(ConnectionInterface $connection, array $rows): array
    {
        $truth = $connection->table('seo_urls')->get()->keyBy(fn (object $row): string => (string) ($row->canonical_url_hash ?? ''));
        $unmappedRows = collect($rows)->filter(fn (array $row): bool => ! $truth->has((string) ($row['canonical_url_hash'] ?? '')))->values();
        $unique = [];
        $combos = [];

        foreach ($unmappedRows as $row) {
            $classified = $this->classifyUrl((string) ($row['canonical_url'] ?? ''), $truth);
            $identity = $classified['normalized_canonical_url_hash'] ?: (string) ($row['canonical_url_hash'] ?? 'missing');
            $unique[$identity] ??= $classified;
            $combos[hash('sha256', implode('|', [
                (string) ($row['query_hash'] ?? 'missing'),
                $identity,
                (string) ($row['report_date'] ?? 'missing'),
            ]))] = true;
        }

        $rootCause = array_fill_keys(self::ROOT_CAUSES, 0);
        $families = array_fill_keys(['Tests', 'Articles', 'Career', 'Personality', 'Other'], 0);
        $locales = array_fill_keys(['zh-CN', 'en', 'unknown'], 0);
        foreach ($unique as $item) {
            $rootCause[$item['root_cause']]++;
            $families[$item['page_family']]++;
            $locales[$item['locale']]++;
        }

        return [
            'unmapped_detail_row_count' => $unmappedRows->count(),
            'unique_normalized_canonical_url_count' => count($unique),
            'unique_query_page_date_combination_count' => count($combos),
            'page_family_distribution' => $families,
            'locale_distribution' => $locales,
            'root_cause_distribution' => $rootCause,
            'classification_unit' => 'unique_normalized_canonical_url',
            'raw_url_retained_or_emitted' => false,
        ];
    }

    /** @param Collection<string,object> $truth @return array{normalized_canonical_url_hash:string,root_cause:string,page_family:string,locale:string} */
    private function classifyUrl(string $rawUrl, Collection $truth): array
    {
        $parts = parse_url(trim($rawUrl));
        if ($rawUrl === '') {
            return $this->classification('', 'raw_canonical_missing', 'Other', 'unknown');
        }
        if (! is_array($parts) || ! is_string($parts['host'] ?? null) || ! is_string($parts['path'] ?? null)) {
            return $this->classification(hash('sha256', $rawUrl), 'query_parameters_or_malformed_url', 'Other', 'unknown');
        }

        $path = $this->normalizePath((string) $parts['path']);
        $locale = $this->locale($path);
        $family = $this->pageFamily($path);
        $normalized = 'https://fermatmind.com'.($path === '/' ? '' : $path);
        $normalizedHash = hash('sha256', $normalized);
        $privateSegments = array_map('strval', (array) config('seo_intel.core_entry_slo.private_path_segments', []));
        if ($this->pathContainsSegment($path, $privateSegments)) {
            return $this->classification($normalizedHash, 'private_deny_path', $family, $locale);
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            return $this->classification($normalizedHash, 'query_parameters_or_malformed_url', $family, $locale);
        }

        $localeNormalizedPath = preg_replace('#^/(zh-cn|zh_cn)(/|$)#i', '/zh$2', $path) ?? $path;
        $localeNormalizedPath = preg_replace('#^/(en-us|en_us)(/|$)#i', '/en$2', $localeNormalizedPath) ?? $localeNormalizedPath;
        $localeNormalizedHash = hash('sha256', 'https://fermatmind.com'.($localeNormalizedPath === '/' ? '' : $localeNormalizedPath));
        if ($localeNormalizedPath !== $path && $truth->has($localeNormalizedHash)) {
            return $this->classification($localeNormalizedHash, 'locale_path_normalization', $this->pageFamily($localeNormalizedPath), $this->locale($localeNormalizedPath));
        }

        $truthRow = $truth->get($normalizedHash);
        if (is_object($truthRow)) {
            if ((bool) ($truthRow->is_private_flow ?? false)) {
                return $this->classification($normalizedHash, 'private_deny_path', $family, $locale);
            }
            $state = strtolower((string) ($truthRow->indexability_state ?? ''));
            if ($this->containsAny($state, ['retired', 'noindex', 'blocked'])) {
                return $this->classification($normalizedHash, 'retired_noindex', $family, $locale);
            }
            if ($this->containsAny($state, ['draft', 'unpublished', 'pending'])) {
                return $this->classification($normalizedHash, 'not_published', $family, $locale);
            }
            $authority = strtolower((string) ($truthRow->source_authority ?? ''));
            if ($this->containsAny($authority, ['redirect', 'alias'])) {
                return $this->classification($normalizedHash, 'redirect_alias', $family, $locale);
            }

            return $this->classification($normalizedHash, 'host_canonical_normalization', $family, $locale);
        }

        return $this->classification($normalizedHash, 'unknown', $family, $locale);
    }

    /** @return array{normalized_canonical_url_hash:string,root_cause:string,page_family:string,locale:string} */
    private function classification(string $hash, string $rootCause, string $family, string $locale): array
    {
        return [
            'normalized_canonical_url_hash' => $hash,
            'root_cause' => $rootCause,
            'page_family' => $family,
            'locale' => $locale,
        ];
    }

    private function normalizePath(string $path): string
    {
        $path = '/'.ltrim(preg_replace('#/+#', '/', $path) ?? $path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    private function locale(string $path): string
    {
        return str_starts_with($path, '/zh/') || $path === '/zh' ? 'zh-CN'
            : (str_starts_with($path, '/en/') || $path === '/en' ? 'en' : 'unknown');
    }

    private function pageFamily(string $path): string
    {
        if (preg_match('#/(tests?|assessments?)(/|$)#i', $path) === 1) {
            return 'Tests';
        }
        if (preg_match('#/(articles?|topics?)(/|$)#i', $path) === 1) {
            return 'Articles';
        }
        if (preg_match('#/(career|careers|jobs?)(/|$)#i', $path) === 1) {
            return 'Career';
        }
        if (preg_match('#/(personality|personalities|profiles?|comparisons?|types?)(/|$)#i', $path) === 1) {
            return 'Personality';
        }

        return 'Other';
    }

    /** @param list<string> $segments */
    private function pathContainsSegment(string $path, array $segments): bool
    {
        $pathSegments = array_values(array_filter(explode('/', strtolower($path))));

        return array_intersect($pathSegments, array_map('strtolower', $segments)) !== [];
    }

    private function naturalKey(mixed $row): string
    {
        return hash('sha256', implode('|', array_map(fn (string $key): string => (string) ($this->value($row, $key) ?? ''), [
            'report_date', 'canonical_url_hash', 'query_hash', 'source_engine', 'device', 'country', 'search_type',
        ])));
    }

    private function value(mixed $row, string $key): mixed
    {
        return is_array($row) ? ($row[$key] ?? null) : ($row->{$key} ?? null);
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private function metricsMatch(array $left, array $right): bool
    {
        return (int) ($left['natural_unique_key_count'] ?? -1) === (int) ($right['natural_unique_key_count'] ?? -2)
            && (int) data_get($left, 'metrics.clicks', -1) === (int) data_get($right, 'metrics.clicks', -2)
            && (int) data_get($left, 'metrics.impressions', -1) === (int) data_get($right, 'metrics.impressions', -2);
    }

    /** @param list<string> $needles */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
