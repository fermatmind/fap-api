<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentEvidence\Bundle\SeoEvidenceBundleVerifier;
use App\Services\SeoAgentEvidence\Competitive\MeasurementSnapshotVerifier;
use App\Services\SeoCouncil\Measurement\ReadOnlyMeasurementEvidenceBundleLoader;
use App\Services\SeoIntel\GscRunCloseoutSummarizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SeoPlatform11FEvidenceSourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['seo_intel.connection' => config('database.default')]);
        foreach (['analytics_seo_conversion_refresh_runs', 'analytics_seo_conversion_daily', 'seo_event_funnel_daily', 'seo_gsc_sync_runs', 'seo_gsc_daily', 'seo_urls'] as $table) {
            Schema::dropIfExists($table);
        }
        \App\Support\SchemaBaseline::clearCache();
        $this->createReadModels();
        $this->seedReadModels();
    }

    public function test_loader_reads_environment_local_gsc_and_public_funnel_aggregates_without_fixture_fallback(): void
    {
        $loader = app(ReadOnlyMeasurementEvidenceBundleLoader::class);
        $this->assertSame([], $loader->loadForScope('mission:source', 'search_measurement', 'tests', 'en', 'ci_candidate'));

        $search = $loader->loadForScope('mission:source', 'search_measurement', 'tests', 'en', 'staging_runtime');
        $this->assertCount(1, $search);
        $this->assertSame('gsc_aggregate', $search[0]['source_type']);
        $this->assertSame('available', $search[0]['source_capability_state']);
        $this->assertSame('fresh', $search[0]['freshness_state']);
        $this->assertSame([7, 28, 90], array_column($search[0]['payload']['windows'], 'window_days'));
        $this->assertTrue(app(SeoEvidenceBundleVerifier::class)->verify($search[0])['valid']);
        $this->assertSame('NONE', $loader->diagnoseForScope('mission:source', 'search_measurement', 'tests', 'en', 'staging_runtime')->diagnostic()['hold_reason']);

        $cro = $loader->loadForScope('mission:source', 'commercial_funnel_cro', 'tests', 'en', 'staging_runtime');
        $this->assertCount(1, $cro);
        $this->assertSame('public_funnel_aggregate', $cro[0]['source_type']);
        $this->assertSame('available', $cro[0]['source_capability_state']);
        $this->assertSame('fresh', $cro[0]['freshness_state']);
        $this->assertTrue(app(SeoEvidenceBundleVerifier::class)->verify($cro[0])['valid']);
        $this->assertSame('NONE', $loader->diagnoseForScope('mission:source', 'commercial_funnel_cro', 'tests', 'en', 'staging_runtime')->diagnostic()['hold_reason']);

        $encoded = json_encode([$search, $cro], JSON_THROW_ON_ERROR);
        foreach (['canonical_url', 'raw_query', 'query_display_masked', 'user_id', 'database'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
    }

    public function test_measurement_snapshot_is_deterministic_and_release_sha_independent(): void
    {
        $verifier = app(MeasurementSnapshotVerifier::class);
        $first = $verifier->verify(str_repeat('a', 40), 'tests', 'production');
        $second = $verifier->verify(str_repeat('b', 40), 'tests', 'production');

        $this->assertSame('READY', $first['status']);
        $this->assertSame($first['measurement_snapshot_set_hash'], $second['measurement_snapshot_set_hash']);
        $this->assertSame($first['search_measurement']['snapshot_hash'], $second['search_measurement']['snapshot_hash']);
        $this->assertSame($first['cro_measurement']['snapshot_hash'], $second['cro_measurement']['snapshot_hash']);
        $this->assertTrue($verifier->refreshable('search_measurement', 'GSC_STALE'));
        $this->assertFalse($verifier->refreshable('search_measurement', 'GSC_MAPPING_FAILED'));
        $this->assertTrue($verifier->refreshable('commercial_funnel_cro', 'CRO_WINDOW_INCOMPLETE'));
        $this->assertFalse($verifier->refreshable('commercial_funnel_cro', 'CRO_MAPPING_FAILED'));
    }

    public function test_loader_uses_read_only_current_authority_metadata_when_url_truth_is_empty(): void
    {
        DB::table('seo_urls')->delete();
        DB::table('seo_gsc_daily')->update([
            'metadata_json' => json_encode([
                'data_origin' => 'live_gsc_api',
                'row_source' => 'live_gsc_api',
                'page_family' => 'tests',
                'authority_revision' => str_repeat('b', 64),
                'mapping_authority' => 'current_public_authority_read_only',
                'source_authority' => 'backend_registry',
            ], JSON_THROW_ON_ERROR),
        ]);

        $loader = app(ReadOnlyMeasurementEvidenceBundleLoader::class);
        $search = $loader->loadForScope(
            'mission:read-only-authority',
            'search_measurement',
            'tests',
            'en',
            'staging_runtime'
        );

        $this->assertCount(1, $search);
        $this->assertSame('available', $search[0]['source_capability_state']);
        $this->assertSame(str_repeat('b', 64), $search[0]['authority_revision']);
        $this->assertSame(
            'NONE',
            $loader->diagnoseForRuntime(
                'mission:read-only-authority-runtime',
                'search_measurement',
                'staging_runtime'
            )->diagnostic()['hold_reason']
        );
        $this->assertDatabaseCount('seo_urls', 0);
    }

    public function test_missing_mapping_and_stale_sources_return_hold_or_no_bundle_never_defaults(): void
    {
        DB::table('seo_gsc_daily')->update(['mapping_state' => 'failed']);
        $loader = app(ReadOnlyMeasurementEvidenceBundleLoader::class);
        $bundles = $loader->loadForScope('mission:mapping', 'search_measurement', 'tests', 'en', 'production_runtime');
        $this->assertCount(1, $bundles);
        $this->assertSame('held', $bundles[0]['source_capability_state']);
        $this->assertSame('failed', $bundles[0]['payload']['mapping_state']);

        DB::table('seo_gsc_daily')->update([
            'mapping_state' => 'mapped', 'report_date' => now('UTC')->subDays(30)->toDateString(),
        ]);
        $stale = $loader->loadForScope('mission:stale', 'search_measurement', 'tests', 'en', 'production_runtime');
        $this->assertCount(1, $stale);
        $this->assertSame('held', $stale[0]['source_capability_state']);
        $this->assertSame('stale', $stale[0]['freshness_state']);

        DB::table('seo_gsc_daily')->delete();
        $this->assertSame([], $loader->loadForScope('mission:missing', 'search_measurement', 'tests', 'en', 'production_runtime'));
    }

    public function test_search_diagnostics_distinguish_schema_data_quality_window_mapping_authority_and_readmodel_failures(): void
    {
        $loader = app(ReadOnlyMeasurementEvidenceBundleLoader::class);

        Schema::table('seo_gsc_daily', static function (Blueprint $table): void {
            $table->dropColumn('mapping_state');
        });
        $this->assertSame('GSC_SCHEMA_UNAVAILABLE', $loader->diagnoseForScope('mission:schema', 'search_measurement', 'tests', 'en', 'staging_runtime')->diagnostic()['hold_reason']);

        $this->setUpReadModels();
        DB::table('seo_gsc_daily')->delete();
        $this->assertSame('GSC_NO_ELIGIBLE_ROWS', app(ReadOnlyMeasurementEvidenceBundleLoader::class)->diagnoseForScope('mission:rows', 'search_measurement', 'tests', 'en', 'staging_runtime')->diagnostic()['hold_reason']);

        $this->setUpReadModels();
        DB::table('seo_gsc_daily')->update(['metadata_json' => json_encode(['data_origin' => 'fixture'], JSON_THROW_ON_ERROR)]);
        $this->assertSame('GSC_QUALITY_HOLD', app(ReadOnlyMeasurementEvidenceBundleLoader::class)->diagnoseForScope('mission:quality', 'search_measurement', 'tests', 'en', 'staging_runtime')->diagnostic()['hold_reason']);

        $this->setUpReadModels();
        DB::table('seo_gsc_daily')->where('report_date', now('UTC')->subDays(50)->toDateString())->delete();
        $this->assertSame('GSC_WINDOW_INCOMPLETE', app(ReadOnlyMeasurementEvidenceBundleLoader::class)->diagnoseForScope('mission:window', 'search_measurement', 'tests', 'en', 'staging_runtime')->diagnostic()['hold_reason']);

        $this->setUpReadModels();
        DB::table('seo_gsc_daily')->update(['mapping_state' => 'failed']);
        $this->assertSame('GSC_MAPPING_FAILED', app(ReadOnlyMeasurementEvidenceBundleLoader::class)->diagnoseForScope('mission:mapping', 'search_measurement', 'tests', 'en', 'staging_runtime')->diagnostic()['hold_reason']);

        $this->setUpReadModels();
        DB::table('seo_urls')->update(['authority_revision' => 'invalid']);
        $this->assertSame('GSC_AUTHORITY_CONFLICT', app(ReadOnlyMeasurementEvidenceBundleLoader::class)->diagnoseForScope('mission:authority', 'search_measurement', 'tests', 'en', 'staging_runtime')->diagnostic()['hold_reason']);

        $this->setUpReadModels();
        DB::table('seo_gsc_daily')->update(['canonical_url' => 'https://www.fermatmind.com/en/other/public']);
        $this->assertSame('GSC_READMODEL_UNHEALTHY', app(ReadOnlyMeasurementEvidenceBundleLoader::class)->diagnoseForScope('mission:readmodel', 'search_measurement', 'tests', 'en', 'staging_runtime')->diagnostic()['hold_reason']);
    }

    public function test_search_window_reuses_a_verified_environment_snapshot_across_release_shas(): void
    {
        $sha = str_repeat('c', 40);
        config([
            'app.git_sha' => $sha,
            'seo_intel.gsc_reporting_timezone' => 'UTC',
        ]);
        DB::table('seo_gsc_daily')
            ->where('report_date', '>', now('UTC')->subDays(20)->toDateString())
            ->delete();

        $start = CarbonImmutable::parse(now('UTC')->subDays(92)->toDateString(), 'UTC');
        $end = CarbonImmutable::parse(now('UTC')->subDays(3)->toDateString(), 'UTC');
        $snapshot = app(GscRunCloseoutSummarizer::class)->readModelSnapshot(DB::connection(), $start, $end, ['web']);

        $receipt = [
            'status' => 'success',
            'fetch_mode' => 'full_window',
            'window_days' => 90,
            'requested_start_date' => now('UTC')->subDays(92)->toDateString(),
            'end_date' => now('UTC')->subDays(3)->toDateString(),
            'search_types' => ['web'],
            'pages_fetched' => 90,
            'rows_seen' => 90,
            'mapped_rows' => 90,
            'unmapped_rows' => 0,
            'duplicate_natural_keys' => 0,
            'quality_gate' => ['status' => 'pass'],
            'read_only_gsc' => true,
            'search_submission_allowed' => false,
            'restricted_egress' => ['status' => 'restricted'],
            'gsc_data_quality' => ['read_model_after' => $snapshot],
            'application_sha' => str_repeat('d', 40),
            'workflow_sha' => str_repeat('d', 40),
            'active_production_sha' => str_repeat('d', 40),
        ];
        DB::table('seo_gsc_sync_runs')->insert([
            'status' => 'success',
            'receipt_json' => json_encode($receipt, JSON_THROW_ON_ERROR),
            'started_at' => now('UTC'),
            'finished_at' => now('UTC'),
        ]);
        $loader = app(ReadOnlyMeasurementEvidenceBundleLoader::class);
        $this->assertSame('NONE', $loader->diagnoseForScope('mission:cross-sha', 'search_measurement', 'tests', 'en', 'staging_runtime')->diagnostic()['hold_reason']);

        DB::table('seo_gsc_daily')
            ->where('report_date', now('UTC')->subDays(20)->toDateString())
            ->update(['report_date' => now('UTC')->subDays(3)->toDateString()]);
        $this->assertSame('GSC_WINDOW_INCOMPLETE', $loader->diagnoseForScope('mission:drifted-snapshot', 'search_measurement', 'tests', 'en', 'staging_runtime')->diagnostic()['hold_reason']);
    }

    public function test_cro_diagnostics_distinguish_schema_readmodel_stale_and_mapping_failures(): void
    {
        Schema::drop('analytics_seo_conversion_daily');
        \App\Support\SchemaBaseline::clearCache();
        $this->assertSame('CRO_SCHEMA_UNAVAILABLE', app(ReadOnlyMeasurementEvidenceBundleLoader::class)->diagnoseForScope('mission:cro-schema', 'commercial_funnel_cro', 'tests', 'en', 'staging_runtime')->diagnostic()['hold_reason']);

        $this->setUpReadModels();
        DB::table('seo_gsc_daily')->delete();
        $this->assertSame('NONE', app(ReadOnlyMeasurementEvidenceBundleLoader::class)->diagnoseForScope('mission:cro-independent', 'commercial_funnel_cro', 'tests', 'en', 'staging_runtime')->diagnostic()['hold_reason']);

        $this->setUpReadModels();
        DB::table('analytics_seo_conversion_refresh_runs')->update(['completed_at' => now('UTC')->subDays(5)]);
        DB::table('analytics_seo_conversion_daily')->update(['last_refreshed_at' => now('UTC')->subDays(5)]);
        $this->assertSame('CRO_STALE', app(ReadOnlyMeasurementEvidenceBundleLoader::class)->diagnoseForScope('mission:cro-stale', 'commercial_funnel_cro', 'tests', 'en', 'staging_runtime')->diagnostic()['hold_reason']);

        $this->setUpReadModels();
        DB::table('analytics_seo_conversion_refresh_runs')->delete();
        DB::table('analytics_seo_conversion_daily')->update(['last_refreshed_at' => null]);
        $this->assertSame('CRO_READMODEL_UNHEALTHY', app(ReadOnlyMeasurementEvidenceBundleLoader::class)->diagnoseForScope('mission:cro-readmodel', 'commercial_funnel_cro', 'tests', 'en', 'staging_runtime')->diagnostic()['hold_reason']);
    }

    public function test_runtime_scope_diagnoses_search_and_cro_from_independent_readmodels(): void
    {
        DB::table('seo_gsc_daily')->delete();
        $loader = app(ReadOnlyMeasurementEvidenceBundleLoader::class);

        $this->assertSame('GSC_NO_ELIGIBLE_ROWS', $loader->diagnoseForRuntime('mission:runtime-search', 'search_measurement', 'staging_runtime')->diagnostic()['hold_reason']);
        $this->assertSame('NONE', $loader->diagnoseForRuntime('mission:runtime-cro', 'commercial_funnel_cro', 'staging_runtime')->diagnostic()['hold_reason']);
    }

    public function test_runtime_search_reuses_the_same_fixed_scope_verified_before_activation(): void
    {
        $canonical = 'https://www.fermatmind.com/en/articles/unmapped';
        $canonicalHash = hash('sha256', $canonical);
        DB::table('seo_urls')->insert([
            'canonical_url_hash' => $canonicalHash, 'canonical_url' => $canonical, 'locale' => 'en',
            'page_entity_type' => 'article', 'page_family' => 'articles_topics', 'source_authority' => 'backend_registry',
            'indexability_state' => 'indexable', 'is_private_flow' => false, 'authority_revision' => str_repeat('c', 64),
        ]);
        DB::table('seo_gsc_daily')->insert([
            'report_date' => now('UTC')->subDays(3)->toDateString(), 'canonical_url_hash' => $canonicalHash,
            'canonical_url' => $canonical, 'query_hash' => hash('sha256', 'unmapped-aggregate'),
            'source_engine' => 'google', 'locale' => 'en', 'device' => 'desktop', 'country' => 'usa',
            'search_type' => 'web', 'query_type' => 'non_brand', 'query_display_masked' => null,
            'data_state' => 'final', 'clicks' => 1, 'impressions' => 10, 'ctr_ppm' => 100000,
            'average_position_milli' => 9000, 'is_brand_query' => false, 'mapping_state' => 'failed',
            'metadata_json' => json_encode(['data_origin' => 'live_gsc_api'], JSON_THROW_ON_ERROR),
            'collected_at' => now('UTC'),
        ]);

        $loader = app(ReadOnlyMeasurementEvidenceBundleLoader::class);

        $this->assertSame(
            'GSC_MAPPING_FAILED',
            $loader->diagnoseForScope(
                'mission:unmapped-scope',
                'search_measurement',
                'articles_topics',
                'en',
                'production_runtime',
            )->diagnostic()['hold_reason'],
        );
        $this->assertSame(
            'NONE',
            $loader->diagnoseForRuntime(
                'mission:fixed-runtime-scope',
                'search_measurement',
                'production_runtime',
            )->diagnostic()['hold_reason'],
        );
    }

    public function test_runtime_cro_reuses_the_same_fixed_scope_verified_before_activation(): void
    {
        DB::table('analytics_seo_conversion_daily')->insert([
            'day' => now('UTC')->subDay()->toDateString(), 'org_id' => 0,
            'url' => '/en/articles/new', 'lang' => 'en', 'page_type' => 'article',
            'source_article' => 'new', 'target_test' => '/en/tests/public', 'scale_id' => 'public',
            'form_id' => 'default', 'source_url' => '/en/articles/new', 'landing_pv_count' => 1,
            'article_to_test_click_count' => 1, 'start_test_count' => 1, 'complete_test_count' => 1,
            'view_result_count' => 1, 'return_public_content_count' => 1, 'last_refreshed_at' => now('UTC'),
        ]);

        $loader = app(ReadOnlyMeasurementEvidenceBundleLoader::class);
        $runtime = $loader->diagnoseForRuntime(
            'mission:fixed-runtime-cro-scope',
            'commercial_funnel_cro',
            'production_runtime',
        );

        $this->assertSame('NONE', $runtime->diagnostic()['hold_reason']);
        $this->assertSame('tests', data_get($runtime->bundles(), '0.page_family'));
        $this->assertSame('en', data_get($runtime->bundles(), '0.locale'));
    }

    public function test_runtime_scope_reuses_verified_public_zero_refresh_proof_across_release_shas(): void
    {
        $sha = str_repeat('e', 40);
        config(['app.git_sha' => $sha]);
        DB::table('analytics_seo_conversion_daily')->delete();
        DB::table('analytics_seo_conversion_refresh_runs')->delete();
        $zeroMetrics = [
            'landing_pv_count' => 0,
            'article_to_test_click_count' => 0,
            'start_test_count' => 0,
            'complete_test_count' => 0,
            'result_ready_count' => 0,
            'view_result_count' => 0,
            'return_public_content_count' => 0,
        ];
        $receipt = [
            'schema_version' => 'analytics-seo-conversion-refresh-receipt.v1',
            'status' => 'success',
            'application_sha' => str_repeat('d', 40),
            'workflow_sha' => str_repeat('d', 40),
            'active_production_sha' => str_repeat('d', 40),
            'from' => now('UTC')->subDays(89)->toDateString(),
            'to' => now('UTC')->toDateString(),
            'org_scope_mode' => 'bounded',
            'org_scope_count' => 1,
            'public_org_zero_only' => true,
            'attempted_rows' => 0,
            'upserted_rows' => 0,
            'readback_receipt' => [
                'status' => 'pass',
                'expected_metrics' => $zeroMetrics,
                'persisted_metrics' => $zeroMetrics,
            ],
            'raw_query_exposed' => false,
            'raw_session_or_business_identifiers_exposed' => false,
            'private_paths_allowed' => false,
            'search_submission_allowed' => false,
        ];
        DB::table('analytics_seo_conversion_refresh_runs')->insert([
            'org_scope_count' => 1,
            'status' => 'success',
            'trigger_mode' => 'manual',
            'receipt_json' => json_encode($receipt, JSON_THROW_ON_ERROR),
            'completed_at' => now('UTC'),
        ]);
        $loader = app(ReadOnlyMeasurementEvidenceBundleLoader::class);
        $result = $loader->diagnoseForRuntime(
            'mission:cross-sha-public-zero',
            'commercial_funnel_cro',
            'staging_runtime',
        );

        $this->assertSame('NONE', $result->diagnostic()['hold_reason']);
        $this->assertTrue(data_get($result->bundles(), '0.payload.explicit_zero_proof'));
        $this->assertSame(0, array_sum(data_get($result->bundles(), '0.payload.windows.0.metrics', [])));
        $this->assertDatabaseCount('analytics_seo_conversion_daily', 0);
    }

    private function setUpReadModels(): void
    {
        foreach (['analytics_seo_conversion_refresh_runs', 'analytics_seo_conversion_daily', 'seo_event_funnel_daily', 'seo_gsc_sync_runs', 'seo_gsc_daily', 'seo_urls'] as $table) {
            Schema::dropIfExists($table);
        }
        \App\Support\SchemaBaseline::clearCache();
        $this->createReadModels();
        $this->seedReadModels();
        $this->app->forgetInstance(ReadOnlyMeasurementEvidenceBundleLoader::class);
    }

    private function createReadModels(): void
    {
        Schema::create('seo_urls', function (Blueprint $table): void {
            $table->string('canonical_url_hash');
            $table->string('canonical_url');
            $table->string('locale');
            $table->string('page_entity_type');
            $table->string('page_family');
            $table->string('source_authority');
            $table->string('indexability_state');
            $table->boolean('is_private_flow');
            $table->string('authority_revision');
        });
        Schema::create('seo_gsc_daily', function (Blueprint $table): void {
            $table->date('report_date');
            $table->string('canonical_url_hash');
            $table->string('canonical_url');
            $table->string('query_hash');
            $table->string('source_engine');
            $table->string('locale');
            $table->string('device');
            $table->string('country');
            $table->string('search_type');
            $table->string('query_type');
            $table->string('query_display_masked')->nullable();
            $table->string('data_state');
            $table->unsignedInteger('clicks');
            $table->unsignedInteger('impressions');
            $table->unsignedInteger('ctr_ppm');
            $table->unsignedInteger('average_position_milli');
            $table->boolean('is_brand_query');
            $table->string('mapping_state');
            $table->text('metadata_json');
            $table->timestamp('collected_at');
        });
        Schema::create('seo_event_funnel_daily', function (Blueprint $table): void {
            $table->date('report_date');
            $table->string('canonical_url_hash');
            $table->string('source_engine');
            $table->string('traffic_quality');
            $table->string('environment');
            $table->unsignedInteger('start_attempt_count');
            $table->unsignedInteger('submit_attempt_count');
            $table->unsignedInteger('view_result_count');
        });
        Schema::create('analytics_seo_conversion_daily', function (Blueprint $table): void {
            $table->date('day');
            $table->unsignedBigInteger('org_id');
            $table->string('url');
            $table->string('lang');
            $table->string('page_type');
            $table->string('source_article');
            $table->string('target_test');
            $table->string('scale_id');
            $table->string('form_id');
            $table->string('source_url');
            $table->unsignedInteger('landing_pv_count');
            $table->unsignedInteger('article_to_test_click_count');
            $table->unsignedInteger('start_test_count');
            $table->unsignedInteger('complete_test_count');
            $table->unsignedInteger('result_ready_count')->default(0);
            $table->unsignedInteger('view_result_count');
            $table->unsignedInteger('return_public_content_count');
            $table->timestamp('last_refreshed_at')->nullable();
        });
        Schema::create('analytics_seo_conversion_refresh_runs', function (Blueprint $table): void {
            $table->unsignedInteger('org_scope_count');
            $table->string('status');
            $table->string('trigger_mode');
            $table->text('receipt_json')->nullable();
            $table->timestamp('completed_at');
        });
        Schema::create('seo_gsc_sync_runs', function (Blueprint $table): void {
            $table->string('status');
            $table->text('receipt_json')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
        });
    }

    private function seedReadModels(): void
    {
        $canonical = 'https://www.fermatmind.com/en/tests/public';
        $canonicalHash = hash('sha256', $canonical);
        $revision = str_repeat('a', 64);
        DB::table('seo_urls')->insert([
            'canonical_url_hash' => $canonicalHash, 'canonical_url' => $canonical, 'locale' => 'en',
            'page_entity_type' => 'test_detail', 'page_family' => 'tests', 'source_authority' => 'backend_registry',
            'indexability_state' => 'indexable', 'is_private_flow' => false, 'authority_revision' => $revision,
        ]);
        for ($days = 92; $days >= 3; $days--) {
            $date = now('UTC')->subDays($days)->toDateString();
            DB::table('seo_gsc_daily')->insert([
                'report_date' => $date, 'canonical_url_hash' => $canonicalHash, 'canonical_url' => $canonical,
                'query_hash' => hash('sha256', 'aggregate-'.$days), 'source_engine' => 'google', 'locale' => 'en',
                'device' => 'desktop', 'country' => 'usa', 'search_type' => 'web',
                'query_type' => $days % 2 === 0 ? 'brand' : 'non_brand', 'query_display_masked' => null, 'data_state' => 'final',
                'clicks' => 2, 'impressions' => 20, 'ctr_ppm' => 100000, 'average_position_milli' => 9000,
                'is_brand_query' => $days % 2 === 0, 'mapping_state' => 'mapped',
                'metadata_json' => json_encode(['data_origin' => 'live_gsc_api'], JSON_THROW_ON_ERROR),
                'collected_at' => now('UTC'),
            ]);
            DB::table('seo_event_funnel_daily')->insert([
                'report_date' => $date, 'canonical_url_hash' => $canonicalHash, 'source_engine' => 'google',
                'traffic_quality' => 'qualified', 'environment' => 'production', 'start_attempt_count' => 4,
                'submit_attempt_count' => 3, 'view_result_count' => 3,
            ]);
            DB::table('analytics_seo_conversion_daily')->insert([
                'day' => $date, 'org_id' => 0, 'url' => '/en/tests/public', 'lang' => 'en', 'page_type' => 'tests',
                'source_article' => 'public-article', 'target_test' => '/en/tests/public', 'scale_id' => 'public',
                'form_id' => 'default', 'source_url' => '/en/articles/public', 'landing_pv_count' => 20,
                'article_to_test_click_count' => 5, 'start_test_count' => 4, 'complete_test_count' => 3,
                'view_result_count' => 3, 'return_public_content_count' => 2, 'last_refreshed_at' => now('UTC'),
            ]);
        }
        DB::table('analytics_seo_conversion_refresh_runs')->insert([
            'org_scope_count' => 0, 'status' => 'success', 'trigger_mode' => 'scheduled', 'completed_at' => now('UTC'),
        ]);
    }
}
