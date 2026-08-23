<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\GscReadModelSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelGscReadModelSyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-08-23 12:00:00 UTC');
        config([
            'database.connections.seo_intel_gsc_sync_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
            'seo_intel.connection' => 'seo_intel_gsc_sync_test',
            'seo_intel.write_enabled' => true,
            'seo_intel.allow_external_api_calls' => true,
            'seo_intel.gsc_enabled' => true,
            'seo_intel.gsc_live_api_enabled' => true,
            'seo_intel.gsc_property_url' => 'sc-domain:fermatmind.com',
            'seo_intel.gsc_backfill_lag_days' => 3,
            'seo_intel.gsc_readonly_adapter.auth_mode' => 'access_token',
            'seo_intel.gsc_readonly_adapter.access_token' => 'test-secret-token',
            'seo_intel.gsc_readonly_adapter.default_limit' => 1,
            'seo_intel.gsc_readonly_adapter.max_limit' => 1,
            'seo_intel.gsc_sync.max_pages_per_run' => 10,
        ]);
        DB::purge('seo_intel_gsc_sync_test');
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function sync_paginates_maps_url_truth_and_is_idempotent(): void
    {
        $url = 'https://fermatmind.com/zh/articles/gsc-live';
        DB::connection('seo_intel_gsc_sync_test')->table('seo_urls')->insert([
            'canonical_url_hash' => hash('sha256', $url),
            'canonical_url' => $url,
            'locale' => 'zh-CN',
        ]);
        $this->seedPreviousSuccess(28);

        Http::fake(static function (Request $request) use ($url) {
            if ((int) $request['startRow'] === 0) {
                return Http::response(['rows' => [[
                    'keys' => ['人格测试', $url, 'MOBILE', 'CHN'],
                    'clicks' => 4,
                    'impressions' => 100,
                    'ctr' => 0.04,
                    'position' => 8.5,
                ]]], 200);
            }

            return Http::response(['rows' => []], 200);
        });

        $first = app(GscReadModelSyncService::class)->sync(28, ['web']);
        $second = app(GscReadModelSyncService::class)->sync(28, ['web']);

        $this->assertSame('success', $first['status']);
        $this->assertSame(2, $first['pages_fetched']);
        $this->assertSame(1, $first['rows_upserted']);
        $this->assertSame(0, $first['unmapped_rows']);
        $this->assertSame('success', $second['status']);
        $this->assertSame(1, DB::connection('seo_intel_gsc_sync_test')->table('seo_gsc_daily')->count());

        $row = DB::connection('seo_intel_gsc_sync_test')->table('seo_gsc_daily')->first();
        $this->assertSame('mapped', $row->mapping_state);
        $this->assertSame('MOBILE', $row->device);
        $this->assertSame('CHN', $row->country);
        $this->assertSame('web', $row->search_type);
        $this->assertNotNull($row->url_truth_id);
        Http::assertSentCount(4);
    }

    #[Test]
    public function unmapped_urls_enter_quality_queue_without_becoming_fake_zeroes(): void
    {
        $this->seedPreviousSuccess();
        Http::fake(static fn (Request $request) => (int) $request['startRow'] === 0
            ? Http::response(['rows' => [[
                'keys' => ['unknown', 'https://fermatmind.com/unknown', 'DESKTOP', 'USA'],
                'clicks' => 0,
                'impressions' => 2,
                'ctr' => 0,
                'position' => 30,
            ]]], 200)
            : Http::response(['rows' => []], 200));

        $result = app(GscReadModelSyncService::class)->sync(7, ['web']);
        $repeat = app(GscReadModelSyncService::class)->sync(7, ['web']);

        $this->assertSame('success', $result['status']);
        $this->assertSame('success', $repeat['status']);
        $this->assertSame(1, $result['unmapped_rows']);
        $this->assertDatabaseHas('seo_gsc_data_quality_queue', [
            'issue_code' => 'canonical_url_not_in_url_truth',
            'status' => 'open',
        ], 'seo_intel_gsc_sync_test');
        $this->assertSame('unmapped', DB::connection('seo_intel_gsc_sync_test')->table('seo_gsc_daily')->value('mapping_state'));
        $this->assertSame(1, DB::connection('seo_intel_gsc_sync_test')->table('seo_gsc_data_quality_queue')->count());
    }

    #[Test]
    public function wider_windows_and_new_search_types_are_backfilled_before_incrementing(): void
    {
        $this->seedPreviousSuccess();
        Http::fake(['*' => Http::response(['rows' => []], 200)]);

        $service = app(GscReadModelSyncService::class);
        $method = new \ReflectionMethod($service, 'incrementalStartDate');
        $connection = DB::connection('seo_intel_gsc_sync_test');
        $endDate = CarbonImmutable::now('UTC')->subDays(3)->startOfDay();

        $sameCoverage = $method->invoke($service, $connection, $endDate->subDays(6), $endDate, 7, ['web']);
        $widerWindow = $method->invoke($service, $connection, $endDate->subDays(89), $endDate, 90, ['web']);
        $newSearchType = $method->invoke($service, $connection, $endDate->subDays(6), $endDate, 7, ['web', 'image']);

        $this->assertSame($endDate->toDateString(), $sameCoverage->toDateString());
        $this->assertSame($endDate->subDays(89)->toDateString(), $widerWindow->toDateString());
        $this->assertSame($endDate->subDays(6)->toDateString(), $newSearchType->toDateString());
    }

    #[Test]
    public function disconnected_credentials_are_explicit_without_external_calls(): void
    {
        config(['seo_intel.gsc_readonly_adapter.access_token' => '']);
        $disconnected = app(GscReadModelSyncService::class)->sync(7, ['web']);
        $this->assertSame('gsc_disconnected', $disconnected['issue']);
        $this->assertContains('gsc_access_token_missing', $disconnected['details']);
        Http::assertNothingSent();

    }

    #[Test]
    public function authentication_and_rate_limit_failures_are_explicit(): void
    {
        $this->seedPreviousSuccess();
        Http::fakeSequence()->push([], 401)->push([], 429);

        $auth = app(GscReadModelSyncService::class)->sync(7, ['web']);
        $rateLimit = app(GscReadModelSyncService::class)->sync(7, ['web']);

        $this->assertSame('gsc_authentication_failed', $auth['issue']);
        $this->assertSame('gsc_rate_limited', $rateLimit['issue']);
    }

    #[Test]
    public function timeout_is_explicit_and_does_not_leak_transport_detail(): void
    {
        $this->seedPreviousSuccess();
        Http::fake(Http::failedConnection('private timeout detail'));
        $timeout = app(GscReadModelSyncService::class)->sync(7, ['web']);
        $this->assertSame('gsc_searchanalytics_timeout_or_transport_failure', $timeout['issue']);
        $this->assertStringNotContainsString('private timeout detail', json_encode($timeout, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function empty_response_is_explicit(): void
    {
        $this->seedPreviousSuccess();
        Http::fake(['*' => Http::response(['rows' => []], 200)]);
        $empty = app(GscReadModelSyncService::class)->sync(7, ['web']);
        $this->assertSame('gsc_empty_response', $empty['issue']);
    }

    #[Test]
    public function quality_gate_failure_is_explicit(): void
    {
        $this->seedPreviousSuccess();
        Http::fake(static fn (Request $request) => (int) $request['startRow'] === 0
            ? Http::response(['rows' => [[
                'keys' => ['one row', 'https://fermatmind.com/one', 'DESKTOP', 'USA'],
                'clicks' => 0,
                'impressions' => 1,
                'ctr' => 0,
                'position' => 10,
            ]]], 200)
            : Http::response(['rows' => []], 200));
        config(['seo_intel.gsc_data_quality.min_rows' => 2]);
        $quality = app(GscReadModelSyncService::class)->sync(7, ['web']);

        $this->assertSame('gsc_data_quality_gate_failed', $quality['issue']);
        $this->assertSame('blocked', data_get($quality, 'quality_gate.status'));
    }

    private function seedPreviousSuccess(int $windowDays = 7): void
    {
        $date = CarbonImmutable::now('UTC')->subDays(4)->toDateString();
        DB::connection('seo_intel_gsc_sync_test')->table('seo_gsc_sync_runs')->insertOrIgnore([
            'sync_run_uid' => '00000000-0000-4000-8000-000000000001',
            'window_days' => $windowDays,
            'start_date' => $date,
            'end_date' => $date,
            'search_types_json' => '["web"]',
            'status' => 'success',
            'started_at' => now(),
            'finished_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSchema(): void
    {
        $schema = Schema::connection('seo_intel_gsc_sync_test');
        $schema->create('seo_urls', function (Blueprint $table): void {
            $table->id();
            $table->char('canonical_url_hash', 64)->unique();
            $table->text('canonical_url');
            $table->string('locale', 16)->nullable();
        });
        $schema->create('seo_gsc_daily', function (Blueprint $table): void {
            $table->id();
            $table->char('idempotency_key', 64)->unique();
            $table->date('report_date');
            $table->char('canonical_url_hash', 64)->nullable();
            $table->text('canonical_url')->nullable();
            $table->unsignedBigInteger('url_truth_id')->nullable();
            $table->string('mapping_state', 32)->default('unmapped');
            $table->uuid('sync_run_uid')->nullable();
            $table->char('query_hash', 64)->nullable();
            $table->string('query_display_masked')->nullable();
            $table->string('locale', 16)->nullable();
            $table->string('source_engine', 64);
            $table->string('device', 32)->nullable();
            $table->string('country', 16)->nullable();
            $table->string('search_type', 32)->nullable();
            $table->unsignedInteger('clicks');
            $table->unsignedInteger('impressions');
            $table->unsignedInteger('ctr_ppm')->nullable();
            $table->unsignedInteger('average_position_milli')->nullable();
            $table->boolean('is_brand_query');
            $table->string('query_type', 32);
            $table->string('data_state', 32);
            $table->timestamp('collected_at')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
        });
        $schema->create('seo_gsc_sync_runs', function (Blueprint $table): void {
            $table->uuid('sync_run_uid')->primary();
            $table->unsignedSmallInteger('window_days');
            $table->date('start_date');
            $table->date('end_date');
            $table->json('search_types_json');
            $table->string('status', 32);
            $table->unsignedInteger('pages_fetched')->default(0);
            $table->unsignedInteger('rows_seen')->default(0);
            $table->unsignedInteger('rows_upserted')->default(0);
            $table->unsignedInteger('unmapped_rows')->default(0);
            $table->string('failure_code')->nullable();
            $table->json('quality_gate_json')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
        $schema->create('seo_gsc_data_quality_queue', function (Blueprint $table): void {
            $table->id();
            $table->uuid('sync_run_uid');
            $table->date('report_date');
            $table->char('canonical_url_hash', 64);
            $table->string('issue_code');
            $table->string('status', 32);
            $table->json('details_json')->nullable();
            $table->timestamps();
            $table->unique(['report_date', 'canonical_url_hash', 'issue_code']);
        });
    }
}
