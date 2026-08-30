<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Measurement;

use App\Services\SeoAgentEvidence\Bundle\SeoEvidenceBundleFactory;
use App\Services\SeoAgentEvidence\Privacy\SeoPrivateDataScanner;
use App\Services\SeoIntel\GscDataQualityGate;
use App\Services\SeoIntel\OpsDashboard\SeoConversionFunnelReadService;
use App\Services\SeoIntel\OpsDashboard\SeoDashboardApiReadService;
use App\Services\SeoIntel\PageFamily\PageFamilyPolicyRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ReadOnlyMeasurementEvidenceBundleLoader implements MeasurementEvidenceBundleLoader, MeasurementEvidenceDiagnosticLoader
{
    private const WINDOWS = [7, 28, 90];

    public function __construct(
        private readonly SeoEvidenceBundleFactory $bundles,
        private readonly SeoPrivateDataScanner $privacy,
        private readonly GscDataQualityGate $quality,
        private readonly SeoDashboardApiReadService $dashboard,
        private readonly SeoConversionFunnelReadService $conversion,
        private readonly MeasurementEvidenceHoldReasonResolver $reasons,
    ) {}

    public function loadForScope(
        string $missionId,
        string $modeId,
        string $pageFamily,
        string $locale,
        string $environment,
    ): array {
        return $this->diagnoseForScope($missionId, $modeId, $pageFamily, $locale, $environment)->bundles();
    }

    public function diagnoseForScope(
        string $missionId,
        string $modeId,
        string $pageFamily,
        string $locale,
        string $environment,
    ): MeasurementEvidenceLoadResult {
        if (! in_array($environment, ['staging_runtime', 'production_runtime'], true)) {
            return MeasurementEvidenceLoadResult::make(
                $modeId,
                [],
                'offline_not_loaded',
                'not_applicable',
                MeasurementEvidenceLoadResult::OFFLINE_NOT_LOADED,
            );
        }

        try {
            return match ($modeId) {
                'search_measurement' => $this->searchResult($missionId, $pageFamily, $locale, $environment),
                'commercial_funnel_cro' => $this->croResult($missionId, $pageFamily, $locale, $environment),
                default => MeasurementEvidenceLoadResult::make(
                    'search_measurement', [], 'unavailable', 'unknown', 'INTERNAL_SAFE_HOLD'
                ),
            };
        } catch (Throwable) {
            return MeasurementEvidenceLoadResult::make(
                $modeId, [], 'unavailable', 'unknown', 'INTERNAL_SAFE_HOLD'
            );
        }
    }

    public function diagnoseForRuntime(
        string $missionId,
        string $modeId,
        string $environment,
    ): MeasurementEvidenceLoadResult {
        if (! in_array($environment, ['staging_runtime', 'production_runtime'], true)) {
            return $this->diagnoseForScope($missionId, $modeId, 'tests', 'en', $environment);
        }

        try {
            if ($modeId === 'search_measurement' && ! $this->searchSchemaAvailable()) {
                return MeasurementEvidenceLoadResult::make(
                    $modeId, [], 'unavailable', 'unknown', 'GSC_SCHEMA_UNAVAILABLE'
                );
            }
            if ($modeId === 'commercial_funnel_cro' && ! $this->croSchemaAvailable()) {
                return MeasurementEvidenceLoadResult::make(
                    $modeId, [], 'unavailable', 'unknown', 'CRO_SCHEMA_UNAVAILABLE'
                );
            }
            $scope = $modeId === 'search_measurement'
                ? $this->searchRuntimeScope()
                : $this->croRuntimeScope();
            if ($scope === null) {
                return MeasurementEvidenceLoadResult::make(
                    $modeId,
                    [],
                    'unavailable',
                    'unknown',
                    $modeId === 'search_measurement' ? 'GSC_NO_ELIGIBLE_ROWS' : 'CRO_READMODEL_UNHEALTHY',
                );
            }

            return $this->diagnoseForScope(
                $missionId,
                $modeId,
                $scope['page_family'],
                $scope['locale'],
                $environment,
            );
        } catch (Throwable) {
            return MeasurementEvidenceLoadResult::make(
                $modeId,
                [],
                'unavailable',
                'unknown',
                $modeId === 'search_measurement' ? 'GSC_READMODEL_UNHEALTHY' : 'CRO_READMODEL_UNHEALTHY',
            );
        }
    }

    private function searchResult(
        string $missionId,
        string $pageFamily,
        string $locale,
        string $environment,
    ): MeasurementEvidenceLoadResult {
        if (! $this->searchSchemaAvailable()) {
            return MeasurementEvidenceLoadResult::make(
                'search_measurement', [], 'unavailable', 'unknown', 'GSC_SCHEMA_UNAVAILABLE'
            );
        }

        $connection = (string) config('seo_intel.connection', 'seo_intel');
        try {
            $rows = $this->searchRowsForScope($connection, $pageFamily, $locale);
        } catch (Throwable) {
            return MeasurementEvidenceLoadResult::make(
                'search_measurement', [], 'unavailable', 'unknown', 'GSC_READMODEL_UNHEALTHY'
            );
        }
        if ($rows === []) {
            return MeasurementEvidenceLoadResult::make(
                'search_measurement', [], 'unavailable', 'unknown', 'GSC_NO_ELIGIBLE_ROWS'
            );
        }

        $quality = $this->quality->evaluate($rows);
        $latest = (string) data_get($quality, 'freshness.max_report_date', '');
        $revisions = array_values(array_unique(array_filter(
            array_column($rows, 'authority_revision'),
            static fn (mixed $value): bool => is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1,
        )));
        $mappingFailed = array_filter(
            $rows,
            static fn (array $row): bool => ($row['mapping_state'] ?? null) !== 'mapped'
        ) !== [];
        try {
            $latestDate = $latest === '' ? null : CarbonImmutable::parse($latest, 'UTC')->startOfDay();
        } catch (Throwable) {
            $latestDate = null;
        }
        $windowComplete = $latestDate !== null;
        $readmodelHealthy = true;
        $windowMetrics = [];
        $computedReadmodels = [];
        foreach (self::WINDOWS as $days) {
            $windowRows = $latestDate === null ? [] : array_values(array_filter(
                $rows,
                static function (array $row) use ($latestDate, $days): bool {
                    try {
                        $date = CarbonImmutable::parse((string) $row['report_date'], 'UTC')->startOfDay();
                    } catch (Throwable) {
                        return false;
                    }

                    return $date->betweenIncluded($latestDate->subDays($days - 1), $latestDate);
                }
            ));
            $windowComplete = $windowComplete
                && count(array_unique(array_column($windowRows, 'report_date'))) === $days;
            try {
                $computed = $this->dashboard->searchPerformance(['days' => $days, 'locale' => $locale]);
            } catch (Throwable) {
                $computed = [];
            }
            $familyMetrics = collect((array) data_get($computed, 'breakdowns.page_family', []))
                ->firstWhere('dimension', $pageFamily);
            if (($computed['measurement_state'] ?? null) !== 'production_healthy' || ! is_array($familyMetrics)) {
                $readmodelHealthy = false;
            }
            $computedReadmodels[$days] = $computed;
            $windowMetrics[] = [
                'window_days' => $days,
                'metrics' => $this->computedGscMetrics(is_array($familyMetrics) ? $familyMetrics : []),
            ];
        }

        $lagDays = max(0, (int) data_get($quality, 'freshness.lag_days_required', 3));
        $maxAgeDays = max($lagDays, (int) data_get($quality, 'freshness.max_report_age_days', 10));
        $stale = $latestDate === null || $latestDate->lessThan(now('UTC')->subDays($maxAgeDays)->startOfDay());
        $fresh = ! $stale
            && ! $latestDate?->greaterThan(now('UTC')->subDays($lagDays)->startOfDay());
        $qualityPassed = ($quality['status'] ?? null) === 'pass';
        $reason = $this->reasons->search([
            'schema_available' => true,
            'eligible_rows' => true,
            'stale' => $stale,
            'quality_passed' => $qualityPassed,
            'mapping_valid' => ! $mappingFailed,
            'authority_valid' => count($revisions) === 1,
            'readmodel_healthy' => $readmodelHealthy,
            'window_complete' => $windowComplete,
        ]);
        $available = $reason === MeasurementEvidenceLoadResult::NONE;
        $authorityRevision = count($revisions) === 1
            ? $revisions[0]
            : hash('sha256', 'measurement:revision-conflict');
        $allZero = array_sum(array_map(
            static fn (array $window): int => (int) $window['metrics']['clicks'] + (int) $window['metrics']['impressions'],
            $windowMetrics,
        )) === 0;
        $payload = [
            'windows' => $windowMetrics,
            'branded_non_branded' => $this->computedBrandMetrics((array) data_get($computedReadmodels, '90.breakdowns.brand', [])),
            'detector_findings' => $this->detectorResults($connection, $pageFamily, $revisions[0] ?? null),
            'freshness' => [
                'lag_days_required' => data_get($quality, 'freshness.lag_days_required'),
                'max_source_age_days' => data_get($quality, 'freshness.max_report_age_days'),
                'min_source_date' => data_get($quality, 'freshness.min_report_date'),
                'max_source_date' => data_get($quality, 'freshness.max_report_date'),
            ],
            'mapping_state' => $mappingFailed ? 'failed' : 'mapped',
            'quality_gate_status' => $qualityPassed ? 'pass' : 'blocked',
            'window_complete' => $windowComplete,
            'current_window_readable' => true,
            'valid_measurement_present' => ! $allZero,
            'explicit_zero_proof' => $available && $allZero,
            'all_relevant_values_zero' => $allZero,
        ];
        $input = [
            'bundle_id' => 'measurement:gsc:aggregate:v2',
            'bundle_version' => 2,
            'mission_id' => $missionId,
            'source_type' => 'gsc_aggregate',
            'source_ref' => hash('sha256', $environment.'|'.$pageFamily.'|'.$locale.'|'.$latest),
            'authority_type' => 'measurement_readmodel',
            'captured_at' => now('UTC')->format('Y-m-d\TH:i:s\Z'),
            'evidence_state' => $available ? 'verified' : 'blocked',
            'freshness_state' => $fresh ? 'fresh' : 'stale',
            'source_capability_state' => $available ? 'available' : 'held',
            'retention_class' => 'first_party_aggregate',
            'page_family' => $pageFamily,
            'locale' => $locale,
            'authority_revision' => $authorityRevision,
            'source_license_class' => 'first_party',
            'data_usage_purpose' => 'measurement_review',
            'egress_decision' => 'not_required',
            'lineage_refs' => [],
            'payload' => $payload,
        ];
        if ($this->privacy->scan($input, SeoPrivateDataScanner::BUNDLE_INPUT_HASH_PATHS)['private_data_present']) {
            return MeasurementEvidenceLoadResult::make(
                'search_measurement', [], 'held', $fresh ? 'fresh' : 'stale', 'BUNDLE_PRIVACY_HOLD', $authorityRevision
            );
        }
        try {
            $bundle = $this->bundles->create($input);
        } catch (Throwable) {
            return MeasurementEvidenceLoadResult::make(
                'search_measurement', [], 'unavailable', 'unknown', 'INTERNAL_SAFE_HOLD', $authorityRevision
            );
        }

        return MeasurementEvidenceLoadResult::make(
            'search_measurement',
            [$bundle],
            $available ? 'available' : 'held',
            $fresh ? 'fresh' : 'stale',
            $reason,
            $authorityRevision,
        );
    }

    private function croResult(
        string $missionId,
        string $pageFamily,
        string $locale,
        string $environment,
    ): MeasurementEvidenceLoadResult {
        if (! $this->croSchemaAvailable()) {
            return MeasurementEvidenceLoadResult::make(
                'commercial_funnel_cro', [], 'unavailable', 'unknown', 'CRO_SCHEMA_UNAVAILABLE'
            );
        }
        try {
            $storageLocale = $locale === 'zh-CN' ? 'zh-cn' : $locale;
            $read = $this->conversion->read(0, [
                'group_by' => 'url',
                'window_days' => 90,
                'lang' => $storageLocale,
            ], 100);
        } catch (Throwable) {
            return MeasurementEvidenceLoadResult::make(
                'commercial_funnel_cro', [], 'unavailable', 'unknown', 'CRO_READMODEL_UNHEALTHY'
            );
        }

        $windowTotals = [];
        foreach (self::WINDOWS as $days) {
            $metrics = data_get($read, 'window_totals.'.(string) $days);
            if (! is_array($metrics)) {
                continue;
            }
            $windowTotals[] = ['window_days' => $days, 'metrics' => $this->safeFunnelMetrics($metrics)];
        }
        $complete = array_column($windowTotals, 'window_days') === self::WINDOWS;
        $mapping = (array) ($read['product_event_mapping'] ?? []);
        $mappingOk = $mapping === [
            'start_test' => 'analytics_seo_conversion_daily.start_test_count',
            'complete_test' => 'analytics_seo_conversion_daily.complete_test_count',
            'view_result' => 'analytics_seo_conversion_daily.view_result_count',
        ];
        $freshness = (array) ($read['freshness'] ?? []);
        $freshnessKnown = is_numeric($freshness['age_hours'] ?? null);
        $stale = $freshnessKnown
            && (float) $freshness['age_hours'] > (float) ($freshness['max_age_hours'] ?? 48);
        $fresh = $freshnessKnown && ! $stale;
        $stageCoverage = [
            'landing' => data_get($read, 'stage_status.search_landing.status') === 'pass',
            'start' => data_get($read, 'stage_status.test_start.status') === 'pass',
            'completion' => data_get($read, 'stage_status.test_complete.status') === 'pass',
            'aggregate_outcome_view' => data_get($read, 'stage_status.result_view.status') === 'pass',
            'return_public_content' => data_get($read, 'stage_status.return_public_content.status') === 'pass',
            'cta' => array_key_exists('article_to_test_click_count', (array) ($read['totals'] ?? [])),
        ];
        $readHealthy = ($read['measurement_state'] ?? null) === 'production_healthy';
        $reason = $this->reasons->cro([
            'schema_available' => true,
            'stale' => $stale,
            'readmodel_healthy' => $readHealthy,
            'window_complete' => $complete,
            'mapping_valid' => $mappingOk,
            'stage_coverage_complete' => ! in_array(false, $stageCoverage, true),
        ]);
        $available = $reason === MeasurementEvidenceLoadResult::NONE;
        $authorityRevision = hash('sha256', json_encode([
            'mapping' => $mapping,
            'refresh' => $freshness['last_successful_refresh_at'] ?? null,
            'page_family' => $pageFamily,
            'locale' => $locale,
            'environment' => $environment,
        ], JSON_THROW_ON_ERROR));
        $allZero = $complete && array_sum(array_map(
            static fn (array $window): int => array_sum(array_map('intval', $window['metrics'])),
            $windowTotals,
        )) === 0;
        $payload = [
            'windows' => $windowTotals,
            'stage_coverage' => $stageCoverage,
            'freshness' => [
                'age_hours' => is_numeric($freshness['age_hours'] ?? null) ? (int) ceil((float) $freshness['age_hours']) : null,
                'max_age_hours' => max(1, (int) ($freshness['max_age_hours'] ?? 48)),
                'latest_refresh_status' => $freshness['latest_attempt_status'] ?? null,
            ],
            'revision_hash' => hash('sha256', json_encode($mapping, JSON_THROW_ON_ERROR)),
            'mapping_state' => $mappingOk ? 'mapped' : 'failed',
            'quality_gate_status' => $readHealthy ? 'pass' : 'blocked',
            'window_complete' => $complete,
            'current_window_readable' => $windowTotals !== [],
            'valid_measurement_present' => ! $allZero,
            'explicit_zero_proof' => $available && $allZero,
            'all_relevant_values_zero' => $allZero,
        ];
        $input = [
            'bundle_id' => 'measurement:funnel:aggregate:v2',
            'bundle_version' => 2,
            'mission_id' => $missionId,
            'source_type' => 'public_funnel_aggregate',
            'source_ref' => $authorityRevision,
            'authority_type' => 'measurement_readmodel',
            'captured_at' => now('UTC')->format('Y-m-d\TH:i:s\Z'),
            'evidence_state' => $available ? 'verified' : 'blocked',
            'freshness_state' => $fresh ? 'fresh' : 'stale',
            'source_capability_state' => $available ? 'available' : 'held',
            'retention_class' => 'first_party_aggregate',
            'page_family' => $pageFamily,
            'locale' => $locale,
            'authority_revision' => $authorityRevision,
            'source_license_class' => 'first_party',
            'data_usage_purpose' => 'measurement_review',
            'egress_decision' => 'not_required',
            'lineage_refs' => [],
            'payload' => $payload,
        ];
        if ($this->privacy->scan($input, SeoPrivateDataScanner::BUNDLE_INPUT_HASH_PATHS)['private_data_present']) {
            return MeasurementEvidenceLoadResult::make(
                'commercial_funnel_cro', [], 'held', $fresh ? 'fresh' : 'stale', 'BUNDLE_PRIVACY_HOLD', $authorityRevision
            );
        }
        try {
            $bundle = $this->bundles->create($input);
        } catch (Throwable) {
            return MeasurementEvidenceLoadResult::make(
                'commercial_funnel_cro', [], 'unavailable', 'unknown', 'INTERNAL_SAFE_HOLD', $authorityRevision
            );
        }

        return MeasurementEvidenceLoadResult::make(
            'commercial_funnel_cro',
            [$bundle],
            $available ? 'available' : 'held',
            $fresh ? 'fresh' : 'stale',
            $reason,
            $authorityRevision,
        );
    }

    /** @return array{page_family:string,locale:string}|null */
    private function searchRuntimeScope(): ?array
    {
        $connection = (string) config('seo_intel.connection', 'seo_intel');
        if ($this->urlTruthSchemaAvailable($connection)) {
            $row = DB::connection($connection)->table('seo_gsc_daily as g')
                ->join('seo_urls as u', 'u.canonical_url_hash', '=', 'g.canonical_url_hash')
                ->whereIn('u.page_family', PageFamilyPolicyRegistry::PUBLIC_FAMILY_IDS)
                ->whereIn('u.locale', ['en', 'zh-CN'])
                ->where('u.is_private_flow', false)
                ->where('g.source_engine', 'google')
                ->where('g.data_state', 'final')
                ->groupBy('u.page_family', 'u.locale')
                ->orderByDesc(DB::raw('MAX(g.report_date)'))
                ->orderByDesc(DB::raw('COUNT(DISTINCT g.report_date)'))
                ->orderBy('u.page_family')
                ->orderBy('u.locale')
                ->first(['u.page_family', 'u.locale']);
            if ($row !== null) {
                return ['page_family' => (string) $row->page_family, 'locale' => (string) $row->locale];
            }
        }

        $rows = DB::connection($connection)->table('seo_gsc_daily')
            ->where('source_engine', 'google')
            ->where('data_state', 'final')
            ->where('mapping_state', 'mapped')
            ->whereIn('locale', ['en', 'zh-CN'])
            ->orderByDesc('report_date')
            ->limit(5000)
            ->get(['locale', 'metadata_json']);
        foreach ($rows as $row) {
            $normalized = $this->normalizeGscRow((array) $row);
            if (in_array($normalized['page_family'] ?? null, PageFamilyPolicyRegistry::PUBLIC_FAMILY_IDS, true)
                && in_array($normalized['locale'] ?? null, ['en', 'zh-CN'], true)
                && preg_match('/^[a-f0-9]{64}$/D', (string) ($normalized['authority_revision'] ?? '')) === 1) {
                return [
                    'page_family' => (string) $normalized['page_family'],
                    'locale' => (string) $normalized['locale'],
                ];
            }
        }

        return null;
    }

    /** @return array{page_family:string,locale:string}|null */
    private function croRuntimeScope(): ?array
    {
        $rows = DB::connection((string) config('database.default'))
            ->table('analytics_seo_conversion_daily')
            ->where('org_id', 0)
            ->whereIn('lang', ['en', 'zh-cn'])
            ->groupBy('page_type', 'lang')
            ->orderByDesc(DB::raw('MAX(day)'))
            ->orderByDesc(DB::raw('COUNT(DISTINCT day)'))
            ->get(['page_type', 'lang']);
        foreach ($rows as $row) {
            $family = $this->croPageFamily((string) ($row->page_type ?? ''));
            $locale = match ($row->lang ?? null) {
                'en' => 'en',
                'zh-cn' => 'zh-CN',
                default => null,
            };
            if ($family !== null && $locale !== null) {
                return ['page_family' => $family, 'locale' => $locale];
            }
        }

        return null;
    }

    private function croPageFamily(string $pageType): ?string
    {
        return match (strtolower(trim($pageType))) {
            'tests', 'test', 'test_detail', 'test_hub' => 'tests',
            'articles_topics', 'article', 'article_hub', 'topic', 'topic_hub' => 'articles_topics',
            'career', 'career_job', 'career_guide', 'career_hub' => 'career',
            'personality', 'personality_hub', 'personality_profile' => 'personality',
            'trust_method_help', 'methodology', 'support_article', 'support_hub' => 'trust_method_help',
            'other_public', 'home', 'landing_page' => 'other_public',
            default => null,
        };
    }

    private function searchSchemaAvailable(): bool
    {
        try {
            $schema = Schema::connection((string) config('seo_intel.connection', 'seo_intel'));

            return $this->schemaHas($schema, 'seo_gsc_daily', [
                'report_date', 'canonical_url_hash', 'query_hash', 'source_engine', 'data_state',
                'clicks', 'impressions', 'ctr_ppm', 'average_position_milli', 'is_brand_query',
                'mapping_state', 'metadata_json', 'locale', 'query_type', 'collected_at',
            ]);
        } catch (Throwable) {
            return false;
        }
    }

    private function croSchemaAvailable(): bool
    {
        try {
            $main = Schema::connection((string) config('database.default'));

            return $this->schemaHas($main, 'analytics_seo_conversion_daily', [
                'day', 'org_id', 'url', 'lang', 'page_type', 'source_article', 'target_test',
                'scale_id', 'form_id', 'source_url', 'landing_pv_count', 'article_to_test_click_count',
                'start_test_count', 'complete_test_count', 'view_result_count',
                'return_public_content_count', 'last_refreshed_at',
            ])
                && $this->schemaHas($main, 'analytics_seo_conversion_refresh_runs', [
                    'org_scope_count', 'status', 'trigger_mode', 'completed_at',
                ]);
        } catch (Throwable) {
            return false;
        }
    }

    /** @param list<string> $columns */
    private function schemaHas(Builder $schema, string $table, array $columns): bool
    {
        if (! $schema->hasTable($table)) {
            return false;
        }
        foreach ($columns as $column) {
            if (! $schema->hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $metrics @return array<string, int> */
    private function computedGscMetrics(array $metrics): array
    {
        return [
            'clicks' => max(0, (int) ($metrics['clicks'] ?? 0)),
            'impressions' => max(0, (int) ($metrics['impressions'] ?? 0)),
            'ctr_ppm' => max(0, (int) round((float) ($metrics['ctr_percent'] ?? 0) * 10_000)),
            'average_position_milli' => max(0, (int) round((float) ($metrics['average_position'] ?? 0) * 1_000)),
        ];
    }

    /** @param list<array<string, mixed>> $breakdown @return array<string, array<string, int>> */
    private function computedBrandMetrics(array $breakdown): array
    {
        $byDimension = collect($breakdown)->keyBy('dimension');

        return [
            'branded' => $this->computedGscMetrics((array) $byDimension->get('brand', [])),
            'non_branded' => $this->computedGscMetrics((array) $byDimension->get('non_brand', [])),
        ];
    }

    /** @return list<string> */
    private function detectorResults(string $connection, string $pageFamily, ?string $authorityRevision): array
    {
        $schema = Schema::connection($connection);
        if (! $schema->hasTable('seo_issue_queue') || ! $schema->hasColumn('seo_issue_queue', 'page_family')) {
            return [];
        }
        $query = DB::connection($connection)->table('seo_issue_queue')->where('page_family', $pageFamily);
        if ($authorityRevision !== null && $schema->hasColumn('seo_issue_queue', 'authority_revision')) {
            $query->where('authority_revision', $authorityRevision);
        }

        return $query->distinct()->pluck('issue_type')->filter('is_string')->sort()->values()->all();
    }

    /** @param array<string, mixed> $metrics @return array<string, int> */
    private function safeFunnelMetrics(array $metrics): array
    {
        return [
            'landing_pv_count' => max(0, (int) ($metrics['landing_pv_count'] ?? 0)),
            'article_to_test_click_count' => max(0, (int) ($metrics['article_to_test_click_count'] ?? 0)),
            'start_test_count' => max(0, (int) ($metrics['start_test_count'] ?? 0)),
            'complete_test_count' => max(0, (int) ($metrics['complete_test_count'] ?? 0)),
            'aggregate_outcome_view_count' => max(0, (int) ($metrics['view_result_count'] ?? 0)),
            'return_public_content_count' => max(0, (int) ($metrics['return_public_content_count'] ?? 0)),
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function normalizeGscRow(array $row): array
    {
        if (is_string($row['metadata_json'] ?? null)) {
            try {
                $decoded = json_decode($row['metadata_json'], true, 32, JSON_THROW_ON_ERROR);
                $row['metadata_json'] = is_array($decoded) ? $decoded : [];
            } catch (Throwable) {
                $row['metadata_json'] = [];
            }
        }
        $metadata = is_array($row['metadata_json'] ?? null) ? $row['metadata_json'] : [];
        $row['authority_revision'] ??= $metadata['authority_revision'] ?? null;
        $row['page_family'] ??= $metadata['page_family'] ?? null;

        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function searchRowsForScope(string $connection, string $pageFamily, string $locale): array
    {
        if ($this->urlTruthSchemaAvailable($connection)) {
            $latest = DB::connection($connection)->table('seo_gsc_daily as g')
                ->join('seo_urls as u', 'u.canonical_url_hash', '=', 'g.canonical_url_hash')
                ->where('u.page_family', $pageFamily)
                ->where('u.locale', $locale)
                ->where('u.is_private_flow', false)
                ->where('g.source_engine', 'google')
                ->where('g.data_state', 'final')
                ->max('g.report_date');
            if (is_string($latest) && $latest !== '') {
                $latestDate = CarbonImmutable::parse($latest, 'UTC')->startOfDay();

                return DB::connection($connection)->table('seo_gsc_daily as g')
                    ->join('seo_urls as u', 'u.canonical_url_hash', '=', 'g.canonical_url_hash')
                    ->where('u.page_family', $pageFamily)
                    ->where('u.locale', $locale)
                    ->where('u.is_private_flow', false)
                    ->where('g.source_engine', 'google')
                    ->where('g.data_state', 'final')
                    ->whereBetween('g.report_date', [
                        $latestDate->subDays(96)->toDateString(),
                        $latestDate->toDateString(),
                    ])
                    ->get([
                        'g.report_date', 'g.canonical_url_hash', 'g.query_hash', 'g.source_engine',
                        'g.clicks', 'g.impressions', 'g.ctr_ppm', 'g.average_position_milli',
                        'g.is_brand_query', 'g.mapping_state', 'g.metadata_json', 'u.authority_revision',
                    ])->map(fn (object $row): array => $this->normalizeGscRow((array) $row))->all();
            }
        }

        $latest = DB::connection($connection)->table('seo_gsc_daily')
            ->where('locale', $locale)
            ->where('source_engine', 'google')
            ->where('data_state', 'final')
            ->where('mapping_state', 'mapped')
            ->max('report_date');
        if (! is_string($latest) || $latest === '') {
            return [];
        }
        $latestDate = CarbonImmutable::parse($latest, 'UTC')->startOfDay();

        return DB::connection($connection)->table('seo_gsc_daily')
            ->where('locale', $locale)
            ->where('source_engine', 'google')
            ->where('data_state', 'final')
            ->where('mapping_state', 'mapped')
            ->whereBetween('report_date', [
                $latestDate->subDays(96)->toDateString(),
                $latestDate->toDateString(),
            ])
            ->get([
                'report_date', 'canonical_url_hash', 'query_hash', 'source_engine',
                'clicks', 'impressions', 'ctr_ppm', 'average_position_milli',
                'is_brand_query', 'mapping_state', 'metadata_json', 'locale',
            ])->map(fn (object $row): array => $this->normalizeGscRow((array) $row))
            ->filter(fn (array $row): bool => ($row['page_family'] ?? null) === $pageFamily
                && preg_match('/^[a-f0-9]{64}$/D', (string) ($row['authority_revision'] ?? '')) === 1)
            ->values()
            ->all();
    }

    private function urlTruthSchemaAvailable(string $connection): bool
    {
        $schema = Schema::connection($connection);

        return $this->schemaHas($schema, 'seo_urls', [
            'canonical_url_hash', 'canonical_url', 'locale', 'page_family', 'page_entity_type',
            'source_authority', 'indexability_state', 'is_private_flow', 'authority_revision',
        ]);
    }
}
