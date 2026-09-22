<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Decision;

use App\Services\SeoIntel\GscDataQualityGate;
use App\Services\SeoIntel\SearchChannelQueue\SearchChannelQueueEligibilityEvaluator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Formal read-model evidence only. No network calls, synchronization or writes. */
final class SeoOpportunityEvidence
{
    public const TYPES = ['high_impressions_low_ctr', 'ranking_4_20'];

    public const DIMENSIONS = ['query', 'page', 'device', 'country'];

    public function __construct(private readonly string $connection = 'seo_intel') {}

    public function target(string $hash, string $locale, CarbonImmutable $now): array
    {
        $db = DB::connection($this->connection);
        $urls = $db->table('seo_urls')->where('canonical_url_hash', $hash)->where('locale', $locale)->get();
        if ($urls->count() !== 1 || ! in_array($locale, ['zh-CN', 'en'], true)) {
            return ['reason' => 'url_truth_missing_or_ambiguous'];
        }
        $url = (array) $urls->first();
        $bindings = $db->table('seo_url_entities')->where('locale', $locale)
            ->where('page_entity_type', $url['page_entity_type'])->where('entity_id_or_slug', $url['entity_id_or_slug'])
            ->where('binding_status', 'current')->get();
        if ($bindings->count() !== 1) {
            return ['reason' => 'authority_missing_or_ambiguous'];
        }
        $binding = (array) $bindings->first();
        foreach (['authority_revision', 'canonical_revision'] as $key) {
            if (! preg_match('/\A[a-f0-9]{64}\z/', (string) ($url[$key] ?? ''))
                || ($url[$key] ?? null) !== ($binding[$key] ?? null)) {
                return ['reason' => 'authority_revision_missing_or_stale'];
            }
        }
        if (($binding['canonical_url_hash'] ?? null) !== $hash || empty($binding['current_binding_key'])
            || ($binding['retired_at'] ?? null) !== null || ($binding['superseded_by_id'] ?? null) !== null
            || ! in_array($binding['authority_status'] ?? null, ['active', 'published', 'published_approved'], true)
            || empty($url['page_family']) || empty($binding['entity_source'])) {
            return ['reason' => 'authority_not_current'];
        }
        $meta = self::json($url['metadata_json'] ?? null);
        $attributes = self::json($binding['attributes_json'] ?? null);
        foreach ([$meta, $attributes] as $flags) {
            foreach (['is_draft', 'redirect_only', 'is_alias', 'frontend_fallback', 'static_sitemap_fallback', 'static_llms_fallback'] as $flag) {
                if ((bool) ($flags[$flag] ?? false)) {
                    return ['reason' => 'non_public_authority'];
                }
            }
            if (($flags['canonical_self'] ?? true) === false || ($flags['publication_state'] ?? '') === 'draft') {
                return ['reason' => 'non_public_authority'];
            }
            foreach (['expires_at', 'valid_until'] as $key) {
                if (isset($flags[$key])) {
                    try {
                        if (CarbonImmutable::parse($flags[$key])->lte($now)) {
                            return ['reason' => 'authority_expired'];
                        }
                    } catch (\Throwable) {
                        return ['reason' => 'authority_expiry_invalid'];
                    }
                }
            }
        }
        $url['entity_source'] = $binding['entity_source'];
        $url['authority_status'] = $binding['authority_status'];
        $gate = (new SearchChannelQueueEligibilityEvaluator)->evaluate($url);
        $path = SearchChannelQueueEligibilityEvaluator::publicPathFromCanonicalUrl($url['canonical_url']);
        if (! $gate->eligible || $path === null || hash('sha256', $url['canonical_url']) !== $hash) {
            return ['reason' => 'page_not_eligible'];
        }

        return ['target' => [
            'canonical_path' => $path, 'canonical_url_hash' => $hash, 'locale' => $locale,
            'page_family' => $url['page_family'], 'authority_revision' => $url['authority_revision'],
            'canonical_revision' => $url['canonical_revision'], 'url_truth_id' => (int) $url['id'],
            'authority_binding_id' => (int) $binding['id'],
        ]];
    }

    /** Candidate queries are frozen before reading the full seven-day evidence. */
    public function evaluate(array $candidates, CarbonImmutable $now, callable $deadline): array
    {
        $deadline();
        $first = $candidates[0];
        $target = $this->target((string) $first['canonical_url_hash'], (string) $first['locale'], $now);
        if (isset($target['reason'])) {
            return $target;
        }
        $queries = array_values(array_unique(array_column($candidates, 'query_hash')));
        sort($queries, SORT_STRING);
        if ($queries === [] || count(array_filter($queries, fn ($q) => is_string($q) && preg_match('/\A[a-f0-9]{64}\z/', $q))) !== count($queries)) {
            return ['reason' => 'query_set_unavailable'];
        }
        $db = DB::connection($this->connection);
        $lastRun = $db->table('seo_gsc_sync_runs')->where('status', 'success')->orderByDesc('end_date')->orderByDesc('finished_at')->first();
        if ($lastRun === null) {
            return ['reason' => 'successful_sync_missing'];
        }
        $latest = (string) $lastRun->end_date;
        $zone = (string) config('seo_intel.gsc_reporting_timezone', 'America/Los_Angeles');
        $end = CarbonImmutable::parse($latest, $zone)->startOfDay();
        $start = $end->subDays(6);
        $lag = max(0, (int) config('seo_intel.gsc_backfill_lag_days', 3));
        $maxAge = (int) config('seo_intel.gsc_data_quality.max_report_age_days', 10);
        if ($maxAge < $lag || $maxAge <= 0) {
            return ['reason' => 'source_freshness_unproven'];
        }
        if ($end->gt($now->setTimezone($zone)->subDays($lag)->startOfDay())) {
            return ['reason' => 'gsc_finalization_lag_not_met'];
        }
        $freshUntil = $end->addDays($maxAge + 1)->utc();
        if ($freshUntil->lte($now)) {
            return ['reason' => 'stale_gsc_report_date'];
        }
        $rows = $db->table('seo_gsc_daily')->where('canonical_url_hash', $first['canonical_url_hash'])
            ->where('locale', $first['locale'])->where('source_engine', 'google')->where('search_type', 'web')
            ->whereIn('query_hash', $queries)->whereBetween('report_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('id')->limit(50001)->get();
        $deadline();
        if ($rows->count() > 50000) {
            return ['reason' => 'evidence_read_limit'];
        }
        if ($rows->isEmpty()) {
            return ['reason' => 'seven_day_rows_missing'];
        }
        $runs = $db->table('seo_gsc_sync_runs')->whereIn('sync_run_uid', $rows->pluck('sync_run_uid')->unique())->get()->keyBy('sync_run_uid');
        $normalized = [];
        $daily = [];
        $sources = [];
        $property = null;
        $clicks = $impressions = $positionWeight = $weightedPosition = 0;
        foreach ($rows as $object) {
            $deadline();
            $r = (array) $object;
            $r['metadata_json'] = self::json($r['metadata_json'] ?? null);
            $run = $runs[$r['sync_run_uid']] ?? null;
            $proof = $this->syncProof($run, (string) $r['report_date']);
            if (isset($proof['reason'])) {
                return $proof;
            }
            if ($property !== null && $property !== $proof['property_hash']) {
                return ['reason' => 'mixed_gsc_properties'];
            }
            $property = $proof['property_hash'];
            if (($r['data_state'] ?? null) !== 'final' || ($r['mapping_state'] ?? null) !== 'mapped'
                || ($r['query_type'] ?? null) !== 'non_brand' || (bool) $r['is_brand_query']
                || ! is_numeric($r['clicks'] ?? null) || ! is_numeric($r['impressions'] ?? null)
                || $r['clicks'] < 0 || $r['impressions'] < 0 || $r['clicks'] > $r['impressions']
                || ! is_string($r['device'] ?? null) || $r['device'] === ''
                || ! is_string($r['country'] ?? null) || $r['country'] === '') {
                return ['reason' => 'metrics_or_dimensions_incomplete'];
            }
            $key = implode('|', [$r['report_date'], $r['query_hash'], $r['device'], $r['country'], 'web']);
            $values = [(int) $r['clicks'], (int) $r['impressions'], $r['average_position_milli'] === null ? null : (int) $r['average_position_milli']];
            $values[] = \App\Services\SeoIntel\GscMetricWeights::fromRow($r, 'positive_position');
            if (isset($normalized[$key])) {
                if ($normalized[$key]['values'] !== $values) {
                    return ['reason' => 'conflicting_source_dimensions'];
                }

                continue;
            }
            $normalized[$key] = ['values' => $values, 'row' => $r];
            $daily[$r['query_hash']][$r['report_date']] = true;
            $clicks += $values[0];
            $impressions += $values[1];
            [$numerator, $denominator] = $values[3];
            $positionWeight += $denominator;
            $weightedPosition += $numerator;
            $sources[] = ['row_id' => (int) $r['id'], 'sync_run_uid' => $r['sync_run_uid'], 'sync_receipt_hash' => $proof['receipt_hash'], 'row_hash' => self::rowHash($r)];
        }
        foreach ($queries as $query) {
            // Absence is unknown, never an invented zero. All seven dates must be observed.
            if (count($daily[$query] ?? []) !== 7) {
                return ['reason' => 'query_date_coverage_incomplete'];
            }
        }
        $gate = (new GscDataQualityGate)->evaluate(array_column($normalized, 'row'), $now->setTimezone($zone));
        if (($gate['status'] ?? '') !== 'pass') {
            return ['reason' => $gate['reasons'][0] ?? 'quality_gate_failed'];
        }
        if ($impressions <= 0 || $positionWeight !== $impressions) {
            return ['reason' => 'ranking_coverage_incomplete'];
        }
        $position = $weightedPosition / $positionWeight / 1000;
        $ctr = $clicks / $impressions;
        $signals = [];
        if ($impressions >= 50 && $ctr <= 0.01) {
            $signals[] = self::TYPES[0];
        }
        if ($impressions >= 50 && $position >= 4 && $position <= 20) {
            $signals[] = self::TYPES[1];
        }
        if ($signals === []) {
            return ['reason' => 'seven_day_threshold_not_met'];
        }
        usort($sources, fn ($a, $b) => $a['row_id'] <=> $b['row_id']);
        $refs = array_values(array_unique(array_column($candidates, 'opportunity_id')));
        sort($refs, SORT_STRING);

        return [
            'target' => $target['target'], 'signals' => $signals, 'candidate_refs' => $refs,
            'query_hashes' => $queries, 'query_set_hash' => hash('sha256', implode('|', $queries)),
            'query_count' => count($queries), 'scope' => 'candidate_query_set_not_page_totals',
            'start_date' => $start->toDateString(), 'end_date' => $end->toDateString(),
            'property_hash' => $property, 'search_type' => 'web', 'dimensions' => self::DIMENSIONS,
            'metrics' => ['clicks' => $clicks, 'impressions' => $impressions, 'ctr' => $ctr,
                'average_position' => $position, 'position_impressions' => $positionWeight, 'position_coverage' => 1.0],
            'sources' => $sources, 'source_fresh_until' => $freshUntil->toIso8601String(),
        ];
    }

    private function syncProof(?object $run, string $date): array
    {
        if ($run === null || $run->status !== 'success' || $run->finished_at === null) {
            return ['reason' => 'successful_sync_missing'];
        }
        $r = self::json($run->receipt_json ?? null);
        $hash = $r['receipt_hash'] ?? '';
        unset($r['receipt_hash']);
        $p = $r['completeness'] ?? [];
        if (($r['schema_version'] ?? '') !== 'seo.gsc_refresh_receipt.v2' || ! is_string($hash) || ! hash_equals($hash, SeoWeeklyDecisionReceiptValidator::hash($r))
            || ($r['sync_run_uid'] ?? null) !== $run->sync_run_uid || ($r['status'] ?? '') !== 'success'
            || ($p['schema_version'] ?? '') !== 'seo.gsc_fetch_completeness.v1'
            || ($p['pagination_complete'] ?? null) !== true || ($p['truncated'] ?? null) !== false
            || ($p['dimensions'] ?? []) !== self::DIMENSIONS || ! in_array('web', $p['search_types'] ?? [], true)
            || ($p['covered_start_date'] ?? '') !== $run->start_date || ($p['covered_end_date'] ?? '') !== $run->end_date
            || $date < $run->start_date || $date > $run->end_date
            || ($r['pages_fetched'] ?? 0) !== (int) $run->pages_fetched || $run->pages_fetched < 1
            || ($r['rows_seen'] ?? -1) !== (int) $run->rows_seen
            || data_get($r, 'quality_gate.status') !== 'pass'
            || ! preg_match('/\A[a-f0-9]{64}\z/', (string) ($r['property_hash'] ?? ''))) {
            return ['reason' => 'sync_completeness_unproven'];
        }

        return ['property_hash' => $r['property_hash'], 'receipt_hash' => $hash];
    }

    public static function rowHash(array $row): string
    {
        $values = [];
        foreach (['report_date', 'canonical_url_hash', 'query_hash', 'locale', 'source_engine', 'device', 'country', 'search_type', 'data_state', 'mapping_state', 'query_type'] as $key) {
            $values[$key] = $row[$key] ?? null;
        }
        foreach (['clicks', 'impressions', 'average_position_milli'] as $key) {
            $values[$key] = isset($row[$key]) ? (int) $row[$key] : null;
        }
        $values['is_brand_query'] = (bool) ($row['is_brand_query'] ?? true);
        $metadata = self::json($row['metadata_json'] ?? null);
        $values['data_origin'] = $metadata['data_origin'] ?? null;
        if (($metadata['_canonical_metric_weights']['version'] ?? null) === 1) {
            $values['canonical_position_weights'] = \App\Services\SeoIntel\GscMetricWeights::fromRow($row, 'positive_position');
        }

        return SeoWeeklyDecisionReceiptValidator::hash($values);
    }

    public static function json(mixed $json): array
    {
        return is_array($json) ? $json : (is_string($json) ? (json_decode($json, true) ?: []) : []);
    }
}
