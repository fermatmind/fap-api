<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Models\Event;
use App\Services\Analytics\CareerAttributionDailyBuilder;
use App\Services\Analytics\CareerAttributionEventMapper;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CareerActionContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('fap.events.ingest_token', 'career_test_token');
        $this->travelTo(CarbonImmutable::parse('2026-10-23T04:00:00Z'));
    }

    public function test_four_actions_old_actions_retry_new_click_and_readback_remain_distinct(): void
    {
        foreach (['en', 'zh'] as $locale) {
            foreach ([...CareerAttributionEventMapper::DETAIL_ACTIONS, 'open_next_step_link', 'open_primary_cta'] as $action) {
                $event = $this->detail($action, $locale);
                $this->send($event)->assertStatus(202)->assertJsonPath('persisted', true);
                $this->send($event)->assertStatus(202);
            }
        }
        $this->assertSame(12, DB::table('events')->count());
        $this->send($this->detail('start_riasec_test'))->assertStatus(202);
        $this->assertSame(13, DB::table('events')->count());
        $report = app(CareerAttributionDailyBuilder::class)->actions(0, '2026-10-23', '2026-10-23');
        $this->assertSame(13, array_sum(array_column($report['rows'], 'event_count')));
        foreach (CareerAttributionEventMapper::DETAIL_ACTIONS as $action) {
            $this->assertContains($action, array_column($report['rows'], 'action_bucket'));
        }
        $this->assertContains('historical_unspecified', array_column($report['rows'], 'action_bucket'));
        $this->assertSame('2026-10-23 04:00:00', DB::table('events')->first()->occurred_at);
    }

    public function test_conflicting_identity_unknown_action_and_failed_storage_do_not_claim_success(): void
    {
        $event = $this->detail('plan_career_action');
        $this->send($event)->assertStatus(202);
        $event['payload']['target_action'] = 'start_riasec_test';
        $this->send($event)->assertStatus(409);
        $this->send($this->detail('made_up_action'))->assertStatus(422);
        $dispatcher = Event::getEventDispatcher();
        Event::setEventDispatcher(clone $dispatcher);
        Event::creating(static fn () => throw new \RuntimeException('synthetic database failure'));
        try {
            $retry = $this->detail('browse_career_options');
            $this->send($retry)->assertStatus(503)->assertJsonPath('error_code', 'EVENT_WRITE_FAILED');
        } finally {
            Event::setEventDispatcher($dispatcher);
        }
        $this->send($retry)->assertStatus(202);
        $this->assertSame(2, DB::table('events')->count());
    }

    public function test_tool_runs_have_separate_denominators_no_private_fields_and_exclude_qa(): void
    {
        $run = (string) Str::uuid();
        foreach (CareerAttributionEventMapper::TOOL_EVENTS as $name) {
            $compare = str_starts_with($name, 'career_compare_');
            $event = $this->detail('start_riasec_test');
            $event['eventName'] = $name;
            $event['path'] = $compare ? '/en/career/compare' : '/en/holland-test/pdf-download';
            $event['payload'] = [
                'locale' => 'en', 'entry_surface' => $compare ? 'career_compare' : 'career_worksheet',
                'source_page_type' => $compare ? 'career_compare' : 'career_worksheet',
                'target_action' => substr($name, 7), 'landing_path' => $event['path'],
                'route_family' => $compare ? 'compare' : 'worksheet', 'subject_kind' => 'none',
                'query_mode' => 'non_query', 'tool_run_id' => $run,
            ];
            if (preg_match('/output_|copy_|download_|print_/', $name)) {
                $event['payload']['output_format'] = str_contains($name, 'print_') ? 'print' : 'text';
            }
            $this->send($event)->assertStatus(202);
            $this->send($event)->assertStatus(202);
            $event['requestId'] = (string) Str::uuid();
            $event['payload']['private_notes'] = 'private';
            $this->send($event)->assertStatus(422);
        }
        $qa = $this->detail('start_riasec_test');
        $qa['payload']['traffic_quality'] = 'qa';
        $this->send($qa)->assertStatus(202);
        $report = app(CareerAttributionDailyBuilder::class)->actions(0, '2026-10-23', '2026-10-23');
        $this->assertSame(1, $report['excluded_event_count']);
        $this->assertSame(1, $report['tools']['career_compare_succeeded']['matched_entered_runs']);
        $this->assertSame(1, $report['tools']['career_worksheet_copy_succeeded']['entered_denominator']);
        $this->assertStringNotContainsString($run, json_encode($report));
        $this->assertStringNotContainsString('anonymousId', json_encode($report));
        $this->assertCount(9, app(CareerAttributionDailyBuilder::class)->actions(0, '2026-10-23', '2026-10-23', true)['rows']);
    }

    public function test_official_resource_click_has_a_bounded_identity_and_cannot_claim_tool_success(): void
    {
        $event = $this->detail('start_riasec_test');
        $event['eventName'] = 'career_support_link_click';
        $event['path'] = '/en/career/tests';
        $event['payload'] = [
            'locale' => 'en', 'entry_surface' => 'career_tests_official_tools',
            'source_page_type' => 'landing', 'target_action' => 'open_official_resource',
            'landing_path' => '/en/career/tests', 'route_family' => 'landing',
            'subject_kind' => 'none', 'query_mode' => 'non_query', 'resource_key' => 'onet_interest_profiler',
        ];
        $this->send($event)->assertStatus(202);
        unset($event['payload']['resource_key']);
        $this->send($event)->assertStatus(422);
        $event['payload']['resource_key'] = 'made_up';
        $this->send($event)->assertStatus(422);
    }

    private function send(array $event): \Illuminate\Testing\TestResponse
    {
        return $this->withToken('career_test_token')->postJson('/api/v0.5/career/attribution/events', $event);
    }

    private function detail(string $action, string $locale = 'en'): array
    {
        return [
            'eventName' => 'career_job_detail_cta_click', 'requestId' => (string) Str::uuid(),
            'anonymousId' => 'action_contract', 'path' => "/$locale/career/jobs/accountants-and-auditors",
            'timestamp' => '2031-01-01T00:00:00Z',
            'payload' => ['locale' => $locale, 'entry_surface' => 'career_job_detail',
                'source_page_type' => 'career_job_detail', 'target_action' => $action,
                'landing_path' => "/$locale/career/jobs/accountants-and-auditors", 'route_family' => 'job_detail',
                'subject_kind' => 'job_slug', 'subject_key' => 'accountants-and-auditors', 'query_mode' => 'non_query'],
        ];
    }
}
