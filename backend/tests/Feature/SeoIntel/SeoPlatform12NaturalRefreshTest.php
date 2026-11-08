<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoCouncil\Platform12\Platform12DailyMissionSet;
use App\Services\SeoCouncil\Platform12\Platform12RuntimeControl;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class SeoPlatform12NaturalRefreshTest extends TestCase
{
    private string $directory;

    private string $sha = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/seo-council-a08-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
        config()->set('seo_council.scheduler_enabled', true);
        config()->set('seo_council.daily_read_only_enabled', true);
        config()->set('seo_council.runtime_cache_store', 'array');
        config()->set('seo_council.activation_receipt_path', $this->directory.'/activation.json');
        config()->set('seo_council.release_revision_path', $this->directory.'/REVISION');
        file_put_contents($this->directory.'/REVISION', $this->sha."\n");
        Cache::store('array')->forget(Platform12RuntimeControl::CACHE_KEY);
        // Keep the already-resolved in-memory store while exercising the
        // production-only shared-store admission branch.
        config()->set('cache.stores.array.driver', 'redis');
        $this->app->instance('env', 'production');
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_m3_collection_authorization_is_independent_and_scope_is_console_production_only(): void
    {
        $runtime = app(Platform12RuntimeControl::class);
        $runtime->change(false, Platform12DailyMissionSet::IDS);
        $generation = $runtime->status()['generation'];
        $this->assertTrue($runtime->allowsMission(Platform12DailyMissionSet::IDS[2]));
        $command = app(\App\Console\Commands\SeoCompetitiveEvidenceIngest::class);
        $scope = new \ReflectionMethod($command, 'installNaturalRefreshScope');
        $original = config('seo_agent_evidence');
        foreach ([[false, false], [true, false], [false, true]] as [$read, $write]) {
            config()->set('seo_agent_evidence.competitive.m3_refresh', ['external_read_enabled' => $read, 'evidence_write_enabled' => $write]);
            $this->assertFalse($scope->invoke($command, $this->sha, $generation));
            $this->assertFalse(config('seo_agent_evidence.competitive.external_read_enabled'));
            $this->assertFalse(config('seo_agent_evidence.bundle_write_enabled'));
        }
        config()->set('seo_agent_evidence.competitive.m3_refresh', ['external_read_enabled' => true, 'evidence_write_enabled' => true]);
        $this->assertFalse($scope->invoke($command, 'invalid', $generation));
        $this->assertFalse($scope->invoke($command, $this->sha, str_repeat('0', 32)));
        $console = new \ReflectionProperty($this->app, 'isRunningInConsole');
        $console->setValue($this->app, false);
        $this->assertFalse($scope->invoke($command, $this->sha, $generation));
        $console->setValue($this->app, true);
        $this->app->instance('env', 'staging');
        $this->assertFalse($scope->invoke($command, $this->sha, $generation));
        $this->app->instance('env', 'production');
        $this->assertTrue($scope->invoke($command, $this->sha, $generation));
        $this->assertTrue(config('seo_agent_evidence.competitive.external_read_enabled'));
        // General writes/egress wait for the original fixed-cohort policy installer.
        foreach (['bundle_write_enabled', 'external_fetch_enabled', 'retention_delete_enabled', 'agent_external_egress'] as $key) {
            $this->assertFalse(config('seo_agent_evidence.'.$key));
        }
        $this->assertSame($original['connection'], config('seo_agent_evidence.connection'));
        $cohort = app(\App\Services\SeoAgentEvidence\Competitive\CompetitiveSourceRegistry::class)->cohort('competitive.big-five.live.v2');
        app(\App\Services\SeoAgentEvidence\Competitive\CompetitiveSourcePolicyRegistry::class)
            ->installForControlledCli($cohort, 'production', $this->sha);
        $this->assertCount(count($cohort['source_ids']), config('seo_agent_evidence.allowed_sources'));
        $this->assertTrue(config('seo_agent_evidence.bundle_write_enabled'));
        $this->assertTrue(config('seo_agent_evidence.external_fetch_enabled'));
        $this->assertFalse(config('seo_agent_evidence.retention_delete_enabled'));
        $this->assertFalse(config('seo_agent_evidence.agent_external_egress'));
        config()->set('seo_agent_evidence', $original);
        config()->set('seo_agent_evidence.competitive.m3_refresh', ['external_read_enabled' => true, 'evidence_write_enabled' => true]);
        $runtime->change(true);
        $this->assertFalse($scope->invoke($command, $this->sha, $generation));
        $runtime->change(false, [Platform12DailyMissionSet::IDS[0]]);
        $this->assertFalse($scope->invoke($command, $this->sha, $runtime->status()['generation']));
    }

    public function test_m3_natural_command_restores_scope_after_failure_and_rejects_other_modes(): void
    {
        $runtime = app(Platform12RuntimeControl::class);
        $runtime->change(false, Platform12DailyMissionSet::IDS);
        config()->set('seo_agent_evidence.competitive.m3_refresh', ['external_read_enabled' => true, 'evidence_write_enabled' => true]);
        config()->set('database.connections.seo_intel', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        config()->set('seo_agent_evidence.connection', 'seo_intel');
        \Illuminate\Support\Facades\DB::purge('seo_intel');
        (require database_path('migrations/seo_intel/2026_08_29_010000_create_seo_evidence_tables.php'))->up();
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        $originalStorage = storage_path();
        $original = config('seo_agent_evidence');
        $this->app->useStoragePath($this->directory);
        try {
            $this->artisan('seo:competitive-evidence-ingest', ['--write-evidence' => true, '--cohort' => 'competitive.big-five.live.v2', '--json' => true])
                ->expectsOutputToContain('COMPETITIVE_WRITE_BOUNDARY_HELD')->assertFailed();
            foreach (['--no-write' => true, '--cohort' => 'unapproved-cohort', '--finalize-activation' => true] as $option => $value) {
                $this->artisan('seo:competitive-evidence-ingest', ['--refresh-if-due' => true, '--json' => true, $option => $value])
                    ->expectsOutputToContain('REFRESH_MODE_INVALID')->assertSuccessful();
            }
            $this->artisan('seo:competitive-evidence-ingest', ['--refresh-if-due' => true, '--json' => true])
                ->expectsOutputToContain('"status":"HOLD"')->assertSuccessful();
            $path = storage_path('app/release-receipts/seo-competitive-evidence/current.json');
            $marker = json_decode(file_get_contents($path), true);
            $this->assertSame('failed', $marker['refresh_status']);
            $this->assertNotSame('COMPETITIVE_WRITE_BOUNDARY_HELD', $marker['reason']);
            $this->assertSame($original, config('seo_agent_evidence'));
            $this->assertSame(0, \Illuminate\Support\Facades\DB::connection('seo_intel')->table('seo_evidence_bundles')->count());
            \Illuminate\Support\Facades\Http::assertNothingSent();
        } finally {
            $this->app->useStoragePath($originalStorage);
            \Illuminate\Support\Facades\DB::purge('seo_intel');
        }
    }

    public function test_natural_refresh_remains_eligible_after_terminal_daily_hold_without_another_evaluation(): void
    {
        $reader = $this->schedulerFixture();
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-06T22:19:00Z'));
        $control = app(Platform12RuntimeControl::class);
        $control->change(false, [Platform12DailyMissionSet::IDS[2]]);
        \Illuminate\Support\Facades\Process::fake([
            '*' => \Illuminate\Support\Facades\Process::result(output: '{"status":"HOLD","hold_reason":"REFRESH_BACKOFF","external_reads":0}'),
        ]);
        $scheduler = app(\App\Services\SeoCouncil\Platform12\Platform12DailyScheduler::class);
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-06T22:30:00Z'));
        $first = $scheduler->tick();
        $this->assertSame('TERMINAL_COMMITTED', $first['status'], json_encode($first));
        $this->assertSame('HOLD', $first['mission_verdict']);
        $receipt = json_decode(\Illuminate\Support\Facades\DB::connection('seo_intel')->table('seo_council_run_receipts')->value('receipt_json'), true);
        $evaluation = collect($receipt['route_plan'])->firstWhere('kind', 'daily_evaluation')['output'];
        $this->assertSame(['STALE_EVIDENCE_HOLD'], $evaluation['reason_codes']);
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-06T23:31:00Z'));
        $this->assertSame('IDLE', $scheduler->tick()['status']);
        \Illuminate\Support\Facades\Process::assertRanTimes(fn ($process): bool => $process->command === [
            PHP_BINARY, base_path('artisan'), 'seo:competitive-evidence-ingest', '--refresh-if-due', '--json',
        ], 2);
        $this->assertSame(1, $reader->reads);
        $this->assertSame(1, \Illuminate\Support\Facades\DB::connection('seo_intel')->table('seo_council_schedule_deliveries')->count());
        $this->assertSame(1, \Illuminate\Support\Facades\DB::connection('seo_intel')->table('seo_council_run_receipts')->count());
        $this->travelBack();
    }

    public function test_natural_refresh_never_runs_without_authorized_m3_or_after_pause(): void
    {
        $this->schedulerFixture();
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-06T22:31:00Z'));
        $control = app(Platform12RuntimeControl::class);
        $control->change(false, [Platform12DailyMissionSet::IDS[0]]);
        \Illuminate\Support\Facades\Process::fake();
        $scheduler = app(\App\Services\SeoCouncil\Platform12\Platform12DailyScheduler::class);
        $this->assertSame('IDLE', $scheduler->tick()['status']);
        $control->change(true);
        $this->assertSame('PAUSED', $scheduler->tick()['status']);
        \Illuminate\Support\Facades\Process::assertNothingRan();
        $this->assertSame(0, \Illuminate\Support\Facades\DB::connection('seo_intel')->table('seo_council_schedule_deliveries')->count());
        $this->travelBack();
    }

    public function test_existing_selection_survives_release_changes_without_reading_or_rewriting_a08_evidence(): void
    {
        $runtime = app(Platform12RuntimeControl::class);
        $this->assertSame([], $runtime->status()['effective_mission_ids']);
        $active = $runtime->change(false, Platform12DailyMissionSet::IDS);
        $store = Cache::store('array');
        $old = $store->get(Platform12RuntimeControl::CACHE_KEY);
        $old['software_sha'] = str_repeat('b', 40);
        $old['mission_source_hash'] = array_fill_keys(Platform12DailyMissionSet::IDS, str_repeat('c', 64));
        $store->forever(Platform12RuntimeControl::CACHE_KEY, $old);
        foreach ([null, '{broken', '{"bound_production_sha":"old-release"}'] as $bytes) {
            $path = $this->directory.'/activation.json';
            if ($bytes !== null) {
                file_put_contents($path, $bytes);
            }
            file_put_contents($this->directory.'/REVISION', str_repeat('d', 40));
            $status = $runtime->status();
            $this->assertSame(Platform12DailyMissionSet::IDS, $status['effective_mission_ids']);
            $this->assertSame($active['generation'], $status['generation']);
            $this->assertSame($old, $store->get(Platform12RuntimeControl::CACHE_KEY));
            $this->assertArrayNotHasKey('activation_bound_sha', $status);
        }
        $runtime->change(true);
        $paused = $store->get(Platform12RuntimeControl::CACHE_KEY);
        $this->assertSame([], $runtime->status()['effective_mission_ids']);
        $this->assertSame($paused, $store->get(Platform12RuntimeControl::CACHE_KEY));
    }

    public function test_invalid_state_real_guards_and_staging_remain_closed_without_activation_receipts(): void
    {
        $runtime = app(Platform12RuntimeControl::class);
        $runtime->change(false, Platform12DailyMissionSet::IDS);
        $store = Cache::store('array');
        $valid = $store->get(Platform12RuntimeControl::CACHE_KEY);
        foreach ([['generation' => 'invalid'], ['catalog_hash' => 'changed'],
            ['selected_missions' => [Platform12DailyMissionSet::IDS[0], 'unknown']],
            ['paused' => 'false'], ['mission_activated_at' => []],
            ['mission_activated_at' => array_fill_keys(Platform12DailyMissionSet::IDS, 'invalid')],
            ['mission_activated_at' => array_fill_keys(Platform12DailyMissionSet::IDS, '2099-01-01T00:00:00Z')]] as $delta) {
            $store->forever(Platform12RuntimeControl::CACHE_KEY, array_replace($valid, $delta));
            $this->assertSame([], $runtime->status()['effective_mission_ids'], json_encode($delta));
        }
        $store->forever(Platform12RuntimeControl::CACHE_KEY, $valid);
        $this->app->instance('env', 'staging');
        $this->assertSame([], $runtime->status()['effective_mission_ids']);
        $this->assertSame($valid, $store->get(Platform12RuntimeControl::CACHE_KEY));
        $this->app->instance('env', 'production');
        config()->set('seo_council.model_runtime_enabled', true);
        $this->assertSame('WRITE_GUARD_HOLD', $runtime->status()['state']);
        $this->assertSame([], $runtime->status()['effective_mission_ids']);
        config()->set('seo_council.model_runtime_enabled', false);
        config()->set('cache.stores.array.driver', 'array');
        $this->assertSame('SHARED_CACHE_HOLD', $runtime->status()['state']);
    }

    private function schedulerFixture(): \App\Services\SeoCouncil\Platform12\Platform12EvidenceReader
    {
        config()->set('database.connections.seo_intel', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        config()->set('seo_council.connection', 'seo_intel');
        \Illuminate\Support\Facades\DB::purge('seo_intel');
        foreach (['2026_08_29_030000_create_seo_council_runtime_tables.php', '2026_09_04_010000_create_seo_council_scheduler_storage.php',
            '2026_09_04_020000_expand_seo_council_scheduler_fencing.php', '2026_09_04_030000_expand_seo_council_run_receipts.php',
            '2026_09_04_040000_create_seo_council_notification_outbox.php'] as $migration) {
            (require database_path('migrations/seo_intel/'.$migration))->up();
        }
        $reader = new class implements \App\Services\SeoCouncil\Platform12\Platform12EvidenceReader
        {
            public int $reads = 0;

            public function capture(string $missionId): array
            {
                $this->reads++;

                return ['input' => ['evaluated_at' => now('UTC')->format('Y-m-d\TH:i:s\Z'),
                    'private_routes' => ['tested_count' => 30, 'rejected_count' => 30],
                    'query_security' => ['hmac_state' => 'VALID', 'key_version_state' => 'CURRENT', 'pii_state' => 'ABSENT'],
                    'drift' => array_fill_keys(['role', 'binding', 'policy', 'tool', 'schema', 'prompt'], 'MATCH'),
                    'evidence_freshness' => ['total_count' => 1, 'fresh_count' => 0, 'expired_count' => 1],
                    'injection' => ['prompt_state' => 'PASS', 'tool_metadata_state' => 'PASS'],
                    'tools' => ['requested_count' => 0, 'authorized_count' => 0],
                    'posture' => ['retention_state' => 'COMPLIANT', 'egress_state' => 'COMPLIANT']],
                    'sources' => array_map(fn (string $id): array => ['id' => $id, 'hash' => str_repeat('d', 64),
                        'read_at' => now('UTC')->toAtomString(), 'observed_at' => now('UTC')->subMinute()->toAtomString()],
                        \App\Services\SeoCouncil\Platform12\Platform12SourceCheck::SOURCES[$missionId]),
                    'source_gaps' => [], 'captured_at' => now('UTC')->toAtomString(),
                    'expires_at' => now('UTC')->addMinutes(10)->toAtomString()];
            }
        };
        $this->app->instance(\App\Services\SeoCouncil\Platform12\Platform12EvidenceReader::class, $reader);

        return $reader;
    }
}
