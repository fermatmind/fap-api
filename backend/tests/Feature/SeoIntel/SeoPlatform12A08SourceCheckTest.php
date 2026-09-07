<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Http\Controllers\API\V0_5\SEO\SitemapSourceController;
use App\Services\Ops\PublicContentDeliveryProbeService;
use App\Services\SeoCouncil\Platform12\Platform12DailyMissionSet;
use App\Services\SeoCouncil\Platform12\Platform12ProductionEvidenceReader;
use App\Services\SeoCouncil\Platform12\Platform12RuntimeControl;
use App\Services\SeoCouncil\Platform12\Platform12SourceCheck;
use App\Services\SeoIntel\Runtime\ScheduledRuntimeProbeReceiptService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SeoPlatform12A08SourceCheckTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.connections.seo_intel', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        config()->set('seo_intel.connection', 'seo_intel');
        config()->set('seo_council.runtime_cache_store', 'array');
        config()->set('cache.default', 'array');
        config()->set('public_content_observability.probe.cache_store', 'array');
        DB::purge('seo_intel');
        Http::preventStrayRequests();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        DB::purge('seo_intel');
        parent::tearDown();
    }

    public function test_source_command_refuses_unknown_scope_without_io_or_control_changes(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        foreach (['*', 'https://example.test', '../../secret', 'App\\Fake', ''] as $id) {
            $this->artisan('seo:council-source-check', ['--mission' => $id, '--json' => true])->assertFailed();
        }
        $this->assertSame([], $queries);
        $this->assertNull(Cache::get(Platform12RuntimeControl::CACHE_KEY));
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_missing_sources_remain_hold_and_never_become_valid_zero(): void
    {
        $report = app(Platform12SourceCheck::class)->check(Platform12DailyMissionSet::IDS[0]);
        $this->assertSame('HOLD', $report['source_wiring_status']);
        $this->assertFalse($report['real_runtime']);
        $this->assertFalse($report['mission_submitted']);
        $this->assertContains('gsc_scheduled_receipt', $report['source_gaps']);
        $this->assertNull(Cache::get(Platform12RuntimeControl::CACHE_KEY));
    }

    public function test_real_reader_failure_observation_is_wired_but_never_a_healthy_verdict(): void
    {
        Schema::connection('seo_intel')->create('seo_gsc_sync_runs', function (Blueprint $table): void {
            $table->id();
            foreach (['trigger_mode', 'status', 'started_at', 'finished_at', 'receipt_json', 'rows_seen', 'failure_code'] as $field) {
                $table->text($field)->nullable();
            }
        });
        DB::connection('seo_intel')->table('seo_gsc_sync_runs')->insert([
            'trigger_mode' => 'scheduled', 'status' => 'failed', 'started_at' => now()->subMinute(),
            'finished_at' => now(), 'rows_seen' => 0, 'failure_code' => 'upstream_failure',
        ]);
        Schema::connection('seo_intel')->create('seo_runtime_probe_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('trigger_mode');
            $table->string('scheduled_for');
            $table->text('receipt_json');
        });
        $at = now()->utc()->format('Y-m-d\TH:i:s\Z');
        $receipt = ['schema_version' => ScheduledRuntimeProbeReceiptService::SCHEMA_VERSION,
            'trigger_mode' => 'scheduled', 'status' => 'measurement_hold', 'scheduled_for' => $at, 'completed_at' => $at];
        $receipt['receipt_hash'] = ScheduledRuntimeProbeReceiptService::contentHash($receipt);
        DB::connection('seo_intel')->table('seo_runtime_probe_receipts')->insert([
            'trigger_mode' => 'scheduled', 'scheduled_for' => $at, 'receipt_json' => json_encode($receipt),
        ]);
        foreach (app(PublicContentDeliveryProbeService::class)->catalog() as $target) {
            Cache::put('public_content_delivery_probe:v1:latest:'.$target['id'], [
                'target_id' => $target['id'], 'observed_at' => $at, 'ok' => false, 'readback' => ['ok' => false],
            ]);
        }
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $report = app(Platform12SourceCheck::class)->check(Platform12DailyMissionSet::IDS[0]);
        $this->assertSame('VERIFIED', $report['source_wiring_status']);
        $this->assertSame('GSC_UNAVAILABLE_HOLD', $report['observed_verdict']);
        $this->assertFalse($report['real_runtime']);
        $this->assertSame([], array_filter($queries, static fn ($sql) => preg_match('/^\s*(insert|update|delete|replace|create|drop)\b/i', $sql)));
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_sitemap_projection_is_read_without_warm_or_xml_generation(): void
    {
        $payload = ['ok' => true, 'source' => 'backend_sitemap_generator', 'count' => 1,
            'items' => [['loc' => 'https://example.test/zh', 'lastmod' => '2026-09-07']]];
        Cache::put(SitemapSourceController::CACHE_KEY_FRESH, $payload);
        $method = new \ReflectionMethod(Platform12ProductionEvidenceReader::class, 'sitemap');
        $result = $method->invoke(app(Platform12ProductionEvidenceReader::class));
        $this->assertSame(['availability' => 'AVAILABLE', 'observation_count' => 1], $result);
        $this->assertSame($payload, Cache::get(SitemapSourceController::CACHE_KEY_FRESH));
        Cache::put(SitemapSourceController::CACHE_KEY_FRESH, [...$payload, 'count' => 2]);
        $this->expectExceptionMessage('SITEMAP_PROJECTION_INVALID');
        $method->invoke(app(Platform12ProductionEvidenceReader::class));
    }

    public function test_expected_generation_never_overwrites_a_new_operator_pause(): void
    {
        config()->set('seo_council.scheduler_enabled', true);
        config()->set('seo_council.daily_read_only_enabled', true);
        $runtime = app(Platform12RuntimeControl::class);
        $first = $runtime->change(false, [Platform12DailyMissionSet::IDS[0]]);
        $pause = $runtime->change(true);
        $runtime->change(false, [Platform12DailyMissionSet::IDS[0]], $first['generation']);
        $this->assertSame($pause['generation'], $runtime->status()['generation']);
        $this->assertSame('PAUSED', $runtime->status()['state']);
    }
}
