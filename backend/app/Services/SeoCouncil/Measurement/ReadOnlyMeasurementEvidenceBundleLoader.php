<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Measurement;

use App\Services\SeoAgentEvidence\Bundle\SeoEvidenceBundleFactory;
use App\Services\SeoAgentEvidence\Privacy\SeoPrivateDataScanner;
use App\Services\SeoIntel\GscDataQualityGate;
use App\Services\SeoIntel\OpsDashboard\SeoConversionFunnelReadService;
use App\Services\SeoIntel\OpsDashboard\SeoDashboardApiReadService;
use App\Services\SeoIntel\SearchToResultFunnelReadModel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ReadOnlyMeasurementEvidenceBundleLoader implements MeasurementEvidenceBundleLoader
{
    private const WINDOWS = [7, 28, 90];

    public function __construct(
        private readonly SeoEvidenceBundleFactory $bundles,
        private readonly SeoPrivateDataScanner $privacy,
        private readonly GscDataQualityGate $quality,
        private readonly SeoDashboardApiReadService $dashboard,
        private readonly SeoConversionFunnelReadService $conversion,
        private readonly SearchToResultFunnelReadModel $searchToResult,
    ) {}

    public function loadForScope(
        string $missionId,
        string $modeId,
        string $pageFamily,
        string $locale,
        string $environment,
    ): array {
        if (! in_array($environment, ['staging_runtime', 'production_runtime'], true)) {
            return [];
        }

        try {
            $bundle = match ($modeId) {
                'search_measurement' => $this->searchBundle($missionId, $pageFamily, $locale, $environment),
                'commercial_funnel_cro' => $this->croBundle($missionId, $pageFamily, $locale, $environment),
                default => null,
            };
        } catch (Throwable) {
            return [];
        }

        return is_array($bundle) ? [$bundle] : [];
    }

    /** @return array<string, mixed>|null */
    private function searchBundle(string $missionId, string $pageFamily, string $locale, string $environment): ?array
    {
        $connection = (string) config('seo_intel.connection', 'seo_intel');
        $schema = Schema::connection($connection);
        foreach (['seo_gsc_daily', 'seo_urls'] as $table) {
            if (! $schema->hasTable($table)) {
                return null;
            }
        }
        foreach (['mapping_state', 'is_brand_query', 'metadata_json'] as $column) {
            if (! $schema->hasColumn('seo_gsc_daily', $column)) {
                return null;
            }
        }
        foreach (['page_family', 'authority_revision', 'is_private_flow'] as $column) {
            if (! $schema->hasColumn('seo_urls', $column)) {
                return null;
            }
        }

        $rows = DB::connection($connection)->table('seo_gsc_daily as g')
            ->join('seo_urls as u', 'u.canonical_url_hash', '=', 'g.canonical_url_hash')
            ->where('u.page_family', $pageFamily)->where('u.locale', $locale)->where('u.is_private_flow', false)
            ->where('g.source_engine', 'google')->where('g.data_state', 'final')
            ->where('g.report_date', '>=', now('UTC')->subDays(96)->toDateString())
            ->get([
                'g.report_date', 'g.canonical_url_hash', 'g.query_hash', 'g.source_engine',
                'g.clicks', 'g.impressions', 'g.ctr_ppm', 'g.average_position_milli',
                'g.is_brand_query', 'g.mapping_state', 'g.metadata_json', 'u.authority_revision',
            ])->map(fn (object $row): array => $this->normalizeGscRow((array) $row))->all();
        if ($rows === []) {
            return null;
        }

        $quality = $this->quality->evaluate($rows);
        $latest = (string) data_get($quality, 'freshness.max_report_date', '');
        $revisions = array_values(array_unique(array_filter(array_column($rows, 'authority_revision'), static fn (mixed $value): bool => is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1)));
        $mappingFailed = array_filter($rows, static fn (array $row): bool => ($row['mapping_state'] ?? null) !== 'mapped') !== [];
        $windowMetrics = [];
        $computedReadmodels = [];
        $complete = $latest !== '';
        $latestDate = $complete ? CarbonImmutable::parse($latest, 'UTC')->startOfDay() : null;
        foreach (self::WINDOWS as $days) {
            $windowRows = $latestDate === null ? [] : array_values(array_filter($rows, static function (array $row) use ($latestDate, $days): bool {
                $date = CarbonImmutable::parse((string) $row['report_date'], 'UTC')->startOfDay();

                return $date->betweenIncluded($latestDate->subDays($days - 1), $latestDate);
            }));
            $complete = $complete && count(array_unique(array_column($windowRows, 'report_date'))) === $days;
            $computed = $this->dashboard->searchPerformance(['days' => $days, 'locale' => $locale]);
            $familyMetrics = collect((array) data_get($computed, 'breakdowns.page_family', []))
                ->firstWhere('dimension', $pageFamily);
            if (($computed['measurement_state'] ?? null) !== 'production_healthy' || ! is_array($familyMetrics)) {
                $complete = false;
            }
            $computedReadmodels[$days] = $computed;
            $windowMetrics[] = [
                'window_days' => $days,
                'metrics' => $this->computedGscMetrics(is_array($familyMetrics) ? $familyMetrics : []),
            ];
        }
        $lagDays = max(0, (int) data_get($quality, 'freshness.lag_days_required', 3));
        $maxAgeDays = max($lagDays, (int) data_get($quality, 'freshness.max_report_age_days', 10));
        $fresh = $latestDate !== null
            && ! $latestDate->greaterThan(now('UTC')->subDays($lagDays)->startOfDay())
            && ! $latestDate->lessThan(now('UTC')->subDays($maxAgeDays)->startOfDay());
        $qualityPassed = ($quality['status'] ?? null) === 'pass';
        $available = $qualityPassed && $fresh && $complete && ! $mappingFailed && count($revisions) === 1;
        $authorityRevision = count($revisions) === 1
            ? $revisions[0]
            : hash('sha256', 'measurement:revision-conflict');
        $allZero = array_sum(array_map(static fn (array $window): int => (int) $window['metrics']['clicks'] + (int) $window['metrics']['impressions'], $windowMetrics)) === 0;
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
            'window_complete' => $complete,
            'current_window_readable' => $rows !== [],
            'valid_measurement_present' => ! $allZero,
            'explicit_zero_proof' => $available && $allZero,
            'all_relevant_values_zero' => $allZero,
        ];

        $input = [
            'bundle_id' => 'measurement:gsc:aggregate:v2',
            'bundle_version' => 2, 'mission_id' => $missionId, 'source_type' => 'gsc_aggregate',
            'source_ref' => hash('sha256', $environment.'|'.$pageFamily.'|'.$locale.'|'.$latest),
            'authority_type' => 'measurement_readmodel', 'captured_at' => now('UTC')->format('Y-m-d\TH:i:s\Z'),
            'evidence_state' => $available ? 'verified' : 'blocked', 'freshness_state' => $fresh ? 'fresh' : 'stale',
            'source_capability_state' => $available ? 'available' : 'held', 'retention_class' => 'first_party_aggregate',
            'page_family' => $pageFamily, 'locale' => $locale,
            'authority_revision' => $authorityRevision,
            'source_license_class' => 'first_party', 'data_usage_purpose' => 'measurement_review',
            'egress_decision' => 'not_required', 'lineage_refs' => [], 'payload' => $payload,
        ];
        $scan = $this->privacy->scan($input, SeoPrivateDataScanner::BUNDLE_INPUT_HASH_PATHS);
        if ($scan['private_data_present']) {
            throw new \InvalidArgumentException('MEASUREMENT_EVIDENCE_PRIVACY_HOLD:'.implode(',', array_keys($scan['category_counts'])));
        }

        return $this->bundles->create($input);
    }

    /** @return array<string, mixed>|null */
    private function croBundle(string $missionId, string $pageFamily, string $locale, string $environment): ?array
    {
        $read = $this->conversion->read(0, ['group_by' => 'url', 'window_days' => 90, 'lang' => $locale], 100);
        $to = now('UTC')->subDays(3)->toDateString();
        $from = now('UTC')->subDays(92)->toDateString();
        $chain = $this->searchToResult->report($from, $to, $pageFamily, 'google');
        $healthy = ($read['measurement_state'] ?? null) === 'production_healthy'
            && ($chain['status'] ?? null) === 'pass'
            && ($chain['read_only'] ?? null) === true;
        $windowTotals = [];
        foreach (self::WINDOWS as $days) {
            $metrics = data_get($read, 'window_totals.'.(string) $days);
            if (! is_array($metrics)) {
                continue;
            }
            $windowTotals[] = ['window_days' => $days, 'metrics' => $this->safeFunnelMetrics($metrics)];
        }
        $complete = array_column($windowTotals, 'window_days') === self::WINDOWS;
        $mapping = (array) ($chain['product_event_mapping'] ?? []);
        $mappingOk = array_keys($mapping) === ['start_test', 'complete_test', 'view_result'];
        $freshness = (array) ($read['freshness'] ?? []);
        $fresh = $healthy && is_numeric($freshness['age_hours'] ?? null)
            && (float) $freshness['age_hours'] <= (float) ($freshness['max_age_hours'] ?? 48);
        $authorityRevision = hash('sha256', json_encode([
            'mapping' => $mapping, 'refresh' => $freshness['last_successful_refresh_at'] ?? null,
            'page_family' => $pageFamily, 'locale' => $locale, 'environment' => $environment,
        ], JSON_THROW_ON_ERROR));
        $allZero = $complete && array_sum(array_map(static fn (array $window): int => array_sum(array_map('intval', $window['metrics'])), $windowTotals)) === 0;
        $payload = [
            'windows' => $windowTotals,
            'stage_coverage' => [
                'landing' => isset($read['stage_status']['search_landing']),
                'start' => isset($read['stage_status']['test_start']),
                'completion' => isset($read['stage_status']['test_complete']),
                'aggregate_outcome_view' => isset($read['stage_status']['result_view']),
                'return_public_content' => isset($read['stage_status']['return_public_content']),
                'cta' => array_key_exists('article_to_test_click_count', (array) ($read['totals'] ?? [])),
            ],
            'freshness' => [
                'age_hours' => is_numeric($freshness['age_hours'] ?? null) ? (int) ceil((float) $freshness['age_hours']) : null,
                'max_age_hours' => max(1, (int) ($freshness['max_age_hours'] ?? 48)),
                'latest_refresh_status' => $freshness['latest_attempt_status'] ?? null,
            ],
            'revision_hash' => hash('sha256', json_encode($mapping, JSON_THROW_ON_ERROR)),
            'mapping_state' => $mappingOk ? 'mapped' : 'failed',
            'quality_gate_status' => $healthy ? 'pass' : 'blocked',
            'window_complete' => $complete,
            'current_window_readable' => $windowTotals !== [],
            'valid_measurement_present' => ! $allZero,
            'explicit_zero_proof' => $healthy && $complete && $allZero,
            'all_relevant_values_zero' => $allZero,
        ];
        $available = $healthy && $fresh && $complete && $mappingOk
            && ! in_array(false, $payload['stage_coverage'], true);

        $input = [
            'bundle_id' => 'measurement:funnel:aggregate:v2', 'bundle_version' => 2,
            'mission_id' => $missionId, 'source_type' => 'public_funnel_aggregate',
            'source_ref' => $authorityRevision,
            'authority_type' => 'measurement_readmodel', 'captured_at' => now('UTC')->format('Y-m-d\TH:i:s\Z'),
            'evidence_state' => $available ? 'verified' : 'blocked', 'freshness_state' => $fresh ? 'fresh' : 'stale',
            'source_capability_state' => $available ? 'available' : 'held', 'retention_class' => 'first_party_aggregate',
            'page_family' => $pageFamily, 'locale' => $locale, 'authority_revision' => $authorityRevision,
            'source_license_class' => 'first_party', 'data_usage_purpose' => 'measurement_review',
            'egress_decision' => 'not_required', 'lineage_refs' => [], 'payload' => $payload,
        ];
        $scan = $this->privacy->scan($input, SeoPrivateDataScanner::BUNDLE_INPUT_HASH_PATHS);
        if ($scan['private_data_present']) {
            $unsafeFields = [];
            foreach ($input as $field => $value) {
                if ($this->privacy->scan([$field => $value], SeoPrivateDataScanner::BUNDLE_INPUT_HASH_PATHS)['private_data_present']) {
                    if ($field === 'payload' && is_array($value)) {
                        foreach ($value as $payloadField => $payloadValue) {
                            if ($this->privacy->scan(['payload' => [$payloadField => $payloadValue]], SeoPrivateDataScanner::BUNDLE_INPUT_HASH_PATHS)['private_data_present']) {
                                $unsafeFields[] = 'payload.'.$payloadField;
                            }
                        }
                    } else {
                        $unsafeFields[] = $field;
                    }
                }
            }
            throw new \InvalidArgumentException('MEASUREMENT_EVIDENCE_PRIVACY_HOLD:'.implode(',', $unsafeFields));
        }

        return $this->bundles->create($input);
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

        return $query->distinct()->pluck('issue_type')->filter(static fn (mixed $value): bool => is_string($value))->sort()->values()->all();
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

        return $row;
    }
}
