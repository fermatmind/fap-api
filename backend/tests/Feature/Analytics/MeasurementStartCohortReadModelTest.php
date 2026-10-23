<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Services\Analytics\MeasurementStartCohortReadModel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SeedsFunnelAnalyticsScenario;
use Tests\TestCase;

final class MeasurementStartCohortReadModelTest extends TestCase
{
    use RefreshDatabase;
    use SeedsFunnelAnalyticsScenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-23T04:00:00Z'));
    }

    public function test_fixed_start_membership_cross_day_d12_duplicates_and_privacy_are_recomputable(): void
    {
        $start = CarbonImmutable::parse('2026-10-01T15:50:00Z');
        $a = (string) Str::uuid();
        $this->insertAttempt($a, 81, 'en', $start, $start->addHours(1));
        $this->insertResult($a, 81, $start->addHours(2));
        $this->visible($a, $start->addHours(3));
        $this->visible($a, $start->addHours(4));
        $outside = (string) Str::uuid();
        $this->insertAttempt($outside, 81, 'en', $start->addMinutes(10), $start->addMinutes(12));
        $late = (string) Str::uuid();
        $this->insertAttempt($late, 81, 'en', $start->subHour(), $start->subHour()->addDays(12));
        $this->insertResult($late, 81, $start->subHour()->addDays(12)->addMinute());
        $pending = (string) Str::uuid();
        $this->insertAttempt($pending, 81, 'en', $start->subHours(2), null);
        $qa = (string) Str::uuid();
        $this->insertAttempt($qa, 81, 'en', $start->subHours(3), null);
        DB::table('attempts')->where('id', $qa)->update(['anon_id' => 'codex_probe_cohort']);
        $this->visible(null, $start->addMinute());
        $report = $this->report();
        $this->assertTrue($report['ok']);
        $this->assertSame(3, $report['totals']['started_count']);
        $this->assertSame(2, $report['totals']['submit_succeeded_cumulative_count']);
        $this->assertSame(1, $report['totals']['submit_succeeded_d7_count']);
        $this->assertSame(2, $report['totals']['result_ready_cumulative_count']);
        $this->assertSame(1, $report['totals']['result_ready_d7_count']);
        $this->assertSame(1, $report['totals']['result_visible_d7_count']);
        $this->assertSame(1, $report['coverage']['visible_duplicate_events']);
        $this->assertSame(1, $report['coverage']['visible_unlinked_events']);
        $this->assertSame(1, $report['coverage']['excluded_qa_attempts']);
        $this->assertSame(3, $report['rates']['submit_from_started_d7']['denominator']);
        $this->assertSame(1, $report['rates']['ready_from_submit_d7']['denominator']);
        $this->assertSame('window_elapsed_provisional', $report['maturity']['status']);
        $this->assertNull($report['maturity']['processing_grace_hours']);
        $this->assertFalse($report['maturity']['finalized']);
        foreach ([$a, $outside, $late, $pending, 'anon_', 'scores_json'] as $private) {
            $this->assertStringNotContainsString($private, json_encode($report));
        }
        $this->assertSame($report['snapshot_version'], $this->report()['snapshot_version']);
    }

    public function test_exact_168_hour_boundary_is_inclusive_but_later_completion_is_cumulative_only(): void
    {
        $start = CarbonImmutable::parse('2026-10-01T01:00:00Z');
        foreach ([0, 1] as $seconds) {
            $id = (string) Str::uuid();
            $at = $start->addHours(168)->addSeconds($seconds);
            $this->insertAttempt($id, 81, 'en', $start, $at);
            $this->insertResult($id, 81, $at);
            $this->visible($id, $at);
        }
        $report = $this->report();
        foreach (['submit_succeeded', 'result_ready', 'result_visible'] as $stage) {
            $this->assertSame(2, $report['totals'][$stage.'_cumulative_count']);
            $this->assertSame(1, $report['totals'][$stage.'_d7_count']);
        }
    }

    public function test_late_in_window_server_fact_and_missing_start_can_revise_snapshot_with_explicit_delta(): void
    {
        $start = CarbonImmutable::parse('2026-10-01T01:00:00Z');
        $a = (string) Str::uuid();
        $this->insertAttempt($a, 81, 'en', $start, $start->addHour());
        $this->insertResult($a, 81, $start->addHours(2));
        DB::table('results')->where('attempt_id', $a)->update(['created_at' => '2026-10-10 00:00:00']);
        $b = (string) Str::uuid();
        $this->insertAttempt($b, 81, 'en', $start->addHour(), null);
        DB::table('attempts')->where('id', $b)->update(['created_at' => '2026-10-10 00:00:00']);
        $previous = $this->report('2026-10-09T00:00:00Z');
        $this->assertSame(1, $previous['totals']['started_count']);
        $this->assertSame(0, $previous['totals']['result_ready_d7_count']);
        $revised = app(MeasurementStartCohortReadModel::class)->report(81, '2026-10-01', '2026-10-01', ['MBTI'], ['en'], '2026-10-15T00:00:00Z', $previous);
        $this->assertTrue($revised['ok']);
        $this->assertSame(1, $revised['revision']['delta']['started_count']);
        $this->assertSame(1, $revised['revision']['delta']['result_ready_d7_count']);
        $this->assertSame(1, $revised['revision']['late_arrival_evidence']['late_received_starts_since_previous']);
        $this->assertSame(1, $revised['revision']['late_arrival_evidence']['late_received_results_since_previous']);
        $this->assertSame($previous['snapshot_version'], $revised['revision']['previous_snapshot_version']);
        $this->assertNotSame($previous['snapshot_version'], $revised['snapshot_version']);
        $this->assertSame($this->report()['snapshot_version'], $revised['snapshot_version']);
        $this->assertFalse(app(MeasurementStartCohortReadModel::class)->report(82, '2026-10-01', '2026-10-01', [], [], null, $previous)['ok']);
    }

    public function test_last_entrant_has_full_window_and_unknown_visibility_is_not_unread(): void
    {
        $start = CarbonImmutable::parse('2026-10-07T15:59:59Z');
        $a = (string) Str::uuid();
        $this->insertAttempt($a, 81, 'en', $start, $start->addMinute());
        $this->insertResult($a, 81, $start->addMinutes(2));
        $this->visible($a, $start->addMinutes(3), false);
        $this->visible($a, $start->addMinutes(4), true, 'denied');
        $report = app(MeasurementStartCohortReadModel::class)->report(81, '2026-10-01', '2026-10-07', [], [], '2026-10-08T00:00:00Z');
        $this->assertSame(1, $report['totals']['window_open_count']);
        $this->assertNull($report['rates']['ready_from_started_d7']['value']);
        $this->assertSame(0, $report['coverage']['visible_clock_unverified']);
        $this->assertSame(2, $report['coverage']['visible_consent_unknown']);
        $this->assertSame(0, $report['totals']['result_visible_cumulative_count']);
        $this->assertSame(1, $report['totals']['visible_not_observed_or_unverifiable_count']);
        $this->assertSame('2026-10-14T16:00:00+00:00', $report['maturity']['last_entrant_full_window_at']);
    }

    public function test_official_command_reads_existing_authorities_and_compares_saved_aggregate_without_writes(): void
    {
        $id = (string) Str::uuid();
        $this->insertAttempt($id, 81, 'en', CarbonImmutable::parse('2026-10-01T01:00:00Z'), null);
        $file = tempnam(sys_get_temp_dir(), 'cohort-');
        try {
            $args = ['--org' => '81', '--from' => '2026-10-01', '--to' => '2026-10-01', '--cohort' => true, '--as-of' => '2026-10-15T00:00:00Z', '--json' => true];
            $this->assertSame(0, Artisan::call('analytics:measurement-funnel-report', $args));
            $snapshot = Artisan::output();
            $this->assertSame(1, json_decode($snapshot, true)['totals']['started_count']);
            $this->assertArrayHasKey('calendar_activity', json_decode($snapshot, true));
            file_put_contents($file, $snapshot);
            $this->assertSame(0, Artisan::call('analytics:measurement-funnel-report', $args + ['--previous-snapshot' => $file]));
            $this->assertSame(0, json_decode(Artisan::output(), true)['revision']['delta']['started_count']);
            $this->assertSame(1, DB::table('attempts')->count());
            $this->assertSame(0, DB::table('events')->count());
            $this->assertSame(1, Artisan::call('analytics:measurement-funnel-report', array_replace($args, ['--from' => 'bad'])));
        } finally {
            unlink($file);
        }
    }

    public function test_real_canonical_receiver_rejects_raw_attempt_link_and_read_model_keeps_visibility_unknown(): void
    {
        $id = (string) Str::uuid();
        $start = CarbonImmutable::parse('2026-10-22T01:00:00Z');
        $this->insertAttempt($id, 0, 'en', $start, $start->addMinute());
        $this->insertResult($id, 0, $start->addMinutes(2));
        config()->set('fap.events.ingest_token', 'cohort_test_token');
        $event = ['eventName' => 'view_result', 'anonymousId' => 'cohort_browser_fixture',
            'path' => '/en/tests/mbti-personality-test-16-personality-types', 'timestamp' => '2031-01-01T00:00:00Z',
            'payload' => ['locale' => 'en', 'scale_code' => 'MBTI', 'attempt_id' => $id, 'consent_state' => 'granted']];
        $this->withHeaders(['Authorization' => 'Bearer cohort_test_token'])
            ->postJson('/api/v0.5/seo/attribution/events', $event)->assertStatus(422)->assertJsonStructure(['details' => ['payload.attempt_id']]);
        unset($event['payload']['attempt_id']);
        $this->postJson('/api/v0.5/seo/attribution/events', $event)->assertStatus(202);
        $report = app(MeasurementStartCohortReadModel::class)->report(0, '2026-10-22', '2026-10-23', [], [], '2026-10-23T04:00:00Z');
        $this->assertSame(0, $report['totals']['result_visible_d7_count']);
        $this->assertSame(1, $report['coverage']['visible_unlinked_events']);
        $this->assertSame(1, $report['totals']['visible_not_observed_or_unverifiable_count']);
        $this->assertNull($report['rates']['visible_from_started_d7']['value']);
    }

    public function test_native_interval_crossing_d7_is_unknown_and_late_ack_is_not_backdated(): void
    {
        $start = CarbonImmutable::parse('2026-10-01T01:00:00Z');
        foreach (['inside', 'crossing', 'outside'] as $kind) {
            $id = (string) Str::uuid();
            $this->insertAttempt($id, 81, 'en', $start, $start->addHour());
            $this->insertResult($id, 81, $start->addHours(2));
            $read = $start->addHours($kind === 'outside' ? 169 : 167);
            $ack = $start->addHours($kind === 'inside' ? 168 : 169);
            DB::table('events')->insert(['id' => (string) Str::uuid(), 'org_id' => 81,
                'event_code' => 'result_visible_observed', 'event_name' => 'result_visible_observed', 'attempt_id' => $id,
                'occurred_at' => $ack, 'created_at' => $ack, 'meta_json' => json_encode(['measurement_observation' => [
                    'version' => 'result-visible.owner-interval.v1', 'consent_state' => 'granted', 'server_read_at' => $read->toISOString(), 'server_received_at' => $ack->toISOString(),
                ]])]);
        }
        $report = $this->report();
        $this->assertSame(3, $report['totals']['result_visible_cumulative_count']);
        $this->assertSame(1, $report['totals']['result_visible_d7_count']);
        $this->assertSame(1, $report['totals']['visible_d7_boundary_unknown_count']);
        $this->assertNull($report['rates']['visible_from_started_d7']['value']);
        $this->assertSame('measurement.start-cohort.168h.v2', $report['definition_version']);
    }

    private function report(string $cutoff = '2026-10-15T00:00:00Z'): array
    {
        return app(MeasurementStartCohortReadModel::class)->report(81, '2026-10-01', '2026-10-01', ['MBTI'], ['en'], $cutoff);
    }

    private function visible(?string $attemptId, CarbonImmutable $at, bool $trusted = true, string $consent = 'granted'): void
    {
        $meta = $trusted ? ['measurement_observation' => ['version' => 'server-received-observation.v1', 'server_received_at' => $at->toIso8601String(), 'consent_state' => $consent]] : [];
        DB::table('events')->insert(['id' => (string) Str::uuid(), 'org_id' => 81, 'event_name' => 'view_result', 'event_code' => 'view_result',
            'attempt_id' => $attemptId, 'occurred_at' => '2031-01-01 00:00:00', 'created_at' => $at, 'meta_json' => json_encode($meta)]);
    }
}
