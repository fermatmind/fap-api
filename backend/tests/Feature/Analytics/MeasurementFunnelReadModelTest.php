<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Services\Analytics\MeasurementFunnelReadModel;
use App\Services\Attempts\AttemptSubmitSideEffects;
use App\Support\OrgContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\SeedsFunnelAnalyticsScenario;
use Tests\TestCase;

final class MeasurementFunnelReadModelTest extends TestCase
{
    use RefreshDatabase;
    use SeedsFunnelAnalyticsScenario;

    public function test_read_model_aggregates_backend_truth_with_privacy_safe_dimensions(): void
    {
        $scenario = $this->seedFunnelAnalyticsScenario(71);
        $attemptId = $scenario['attempt_a'];
        DB::table('attempts')->where('id', $attemptId)->update([
            'client_platform' => 'wechat-miniprogram',
            'channel' => 'organic',
            'answers_summary_json' => json_encode([
                'meta' => [
                    'form_code' => 'mbti_93',
                    'entry_surface' => 'article_detail',
                    'source_page_type' => 'article_detail',
                    'target_action' => 'start_mbti_test',
                    'utm' => [
                        'source' => 'google',
                        'medium' => 'organic',
                        'term' => 'private search phrase',
                        'content' => 'person@example.com',
                    ],
                    'landing_path' => '/en/tests/private?token=secret',
                    'referrer' => 'https://example.com/private?email=person@example.com',
                    'attempt_id' => $attemptId,
                    'answers' => ['Q1' => 'A'],
                ],
            ], JSON_UNESCAPED_SLASHES),
        ]);

        $before = $this->tableCounts();
        $report = app(MeasurementFunnelReadModel::class)->report(
            71,
            $scenario['day'],
            $scenario['day'],
            ['MBTI'],
            ['en'],
        );

        $this->assertTrue($report['ok'] ?? false);
        $this->assertSame('warning', $report['status'] ?? null);
        $this->assertSame(2, data_get($report, 'totals.attempt_started_count'));
        $this->assertSame(2, data_get($report, 'totals.test_completed_count'));
        $this->assertSame(1, data_get($report, 'totals.result_ready_count'));
        $this->assertSame(0, data_get($report, 'totals.result_ready_event_count'));
        $this->assertSame('partial', data_get($report, 'totals.result_ready_event_coverage_status'));
        $this->assertContains('result_ready_event_coverage_partial', $report['issues'] ?? []);
        $this->assertSame($before, $this->tableCounts());

        $encoded = json_encode($report, JSON_UNESCAPED_SLASHES);
        $this->assertIsString($encoded);
        $this->assertStringContainsString('article_detail', $encoded);
        $this->assertStringContainsString('google', $encoded);
        $this->assertStringContainsString('mini_program', $encoded);
        foreach ([$attemptId, 'start_mbti_test', 'private search phrase', 'person@example.com', 'token=secret', '/en/tests/private'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
    }

    public function test_command_is_read_only_and_rejects_invalid_windows(): void
    {
        $scenario = $this->seedFunnelAnalyticsScenario(72);
        $before = $this->tableCounts();

        $exitCode = Artisan::call('analytics:measurement-funnel-report', [
            '--from' => $scenario['day'],
            '--to' => $scenario['day'],
            '--org' => '72',
            '--scale' => ['MBTI'],
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('"read_only": true', $output);
        $this->assertStringContainsString('"org_id": 72', $output);
        $this->assertSame($before, $this->tableCounts());

        $invalidExitCode = Artisan::call('analytics:measurement-funnel-report', [
            '--from' => '2026-01-04',
            '--to' => '2026-01-03',
            '--org' => '72',
            '--json' => true,
        ]);
        $this->assertSame(1, $invalidExitCode);
        $this->assertStringContainsString('date_window_invalid', Artisan::output());
        $this->assertSame($before, $this->tableCounts());
    }

    public function test_submit_event_meta_uses_only_the_measurement_allowlist(): void
    {
        $scenario = $this->seedFunnelAnalyticsScenario(73);
        $attemptId = $scenario['attempt_a'];
        DB::table('attempts')->where('id', $attemptId)->update([
            'answers_summary_json' => json_encode([
                'meta' => [
                    'form_code' => 'mbti_93',
                    'entry_surface' => 'article_detail',
                    'source_page_type' => 'article_detail',
                    'target_action' => 'start_mbti_test',
                    'landing_path' => '/private?token=secret',
                    'email' => 'person@example.com',
                    'answers' => ['Q1' => 'A'],
                ],
            ], JSON_UNESCAPED_SLASHES),
        ]);
        $ctx = new OrgContext;
        $ctx->set(73, null, 'member', 'internal-anon', OrgContext::KIND_TENANT);

        app(AttemptSubmitSideEffects::class)->recordSubmitEvent(
            $ctx,
            $attemptId,
            null,
            'internal-anon',
            ['scale_code' => 'MBTI', 'form_code' => 'mbti_93'],
        );

        $event = DB::table('events')
            ->where('event_code', 'test_submit')
            ->where('attempt_id', $attemptId)
            ->latest('created_at')
            ->first();
        $this->assertNotNull($event);
        $meta = json_decode((string) ($event->meta_json ?? '{}'), true);
        $this->assertIsArray($meta);
        $this->assertSame([
            'scale_code',
            'form_code',
            'locale',
            'entry_surface',
            'source_page_type',
            'organic_channel',
            'device_class',
            'result_state',
        ], array_keys($meta));
        $this->assertSame('article_detail', $meta['entry_surface'] ?? null);
        $this->assertArrayNotHasKey('target_action', $meta);
        $this->assertArrayNotHasKey('attempt_id', $meta);
        $this->assertStringNotContainsString($attemptId, (string) $event->meta_json);
        $this->assertStringNotContainsString('person@example.com', (string) $event->meta_json);
        $this->assertStringNotContainsString('token=secret', (string) $event->meta_json);
    }

    public function test_read_model_deduplicates_result_ready_events_and_reports_duplicates(): void
    {
        $scenario = $this->seedFunnelAnalyticsScenario(74);
        $attemptId = $scenario['attempt_a'];
        $occurredAt = CarbonImmutable::parse($scenario['day'].' 08:06:00');
        $this->insertEvent(74, 'result_ready', $attemptId, $occurredAt);
        $this->insertEvent(74, 'result_ready', $attemptId, $occurredAt->addSecond());

        $report = app(MeasurementFunnelReadModel::class)->report(
            74,
            $scenario['day'],
            $scenario['day'],
            ['MBTI'],
            ['en'],
        );

        $this->assertTrue($report['ok'] ?? false);
        $this->assertSame('warning', $report['status'] ?? null);
        $this->assertSame(1, data_get($report, 'totals.result_ready_count'));
        $this->assertSame(1, data_get($report, 'totals.result_ready_event_count'));
        $this->assertSame(1, data_get($report, 'totals.result_ready_duplicate_event_count'));
        $this->assertSame('duplicate', data_get($report, 'totals.result_ready_event_coverage_status'));
        $this->assertContains('result_ready_event_duplicates_detected', $report['issues'] ?? []);
    }

    public function test_read_model_does_not_treat_equal_but_mismatched_attempt_sets_as_complete(): void
    {
        $scenario = $this->seedFunnelAnalyticsScenario(75);
        $this->insertEvent(
            75,
            'result_ready',
            $scenario['attempt_c'],
            CarbonImmutable::parse($scenario['day'].' 12:06:00'),
        );

        $report = app(MeasurementFunnelReadModel::class)->report(
            75,
            $scenario['day'],
            $scenario['day'],
            ['MBTI'],
            ['en'],
        );

        $this->assertSame(1, data_get($report, 'totals.result_ready_count'));
        $this->assertSame(1, data_get($report, 'totals.result_ready_event_count'));
        $this->assertSame('partial', data_get($report, 'totals.result_ready_event_coverage_status'));
        $this->assertContains('result_ready_event_coverage_partial', $report['issues'] ?? []);
    }

    public function test_read_model_blocks_when_a_required_source_table_is_missing(): void
    {
        Schema::drop('events');

        $report = app(MeasurementFunnelReadModel::class)->report(999, '2026-01-03', '2026-01-03');

        $this->assertFalse($report['ok'] ?? true);
        $this->assertSame('blocked', $report['status'] ?? null);
        $this->assertContains('events_missing', $report['issues'] ?? []);
        $this->assertSame([], $report['rows'] ?? null);
    }

    public function test_report_and_command_require_an_exact_organization_scope(): void
    {
        $scenario = $this->seedFunnelAnalyticsScenario(0);
        $day = CarbonImmutable::parse($scenario['day'].' 08:00:00');
        $tenantAttemptId = (string) Str::uuid();
        $this->insertAttempt($tenantAttemptId, 81, 'en', $day, null);
        $this->insertResult($tenantAttemptId, 81, $day->addMinutes(6));
        $this->insertEvent(81, 'result_ready', $tenantAttemptId, $day->addMinutes(7));

        $global = app(MeasurementFunnelReadModel::class)->report(0, $scenario['day'], $scenario['day'], ['MBTI'], ['en']);
        $tenant = app(MeasurementFunnelReadModel::class)->report(81, $scenario['day'], $scenario['day'], ['MBTI'], ['en']);

        $this->assertSame(0, $global['org_id'] ?? null);
        $this->assertSame(2, data_get($global, 'totals.attempt_started_count'));
        $this->assertSame(1, data_get($global, 'totals.result_ready_count'));
        $this->assertSame(81, $tenant['org_id'] ?? null);
        $this->assertSame(1, data_get($tenant, 'totals.attempt_started_count'));
        $this->assertSame(1, data_get($tenant, 'totals.result_ready_count'));
        $this->assertSame(1, data_get($tenant, 'totals.result_ready_event_count'));

        $exitCode = Artisan::call('analytics:measurement-funnel-report', [
            '--from' => $scenario['day'],
            '--to' => $scenario['day'],
            '--json' => true,
        ]);
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('org_id_invalid', Artisan::output());

        $invalid = app(MeasurementFunnelReadModel::class)->report(-1, $scenario['day'], $scenario['day']);
        $this->assertFalse($invalid['ok'] ?? true);
        $this->assertNull($invalid['org_id'] ?? null);
        $this->assertContains('org_id_invalid', $invalid['issues'] ?? []);
    }

    /**
     * @return array<string, int>
     */
    private function tableCounts(): array
    {
        return [
            'attempts' => DB::table('attempts')->count(),
            'results' => DB::table('results')->count(),
            'events' => DB::table('events')->count(),
        ];
    }
}
