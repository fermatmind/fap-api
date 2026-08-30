<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentEvidence\Bundle\SeoEvidenceBundleVerifier;
use App\Services\SeoCouncil\Measurement\ReadOnlyMeasurementEvidenceBundleLoader;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SeoPlatform11FEvidenceSourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['seo_intel.connection' => 'sqlite']);
        foreach (['analytics_seo_conversion_refresh_runs', 'analytics_seo_conversion_daily', 'seo_event_funnel_daily', 'seo_gsc_daily', 'seo_urls'] as $table) {
            Schema::dropIfExists($table);
        }
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

        $cro = $loader->loadForScope('mission:source', 'commercial_funnel_cro', 'tests', 'en', 'staging_runtime');
        $this->assertCount(1, $cro);
        $this->assertSame('public_funnel_aggregate', $cro[0]['source_type']);
        $this->assertSame('available', $cro[0]['source_capability_state']);
        $this->assertSame('fresh', $cro[0]['freshness_state']);
        $this->assertTrue(app(SeoEvidenceBundleVerifier::class)->verify($cro[0])['valid']);

        $encoded = json_encode([$search, $cro], JSON_THROW_ON_ERROR);
        foreach (['canonical_url', 'raw_query', 'query_display_masked', 'user_id', 'database'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
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
            $table->unsignedInteger('view_result_count');
            $table->unsignedInteger('return_public_content_count');
            $table->timestamp('last_refreshed_at')->nullable();
        });
        Schema::create('analytics_seo_conversion_refresh_runs', function (Blueprint $table): void {
            $table->unsignedInteger('org_scope_count');
            $table->string('status');
            $table->string('trigger_mode');
            $table->timestamp('completed_at');
        });
    }

    private function seedReadModels(): void
    {
        $canonical = 'https://www.fermatmind.com/en/tests/public';
        $canonicalHash = hash('sha256', $canonical);
        $revision = str_repeat('a', 64);
        DB::table('seo_urls')->insert([
            'canonical_url_hash' => $canonicalHash, 'canonical_url' => $canonical, 'locale' => 'en',
            'page_entity_type' => 'tests', 'page_family' => 'tests', 'source_authority' => 'backend_registry',
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
