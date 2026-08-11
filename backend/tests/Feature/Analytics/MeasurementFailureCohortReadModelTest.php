<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Services\Analytics\MeasurementFailureCohortReadModel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\SeedsFunnelAnalyticsScenario;
use Tests\TestCase;

final class MeasurementFailureCohortReadModelTest extends TestCase
{
    use RefreshDatabase;
    use SeedsFunnelAnalyticsScenario;

    public function test_read_model_uses_distinct_eligible_attempts_retries_and_eventual_success(): void
    {
        $day = CarbonImmutable::parse('2026-08-10 08:00:00');
        $attemptIds = [];
        for ($index = 0; $index < 6; $index++) {
            $attemptId = (string) Str::uuid();
            $attemptIds[] = $attemptId;
            $submittedAt = $index < 5 ? $day->addHours(2) : null;
            $this->insertAttempt($attemptId, 81, 'en', $day, $submittedAt);
            DB::table('attempts')->where('id', $attemptId)->update([
                'answers_summary_json' => json_encode(['meta' => ['form_code' => 'mbti_93']]),
            ]);
            if ($index < 5) {
                $this->insertResult($attemptId, 81, $day->addHours(2));
            }
            $this->insertFailureEvent($attemptId, $day->addHour(), 'submit_failure');
            $this->insertFailureEvent($attemptId, $day->addHour()->addMinutes(5), 'submit_failure');
        }
        $this->insertFailureEvent($attemptIds[0], $day->addHour()->addMinutes(5), 'submit_failure');

        $before = $this->tableCounts();
        $report = app(MeasurementFailureCohortReadModel::class)->report(81, '2026-08-10', '2026-08-10');

        $this->assertTrue($report['ok'] ?? false);
        $this->assertSame('warning', $report['status'] ?? null);
        $this->assertSame(6, data_get($report, 'cohorts.submit_failure.eligible_attempt_count'));
        $this->assertSame(6, data_get($report, 'cohorts.submit_failure.failed_attempt_count'));
        $this->assertSame(6, data_get($report, 'cohorts.submit_failure.first_failure_attempt_count'));
        $this->assertSame(6, data_get($report, 'cohorts.submit_failure.retrying_attempt_count'));
        $this->assertSame(5, data_get($report, 'cohorts.submit_failure.eventual_success_attempt_count'));
        $this->assertSame(1, data_get($report, 'cohorts.submit_failure.terminal_failure_attempt_count'));
        $this->assertSame(13, data_get($report, 'cohorts.submit_failure.failure_event_count'));
        $this->assertSame(1, data_get($report, 'cohorts.submit_failure.duplicate_failure_event_count'));
        $this->assertSame(0, data_get($report, 'cohorts.submit_failure.unattributed_failure_event_count'));
        $this->assertSame(6, data_get($report, 'cohorts.submit_failure.retry_count'));
        $this->assertSame(1.0, data_get($report, 'cohorts.submit_failure.eligible_failure_rate'));
        $this->assertSame(0.833333, data_get($report, 'cohorts.submit_failure.eventual_success_rate'));
        $this->assertSame(3600, data_get($report, 'cohorts.submit_failure.recovery_time_p50_seconds'));
        $this->assertSame(3600, data_get($report, 'cohorts.submit_failure.recovery_time_p95_seconds'));
        $this->assertSame('complete', data_get($report, 'cohorts.submit_failure.coverage_status'));
        $this->assertSame(1, $report['row_count'] ?? null);
        $this->assertFalse((bool) data_get($report, 'rows.0.suppressed'));
        $this->assertSame('one', data_get($report, 'rows.0.dimensions.retry_bucket'));
        $this->assertSame($before, $this->tableCounts());

        $encoded = json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        foreach ([$attemptIds[0], 'request-private', 'anon-private', 'token=secret', 'answers', 'scores'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
    }

    public function test_questions_load_failure_recovers_only_at_a_later_backend_start_boundary(): void
    {
        $day = CarbonImmutable::parse('2026-08-10 08:00:00');
        $attemptId = (string) Str::uuid();
        $this->insertAttempt($attemptId, 82, 'en', $day->addHours(2), null);
        $this->insertFailureEvent($attemptId, $day->addHour(), 'questions_load_failure', orgId: 82);

        $report = app(MeasurementFailureCohortReadModel::class)->report(82, '2026-08-10', '2026-08-10');

        $this->assertSame(1, data_get($report, 'cohorts.questions_load_failure.eventual_success_attempt_count'));
        $this->assertSame(0, data_get($report, 'cohorts.questions_load_failure.terminal_failure_attempt_count'));
        $this->assertSame(3600, data_get($report, 'cohorts.questions_load_failure.recovery_time_p50_seconds'));
    }

    public function test_unattributed_events_are_partial_and_do_not_create_a_fake_rate(): void
    {
        $day = CarbonImmutable::parse('2026-08-10 08:00:00');
        $attemptId = (string) Str::uuid();
        $this->insertAttempt($attemptId, 82, 'en', $day, null);
        $this->insertFailureEvent(null, $day->addHour(), 'questions_load_failure', orgId: 82);

        $report = app(MeasurementFailureCohortReadModel::class)->report(82, '2026-08-10', '2026-08-10');

        $this->assertSame('warning', $report['status'] ?? null);
        $this->assertSame(1, data_get($report, 'cohorts.questions_load_failure.eligible_attempt_count'));
        $this->assertSame(0, data_get($report, 'cohorts.questions_load_failure.failed_attempt_count'));
        $this->assertSame(1, data_get($report, 'cohorts.questions_load_failure.unattributed_failure_event_count'));
        $this->assertSame('insufficient_correlation', data_get($report, 'cohorts.questions_load_failure.coverage_status'));
        $this->assertNull(data_get($report, 'cohorts.questions_load_failure.eligible_failure_rate'));
    }

    public function test_event_dimension_filters_keep_the_eligible_denominator_unknown(): void
    {
        $day = CarbonImmutable::parse('2026-08-10 08:00:00');
        $attemptId = (string) Str::uuid();
        $this->insertAttempt($attemptId, 83, 'en', $day, $day->addHours(2));
        $this->insertFailureEvent($attemptId, $day->addHour(), 'submit_failure', orgId: 83);

        $report = app(MeasurementFailureCohortReadModel::class)->report(83, '2026-08-10', '2026-08-10', [
            'status_group' => ['server_5xx'],
        ]);

        $this->assertTrue($report['ok'] ?? false);
        $this->assertNull(data_get($report, 'cohorts.submit_failure.eligible_attempt_count'));
        $this->assertNull(data_get($report, 'cohorts.submit_failure.eligible_failure_rate'));
        $this->assertContains('eligible_denominator_unavailable_for_event_dimension_filters', $report['issues'] ?? []);
        $this->assertTrue((bool) data_get($report, 'rows.0.suppressed'));
        $this->assertNull(data_get($report, 'rows.0.metrics'));
        $this->assertSame(['suppressed' => 'suppressed'], data_get($report, 'rows.0.dimensions'));
    }

    public function test_all_supported_filters_are_applied_without_fuzzy_correlation(): void
    {
        $day = CarbonImmutable::parse('2026-08-10 08:00:00');
        $attemptId = (string) Str::uuid();
        $this->insertAttempt($attemptId, 83, 'en', $day, $day->addHours(2));
        DB::table('attempts')->where('id', $attemptId)->update([
            'answers_summary_json' => json_encode(['meta' => ['form_code' => 'mbti_93']]),
        ]);
        $this->insertFailureEvent($attemptId, $day->addHour(), 'submit_failure', orgId: 83);

        $matchingFilters = [
            'scale_code' => 'MBTI',
            'form_code' => 'mbti_93',
            'locale' => 'en',
            'device_class' => 'desktop',
            'browser_class' => 'chrome',
            'endpoint_class' => 'attempt_submit',
            'status_group' => 'server_5xx',
            'error_class' => 'server_error',
        ];
        foreach ($matchingFilters as $field => $value) {
            $report = app(MeasurementFailureCohortReadModel::class)->report(83, '2026-08-10', '2026-08-10', [
                $field => [$value],
            ]);
            $this->assertSame(1, data_get($report, 'cohorts.submit_failure.failed_attempt_count'), $field);
        }

        $noMatch = app(MeasurementFailureCohortReadModel::class)->report(83, '2026-08-10', '2026-08-10', [
            'browser_class' => ['firefox'],
        ]);
        $this->assertSame(0, data_get($noMatch, 'cohorts.submit_failure.failed_attempt_count'));
        $this->assertNull(data_get($noMatch, 'cohorts.submit_failure.eligible_failure_rate'));
    }

    public function test_sparse_rows_use_complementary_suppression(): void
    {
        $day = CarbonImmutable::parse('2026-08-10 08:00:00');
        foreach (range(1, 6) as $index) {
            $attemptId = (string) Str::uuid();
            $this->insertAttempt($attemptId, 84, 'en', $day, null);
            $this->insertFailureEvent($attemptId, $day->addHour(), 'submit_failure', $index === 6 ? 'safari' : 'chrome', 84);
        }

        $report = app(MeasurementFailureCohortReadModel::class)->report(84, '2026-08-10', '2026-08-10');

        $this->assertSame(1, $report['row_count'] ?? null);
        $this->assertTrue((bool) data_get($report, 'rows.0.suppressed'));
        $this->assertNull(data_get($report, 'rows.0.metrics'));
        $this->assertSame('minimum_cohort_or_complementary_suppression', data_get($report, 'rows.0.suppression_reason'));
    }

    public function test_empty_data_is_success_but_invalid_filters_and_missing_tables_are_blocked(): void
    {
        $empty = app(MeasurementFailureCohortReadModel::class)->report(999, '2026-08-10', '2026-08-10');
        $this->assertTrue($empty['ok'] ?? false);
        $this->assertSame('empty', $empty['status'] ?? null);

        $invalid = app(MeasurementFailureCohortReadModel::class)->report(999, '2026-08-10', '2026-08-10', [
            'browser_class' => ['private browser'],
        ]);
        $this->assertFalse($invalid['ok'] ?? true);
        $this->assertContains('filter_invalid', $invalid['issues'] ?? []);

        Schema::drop('events');
        $missing = app(MeasurementFailureCohortReadModel::class)->report(999, '2026-08-10', '2026-08-10');
        $this->assertFalse($missing['ok'] ?? true);
        $this->assertContains('events_missing', $missing['issues'] ?? []);
    }

    public function test_command_is_read_only_and_rejects_invalid_windows(): void
    {
        $day = CarbonImmutable::parse('2026-08-10 08:00:00');
        $attemptId = (string) Str::uuid();
        $this->insertAttempt($attemptId, 85, 'en', $day, null);
        $this->insertFailureEvent($attemptId, $day->addHour(), 'submit_failure', orgId: 85);
        $before = $this->databaseSnapshot();
        $exitCode = Artisan::call('analytics:measurement-failure-cohorts-report', [
            '--from' => '2026-08-10',
            '--to' => '2026-08-10',
            '--org' => '85',
            '--scale' => ['MBTI'],
            '--json' => true,
        ]);
        $this->assertSame(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('"read_only": true', $output);
        $this->assertStringContainsString('"org_id": 85', $output);
        $this->assertSame($before, $this->databaseSnapshot());

        $invalidExitCode = Artisan::call('analytics:measurement-failure-cohorts-report', [
            '--from' => '2026-08-11',
            '--to' => '2026-08-10',
            '--org' => '85',
            '--json' => true,
        ]);
        $this->assertSame(1, $invalidExitCode);
        $this->assertStringContainsString('date_window_invalid', Artisan::output());
        $this->assertSame($before, $this->databaseSnapshot());
    }

    public function test_report_and_command_require_an_exact_organization_scope(): void
    {
        $day = CarbonImmutable::parse('2026-08-10 08:00:00');
        $globalAttemptId = (string) Str::uuid();
        $tenantAttemptId = (string) Str::uuid();
        $this->insertAttempt($globalAttemptId, 0, 'en', $day, null);
        $this->insertAttempt($tenantAttemptId, 81, 'en', $day, null);
        $this->insertFailureEvent($globalAttemptId, $day->addHour(), 'submit_failure', orgId: 0);
        $this->insertFailureEvent($tenantAttemptId, $day->addHour(), 'submit_failure', orgId: 81);

        $global = app(MeasurementFailureCohortReadModel::class)->report(0, '2026-08-10', '2026-08-10');
        $tenant = app(MeasurementFailureCohortReadModel::class)->report(81, '2026-08-10', '2026-08-10');

        $this->assertSame(0, $global['org_id'] ?? null);
        $this->assertSame(1, data_get($global, 'cohorts.submit_failure.eligible_attempt_count'));
        $this->assertSame(1, data_get($global, 'cohorts.submit_failure.failed_attempt_count'));
        $this->assertSame(81, $tenant['org_id'] ?? null);
        $this->assertSame(1, data_get($tenant, 'cohorts.submit_failure.eligible_attempt_count'));
        $this->assertSame(1, data_get($tenant, 'cohorts.submit_failure.failed_attempt_count'));

        $exitCode = Artisan::call('analytics:measurement-failure-cohorts-report', [
            '--from' => '2026-08-10',
            '--to' => '2026-08-10',
            '--json' => true,
        ]);
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('org_id_invalid', Artisan::output());

        $invalid = app(MeasurementFailureCohortReadModel::class)->report(-1, '2026-08-10', '2026-08-10');
        $this->assertFalse($invalid['ok'] ?? true);
        $this->assertNull($invalid['org_id'] ?? null);
        $this->assertContains('org_id_invalid', $invalid['issues'] ?? []);
    }

    private function insertFailureEvent(
        ?string $attemptId,
        CarbonImmutable $occurredAt,
        string $eventName,
        string $browserClass = 'chrome',
        int $orgId = 81,
    ): void {
        $row = [
            'id' => (string) Str::uuid(),
            'event_code' => $eventName,
            'event_name' => $eventName,
            'org_id' => $orgId,
            'anon_id' => 'anon-private',
            'request_id' => 'request-private',
            'attempt_id' => $attemptId,
            'meta_json' => json_encode([
                'scale_code' => 'MBTI',
                'form_code' => 'mbti_93',
                'locale' => 'en',
                'device_class' => 'desktop',
                'browser_class' => $browserClass,
                'endpoint_class' => $eventName === 'questions_load_failure' ? 'questions' : 'attempt_submit',
                'stage' => $eventName === 'questions_load_failure' ? 'questions' : 'attempt_submit',
                'status_group' => 'server_5xx',
                'error_class' => 'server_error',
                'retry_bucket' => 'unknown',
            ], JSON_UNESCAPED_SLASHES),
            'occurred_at' => $occurredAt,
            'scale_code' => 'MBTI',
            'channel' => 'web',
            'locale' => 'en',
            'client_platform' => 'web',
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ];
        if (Schema::hasColumn('events', 'scale_code_v2')) {
            $row['scale_code_v2'] = 'MBTI';
        }
        DB::table('events')->insert($row);
    }

    /** @return array<string, int> */
    private function tableCounts(): array
    {
        return [
            'attempts' => DB::table('attempts')->count(),
            'results' => DB::table('results')->count(),
            'events' => DB::table('events')->count(),
        ];
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function databaseSnapshot(): array
    {
        return [
            'attempts' => DB::table('attempts')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'results' => DB::table('results')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'events' => DB::table('events')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
        ];
    }
}
