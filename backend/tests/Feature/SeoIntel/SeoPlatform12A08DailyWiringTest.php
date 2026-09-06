<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoCouncil\Platform12\Operations\Platform12SystemHealthReadService;
use App\Services\SeoCouncil\Platform12\Platform12DailyMissionSet;
use App\Services\SeoCouncil\Platform12\Platform12DailyScheduler;
use App\Services\SeoCouncil\Platform12\Platform12EvidenceReader;
use App\Services\SeoCouncil\Platform12\Platform12FrozenMission;
use App\Services\SeoCouncil\Platform12\Platform12RuntimeControl;
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
        $this->assertSame('FULL_NIGHTLY_EVIDENCE_HOLD', app(Platform12RuntimeControl::class)->prerequisite());
        $this->assertFalse(app(Platform12RuntimeControl::class)->change(false)['computation_enabled']);
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_council_runs')->count());
    }

    public function test_pause_resume_changes_generation_and_never_enables_business_writes(): void
    {
        $runtime = app(Platform12RuntimeControl::class);
        $active = $runtime->change(false);
        $this->assertSame('ACTIVE_READ_ONLY', $active['state']);
        $this->assertSame('PAUSED', $runtime->change(true)['state']);
        $resumed = $runtime->change(false);
        $this->assertNotSame($active['generation'], $resumed['generation']);
        $this->assertSame($active['activated_at'], $resumed['activated_at']);
        $this->assertFalse($resumed['business_write_enabled']);
    }

    public function test_one_due_mission_runs_through_orchestrator_and_closes_atomically_without_duplicate_tick(): void
    {
        $clock = CarbonImmutable::now('Asia/Shanghai')->setTime(6, 19);
        CarbonImmutable::setTestNow($clock);
        \Carbon\Carbon::setTestNow($clock);
        app(Platform12RuntimeControl::class)->change(false);
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
        app(Platform12RuntimeControl::class)->change(false);
        $this->assertSame('TERMINAL_COMMITTED', app(Platform12DailyScheduler::class)->tick(Platform12DailyMissionSet::IDS[0])['status']);
    }

    public function test_unchanged_failure_is_quiet_and_recovery_is_enqueued_once_without_verdict_changes(): void
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
        for ($day = 1; $day <= 3; $day++) {
            $clock = CarbonImmutable::now()->addDay();
            CarbonImmutable::setTestNow($clock);
            \Carbon\Carbon::setTestNow($clock);
            $reader->canonicalFault = $day === 1;
            $this->assertSame('TERMINAL_COMMITTED', $scheduler->tick($mission)['status']);
        }
        $events = DB::connection('seo_intel')->table('seo_council_notification_outbox');
        $this->assertSame(1, (clone $events)->where('event_type', 'AUTHORITY_INDEXABILITY_P0')->count());
        $this->assertSame(1, (clone $events)->where('event_type', 'AUTHORITY_INDEXABILITY_P0_RECOVERY')->count());
        $this->assertSame(2, $events->count());
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

    private function startAtSlot(): void
    {
        $clock = CarbonImmutable::now('Asia/Shanghai')->setTime(6, 19);
        CarbonImmutable::setTestNow($clock);
        \Carbon\Carbon::setTestNow($clock);
        app(Platform12RuntimeControl::class)->change(false);
    }

    private function fixtureReader(): Platform12EvidenceReader
    {
        $reader = new class implements Platform12EvidenceReader
        {
            public int $reads = 0;

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

                return ['input' => ['evaluated_at' => now('UTC')->format('Y-m-d\TH:i:s\Z'), ...$input],
                    'sources' => [], 'source_gaps' => [], 'captured_at' => now('UTC')->format('Y-m-d\TH:i:s\Z'),
                    'expires_at' => now('UTC')->addMinutes(10)->format('Y-m-d\TH:i:s\Z')];
            }
        };
        $this->app->instance(Platform12EvidenceReader::class, $reader);

        return $reader;
    }
}
