<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/** A start-bound view of the existing attempt/result/event authorities. */
final class MeasurementStartCohortReadModel
{
    public const VERSION = 'measurement.start-cohort.168h.v2';

    public function __construct(
        private readonly MeasurementAttributionDimensions $dimensions,
        private readonly AnalyticsTrafficExclusionPolicy $exclusions,
        private readonly AnalyticsFunnelDailyBuilder $windows,
    ) {}

    public function report(int $orgId, string $from, string $to, array $scales = [], array $locales = [], ?string $asOf = null, ?array $previous = null): array
    {
        try {
            $start = CarbonImmutable::createFromFormat('!Y-m-d', $from, 'Asia/Shanghai');
            $end = CarbonImmutable::createFromFormat('!Y-m-d', $to, 'Asia/Shanghai');
            $cutoff = $asOf === null ? CarbonImmutable::now('UTC') : CarbonImmutable::parse($asOf)->utc();
            if ($orgId < 0 || $start->format('Y-m-d') !== $from || $end->format('Y-m-d') !== $to
                || $start->greaterThan($end) || $start->diffInDays($end) > 92 || $cutoff->isFuture()) {
                throw new \InvalidArgumentException;
            }
        } catch (Throwable) {
            return ['ok' => false, 'schema_version' => self::VERSION, 'issues' => ['invalid_cohort_scope'], 'read_only' => true];
        }
        $window = $this->windows->reportingWindow($start, $end);
        $fromAt = CarbonImmutable::parse($window['window_utc_start']);
        $toAt = CarbonImmutable::parse($window['window_utc_end_exclusive']);
        $scales = array_values(array_unique(array_map(static fn ($value) => strtoupper(trim((string) $value)), $scales)));
        $locales = array_values(array_unique(array_map(static fn ($value) => in_array(strtolower((string) $value), ['zh', 'zh-cn'], true) ? 'zh-CN' : (string) $value, $locales)));
        sort($scales);
        sort($locales);
        $scope = ['org_id' => $orgId, 'from' => $from, 'to' => $to, 'scales' => $scales, 'locales' => $locales];
        $scopeHash = hash('sha256', json_encode($scope));
        if ($previous !== null && (! in_array($previous['schema_version'] ?? null, [self::VERSION, 'measurement.start-cohort.168h.v1'], true)
            || ($previous['scope_hash'] ?? null) !== $scopeHash || ! isset($previous['as_of'], $previous['snapshot_version'], $previous['totals']))) {
            return ['ok' => false, 'schema_version' => self::VERSION, 'issues' => ['incompatible_previous_snapshot'], 'read_only' => true];
        }
        $previousCutoff = $previous === null ? null : CarbonImmutable::parse($previous['as_of']);
        if ($previousCutoff !== null && $previousCutoff->greaterThan($cutoff)) {
            return ['ok' => false, 'issues' => ['previous_snapshot_is_newer'], 'read_only' => true];
        }
        $query = DB::table('attempts')->where('org_id', $orgId)
            ->where('started_at', '>=', $fromAt)->where('started_at', '<', $toAt)
            ->where('started_at', '<=', $cutoff)->where('created_at', '<=', $cutoff);
        if ($scales !== []) {
            $query->whereIn('scale_code', $scales);
        }
        if ($locales !== []) {
            $query->whereIn('locale', array_values(array_unique(array_merge($locales, in_array('zh-CN', $locales, true) ? ['zh', 'zh-cn'] : []))));
        }
        $attempts = $query->select(['id', 'org_id', 'anon_id', 'started_at', 'submitted_at', 'created_at', 'updated_at',
            'scale_code', 'locale', 'pack_id', 'dir_version', 'answers_summary_json', 'channel', 'client_platform'])->orderBy('id')->get();
        $rows = [];
        $source = hash_init('sha256');
        $coverage = ['unknown_form_attempts' => 0, 'unknown_pack_attempts' => 0, 'excluded_qa_attempts' => 0, 'visible_unlinked_events' => 0, 'visible_clock_unverified' => 0,
            'visible_consent_unknown' => 0, 'visible_duplicate_events' => 0, 'invalid_stage_chronology' => 0,
            'late_received_starts_since_previous' => 0, 'late_received_results_since_previous' => 0, 'late_received_visible_since_previous' => 0];
        $ids = [];
        foreach ($attempts as $attempt) {
            if ($this->exclusions->isExcludedAttemptRow($attempt)) {
                $coverage['excluded_qa_attempts']++;

                continue;
            }
            $ids[$attempt->id] = $attempt;
        }
        // Exact attempt/org joins only. Never reconstruct a person from a
        // search query, anonymous cookie, provider aggregate or private URL.
        $results = [];
        $visible = [];
        foreach (array_chunk(array_keys($ids), 500) as $chunk) {
            foreach (DB::table('results')->where('org_id', $orgId)->whereIn('attempt_id', $chunk)
                ->where('is_valid', true)->where('created_at', '<=', $cutoff)->orderBy('computed_at')->get(['attempt_id', 'computed_at', 'created_at']) as $result) {
                $results[$result->attempt_id] ??= $result;
            }
            foreach (DB::table('events')->where('org_id', $orgId)->whereIn('attempt_id', $chunk)
                ->whereIn('event_code', ['view_result', ResultVisibilityObservation::EVENT])->where('created_at', '<=', $cutoff)->orderBy('created_at')
                ->get(['id', 'attempt_id', 'anon_id', 'session_id', 'request_id', 'event_code', 'occurred_at', 'created_at', 'meta_json']) as $event) {
                $meta = json_decode((string) $event->meta_json, true) ?: [];
                if ($this->exclusions->isExcludedSeoConversionEvent($event, $meta)
                    || in_array(data_get($meta, 'raw_payload.traffic_quality'), ['qa', 'internal', 'bot'], true)) {
                    continue;
                }
                $observation = $meta['measurement_observation'] ?? [];
                $consent = $observation['consent_state'] ?? data_get($meta, 'raw_payload.consent_state', 'unknown');
                if ($consent !== 'granted') {
                    $coverage['visible_consent_unknown']++;

                    continue;
                }
                $at = $this->time($event->created_at);
                if ($at === null || $at->greaterThan($cutoff)) {
                    $coverage['visible_clock_unverified']++;

                    continue;
                }
                $readAt = $at;
                if ($event->event_code === ResultVisibilityObservation::EVENT) {
                    $readAt = $this->time($observation['server_read_at'] ?? null);
                    $at = $this->time($observation['server_received_at'] ?? null);
                    if (($observation['version'] ?? null) !== ResultVisibilityObservation::VERSION
                        || $readAt === null || $at === null || $at->greaterThan($cutoff) || $readAt->greaterThan($at)) {
                        $coverage['visible_clock_unverified']++;

                        continue;
                    }
                }
                if (isset($visible[$event->attempt_id])) {
                    $coverage['visible_duplicate_events']++;
                    if ($at->greaterThanOrEqualTo($visible[$event->attempt_id]['at'])) {
                        continue;
                    }
                }
                $visible[$event->attempt_id] = ['at' => $at, 'read_at' => $readAt, 'received' => $this->time($event->created_at)];
            }
        }
        foreach ($ids as $attemptId => $attempt) {
            $started = $this->time($attempt->started_at);
            $submitted = $this->time($attempt->submitted_at);
            $result = $results[$attemptId] ?? null;
            $ready = $this->time($result->computed_at ?? null);
            $seen = $visible[$attemptId]['at'] ?? null;
            $readAt = $visible[$attemptId]['read_at'] ?? null;
            $deadline = $started->addHours(168);
            $elapsed = $deadline->lessThanOrEqualTo($cutoff);
            $dimensions = $this->dimensions->aggregateDimensions($this->dimensions->fromAttempt($attempt));
            $dimensions['pack_id'] = $this->token($attempt->pack_id);
            $dimensions['dir_version'] = $this->token($attempt->dir_version);
            $meta = json_decode((string) $attempt->answers_summary_json, true) ?: [];
            $dimensions['quality_version'] = $this->token(data_get($meta, 'meta.quality_version'));
            $dimensions['measurement_contract_version'] = $this->token(data_get($meta, 'meta.measurement_contract_version'));
            $coverage['unknown_form_attempts'] += $dimensions['form_code'] === 'unknown' ? 1 : 0;
            $coverage['unknown_pack_attempts'] += $dimensions['pack_id'] === 'unknown' ? 1 : 0;
            $dimensions['start_day'] = $started->setTimezone('Asia/Shanghai')->toDateString();
            $key = json_encode($dimensions);
            $rows[$key] ??= $dimensions + $this->emptyMetrics();
            $row = &$rows[$key];
            $row['started_count']++;
            $row[$elapsed ? 'window_elapsed_count' : 'window_open_count']++;
            $validSubmit = $submitted !== null && $submitted->greaterThanOrEqualTo($started) && $submitted->lessThanOrEqualTo($cutoff);
            $validReady = $validSubmit && $ready !== null && $ready->greaterThanOrEqualTo($submitted) && $ready->lessThanOrEqualTo($cutoff);
            $validVisible = $validReady && $seen !== null && $readAt->greaterThanOrEqualTo($ready) && $seen->lessThanOrEqualTo($cutoff);
            if (($submitted !== null && ! $validSubmit && $submitted->lessThanOrEqualTo($cutoff))
                || ($ready !== null && ! $validReady && $ready->lessThanOrEqualTo($cutoff))
                || ($seen !== null && ! $validVisible)) {
                $coverage['invalid_stage_chronology']++;
            }
            foreach (['submit_succeeded' => [$submitted, $validSubmit], 'result_ready' => [$ready, $validReady], 'result_visible' => [$seen, $validVisible]] as $stage => [$at, $valid]) {
                if (! $valid) {
                    continue;
                }
                $row[$stage.'_cumulative_count']++;
                if ($at->lessThanOrEqualTo($deadline)) {
                    $row[$stage.'_d7_count']++;
                    if ($elapsed) {
                        $row[$stage.'_elapsed_d7_count']++;
                    }
                }
            }
            if ($validVisible && $readAt->lessThanOrEqualTo($deadline) && $seen->greaterThan($deadline)) {
                $row['visible_d7_boundary_unknown_count']++;
            }
            $row['visible_not_observed_or_unverifiable_count'] += $validVisible ? 0 : 1;
            foreach (['starts' => [$started, $this->time($attempt->created_at)], 'results' => [$ready, $this->time($result->created_at ?? null)], 'visible' => [$seen, $visible[$attemptId]['received'] ?? null]] as $kind => [$businessAt, $receivedAt]) {
                if ($previousCutoff !== null && $businessAt !== null && $receivedAt !== null
                    && $businessAt->lessThanOrEqualTo($previousCutoff) && $receivedAt->greaterThan($previousCutoff)) {
                    $coverage['late_received_'.$kind.'_since_previous']++;
                }
            }
            hash_update($source, json_encode([$attemptId, $dimensions, $started, $submitted, $ready, $seen, $readAt]));
            unset($row);
        }
        $coverage['visible_unlinked_events'] = DB::table('events')->where('org_id', $orgId)->where('event_code', 'view_result')
            ->where('created_at', '>=', $fromAt)->where('created_at', '<', $toAt)->where('created_at', '<=', $cutoff)
            ->where(function ($q) use ($orgId): void {
                $q->whereNull('attempt_id')->orWhereNotExists(function ($a) use ($orgId): void {
                    $a->selectRaw('1')->from('attempts')->whereColumn('attempts.id', 'events.attempt_id')->where('attempts.org_id', $orgId);
                });
            })->count();
        ksort($rows);
        $totals = $this->emptyMetrics();
        foreach ($rows as $row) {
            foreach (array_keys($totals) as $metric) {
                $totals[$metric] += $row[$metric];
            }
        }
        $rates = [
            'submit_from_started_d7' => $this->ratio($totals['submit_succeeded_elapsed_d7_count'], $totals['window_elapsed_count']),
            'ready_from_started_d7' => $this->ratio($totals['result_ready_elapsed_d7_count'], $totals['window_elapsed_count']),
            'visible_from_started_d7' => $this->ratio($totals['result_visible_elapsed_d7_count'], $totals['window_elapsed_count']),
            'ready_from_submit_d7' => $this->ratio($totals['result_ready_elapsed_d7_count'], $totals['submit_succeeded_elapsed_d7_count']),
            'visible_from_ready_d7' => $this->ratio($totals['result_visible_elapsed_d7_count'], $totals['result_ready_elapsed_d7_count']),
        ];
        foreach (['visible_from_started_d7', 'visible_from_ready_d7'] as $metric) {
            $rates[$metric]['observed_lower_bound'] = $rates[$metric]['value'];
            if ($totals['visible_not_observed_or_unverifiable_count'] > 0 || $totals['visible_d7_boundary_unknown_count'] > 0) {
                $rates[$metric]['value'] = null;
                $rates[$metric]['status'] = 'visibility_coverage_incomplete_not_a_nonreading_rate';
            }
        }
        $sourceHash = hash_final($source);
        $lateEvidence = [];
        foreach (array_keys($coverage) as $metric) {
            if (str_starts_with($metric, 'late_received_')) {
                $lateEvidence[$metric] = $coverage[$metric];
                unset($coverage[$metric]);
            }
        }
        $delta = [];
        if ($previous !== null) {
            foreach ($totals as $metric => $value) {
                $delta[$metric] = $value - (int) ($previous['totals'][$metric] ?? 0);
            }
        }

        return [
            'ok' => true, 'status' => 'provisional', 'schema_version' => self::VERSION, 'definition_version' => self::VERSION,
            'scope' => $scope, 'scope_hash' => $scopeHash, 'as_of' => $cutoff->toISOString(),
            'snapshot_version' => hash('sha256', json_encode([self::VERSION, $scopeHash, $cutoff->toISOString(), $sourceHash, $totals, $coverage])),
            'source_fingerprint' => $sourceHash, 'source_tables' => ['attempts', 'results', 'events'],
            'analysis_unit' => 'distinct_server_persisted_attempt_id_started_in_half_open_interval',
            'window_hours' => 168, 'deadline_boundary' => 'started_at_plus_168h_inclusive',
            'reporting_timezone' => $window['reporting_timezone'], 'window_utc_start' => $window['window_utc_start'], 'window_utc_end_exclusive' => $window['window_utc_end_exclusive'],
            'quality_policy' => 'include_all_eligible_starts_do_not_condition_denominator_on_future_result_quality',
            'stage_definitions' => ['submit_succeeded' => 'durable_attempt_submitted_at', 'result_ready' => 'valid_result_computed_at_after_submission', 'result_visible' => 'consented_owner_native_read_to_ack_interval_or_legacy_browser_receipt_after_ready_not_API_read'],
            'unlinked_coverage_scope' => 'visible_events_received_in_selected_calendar_interval_not_assigned_to_cohort',
            'clock_policy' => ['start_submit_ready' => 'server_business_timestamps', 'visible' => 'native_server_read_to_ack_interval_D7_upper_bound_or_legacy_receipt_not_client_clock', 'received' => 'created_at', 'processed' => 'not_independently_recorded'],
            'maturity' => ['status' => $totals['started_count'] === 0 ? 'no_observed_eligible_starts' : ($totals['window_open_count'] > 0 ? 'window_open' : 'window_elapsed_provisional'),
                'last_entrant_full_window_at' => $toAt->addHours(168)->toIso8601String(), 'processing_grace_hours' => null,
                'processing_grace_evidence' => 'no_verified_ingest_watermark_or_delay_bound', 'finalized' => false],
            'rows' => array_values($rows), 'totals' => $totals, 'rates' => $rates, 'coverage' => $coverage,
            'revision' => ['previous_snapshot_version' => $previous['snapshot_version'] ?? null, 'delta' => $delta,
                'late_arrival_evidence' => $lateEvidence,
                'reason' => $previous !== null && $previous['schema_version'] !== self::VERSION ? 'definition_changed_to_owner_interval_v2' : ($previous === null ? 'initial_observation' : ($sourceHash === ($previous['source_fingerprint'] ?? null) ? 'window_elapsed_or_cutoff_changed' : 'source_facts_changed_recomputed')),
                'denominator_revisable' => true, 'unclassified_correction_cause' => $previous !== null && $delta !== [] && $sourceHash !== ($previous['source_fingerprint'] ?? null) ? 'not_inferable_without_source_history' : null],
            'limitations' => ['missing_visible_observation_does_not_prove_unread', 'canonical_SEO_events_have_no_authoritative_attempt_link', 'D12_completion_is_cumulative_only', 'late_within_window_server_facts_can_revise_snapshots'],
            'read_only' => true,
        ];
    }

    private function emptyMetrics(): array
    {
        $metrics = ['started_count' => 0, 'window_elapsed_count' => 0, 'window_open_count' => 0, 'visible_d7_boundary_unknown_count' => 0, 'visible_not_observed_or_unverifiable_count' => 0];
        foreach (['submit_succeeded', 'result_ready', 'result_visible'] as $stage) {
            foreach (['cumulative_count', 'd7_count', 'elapsed_d7_count'] as $suffix) {
                $metrics[$stage.'_'.$suffix] = 0;
            }
        }

        return $metrics;
    }

    private function time(mixed $value): ?CarbonImmutable
    {
        try {
            return $value === null || $value === '' ? null : CarbonImmutable::parse($value, 'UTC')->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function token(mixed $value): string
    {
        return is_string($value) && preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/', $value) ? $value : 'unknown';
    }

    private function ratio(int $numerator, int $denominator): array
    {
        return ['numerator' => $numerator, 'denominator' => $denominator, 'value' => $denominator === 0 ? null : $numerator / $denominator,
            'status' => 'provisional_elapsed_windows_only'];
    }
}
