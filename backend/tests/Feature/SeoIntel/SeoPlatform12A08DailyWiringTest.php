<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoCouncil\Platform12\Operations\Platform12SystemHealthReadService;
use App\Services\SeoCouncil\Platform12\Platform12DailyMissionSet;
use App\Services\SeoCouncil\Platform12\Platform12DailyScheduler;
use App\Services\SeoCouncil\Platform12\Platform12EvidenceReader;
use App\Services\SeoCouncil\Platform12\Platform12FrozenMission;
use App\Services\SeoCouncil\Platform12\Platform12RuntimeControl;
use App\Services\SeoCouncil\Platform12\Platform12SourceCheck;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SeoPlatform12A08DailyWiringTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.connections.seo_intel', ['driver' => 'sqlite', 'database' => ':memory:',
            'prefix' => '', 'foreign_key_constraints' => true]);
        config()->set('seo_council.connection', 'seo_intel');
        config()->set('seo_council.runtime_cache_store', 'array');
        config()->set('seo_council.scheduler_enabled', true);
        config()->set('seo_council.daily_read_only_enabled', true);
        DB::purge('seo_intel');
        foreach (['2026_08_29_030000_create_seo_council_runtime_tables.php',
            '2026_09_04_010000_create_seo_council_scheduler_storage.php',
            '2026_09_04_020000_expand_seo_council_scheduler_fencing.php',
            '2026_09_04_030000_expand_seo_council_run_receipts.php',
            '2026_09_04_040000_create_seo_council_notification_outbox.php'] as $migration) {
            (require database_path('migrations/seo_intel/'.$migration))->up();
        }
        Cache::store('array')->forget(Platform12RuntimeControl::CACHE_KEY);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Carbon\Carbon::setTestNow();
        DB::purge('seo_intel');
        parent::tearDown();
    }

    public function test_daily_slots_have_shanghai_timezone_no_pre_activation_backfill_and_bounded_catchup(): void
    {
        $set = app(Platform12DailyMissionSet::class);
        $active = CarbonImmutable::parse('2026-09-06T22:19:00Z');
        $now = CarbonImmutable::parse('2026-09-06T22:26:00Z');
        $slots = $set->slots($now, $active, $now);
        $this->assertCount(2, $slots);
        $this->assertSame('2026-09-06T22:20:00Z', $slots[0]['scheduled_for']);
        $this->assertSame('catch_up', $slots[0]['trigger_mode']);
        $this->assertSame([], $set->slots($now->subDay(), $active, $now));
        $this->assertSame('missed', $set->slots($now, $active, $now->addDay())[0]['trigger_mode']);
        $this->assertCount(1, $set->slots($now, $active->addMinutes(3), $now));
    }

    public function test_production_requires_full_exact_sha_evidence_even_when_switches_are_enabled(): void
    {
        $this->app->instance('env', 'production');
        $this->assertSame('ACTIVATION_EVIDENCE_MISSING', app(Platform12RuntimeControl::class)->prerequisite());
        $this->assertFalse(app(Platform12RuntimeControl::class)->change(false, Platform12DailyMissionSet::IDS)['computation_enabled']);
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_council_runs')->count());
    }

    public function test_pause_resume_changes_generation_and_never_enables_business_writes(): void
    {
        $runtime = app(Platform12RuntimeControl::class);
        $active = $runtime->change(false, Platform12DailyMissionSet::IDS);
        $this->assertSame('ACTIVE_READ_ONLY', $active['state']);
        $this->assertSame('PAUSED', $runtime->change(true)['state']);
        $resumed = $runtime->change(false, Platform12DailyMissionSet::IDS);
        $this->assertNotSame($active['generation'], $resumed['generation']);
        $this->assertSame($active['activated_at'], $resumed['activated_at']);
        $this->assertFalse($resumed['business_write_enabled']);
    }

    public function test_one_due_mission_runs_through_orchestrator_and_closes_atomically_without_duplicate_tick(): void
    {
        $clock = CarbonImmutable::now('Asia/Shanghai')->setTime(6, 19);
        CarbonImmutable::setTestNow($clock);
        \Carbon\Carbon::setTestNow($clock);
        app(Platform12RuntimeControl::class)->change(false, Platform12DailyMissionSet::IDS);
        CarbonImmutable::setTestNow($clock->addMinute());
        \Carbon\Carbon::setTestNow($clock->addMinute());
        $this->app->instance(Platform12EvidenceReader::class, new class implements Platform12EvidenceReader
        {
            public int $reads = 0;

            public function capture(string $missionId): array
            {
                $this->reads++;

                return ['input' => ['evaluated_at' => now('UTC')->format('Y-m-d\TH:i:s\Z'),
                    'gsc' => ['availability' => 'AVAILABLE', 'scheduled_receipt_status' => 'success',
                        'trigger_mode' => 'scheduled', 'mapping_state' => 'READY', 'data_quality_state' => 'READY',
                        'window_state' => 'COMPLETE', 'row_count' => 0, 'data_max_date' => now('UTC')->subDay()->toDateString()],
                    'runtime' => ['core_runtime_state' => 'AVAILABLE', 'public_api_state' => 'AVAILABLE',
                        'readback_state' => 'AVAILABLE', 'production_sha' => str_repeat('a', 40), 'readback_sha' => str_repeat('a', 40)]],
                    'sources' => [], 'source_gaps' => [], 'captured_at' => now('UTC')->format('Y-m-d\TH:i:s\Z'),
                    'expires_at' => now('UTC')->addMinutes(10)->format('Y-m-d\TH:i:s\Z')];
            }
        });
        $scheduler = app(Platform12DailyScheduler::class);
        $first = $scheduler->tick();
        $this->assertSame('TERMINAL_COMMITTED', $first['status'], json_encode($first));
        $this->assertSame('IDLE', $scheduler->tick()['status']);
        $connection = DB::connection('seo_intel');
        $this->assertSame(1, $connection->table('seo_council_runs')->count());
        $this->assertSame(1, $connection->table('seo_council_run_receipts')->count());
        $this->assertSame('CLOSED', $connection->table('seo_council_schedule_deliveries')->value('status'));
        $this->assertSame('DAILY_MISSION_READY', $connection->table('seo_council_runs')->value('status'));
        $this->assertSame(1, app(Platform12EvidenceReader::class)->reads);
        $health = app(Platform12SystemHealthReadService::class)->snapshot();
        $this->assertSame('READY', collect($health['items'])->firstWhere('component', 'scheduler')['state']);
        $this->assertSame('READY', $health['daily_missions']['items'][0]['state']);
    }

    public function test_three_allowlisted_missions_close_once_with_no_models_tools_or_business_writes(): void
    {
        $this->startAtSlot();
        $reader = $this->fixtureReader();
        foreach ([0, 1, 2] as $index) {
            $this->assertSame('TERMINAL_COMMITTED', app(Platform12DailyScheduler::class)->tick(Platform12DailyMissionSet::IDS[$index])['status'], 'Mission '.$index);
            $this->assertSame('ACCEPTANCE_ALREADY_RECORDED', app(Platform12DailyScheduler::class)->tick(Platform12DailyMissionSet::IDS[$index])['status']);
        }
        $this->assertSame(3, $reader->reads);
        $rows = DB::connection('seo_intel')->table('seo_council_run_receipts')->get();
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $receipt = json_decode($row->receipt_json, true);
            $this->assertSame('DAILY_MISSION_READY', $receipt['status']);
            foreach (['model_calls', 'tool_calls', 'external_calls', 'business_writes', 'cms_writes', 'url_truth_writes', 'search_submissions'] as $guard) {
                $this->assertSame(0, $receipt['negative_guarantees'][$guard]);
            }
        }
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_council_notification_outbox')->count());
        $this->assertSame('ACCEPTANCE_SCOPE_DENIED', app(Platform12DailyScheduler::class)->tick('seo.platform12.weekly_opportunity')['status']);
        $this->assertSame(3, $reader->reads);
        $this->assertSame(0, app(Platform12SystemHealthReadService::class)->snapshot()['daily_missions']['actionable_count']);
    }

    public function test_controlled_acceptance_is_idempotent_per_release_without_consuming_natural_slot(): void
    {
        $this->startAtSlot();
        $reader = $this->fixtureReader();
        $revision = tempnam(sys_get_temp_dir(), 'a08-revision-');
        $this->assertIsString($revision);
        config()->set('seo_council.release_revision_path', $revision);
        try {
            file_put_contents($revision, str_repeat('a', 40)."\n");
            $scheduler = app(Platform12DailyScheduler::class);
            $this->assertSame('TERMINAL_COMMITTED', $scheduler->tick(Platform12DailyMissionSet::IDS[0])['status']);
            $this->assertSame('ACCEPTANCE_ALREADY_RECORDED', $scheduler->tick(Platform12DailyMissionSet::IDS[0])['status']);
            file_put_contents($revision, str_repeat('b', 40)."\n");
            $this->assertSame('TERMINAL_COMMITTED', $scheduler->tick(Platform12DailyMissionSet::IDS[0])['status']);
            $this->assertSame(2, $reader->reads);
            $this->assertSame(2, DB::connection('seo_intel')->table('seo_council_schedule_deliveries')
                ->where('slot_key', 'like', 'a08:acceptance:%')->count());
            $clock = CarbonImmutable::now('Asia/Shanghai')->setTime(6, 20);
            CarbonImmutable::setTestNow($clock);
            \Carbon\Carbon::setTestNow($clock);
            $this->assertSame('TERMINAL_COMMITTED', $scheduler->tick()['status']);
            $this->assertSame(3, $reader->reads);
        } finally {
            unlink($revision);
        }
    }

    public function test_transaction_failure_recovers_frozen_input_once_without_partial_audit(): void
    {
        $this->startAtSlot();
        $reader = $this->fixtureReader();
        $fail = true;
        DB::connection('seo_intel')->listen(function ($event) use (&$fail): void {
            if ($fail && str_starts_with($event->sql, 'insert into "seo_council_runs"')) {
                $fail = false;
                throw new \RuntimeException('TEST_TRANSACTION_FAILURE');
            }
        });
        $first = app(Platform12DailyScheduler::class)->tick(Platform12DailyMissionSet::IDS[0]);
        $this->assertFalse($first['terminal_committed']);
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_council_runs')->count());
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_council_run_receipts')->count());
        $this->assertSame('TERMINAL_COMMITTED', app(Platform12DailyScheduler::class)->tick()['status']);
        $this->assertSame(1, $reader->reads);
        $this->assertSame(2, DB::connection('seo_intel')->table('seo_council_schedule_deliveries')->value('attempt'));
    }

    public function test_pause_during_source_read_does_not_reserve_or_send_and_resume_uses_same_activation_date(): void
    {
        $this->startAtSlot();
        $reader = $this->fixtureReader();
        $reader->pauseAfterRead = true;
        $this->assertSame('PAUSED_BEFORE_RESERVATION', app(Platform12DailyScheduler::class)->tick(Platform12DailyMissionSet::IDS[0])['status']);
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_council_schedule_deliveries')->count());
        $this->assertSame('PAUSED', app(Platform12DailyScheduler::class)->tick()['status']);
        $reader->pauseAfterRead = false;
        app(Platform12RuntimeControl::class)->change(false, Platform12DailyMissionSet::IDS);
        $this->assertSame('TERMINAL_COMMITTED', app(Platform12DailyScheduler::class)->tick(Platform12DailyMissionSet::IDS[0])['status']);
    }

    public function test_unchanged_failure_is_quiet_and_controlled_health_never_recovers_it(): void
    {
        $this->startAtSlot();
        Http::fake(['*' => Http::response('', 200)]);
        config()->set('ops.alert.webhook', 'https://alerts.example.test/hook');
        $reader = $this->fixtureReader();
        $reader->canonicalFault = true;
        $scheduler = app(Platform12DailyScheduler::class);
        $mission = Platform12DailyMissionSet::IDS[1];
        $this->assertSame('TERMINAL_COMMITTED', $scheduler->tick($mission)['status']);
        $this->assertSame('HELD', DB::connection('seo_intel')->table('seo_council_schedule_deliveries')->value('status'));
        $heldItem = app(Platform12SystemHealthReadService::class)->snapshot()['daily_missions']['items'][1];
        $this->assertSame('HOLD', $heldItem['state']);
        $this->assertSame('WRONG_CANONICAL_HOLD', $heldItem['reason_code']);
        $this->assertSame('seo-council.reasons.WRONG_CANONICAL_HOLD.recommendation', $heldItem['recommendation_key']);
        for ($day = 1; $day <= 3; $day++) {
            $clock = CarbonImmutable::now()->addDay();
            CarbonImmutable::setTestNow($clock);
            \Carbon\Carbon::setTestNow($clock);
            $reader->canonicalFault = $day === 1;
            $this->assertSame('TERMINAL_COMMITTED', $scheduler->tick($mission)['status']);
        }
        $events = DB::connection('seo_intel')->table('seo_council_notification_outbox');
        $this->assertSame(1, (clone $events)->where('event_type', 'AUTHORITY_INDEXABILITY_P0')->count());
        $this->assertSame(0, (clone $events)->where('event_type', 'AUTHORITY_INDEXABILITY_P0_RECOVERY')->count());
        $this->assertSame(1, $events->count());
        $item = app(Platform12SystemHealthReadService::class)->snapshot()['daily_missions']['items'][1];
        $this->assertContains($item['state'], ['READY', 'STALE']);
        $this->assertSame($item['state'] === 'STALE' ? 'STALE_EVIDENCE_HOLD' : 'READY', $item['reason_code']);
        $this->assertSame('AVAILABLE', $item['source_checks'][0]['state']);
        $this->assertNotNull($item['source_checks'][0]['observed_at']);
    }

    public function test_mysql_json_key_reordering_replays_but_tampering_is_rejected(): void
    {
        $this->startAtSlot();
        $reader = $this->fixtureReader();
        $evidence = $reader->capture(Platform12DailyMissionSet::IDS[2]);
        $this->assertFalse(app(\App\Services\SeoAgentPolicyGateway\PolicyGatewayPrivacyGuard::class)->containsPrivateData(array_diff_key($evidence['input'], ['private_routes' => true])));
        $result = app(Platform12DailyScheduler::class)->tick(Platform12DailyMissionSet::IDS[2]);
        $this->assertSame('TERMINAL_COMMITTED', $result['status']);
        $json = DB::connection('seo_intel')->table('seo_council_schedule_deliveries')->value('mission_request_json');
        $envelope = json_decode($json, true);
        $sort = function (array $array) use (&$sort): array {
            if (! array_is_list($array)) {
                ksort($array);
            }

            return array_map(fn ($value) => is_array($value) ? $sort($value) : $value, $array);
        };
        $restored = Platform12FrozenMission::restore($sort($envelope));
        $this->assertSame($envelope['request']['mission_id'], $restored->request->payload['mission_id']);
        $envelope['evidence']['input']['tools']['authorized_count'] = 1;
        $this->expectException(\InvalidArgumentException::class);
        Platform12FrozenMission::restore($envelope);
    }

    public function test_selection_is_complete_and_all_disabled_entrypoints_stay_closed(): void
    {
        $this->startAtSlot();
        $reader = $this->fixtureReader();
        $runtime = app(Platform12RuntimeControl::class);
        $state = $runtime->change(false, [Platform12DailyMissionSet::IDS[0]]);
        $this->assertSame([Platform12DailyMissionSet::IDS[0]], $state['selected_missions']);
        foreach ([1, 2] as $index) {
            $this->assertSame('MISSION_NOT_AUTHORIZED', app(Platform12DailyScheduler::class)->tick(Platform12DailyMissionSet::IDS[$index])['status']);
            $this->assertFalse($runtime->admits('scheduler', ['mission_id' => 'seo.platform12.daily_check_'.$index.':'.now()->toDateString(),
                'mission_type' => 'bounded_review', 'autonomy' => 'L0', 'family' => 'other_public', 'locale' => 'zh-CN', 'tool_scope' => [], 'egress_scope' => [], 'requested_role' => null]));
        }
        $this->assertSame(0, $reader->reads);
        $runtime->change(true);
        $this->assertSame('PAUSED', app(Platform12DailyScheduler::class)->tick(Platform12DailyMissionSet::IDS[0])['status']);
        $legacy = Cache::store('array')->get(Platform12RuntimeControl::CACHE_KEY);
        unset($legacy['selected_missions']);
        $legacy['paused'] = false;
        Cache::store('array')->forever(Platform12RuntimeControl::CACHE_KEY, $legacy);
        $this->assertSame([], $runtime->status()['effective_mission_ids']);
    }

    public function test_later_enabled_mission_uses_own_first_time_and_cursor_without_pre_enable_misses(): void
    {
        $this->startAtSlot();
        $this->fixtureReader();
        $runtime = app(Platform12RuntimeControl::class);
        // Establish the old-cache empty-set case before first activation of mission 2.
        Cache::store('array')->forget(Platform12RuntimeControl::CACHE_KEY);
        $runtime->change(false, [Platform12DailyMissionSet::IDS[0]]);
        $clock = CarbonImmutable::now()->addDays(3);
        CarbonImmutable::setTestNow($clock);
        \Carbon\Carbon::setTestNow($clock);
        $state = $runtime->change(false, [Platform12DailyMissionSet::IDS[0], Platform12DailyMissionSet::IDS[1]]);
        $this->assertSame($clock->utc()->format('Y-m-d\TH:i:s\Z'), $state['missions'][Platform12DailyMissionSet::IDS[1]]['first_enabled_at']);
        for ($i = 0; $i < 8; $i++) {
            app(Platform12DailyScheduler::class)->tick();
        }
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_council_schedule_deliveries')
            ->where('mission_id', Platform12DailyMissionSet::IDS[1])->where('scheduled_for', '<', $clock->utc()->format('Y-m-d H:i:s'))->count());
    }

    public function test_disabled_stale_delivery_and_old_generation_do_not_block_selected_mission(): void
    {
        $this->startAtSlot();
        $this->fixtureReader();
        $runtime = app(Platform12RuntimeControl::class);
        $fail = true;
        DB::connection('seo_intel')->listen(function ($event) use (&$fail): void {
            if ($fail && str_starts_with($event->sql, 'insert into "seo_council_runs"')) {
                $fail = false;
                throw new \RuntimeException('fixture rollback');
            }
        });
        app(Platform12DailyScheduler::class)->tick(Platform12DailyMissionSet::IDS[2]);
        $old = $runtime->status()['generation'];
        $runtime->change(false, [Platform12DailyMissionSet::IDS[0]]);
        $this->assertFalse($runtime->allowsMission(Platform12DailyMissionSet::IDS[0], true, $old));
        $this->assertSame('TERMINAL_COMMITTED', app(Platform12DailyScheduler::class)->tick(Platform12DailyMissionSet::IDS[0])['status']);
        $this->assertSame(1, DB::connection('seo_intel')->table('seo_council_runs')->count());
        $this->assertSame(1, DB::connection('seo_intel')->table('seo_council_schedule_deliveries')->where('mission_id', Platform12DailyMissionSet::IDS[2])->whereNull('terminal_receipt_hash')->count());
    }

    private function startAtSlot(): void
    {
        $clock = CarbonImmutable::now('Asia/Shanghai')->setTime(6, 19);
        CarbonImmutable::setTestNow($clock);
        \Carbon\Carbon::setTestNow($clock);
        app(Platform12RuntimeControl::class)->change(false, Platform12DailyMissionSet::IDS);
    }

    public function test_verified_acceptance_wait_keeps_hold_and_never_creates_failure_or_recovery(): void
    {
        $this->clock('2026-09-22T06:34:51Z');
        app(Platform12RuntimeControl::class)->change(false, [Platform12DailyMissionSet::IDS[0]]);
        $reader = $this->fixtureReader();
        $this->successfulSync($reader);
        $scheduler = app(Platform12DailyScheduler::class);
        $result = $scheduler->tick(Platform12DailyMissionSet::IDS[0]);
        $this->assertSame('TERMINAL_COMMITTED', $result['status']);
        $this->assertSame('DATA_FRESHNESS_HOLD', $result['mission_verdict']);
        $this->assertSame('ACCEPTANCE_ALREADY_RECORDED', $scheduler->tick(Platform12DailyMissionSet::IDS[0])['status']);
        $receipt = json_decode(DB::connection('seo_intel')->table('seo_council_run_receipts')->value('receipt_json'), true);
        $this->assertSame('ACCEPTANCE_REFRESH_NOT_DUE', collect($receipt['route_plan'])->firstWhere('kind', 'notification_classification')['reason_code']);
        $this->assertSame(0, $this->events()->count());
        $reader->overrides = [];
        $reader->gscSource = null;
        $this->clock('2026-09-22T22:20:04Z');
        $this->assertSame('READY', $scheduler->tick()['mission_verdict']);
        $this->assertSame(0, $this->events()->count());
    }

    public function test_wait_requires_latest_success_quality_mapping_hash_and_before_refresh_boundary(): void
    {
        $this->clock('2026-09-22T06:34:51Z');
        app(Platform12RuntimeControl::class)->change(false, [Platform12DailyMissionSet::IDS[0]]);
        $reader = $this->fixtureReader();
        $this->successfulSync($reader);
        $result = app(Platform12DailyScheduler::class)->tick(Platform12DailyMissionSet::IDS[0]);
        $evidence = app(\App\Services\SeoCouncil\Platform12\Notification\Platform12NotificationEvidence::class);
        $context = $evidence->terminal($result['receipt_hash']);
        $this->assertTrue($evidence->expectedAcceptanceWait($context));
        $syncs = DB::connection('seo_intel')->table('seo_gsc_sync_runs');
        $original = $syncs->first();
        foreach (['failed', 'running', 'quality_failed'] as $status) {
            $syncs->insert(['sync_run_uid' => $status, 'trigger_mode' => 'scheduled', 'status' => $status,
                'created_at' => '2026-09-22 05:00:00']);
            $this->assertFalse($evidence->expectedAcceptanceWait($context), $status.' must not fall back to success');
            (clone $syncs)->where('sync_run_uid', $status)->delete();
        }
        foreach (['quality_gate' => ['status' => 'fail'], 'unmapped_rows' => 1,
            'completeness' => ['pagination_complete' => false, 'truncated' => true], 'data_max_date' => '2026-09-17'] as $field => $value) {
            $r = json_decode($original->receipt_json, true);
            $r[$field] = $value;
            $r['receipt_hash'] = app(\App\Services\SeoAgentGovernance\SeoRegistryHasher::class)->hashWithout($r, 'receipt_hash');
            $syncs->update(['receipt_json' => json_encode($r)]);
            $this->assertFalse($evidence->expectedAcceptanceWait($context), $field);
        }
        $syncs->update(['receipt_json' => $original->receipt_json]);
        $context['at'] = CarbonImmutable::parse('2026-09-22T18:17:00Z');
        $this->assertFalse($evidence->expectedAcceptanceWait($context));
        $context['at'] = CarbonImmutable::parse('2026-09-22T18:16:59Z');
        $this->assertTrue($evidence->expectedAcceptanceWait($context));
        $context['trigger'] = 'unknown';
        $this->assertFalse($evidence->expectedAcceptanceWait($context));
        $this->assertStringContainsString('cron: "17 18 * * *"', file_get_contents(base_path('../.github/workflows/nightly.yml')));
    }

    public function test_natural_cycles_preserve_legacy_identity_across_policy_changes_and_acceptance_success(): void
    {
        config()->set('seo_council.notification_dispatch_enabled', true);
        $transport = new class implements \App\Services\SeoCouncil\Platform12\Notification\Platform12NotificationTransport
        {
            public array $deliveries = [];

            public function send(string $notificationId, array $sanitizedPayload): void
            {
                $this->deliveries[] = $sanitizedPayload;
            }
        };
        $this->app->instance(\App\Services\SeoCouncil\Platform12\Notification\Platform12NotificationTransport::class, $transport);
        $this->clock('2026-09-22T22:19:00Z');
        app(Platform12RuntimeControl::class)->change(false, [Platform12DailyMissionSet::IDS[0]]);
        $reader = $this->fixtureReader();
        $reader->overrides = ['gsc' => ['data_max_date' => '2026-09-17']];
        $scheduler = app(Platform12DailyScheduler::class);
        $this->clock('2026-09-22T22:20:04Z');
        $first = $scheduler->tick();
        $this->assertSame('DATA_FRESHNESS_HOLD', $first['mission_verdict']);
        $this->events()->update(['status' => 'sent', 'sent_at' => '2026-09-22 22:21:00',
            'subject_hash' => hash('sha256', 'legacy-subject'), 'policy_revision' => str_repeat('b', 64)]);
        $reader->overrides = [];
        $this->clock('2026-09-23T06:00:00Z');
        $this->assertSame('READY', $scheduler->tick(Platform12DailyMissionSet::IDS[0])['mission_verdict']);
        $this->assertSame(1, $this->events()->count());
        $reader->overrides = ['gsc' => ['data_max_date' => '2026-09-17']];
        $this->clock('2026-09-23T22:20:04Z');
        $this->assertSame('DATA_FRESHNESS_HOLD', $scheduler->tick()['mission_verdict']);
        $this->assertSame(1, $this->events()->count());
        $reader->overrides = [];
        $this->clock('2026-09-24T22:20:04Z');
        $healthy = $scheduler->tick();
        $this->assertSame('READY', $healthy['mission_verdict']);
        $this->assertSame(1, $this->events()->where('incident_state', 'healthy')->count());
        $this->assertSame(hash('sha256', 'legacy-subject'), $this->events()->where('incident_state', 'healthy')->value('subject_hash'));
        $reader->overrides = ['gsc' => ['data_max_date' => '2026-09-17']];
        $this->clock('2026-09-25T22:20:04Z');
        $this->assertSame('DATA_FRESHNESS_HOLD', $scheduler->tick()['mission_verdict']);
        $this->assertSame(2, $this->events()->where('incident_state', 'failed')->count());
        $this->assertSame(2, $this->events()->where('incident_state', 'failed')->distinct()->count('subject_hash'));
        $this->assertCount(1, $transport->deliveries);
        $this->assertSame('DATA_FAILURE_RECOVERY', $transport->deliveries[0]['event_type']);
        $evidence = app(\App\Services\SeoCouncil\Platform12\Notification\Platform12NotificationEvidence::class);
        $good = $evidence->terminal($healthy['receipt_hash']);
        $bad = $evidence->terminal($first['receipt_hash']);
        $this->assertTrue($evidence->resolves($good, $bad));
        $other = $bad;
        $other['mission'] = Platform12DailyMissionSet::IDS[1];
        $this->assertFalse($evidence->resolves($good, $other));
        $old = $good;
        $old['at'] = $bad['at']->subMinute();
        $this->assertFalse($evidence->resolves($old, $bad));
        $old = $good;
        foreach ($old['envelope']['evidence']['sources'] as &$source) {
            $source['observed_at'] = $bad['at']->subMinute()->toAtomString();
        }
        unset($source);
        $this->assertFalse($evidence->resolves($old, $bad));
        $good['trigger'] = 'unknown';
        $this->assertFalse($evidence->resolves($good, $bad));

    }

    public function test_unsent_failed_or_unknown_delivery_never_generates_recovery_only_mail(): void
    {
        $this->clock('2026-09-22T22:19:00Z');
        app(Platform12RuntimeControl::class)->change(false, [Platform12DailyMissionSet::IDS[0]]);
        $reader = $this->fixtureReader();
        $reader->overrides = ['gsc' => ['data_max_date' => '2026-09-17']];
        $scheduler = app(Platform12DailyScheduler::class);
        $this->clock('2026-09-22T22:20:04Z');
        $scheduler->tick();
        $failure = $this->events()->first();
        // Keep pending rows out of drain; the test only exercises terminal closure.
        $this->events()->update(['available_at' => '2099-01-01 00:00:00']);
        $reader->overrides = [];
        $this->clock('2026-09-23T22:20:04Z');
        $healthy = $scheduler->tick();
        $this->assertSame('READY', $healthy['mission_verdict']);
        $this->assertSame('suppressed', $this->events()->value('status'));
        $outbox = app(\App\Services\SeoCouncil\Platform12\Notification\Platform12NotificationOutbox::class);
        foreach (['pending', 'failed', 'sending'] as $status) {
            $this->events()->update(['status' => $status, 'last_error_code' => $status === 'failed' ? 'DELIVERY_ACK_UNKNOWN' : null]);
            $r = $outbox->enqueueRecovery($failure->event_type, $failure->subject_hash, $failure->policy_revision,
                [['id' => 'council:daily-terminal', 'hash' => $healthy['receipt_hash']]], now('UTC')->addDay()->toAtomString(), 'READY');
            $this->assertSame('FAILURE_NOT_DELIVERED', $r['reason_code']);
            $this->assertSame($status === 'pending' ? 'suppressed' : $status, $this->events()->value('status'));
        }
        $this->assertSame(1, $this->events()->count());
    }

    public function test_controlled_real_failures_remain_alerts_and_invalid_receipts_cannot_recover(): void
    {
        $this->clock('2026-09-22T06:34:51Z');
        app(Platform12RuntimeControl::class)->change(false, Platform12DailyMissionSet::IDS);
        $reader = $this->fixtureReader();
        $reader->overrides = ['gsc' => ['scheduled_receipt_status' => 'failed']];
        $result = app(Platform12DailyScheduler::class)->tick(Platform12DailyMissionSet::IDS[0]);
        $this->assertSame('GSC_UNAVAILABLE_HOLD', $result['mission_verdict']);
        $this->assertSame(1, $this->events()->count());
        $reader->overrides = [];
        $reader->canonicalFault = true;
        app(Platform12DailyScheduler::class)->tick(Platform12DailyMissionSet::IDS[1]);
        $reader->overrides = ['query_security' => ['pii_state' => 'PRESENT']];
        app(Platform12DailyScheduler::class)->tick(Platform12DailyMissionSet::IDS[2]);
        $this->assertSame(1, $this->events()->where('event_type', 'AUTHORITY_INDEXABILITY_P0')->count());
        $this->assertSame(1, $this->events()->where('event_type', 'PRIVATE_OR_SAFETY')->count());
        $evidence = app(\App\Services\SeoCouncil\Platform12\Notification\Platform12NotificationEvidence::class);
        $this->assertNull($evidence->terminal(str_repeat('a', 64)));
        $row = DB::connection('seo_intel')->table('seo_council_run_receipts')->where('receipt_hash', $result['receipt_hash'])->first();
        $r = json_decode($row->receipt_json, true);
        $r['status'] = 'DAILY_MISSION_READY';
        DB::connection('seo_intel')->table('seo_council_run_receipts')->where('id', $row->id)->update(['receipt_json' => json_encode($r)]);
        $this->assertNull($evidence->terminal($result['receipt_hash']));
    }

    public function test_legacy_pending_wait_is_audited_without_touching_sending_or_unknown_delivery(): void
    {
        $this->clock('2026-09-22T06:34:51Z');
        app(Platform12RuntimeControl::class)->change(false, [Platform12DailyMissionSet::IDS[0]]);
        $reader = $this->fixtureReader();
        $this->successfulSync($reader);
        $result = app(Platform12DailyScheduler::class)->tick(Platform12DailyMissionSet::IDS[0]);
        $policy = app(\App\Services\SeoCouncil\Platform12\Notification\Platform12NotificationPolicyContract::class);
        $event = ['event_type' => 'DATA_FAILURE', 'severity' => 'P1', 'subject_hash' => hash('sha256', 'legacy-wait'),
            'evidence_refs' => [['id' => 'council:daily-terminal', 'hash' => $result['receipt_hash']]],
            'policy_revision' => $policy->reference()['hash'], 'state' => 'ACTIVE',
            'expires_at' => now('UTC')->addDay()->toAtomString(), 'decision_metrics' => null];
        $outbox = app(\App\Services\SeoCouncil\Platform12\Notification\Platform12NotificationOutbox::class);
        $outbox->enqueue($policy->evaluate($event), 'failed', 'HOLD');
        $this->assertSame('ACCEPTANCE_REFRESH_NOT_DUE', $outbox->claim('worker:legacy-wait')['status']);
        $this->assertSame('suppressed', $this->events()->value('status'));
        $this->assertSame('ACCEPTANCE_REFRESH_NOT_DUE', $this->events()->value('last_error_code'));
        $this->events()->update(['status' => 'sending', 'last_error_code' => 'DISPATCH_IN_FLIGHT', 'lease_expires_at' => '2000-01-01 00:00:00']);
        $this->assertSame('DELIVERY_ACK_UNKNOWN', $outbox->claim('worker:unknown-send')['status']);
        $this->assertSame('failed', $this->events()->value('status'));
        $this->assertSame('EMPTY', $outbox->claim('worker:no-replay')['status']);
        $this->assertSame(1, $this->events()->count());
    }

    public function test_natural_data_quality_mapping_missing_and_runtime_faults_keep_their_alerts(): void
    {
        $this->clock('2026-09-22T22:19:00Z');
        app(Platform12RuntimeControl::class)->change(false, [Platform12DailyMissionSet::IDS[0]]);
        $reader = $this->fixtureReader();
        foreach ([['gsc' => ['mapping_state' => 'FAILED']], ['gsc' => ['data_quality_state' => 'HOLD']],
            ['gsc' => ['window_state' => 'INCOMPLETE']], ['gsc' => ['availability' => 'UNAVAILABLE']],
            ['runtime' => ['public_api_state' => 'FAILED']]] as $day => $override) {
            $this->clock(CarbonImmutable::parse('2026-09-22T22:20:04Z')->addDays($day)->toAtomString());
            $reader->overrides = $override;
            $r = app(Platform12DailyScheduler::class)->tick();
            $this->assertSame('TERMINAL_COMMITTED', $r['status']);
            $this->assertNotSame('READY', $r['mission_verdict']);
        }
        $this->assertSame(5, $this->events()->where('event_type', 'DATA_FAILURE')->count());
        $this->assertSame(0, $this->events()->where('incident_state', 'healthy')->count());
    }

    public function test_late_natural_catchup_health_closes_cycle_by_observation_not_planned_slot(): void
    {
        $this->clock('2026-09-22T22:19:00Z');
        app(Platform12RuntimeControl::class)->change(false, [Platform12DailyMissionSet::IDS[0]]);
        $reader = $this->fixtureReader();
        $reader->overrides = ['gsc' => ['data_max_date' => '2026-09-17']];
        $scheduler = app(Platform12DailyScheduler::class);
        $this->clock('2026-09-22T22:30:00Z');
        $this->assertSame('DATA_FRESHNESS_HOLD', $scheduler->tick(Platform12DailyMissionSet::IDS[0])['mission_verdict']);
        $this->events()->update(['status' => 'sent', 'sent_at' => '2026-09-22 22:31:00']);
        $reader->overrides = [];
        $this->clock('2026-09-22T22:40:00Z');
        $this->assertSame('READY', $scheduler->tick()['mission_verdict']);
        $this->assertSame(1, $this->events()->where('incident_state', 'healthy')->count());
        $reader->overrides = ['gsc' => ['data_max_date' => '2026-09-17']];
        $this->clock('2026-09-23T22:20:04Z');
        $this->assertSame('DATA_FRESHNESS_HOLD', $scheduler->tick()['mission_verdict']);
        $this->assertSame(2, $this->events()->where('incident_state', 'failed')->count());
    }

    private function clock(string $instant): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($instant));
        \Carbon\Carbon::setTestNow(CarbonImmutable::parse($instant));
    }

    private function events(): \Illuminate\Database\Query\Builder
    {
        return DB::connection('seo_intel')->table('seo_council_notification_outbox');
    }

    private function successfulSync(object $reader): void
    {
        DB::connection('seo_intel')->getSchemaBuilder()->create('seo_gsc_sync_runs', function ($t): void {
            $t->string('sync_run_uid')->primary();
            foreach (['trigger_mode', 'status', 'created_at', 'finished_at', 'receipt_json'] as $field) {
                $t->text($field)->nullable();
            }
        });
        $h = app(\App\Services\SeoAgentGovernance\SeoRegistryHasher::class);
        $r = ['schema_version' => 'seo.gsc_refresh_receipt.v2', 'sync_run_uid' => 'test-sync', 'status' => 'success',
            'trigger_mode' => 'scheduled', 'reporting_timezone' => 'America/Los_Angeles', 'quality_gate' => ['status' => 'pass'],
            'unmapped_rows' => 0, 'rows_seen' => 42, 'data_max_date' => '2026-09-18', 'end_date' => '2026-09-18',
            'completeness' => ['pagination_complete' => true, 'truncated' => false]];
        $r['receipt_hash'] = $h->hash($r);
        DB::connection('seo_intel')->table('seo_gsc_sync_runs')->insert(['sync_run_uid' => 'test-sync', 'trigger_mode' => 'scheduled',
            'status' => 'success', 'created_at' => '2026-09-21 21:58:21', 'finished_at' => '2026-09-21 22:03:39', 'receipt_json' => json_encode($r)]);
        $gsc = ['availability' => 'AVAILABLE', 'scheduled_receipt_status' => 'success',
            'observed_at' => '2026-09-21T22:03:39Z', 'source_hash' => $h->hash($r), 'trigger_mode' => 'scheduled',
            'mapping_state' => 'READY', 'data_quality_state' => 'READY', 'window_state' => 'COMPLETE', 'row_count' => 42, 'data_max_date' => '2026-09-18'];
        $reader->overrides = ['gsc' => array_diff_key($gsc, ['observed_at' => true, 'source_hash' => true])];
        $reader->gscSource = ['id' => 'gsc_scheduled_receipt', 'hash' => $h->hash($gsc),
            'read_at' => now('UTC')->format('Y-m-d\TH:i:s\Z'), 'observed_at' => $gsc['observed_at']];
    }

    private function fixtureReader(): Platform12EvidenceReader
    {
        $reader = new class implements Platform12EvidenceReader
        {
            public int $reads = 0;

            public array $overrides = [];

            public ?array $gscSource = null;

            public bool $pauseAfterRead = false;

            public bool $canonicalFault = false;

            public function capture(string $missionId): array
            {
                $this->reads++;
                $input = match ($missionId) {
                    Platform12DailyMissionSet::IDS[0] => ['gsc' => ['availability' => 'AVAILABLE', 'scheduled_receipt_status' => 'success',
                        'trigger_mode' => 'scheduled', 'mapping_state' => 'READY', 'data_quality_state' => 'READY',
                        'window_state' => 'COMPLETE', 'row_count' => 0, 'data_max_date' => now('UTC')->subDay()->toDateString()],
                        'runtime' => ['core_runtime_state' => 'AVAILABLE', 'public_api_state' => 'AVAILABLE',
                            'readback_state' => 'AVAILABLE', 'production_sha' => str_repeat('a', 40), 'readback_sha' => str_repeat('a', 40)]],
                    Platform12DailyMissionSet::IDS[1] => ['authority' => ['availability' => 'AVAILABLE', 'revision_hash' => str_repeat('a', 64), 'current_public_count' => 100],
                        'url_truth' => ['availability' => 'AVAILABLE', 'revision_hash' => str_repeat('b', 64), 'current_url_truth_count' => 100, 'wrong_canonical_count' => 0, 'false_noindex_count' => 0],
                        'clustering' => ['availability' => 'AVAILABLE', 'issue_count' => 0, 'clustered_issue_count' => 0, 'dedupe_candidate_count' => 0, 'dedupe_unique_count' => 0],
                        'd1_observation' => ['availability' => 'AVAILABLE', 'candidate_count' => 0, 'observed_count' => 0],
                        'runtime_observation' => ['availability' => 'AVAILABLE', 'observation_count' => 3],
                        'sitemap_observation' => ['availability' => 'AVAILABLE', 'observation_count' => 100]],
                    Platform12DailyMissionSet::IDS[2] => ['private_routes' => ['tested_count' => 30, 'rejected_count' => 30],
                        'query_security' => ['hmac_state' => 'VALID', 'key_version_state' => 'CURRENT', 'pii_state' => 'ABSENT'],
                        'drift' => array_fill_keys(['role', 'binding', 'policy', 'tool', 'schema', 'prompt'], 'MATCH'),
                        'evidence_freshness' => ['total_count' => 0, 'fresh_count' => 0, 'expired_count' => 0],
                        'injection' => ['prompt_state' => 'PASS', 'tool_metadata_state' => 'PASS'],
                        'tools' => ['requested_count' => 0, 'authorized_count' => 0],
                        'posture' => ['retention_state' => 'COMPLIANT', 'egress_state' => 'COMPLIANT']],
                };
                if ($this->pauseAfterRead) {
                    app(Platform12RuntimeControl::class)->change(true);
                }
                if ($this->canonicalFault && $missionId === Platform12DailyMissionSet::IDS[1]) {
                    $input['url_truth']['wrong_canonical_count'] = 1;
                    $input['url_truth']['current_url_truth_count'] = 99;
                }

                $input = array_replace_recursive($input, $this->overrides);

                return ['input' => ['evaluated_at' => now('UTC')->format('Y-m-d\TH:i:s\Z'), ...$input],
                    'sources' => array_map(fn (string $id): array => $id === 'gsc_scheduled_receipt' && $this->gscSource !== null ? $this->gscSource : ['id' => $id, 'hash' => str_repeat('d', 64),
                        'read_at' => now('UTC')->format('Y-m-d\TH:i:s\Z'),
                        'observed_at' => now('UTC')->subMinute()->format('Y-m-d\TH:i:s\Z')], Platform12SourceCheck::SOURCES[$missionId]),
                    'source_gaps' => [], 'captured_at' => now('UTC')->format('Y-m-d\TH:i:s\Z'),
                    'expires_at' => now('UTC')->addMinutes(10)->format('Y-m-d\TH:i:s\Z')];
            }
        };
        $this->app->instance(Platform12EvidenceReader::class, $reader);

        return $reader;
    }
}
