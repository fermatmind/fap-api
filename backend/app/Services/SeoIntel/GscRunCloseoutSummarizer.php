<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

use App\Services\SeoIntel\OpsDashboard\SeoIssueClusterReadService;
use App\Services\SeoIntel\PageFamily\PageFamilyClassifier;
use App\Services\SeoIntel\Sources\BackendAuthorityUrlTruthSource;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;

/** Builds sanitized, aggregate-only evidence for a completed readonly GSC sync. */
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
        private readonly BackendAuthorityUrlTruthSource $backendAuthority,
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
     * Build aggregate-only evidence from the persisted read model without an
     * external GSC call or any database mutation.
     *
     * @param  list<string>  $searchTypes
     * @return array<string,mixed>
     */
    public function summarizeCurrentReadModel(
        ConnectionInterface $connection,
        int $windowDays = 90,
        array $searchTypes = ['web'],
    ): array {
        $windowDays = max(1, min($windowDays, 90));
        $latest = $connection->table('seo_gsc_daily')
            ->where('source_engine', 'google')
            ->whereIn('search_type', $searchTypes)
            ->max('report_date');
        if (! is_string($latest) || $latest === '') {
            return ['state' => 'unavailable', 'reason' => 'gsc_read_model_empty'];
        }

        $endDate = CarbonImmutable::parse($latest, 'UTC')->startOfDay();
        $startDate = $endDate->subDays($windowDays - 1);
        $query = $connection->table('seo_gsc_daily')
            ->whereBetween('report_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->where('source_engine', 'google')
            ->whereIn('search_type', $searchTypes);
        $rows = (clone $query)->get([
            'report_date',
            'canonical_url_hash',
            'canonical_url',
            'query_hash',
            'source_engine',
            'device',
            'country',
            'search_type',
            'clicks',
            'impressions',
            'average_position_milli',
        ])->map(static fn (object $row): array => (array) $row)->all();
        $detail = $this->metricSnapshot(collect($rows));
        $aggregateRow = (clone $query)
            ->selectRaw('COUNT(*) AS row_count')
            ->selectRaw('COALESCE(SUM(clicks), 0) AS clicks')
            ->selectRaw('COALESCE(SUM(impressions), 0) AS impressions')
            ->selectRaw('COALESCE(SUM(CASE WHEN average_position_milli IS NOT NULL AND impressions > 0 THEN average_position_milli * impressions ELSE 0 END), 0) AS position_weight')
            ->selectRaw('COALESCE(SUM(CASE WHEN average_position_milli IS NOT NULL AND impressions > 0 THEN impressions ELSE 0 END), 0) AS position_impressions')
            ->first();
        $aggregateClicks = (int) ($aggregateRow->clicks ?? 0);
        $aggregateImpressions = (int) ($aggregateRow->impressions ?? 0);
        $positionImpressions = (int) ($aggregateRow->position_impressions ?? 0);
        $databaseAggregate = [
            'row_count' => (int) ($aggregateRow->row_count ?? 0),
            'clicks' => $aggregateClicks,
            'impressions' => $aggregateImpressions,
            'ctr_percent' => $aggregateImpressions > 0 ? round(($aggregateClicks / $aggregateImpressions) * 100, 4) : null,
            'average_position' => $positionImpressions > 0
                ? round(((int) ($aggregateRow->position_weight ?? 0) / $positionImpressions) / 1000, 4)
                : null,
        ];

        return [
            'state' => 'verified',
            'gsc_data_quality' => [
                'evidence_source' => 'persisted_production_read_model',
                'property' => (string) config('seo_intel.gsc_property_url', 'unknown'),
                'timezone' => 'UTC',
                'window' => [
                    'anchor' => 'latest_persisted_report_date',
                    'start_date' => $startDate->toDateString(),
                    'end_date' => $endDate->toDateString(),
                    'window_days' => $windowDays,
                ],
                'filters' => [
                    'country' => null,
                    'device' => null,
                    'search_types' => $searchTypes,
                ],
                'detail_snapshot' => $detail,
                'database_aggregate' => $databaseAggregate,
                'aggregate_matches_detail' => (int) $detail['row_count'] === $databaseAggregate['row_count']
                    && (int) data_get($detail, 'metrics.clicks', -1) === $aggregateClicks
                    && (int) data_get($detail, 'metrics.impressions', -1) === $aggregateImpressions
                    && data_get($detail, 'metrics.average_position') === $databaseAggregate['average_position'],
                'detail_read_limit' => null,
                'aggregation_strategy' => 'unbounded_database_aggregate_reconciled_to_full_detail_set',
                'fresh_api_pagination_receipt' => 'production_unproven',
                'row_completeness' => 'production_unproven',
                'scheduled_overlap' => 'production_unproven',
                'scheduled_rerun_accumulation' => 'production_unproven',
            ],
            'unmapped_classification' => $this->unmappedClassification($connection, $rows),
            'issue_clusters' => $this->issueClusters->closeoutSummary(),
        ];
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
        $backendAuthority = $this->backendAuthorityCandidates();
        $unmappedRows = collect($rows)->filter(fn (array $row): bool => ! $truth->has((string) ($row['canonical_url_hash'] ?? '')))->values();
        $unique = [];
        $combos = [];

        $opaqueHashIndex = $this->opaqueHashClassificationIndex($truth, $backendAuthority);
        foreach ($unmappedRows as $row) {
            $rawUrl = (string) ($row['canonical_url'] ?? '');
            $rawHash = (string) ($row['canonical_url_hash'] ?? '');
            $classified = $rawUrl !== ''
                ? $this->classifyUrl($rawUrl, $truth, $backendAuthority)
                : $this->classifyOpaqueHash($rawHash, $backendAuthority, $opaqueHashIndex);
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
        $missingFamily = array_fill_keys(['tests', 'articles_topics', 'career', 'personality', 'trust_method_help', 'other_public', 'unclassified'], 0);
        $missingLocale = array_fill_keys(['zh-CN', 'en', 'unknown'], 0);
        foreach (array_filter($unique, static fn (array $item): bool => $item['root_cause'] === 'current_url_truth_missing') as $item) {
            $familyId = $this->pageFamilyIdForMissing($item, $backendAuthority);
            $missingFamily[$familyId]++;
            $missingLocale[$item['locale']]++;
        }

        return [
            'unmapped_detail_row_count' => $unmappedRows->count(),
            'unique_normalized_canonical_url_count' => count($unique),
            'unique_query_page_date_combination_count' => count($combos),
            'page_family_distribution' => $families,
            'locale_distribution' => $locales,
            'root_cause_distribution' => $rootCause,
            'current_url_truth_missing_handoff_count' => $rootCause['current_url_truth_missing'],
            'current_url_truth_missing_distribution' => [
                'page_family' => $missingFamily,
                'locale' => $missingLocale,
            ],
            'backend_authority_candidate_count' => $backendAuthority->count(),
            'classification_unit' => 'unique_normalized_canonical_url',
            'classification_authority' => 'backend_cms_and_persisted_url_truth_only',
            'opaque_hash_fallback_count' => collect($unique)->where('opaque_hash_fallback', true)->count(),
            'identity_resolution' => 'authority_normalized_hash_or_distinct_opaque_source_hash',
            'unknown_next_evidence' => 'backend_cms_publication_history_or_approved_alias_registry',
            'raw_url_retained_or_emitted' => false,
        ];
    }

    /** @param array<string,mixed> $item @param Collection<string,mixed> $backendAuthority */
    private function pageFamilyIdForMissing(array $item, Collection $backendAuthority): string
    {
        $record = $backendAuthority->get((string) ($item['normalized_canonical_url_hash'] ?? ''));
        if (! $record instanceof UrlTruthInventoryRecord) {
            return 'unclassified';
        }

        $classification = (new PageFamilyClassifier)->classify([
            'canonical_url' => $record->canonicalUrl,
            'locale' => $record->locale,
            'page_entity_type' => $record->pageEntityType,
            'entity_source' => $record->entitySource,
            'source_authority' => $record->sourceAuthority,
            'authority_status' => $record->authorityStatus,
            'indexability_state' => $record->indexabilityState,
            'is_private_flow' => $record->isPrivateFlow,
        ]);

        return ($classification['classification_status'] ?? '') === 'classified'
            ? (string) $classification['family_id']
            : 'unclassified';
    }

    /**
     * @param  Collection<string,object>  $truth
     * @param  Collection<string,mixed>  $backendAuthority
     * @return array{normalized_canonical_url_hash:string,root_cause:string,page_family:string,locale:string}
     */
    private function classifyUrl(string $rawUrl, Collection $truth, Collection $backendAuthority): array
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
        $normalized = 'https://fermatmind.com'.$path;
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
        $localeNormalizedHash = hash('sha256', 'https://fermatmind.com'.$localeNormalizedPath);
        if ($localeNormalizedPath !== $path && $truth->has($localeNormalizedHash)) {
            return $this->classification($localeNormalizedHash, 'locale_path_normalization', $this->pageFamily($localeNormalizedPath), $this->locale($localeNormalizedPath));
        }

        $truthRow = $truth->get($normalizedHash);
        if (is_object($truthRow)) {
            if ((bool) ($truthRow->is_private_flow ?? false)) {
                return $this->classification($normalizedHash, 'private_deny_path', $family, $locale);
            }
            $state = strtolower((string) ($truthRow->indexability_state ?? ''));
            $authority = strtolower((string) ($truthRow->source_authority ?? ''));
            if ($this->containsAny($state.' '.$authority, ['redirect', 'alias'])) {
                return $this->classification($normalizedHash, 'redirect_alias', $family, $locale);
            }
            if ($this->containsAny($state, ['superseded', 'historical'])) {
                return $this->classification($normalizedHash, 'historical_url', $family, $locale);
            }
            if ($this->containsAny($state, ['retired', 'noindex', 'blocked'])) {
                return $this->classification($normalizedHash, 'retired_noindex', $family, $locale);
            }
            if ($this->containsAny($state, ['draft', 'unpublished', 'pending'])) {
                return $this->classification($normalizedHash, 'not_published', $family, $locale);
            }

            return $this->classification($normalizedHash, 'host_canonical_normalization', $family, $locale);
        }

        $authorityHash = $backendAuthority->has($normalizedHash) ? $normalizedHash : $localeNormalizedHash;
        if ($backendAuthority->has($authorityHash)) {
            return $this->classification($authorityHash, 'current_url_truth_missing', $this->pageFamily($localeNormalizedPath), $this->locale($localeNormalizedPath));
        }

        return $this->classification($normalizedHash, 'unknown', $family, $locale);
    }

    /** @return Collection<string,mixed> */
    private function backendAuthorityCandidates(): Collection
    {
        try {
            return collect($this->backendAuthority->candidates())
                ->keyBy(fn (mixed $record): string => (string) $record->canonicalUrlHash());
        } catch (\Throwable) {
            return collect();
        }
    }

    /** @return array{normalized_canonical_url_hash:string,root_cause:string,page_family:string,locale:string} */
    private function classification(string $hash, string $rootCause, string $family, string $locale): array
    {
        return [
            'normalized_canonical_url_hash' => $hash,
            'root_cause' => $rootCause,
            'page_family' => $family,
            'locale' => $locale,
            'opaque_hash_fallback' => false,
        ];
    }

    /**
     * @param  Collection<string,mixed>  $backendAuthority
     * @param  array<string,array<string,mixed>>  $opaqueHashIndex
     * @return array<string,mixed>
     */
    private function classifyOpaqueHash(string $hash, Collection $backendAuthority, array $opaqueHashIndex): array
    {
        if ($hash === '') {
            return $this->classification('', 'raw_canonical_missing', 'Other', 'unknown');
        }
        if ($backendAuthority->has($hash)) {
            $record = $backendAuthority->get($hash);
            $path = is_object($record) ? (string) parse_url((string) ($record->canonicalUrl ?? ''), PHP_URL_PATH) : '';

            return $this->classification($hash, 'current_url_truth_missing', $this->pageFamily($path), $this->locale($path));
        }
        if (isset($opaqueHashIndex[$hash])) {
            return $opaqueHashIndex[$hash];
        }

        return [
            ...$this->classification($hash, 'unknown', 'Other', 'unknown'),
            'opaque_hash_fallback' => true,
        ];
    }

    /**
     * @param  Collection<string,object>  $truth
     * @param  Collection<string,mixed>  $backendAuthority
     * @return array<string,array<string,mixed>>
     */
    private function opaqueHashClassificationIndex(Collection $truth, Collection $backendAuthority): array
    {
        $index = [];
        foreach ($truth as $row) {
            $canonical = (string) ($row->canonical_url ?? '');
            $state = strtolower((string) ($row->indexability_state ?? ''));
            $authority = strtolower((string) ($row->source_authority ?? ''));
            $rootCause = $this->containsAny($state.' '.$authority, ['redirect', 'alias']) ? 'redirect_alias'
                : ($this->containsAny($state, ['superseded', 'historical']) ? 'historical_url'
                    : ($this->containsAny($state, ['retired', 'noindex', 'blocked']) ? 'retired_noindex'
                        : ($this->containsAny($state, ['draft', 'unpublished', 'pending']) ? 'not_published' : null)));
            $this->addCanonicalVariants($index, $canonical, $rootCause);
        }
        foreach ($backendAuthority as $record) {
            if (is_object($record)) {
                $this->addCanonicalVariants($index, (string) ($record->canonicalUrl ?? ''), null);
            }
        }

        return $index;
    }

    /** @param array<string,array<string,mixed>> $index */
    private function addCanonicalVariants(array &$index, string $canonicalUrl, ?string $authorityStateRootCause): void
    {
        $parts = parse_url($canonicalUrl);
        if (! is_array($parts) || ! is_string($parts['path'] ?? null)) {
            return;
        }
        $path = $this->normalizePath((string) $parts['path']);
        $normalizedHash = hash('sha256', 'https://fermatmind.com'.$path);
        $family = $this->pageFamily($path);
        $locale = $this->locale($path);
        $hostVariants = [
            'https://www.fermatmind.com'.$path,
            'http://fermatmind.com'.$path,
            'http://www.fermatmind.com'.$path,
        ];
        if ($path !== '/') {
            $hostVariants[] = 'https://fermatmind.com'.$path.'/';
            $hostVariants[] = 'https://www.fermatmind.com'.$path.'/';
        }
        foreach ($hostVariants as $variant) {
            $index[hash('sha256', $variant)] = $this->classification(
                $normalizedHash,
                $authorityStateRootCause ?? 'host_canonical_normalization',
                $family,
                $locale,
            );
        }

        $localeVariants = [];
        if (preg_match('#^/zh(/|$)#', $path) === 1) {
            $localeVariants[] = preg_replace('#^/zh(/|$)#', '/zh-cn$1', $path);
            $localeVariants[] = preg_replace('#^/zh(/|$)#', '/zh_CN$1', $path);
        } elseif (preg_match('#^/en(/|$)#', $path) === 1) {
            $localeVariants[] = preg_replace('#^/en(/|$)#', '/en-us$1', $path);
            $localeVariants[] = preg_replace('#^/en(/|$)#', '/en_US$1', $path);
        }
        foreach (array_filter($localeVariants, 'is_string') as $variantPath) {
            foreach (['https://fermatmind.com', 'https://www.fermatmind.com'] as $host) {
                $index[hash('sha256', $host.$variantPath)] = $this->classification(
                    $normalizedHash,
                    $authorityStateRootCause ?? 'locale_path_normalization',
                    $family,
                    $locale,
                );
            }
        }
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
