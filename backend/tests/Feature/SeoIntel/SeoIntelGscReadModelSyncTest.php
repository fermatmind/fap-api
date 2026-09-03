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
            'seo_intel.gsc_reporting_timezone' => 'America/Los_Angeles',
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
            'page_entity_type' => 'article',
            'entity_id_or_slug' => 'gsc-live',
            'indexability_state' => 'indexable',
            'is_private_flow' => false,
        ]);
        DB::connection('seo_intel_gsc_sync_test')->table('seo_url_entities')->insert([
            'canonical_url_hash' => hash('sha256', $url),
            'locale' => 'zh-CN',
            'page_entity_type' => 'article',
            'entity_id_or_slug' => 'gsc-live',
            'binding_status' => 'current',
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
        $this->assertSame($url, $row->canonical_url);
        $this->assertSame('MOBILE', $row->device);
        $this->assertSame('CHN', $row->country);
        $this->assertSame('web', $row->search_type);
        $this->assertNotNull($row->url_truth_id);
        Http::assertSentCount(4);
    }

    #[Test]
    public function non_authority_urls_enter_quality_queue_without_entering_primary_readmodel(): void
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
        $this->assertSame('seo.gsc_refresh_receipt.v2', $result['schema_version']);
        $this->assertSame('testing', $result['environment']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $result['readmodel_snapshot_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $result['receipt_hash']);
        $this->assertSame('success', $repeat['status']);
        $this->assertSame(0, $result['unmapped_rows']);
        $this->assertSame(0, $result['mapped_rows']);
        $this->assertSame(1, $result['excluded_non_authority_rows']);
        $this->assertDatabaseHas('seo_gsc_data_quality_queue', [
            'issue_code' => 'canonical_url_not_in_current_public_authority',
            'status' => 'open',
        ], 'seo_intel_gsc_sync_test');
        $this->assertSame(0, DB::connection('seo_intel_gsc_sync_test')->table('seo_gsc_daily')->count());
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
    public function scheduled_full_window_reconciles_all_dates_and_emits_only_sanitized_closeout_statistics(): void
    {
        config([
            'seo_intel.gsc_sync.max_pages_per_run' => 20,
            'seo_intel.url_truth_inventory.backend_authority_canary_candidates' => [[
                'path' => '/zh/articles/full-window',
                'locale' => 'zh-CN',
                'page_entity_type' => 'article',
                'entity_id_or_slug' => 'full-window',
                'source_authority' => 'backend_cms',
            ]],
        ]);
        $this->seedPreviousSuccess();
        Http::fake(static fn (Request $request) => (int) $request['startRow'] === 0
            ? Http::response(['rows' => [[
                'keys' => ['query', 'https://www.fermatmind.com/zh/articles/full-window', 'DESKTOP', 'CHN'],
                'clicks' => 1,
                'impressions' => 10,
                'ctr' => 0.1,
                'position' => 5,
            ]]], 200)
            : Http::response(['rows' => []], 200));

        $result = app(GscReadModelSyncService::class)->sync(7, ['web'], true, 'scheduled');

        $this->assertSame('success', $result['status']);
        $this->assertSame('full_window', $result['fetch_mode']);
        $this->assertSame('scheduled', $result['trigger_mode']);
        $this->assertSame('America/Los_Angeles', $result['reporting_timezone']);
        $this->assertSame('restricted', data_get($result, 'restricted_egress.status'));
        $this->assertTrue(data_get($result, 'read_only_gsc'));
        $this->assertSame(0, $result['duplicate_natural_keys']);
        $this->assertSame(7, $result['mapped_rows']);
        $this->assertSame(0, $result['unmapped_rows']);
        $this->assertSame(0, $result['excluded_non_authority_rows']);
        $this->assertSame('2026-08-20', $result['data_max_date']);
        $this->assertEquals(3, $result['data_lag_days']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $result['property_hash']);
        $this->assertSame('2026-08-14', $result['start_date']);
        $this->assertSame(7, data_get($result, 'gsc_data_quality.fetched.date_point_count'));
        $this->assertSame(7, data_get($result, 'gsc_data_quality.fetched.natural_unique_key_count'));
        $this->assertSame(0, data_get($result, 'gsc_data_quality.overlap_comparison.natural_key_duplicate_count'));
        $this->assertSame(1, data_get($result, 'unmapped_classification.unique_normalized_canonical_url_count'));
        $this->assertSame(1, data_get($result, 'unmapped_classification.page_family_distribution.Articles'));
        $this->assertSame(1, data_get($result, 'unmapped_classification.locale_distribution.zh-CN'));
        $this->assertSame(1, data_get($result, 'unmapped_classification.root_cause_distribution.current_url_truth_missing'));
        $this->assertSame(1, data_get($result, 'unmapped_classification.current_url_truth_missing_handoff_count'));
        $this->assertSame(1, data_get($result, 'unmapped_classification.current_url_truth_missing_distribution.page_family.articles_topics'));
        $this->assertSame(1, data_get($result, 'unmapped_classification.current_url_truth_missing_distribution.locale.zh-CN'));
        $this->assertGreaterThanOrEqual(1, data_get($result, 'unmapped_classification.backend_authority_candidate_count'));
        $this->assertSame('backend_cms_and_persisted_url_truth_only', data_get($result, 'unmapped_classification.classification_authority'));
        $this->assertFalse(data_get($result, 'unmapped_classification.raw_url_retained_or_emitted'));
        $this->assertStringNotContainsString('full-window', json_encode($result['unmapped_classification'], JSON_THROW_ON_ERROR));
        $persisted = DB::connection('seo_intel_gsc_sync_test')->table('seo_gsc_daily')->first();
        $metadata = json_decode((string) $persisted->metadata_json, true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame('mapped', $persisted->mapping_state);
        $this->assertNull($persisted->url_truth_id);
        $this->assertSame('articles_topics', $metadata['page_family']);
        $this->assertSame('current_public_url_authority_read_only', $metadata['mapping_authority']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $metadata['authority_revision']);
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
            $table->string('page_entity_type', 64)->nullable();
            $table->string('entity_id_or_slug')->nullable();
            $table->string('indexability_state', 64)->default('indexable');
            $table->boolean('is_private_flow')->default(false);
        });
        $schema->create('seo_url_entities', function (Blueprint $table): void {
            $table->id();
            $table->char('canonical_url_hash', 64);
            $table->string('locale', 16);
            $table->string('page_entity_type', 64);
            $table->string('entity_id_or_slug');
            $table->string('binding_status', 64)->nullable();
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
            $table->string('trigger_mode', 32)->default('manual');
            $table->string('status', 32);
            $table->unsignedInteger('pages_fetched')->default(0);
            $table->unsignedInteger('rows_seen')->default(0);
            $table->unsignedInteger('rows_upserted')->default(0);
            $table->unsignedInteger('unmapped_rows')->default(0);
            $table->string('failure_code')->nullable();
            $table->json('quality_gate_json')->nullable();
            $table->json('receipt_json')->nullable();
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
