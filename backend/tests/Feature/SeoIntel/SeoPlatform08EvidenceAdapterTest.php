<?php

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\Ledger\SeoLedgerEvidenceAdapter;
use App\Services\SeoIntel\Ledger\SeoLedgerEvidenceReadService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SeoPlatform08EvidenceAdapterTest extends TestCase
{
    #[Test]
    public function it_adapts_only_fresh_public_aggregate_evidence(): void
    {
        config([
            'seo_intel.gsc_backfill_lag_days' => 3,
            'seo_intel.gsc_data_quality.max_report_age_days' => 10,
            'seo_intel.gsc_data_quality.min_rows' => 1,
            'seo_intel.gsc_data_quality.allowed_data_origins' => ['live_gsc_api'],
        ]);
        $now = CarbonImmutable::parse('2026-08-27T00:00:00Z');

        $snapshot = (new SeoLedgerEvidenceAdapter)->adapt($this->sources(), $now);

        $this->assertSame('verified', $snapshot['state']);
        $this->assertSame([], $snapshot['hold_reasons']);
        $this->assertSame(0, data_get($snapshot, 'evidence.gsc_aggregate.clicks'));
        $this->assertSame(120, data_get($snapshot, 'evidence.gsc_aggregate.impressions'));
        $this->assertSame('tests', data_get($snapshot, 'evidence.page_family.id'));
        $this->assertTrue(data_get($snapshot, 'boundaries.read_only'));
        $this->assertFalse(data_get($snapshot, 'boundaries.search_submission_allowed'));

        $json = json_encode($snapshot, JSON_THROW_ON_ERROR);
        foreach (['canonical_url', 'query_hash', 'query_display_masked', 'meta_json', 'raw-query-value', 'user_agent'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
    }

    #[Test]
    public function stale_or_missing_evidence_enters_measurement_hold(): void
    {
        $sources = $this->sources();
        $sources['cms_revision']['observed_at'] = '2026-08-01T00:00:00Z';
        unset($sources['runtime']);

        $snapshot = (new SeoLedgerEvidenceAdapter)->adapt($sources, CarbonImmutable::parse('2026-08-27T00:00:00Z'));

        $this->assertSame('MEASUREMENT_HOLD', $snapshot['state']);
        $this->assertContains('source_unavailable:runtime', $snapshot['hold_reasons']);
        $this->assertContains('evidence_stale_or_undated:cms_revision', $snapshot['hold_reasons']);
        $this->assertContains('runtime_not_production_proven', $snapshot['hold_reasons']);
    }

    #[Test]
    public function private_product_or_raw_evidence_is_held_and_never_emitted(): void
    {
        $sources = $this->sources();
        $sources['runtime']['attempt_id'] = 'attempt-private-1';
        $sources['deploy']['meta_json'] = ['user_agent' => 'private-agent'];
        $sources['url_truth']['raw_url'] = 'https://example.test/private/result/token';

        $snapshot = (new SeoLedgerEvidenceAdapter)->adapt($sources, CarbonImmutable::parse('2026-08-27T00:00:00Z'));

        $this->assertSame('MEASUREMENT_HOLD', $snapshot['state']);
        $this->assertContains('private_or_raw_evidence', $snapshot['hold_reasons']);
        $this->assertStringNotContainsString('attempt-private-1', json_encode($snapshot, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('private-agent', json_encode($snapshot, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('example.test', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function gsc_is_read_only_aggregate_and_quality_failure_holds(): void
    {
        $sources = $this->sources();
        $sources['gsc']['rows'][0]['metadata_json']['data_origin'] = 'fixture';

        $snapshot = (new SeoLedgerEvidenceAdapter)->adapt($sources, CarbonImmutable::parse('2026-08-27T00:00:00Z'));

        $this->assertSame('MEASUREMENT_HOLD', $snapshot['state']);
        $this->assertContains('gsc_aggregate_quality_hold', $snapshot['hold_reasons']);
        $this->assertSame('blocked', data_get($snapshot, 'evidence.gsc_aggregate.state'));
        $this->assertContains('fixture_or_mock_source', data_get($snapshot, 'evidence.gsc_aggregate.quality_reasons'));
        $this->assertFalse(data_get($snapshot, 'boundaries.write_authorization_granted'));
    }

    #[Test]
    public function read_service_connects_real_authority_deploy_and_gsc_tables_without_writes(): void
    {
        foreach (['seo_ledger_evidence_test', 'seo_ledger_app_test'] as $connection) {
            config(["database.connections.{$connection}" => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
            DB::purge($connection);
        }
        config([
            'seo_intel.gsc_backfill_lag_days' => 3,
            'seo_intel.gsc_data_quality.max_report_age_days' => 10,
            'seo_intel.gsc_data_quality.min_rows' => 1,
            'seo_intel.gsc_data_quality.allowed_data_origins' => ['live_gsc_api'],
        ]);

        Schema::connection('seo_ledger_evidence_test')->create('seo_urls', function (Blueprint $table): void {
            $table->id();
            $table->char('canonical_url_hash', 64);
            $table->string('locale', 16);
            $table->string('page_family', 64);
            $table->char('authority_revision', 64);
            $table->boolean('is_private_flow');
            $table->string('indexability_state', 64);
            $table->timestamps();
        });
        Schema::connection('seo_ledger_evidence_test')->create('seo_gsc_daily', function (Blueprint $table): void {
            $table->id();
            $table->date('report_date');
            $table->char('canonical_url_hash', 64);
            $table->char('query_hash', 64);
            $table->string('locale', 16);
            $table->string('source_engine', 64);
            $table->unsignedInteger('clicks');
            $table->unsignedInteger('impressions');
            $table->json('metadata_json');
        });
        Schema::connection('seo_ledger_app_test')->create('ops_deploy_events', function (Blueprint $table): void {
            $table->id();
            $table->string('env', 32);
            $table->string('revision', 64);
            $table->string('status', 32);
            $table->dateTime('occurred_at');
        });

        DB::connection('seo_ledger_evidence_test')->table('seo_urls')->insert([
            [
                'canonical_url_hash' => str_repeat('1', 64),
                'locale' => 'zh-CN',
                'page_family' => 'tests',
                'authority_revision' => str_repeat('2', 64),
                'is_private_flow' => false,
                'indexability_state' => 'indexable',
                'created_at' => '2026-08-26 12:00:00',
                'updated_at' => '2026-08-26 12:00:00',
            ],
            [
                'canonical_url_hash' => str_repeat('3', 64),
                'locale' => 'zh-CN',
                'page_family' => 'tests',
                'authority_revision' => str_repeat('4', 64),
                'is_private_flow' => true,
                'indexability_state' => 'indexable',
                'created_at' => '2026-08-26 12:00:00',
                'updated_at' => '2026-08-26 12:00:00',
            ],
        ]);
        DB::connection('seo_ledger_evidence_test')->table('seo_gsc_daily')->insert([
            'report_date' => '2026-08-23',
            'canonical_url_hash' => str_repeat('1', 64),
            'query_hash' => str_repeat('5', 64),
            'locale' => 'zh-CN',
            'source_engine' => 'google',
            'clicks' => 0,
            'impressions' => 50,
            'metadata_json' => json_encode(['data_origin' => 'live_gsc_api'], JSON_THROW_ON_ERROR),
        ]);
        DB::connection('seo_ledger_app_test')->table('ops_deploy_events')->insert([
            'env' => 'production',
            'revision' => str_repeat('6', 40),
            'status' => 'success',
            'occurred_at' => '2026-08-26 12:00:00',
        ]);

        $snapshot = (new SeoLedgerEvidenceReadService('seo_ledger_evidence_test', 'seo_ledger_app_test'))
            ->read('tests', 'zh-CN', CarbonImmutable::parse('2026-08-27T00:00:00Z'));

        $this->assertSame(1, data_get($snapshot, 'evidence.url_truth.public_count'));
        $this->assertSame(50, data_get($snapshot, 'evidence.gsc_aggregate.impressions'));
        $this->assertSame(str_repeat('6', 40), data_get($snapshot, 'evidence.deploy.sha'));
        $this->assertSame(2, DB::connection('seo_ledger_evidence_test')->table('seo_urls')->count());
        $this->assertSame(1, DB::connection('seo_ledger_evidence_test')->table('seo_gsc_daily')->count());
        $this->assertSame(1, DB::connection('seo_ledger_app_test')->table('ops_deploy_events')->count());

        DB::disconnect('seo_ledger_evidence_test');
        DB::disconnect('seo_ledger_app_test');
    }

    private function sources(): array
    {
        $freshness = ['observed_at' => '2026-08-26T18:00:00Z', 'max_age_hours' => 24];

        return [
            'runtime' => $freshness + [
                'state' => 'production_proven',
                'contract_projection_hash' => str_repeat('a', 64),
            ],
            'url_truth' => $freshness + [
                'cohort_digest' => str_repeat('b', 64),
                'public_count' => 12,
                'revision' => 'url-truth-current-v1',
            ],
            'page_family' => $freshness + [
                'id' => 'tests',
                'locale' => 'zh-CN',
                'authority_revision' => 'tests-authority-v3',
            ],
            'cms_revision' => $freshness + ['revision' => 'cms-revision-123'],
            'deploy' => $freshness + [
                'sha' => str_repeat('c', 64),
                'status' => 'success',
                'meta_json' => ['environment' => 'production'],
            ],
            'gsc' => [
                'rows' => [[
                    'report_date' => '2026-08-23',
                    'canonical_url_hash' => str_repeat('d', 64),
                    'query_hash' => str_repeat('e', 64),
                    'query_display_masked' => 'masked',
                    'source_engine' => 'google',
                    'clicks' => 0,
                    'impressions' => 120,
                    'metadata_json' => ['data_origin' => 'live_gsc_api'],
                ]],
            ],
        ];
    }
}
