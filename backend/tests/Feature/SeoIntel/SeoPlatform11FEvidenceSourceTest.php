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
        config([
            'database.default' => 'measurement_source_fixture',
            'database.connections.measurement_source_fixture' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
                'foreign_key_constraints' => false,
            ],
            'seo_intel.connection' => 'measurement_source_fixture',
        ]);
        DB::purge('measurement_source_fixture');
        foreach (['analytics_seo_conversion_refresh_runs', 'analytics_seo_conversion_daily', 'seo_event_funnel_daily', 'seo_gsc_sync_runs', 'seo_gsc_daily', 'seo_urls'] as $table) {
            Schema::dropIfExists($table);
        }
        \App\Support\SchemaBaseline::clearCache();
        $this->createReadModels();
        $this->seedReadModels();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        DB::purge('measurement_source_fixture');
        parent::tearDown();
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

    public function test_staging_measurement_command_reuses_healthy_real_snapshots_without_publication_or_refresh(): void
    {
        $sha = str_repeat('a', 40);
        $directory = '/tmp/fermatmind-11g-staging-'.getmypid().'-1';
        $revision = dirname(base_path()).'/REVISION';
        $oldRevision = is_file($revision) ? file_get_contents($revision) : null;
        $oldCache = $_ENV['APP_CONFIG_CACHE'] ?? null;
        $oldServerCache = $_SERVER['APP_CONFIG_CACHE'] ?? null;
        $oldProcessCache = getenv('APP_CONFIG_CACHE');
        $oldEnvironment = app()->environment();
        mkdir($directory, 0700);
        try {
            file_put_contents($revision, $sha);
            foreach (['measurement.env', 'competitive-writer.env'] as $name) {
                file_put_contents($directory.'/'.$name, '');
                chmod($directory.'/'.$name, 0600);
            }
            $_ENV['APP_CONFIG_CACHE'] = $directory.'/competitive-config.php';
            $_SERVER['APP_CONFIG_CACHE'] = $_ENV['APP_CONFIG_CACHE'];
            putenv('APP_CONFIG_CACHE='.$_ENV['APP_CONFIG_CACHE']);
            app()->detectEnvironment(fn () => 'staging');
            config(['seo_intel.write_enabled' => true]);
            DB::connection()->enableQueryLog();
            $code = \Illuminate\Support\Facades\Artisan::call('seo:competitive-release-prepare', [
                '--candidate-sha' => $sha, '--measurement-only' => true,
                '--gsc-env' => $directory.'/measurement.env', '--writer-env' => $directory.'/competitive-writer.env', '--json' => true,
            ]);
            $payload = json_decode(trim(\Illuminate\Support\Facades\Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(0, $code, json_encode($payload));
            $this->assertSame('staging', $payload['environment']);
            $this->assertSame(['gsc' => 'reused', 'cro' => 'reused'], $payload['measurement_actions']);
            $this->assertSame(0, $payload['cms_writes']);
            $this->assertSame(0, $payload['search_writes']);
            $this->assertFalse($payload['competitive_publication']);
            foreach (DB::connection()->getQueryLog() as $query) {
                $this->assertDoesNotMatchRegularExpression('/^\s*(?:insert|update|delete|create|drop|alter)\b/i', $query['query']);
            }
            $this->assertFileDoesNotExist($directory.'/competitive-config.php');
        } finally {
            app()->detectEnvironment(fn () => $oldEnvironment);
            if ($oldCache === null) {
                unset($_ENV['APP_CONFIG_CACHE']);
            } else {
                $_ENV['APP_CONFIG_CACHE'] = $oldCache;
            }
            if ($oldServerCache === null) {
                unset($_SERVER['APP_CONFIG_CACHE']);
            } else {
                $_SERVER['APP_CONFIG_CACHE'] = $oldServerCache;
            }
            putenv($oldProcessCache === false ? 'APP_CONFIG_CACHE' : 'APP_CONFIG_CACHE='.$oldProcessCache);
            if ($oldRevision === null) {
                unlink($revision);
            } else {
                file_put_contents($revision, $oldRevision);
            }
            unlink($directory.'/measurement.env');
            unlink($directory.'/competitive-writer.env');
            rmdir($directory);
        }
    }

    public function test_refresh_processes_execute_serially_and_reject_empty_success_output(): void
    {
        $command = app(\App\Console\Commands\SeoCompetitiveReleasePrepareCommand::class);
        $method = new \ReflectionMethod($command, 'runRefreshes');
        $result = $method->invoke($command, [
            'gsc' => new \Symfony\Component\Process\Process(['php', '-r', 'echo json_encode(["status"=>"success","window_days"=>90,"search_types"=>["web"]]);']),
            'cro' => new \Symfony\Component\Process\Process(['php', '-r', 'echo json_encode(["status"=>"success","readback_receipt"=>["status"=>"pass"]]);']),
        ]);
        $this->assertNull($result);
        $this->assertSame('GSC_REFRESH_FAILED', $method->invoke($command, ['gsc' => new \Symfony\Component\Process\Process(['php', '-r', 'exit(0);'])]));
    }

    public function test_reporting_lag_stale_source_stays_held_and_enters_existing_refresh_plan(): void
    {
        config(['seo_intel.gsc_reporting_timezone' => 'UTC']);
        DB::table('seo_gsc_daily')->where('report_date', now('UTC')->subDays(3)->toDateString())->delete();

        $loader = app(ReadOnlyMeasurementEvidenceBundleLoader::class);
        $diagnosis = $loader->diagnoseForScope('mission:reporting-lag', 'search_measurement', 'tests', 'en', 'production_runtime');
        $this->assertSame('GSC_STALE', $diagnosis->diagnostic()['hold_reason']);
        $this->assertFalse($diagnosis->ready());
        $this->assertSame('held', $diagnosis->bundles()[0]['source_capability_state']);
        $this->assertSame('stale', $diagnosis->bundles()[0]['freshness_state']);
        $this->assertSame('pass', $diagnosis->bundles()[0]['payload']['quality_gate_status']);

        $verifier = app(MeasurementSnapshotVerifier::class);
        $snapshot = $verifier->verify(str_repeat('a', 40), 'tests', 'production');
        $this->assertSame('HOLD', $snapshot['status']);
        $this->assertSame('GSC_STALE', $snapshot['search_measurement']['hold_reason']);
        $this->assertTrue($snapshot['search_measurement']['refresh_eligible']);
        $command = app(\App\Console\Commands\SeoCompetitiveReleasePrepareCommand::class);
        $plan = (new \ReflectionMethod($command, 'refreshPlan'))->invoke($command, $snapshot, $verifier, 'production');
        $this->assertNull($plan['hold_reason']);
        $this->assertSame('full_refresh', $plan['actions']['gsc']);
        $this->assertSame('reused', $plan['actions']['cro']);
    }

    public function test_reporting_lag_stale_rows_do_not_make_a_failed_dashboard_query_refreshable(): void
    {
        config([
            'seo_intel.gsc_reporting_timezone' => 'UTC',
            'database.connections.measurement_dashboard_failure' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            ],
        ]);
        DB::table('seo_gsc_daily')->where('report_date', now('UTC')->subDays(3)->toDateString())->delete();
        $this->app->instance(
            \App\Services\SeoIntel\OpsDashboard\SeoDashboardApiReadService::class,
            new \App\Services\SeoIntel\OpsDashboard\SeoDashboardApiReadService('measurement_dashboard_failure'),
        );

        try {
            $verifier = app(MeasurementSnapshotVerifier::class);
            $snapshot = $verifier->verify(str_repeat('a', 40), 'tests', 'production');
            $this->assertSame('HOLD', $snapshot['status']);
            $this->assertSame('GSC_READMODEL_UNHEALTHY', $snapshot['search_measurement']['hold_reason']);
            $this->assertFalse($snapshot['search_measurement']['refresh_eligible']);
            $command = app(\App\Console\Commands\SeoCompetitiveReleasePrepareCommand::class);
            $plan = (new \ReflectionMethod($command, 'refreshPlan'))->invoke($command, $snapshot, $verifier, 'production');
            $this->assertSame('GSC_READMODEL_UNHEALTHY', $plan['hold_reason']);
            $this->assertSame('not_run', $plan['actions']['gsc']);
        } finally {
            DB::purge('measurement_dashboard_failure');
        }
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
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $loader = app(ReadOnlyMeasurementEvidenceBundleLoader::class);
        $this->assertSame('NONE', $loader->diagnoseForScope('mission:cross-sha', 'search_measurement', 'tests', 'en', 'staging_runtime')->diagnostic()['hold_reason']);

        DB::table('seo_gsc_daily')
            ->where('report_date', now('UTC')->subDays(20)->toDateString())
            ->update(['report_date' => now('UTC')->subDays(3)->toDateString()]);
        $this->assertSame('GSC_WINDOW_INCOMPLETE', $loader->diagnoseForScope('mission:drifted-snapshot', 'search_measurement', 'tests', 'en', 'staging_runtime')->diagnostic()['hold_reason']);
    }

    public function test_full_window_proof_survives_utc_pacific_and_dst_boundaries(): void
    {
        foreach ([
            ['2026-10-06T22:40:00Z', '2026-10-07T08:01:00Z'],
            ['2026-03-08T07:55:00Z', '2026-03-08T11:05:00Z'],
            ['2026-11-01T06:55:00Z', '2026-11-01T10:05:00Z'],
        ] as [$collected, $checked]) {
            $this->travelTo(CarbonImmutable::parse($collected));
            $receipt = $this->installSparseFullWindowReceipt();
            $loader = app(ReadOnlyMeasurementEvidenceBundleLoader::class);
            $this->assertSame('NONE', $loader->diagnoseForScope('mission:clock', 'search_measurement', 'tests', 'en', 'staging_runtime')->diagnostic()['hold_reason']);
            $this->travelTo(CarbonImmutable::parse($checked));
            $this->assertSame('NONE', $loader->diagnoseForScope('mission:clock', 'search_measurement', 'tests', 'en', 'staging_runtime')->diagnostic()['hold_reason']);
            $this->assertSame(json_encode($receipt, JSON_THROW_ON_ERROR), DB::table('seo_gsc_sync_runs')->value('receipt_json'));
        }
    }

    public function test_full_window_proof_rejects_expired_latest_failed_truncated_and_tampered_sources(): void
    {
        foreach (['expired', 'failed', 'running', 'truncated', 'tampered', 'metrics', 'identity'] as $failure) {
            $this->travelTo(CarbonImmutable::parse('2026-10-06T22:40:00Z'));
            $receipt = $this->installSparseFullWindowReceipt();
            if ($failure === 'expired') {
                $this->travelTo(now('UTC')->addHours(26)->addSecond());
            } elseif (in_array($failure, ['failed', 'running'], true)) {
                DB::table('seo_gsc_sync_runs')->insert([
                    'status' => $failure, 'created_at' => now('UTC')->addSecond(),
                    'started_at' => now('UTC')->addSecond(), 'finished_at' => $failure === 'failed' ? now('UTC')->addSecond() : null,
                ]);
                $this->travelTo(now('UTC')->addSeconds(2));
            } elseif ($failure === 'metrics') {
                DB::table('seo_gsc_daily')->where('id', DB::table('seo_gsc_daily')->min('id'))->increment('clicks');
            } elseif ($failure === 'identity') {
                DB::table('seo_gsc_daily')->where('id', DB::table('seo_gsc_daily')->min('id'))->update(['report_date' => $receipt['end_date']]);
            } else {
                if ($failure === 'truncated') {
                    $receipt['completeness']['truncated'] = true;
                    unset($receipt['receipt_hash']);
                    $receipt['receipt_hash'] = $this->gscReceiptHash($receipt);
                } else {
                    $receipt['readmodel_snapshot_hash'] = str_repeat('f', 64);
                }
                DB::table('seo_gsc_sync_runs')->update(['receipt_json' => json_encode($receipt, JSON_THROW_ON_ERROR)]);
            }
            $this->assertNotSame('NONE', app(ReadOnlyMeasurementEvidenceBundleLoader::class)->diagnoseForScope('mission:failure', 'search_measurement', 'tests', 'en', 'staging_runtime')->diagnostic()['hold_reason'], $failure);
        }
    }

    public function test_latest_failed_receipt_is_not_masked_by_a_complete_older_date_set(): void
    {
        DB::table('seo_gsc_sync_runs')->insert([
            'status' => 'failed', 'failure_code' => 'gsc_transport_failed',
            'started_at' => now('UTC'), 'created_at' => now('UTC'), 'finished_at' => now('UTC'),
        ]);
        $this->assertNotSame('NONE', app(ReadOnlyMeasurementEvidenceBundleLoader::class)->diagnoseForScope('mission:latest-failed', 'search_measurement', 'tests', 'en', 'staging_runtime')->diagnostic()['hold_reason']);
    }

    private function installSparseFullWindowReceipt(): array
    {
        config(['seo_intel.gsc_reporting_timezone' => 'America/Los_Angeles']);
        DB::table('seo_gsc_sync_runs')->delete();
        DB::table('seo_gsc_daily')->delete();
        $this->seedReadModels();
        $end = CarbonImmutable::parse(now('America/Los_Angeles')->subDays(3)->toDateString(), 'UTC');
        $start = $end->subDays(89);
        foreach (DB::table('seo_gsc_daily')->orderBy('id')->get() as $index => $row) {
            if ($index % 3 !== 0 && $index !== 89) {
                DB::table('seo_gsc_daily')->where('id', $row->id)->delete();
            } else {
                DB::table('seo_gsc_daily')->where('id', $row->id)->update(['report_date' => $start->addDays($index)->toDateString()]);
            }
        }
        $snapshot = app(GscRunCloseoutSummarizer::class)->readModelSnapshot(DB::connection(), $start, $end, ['web']);
        $receipt = [
            'schema_version' => 'seo.gsc_refresh_receipt.v2', 'environment' => 'staging', 'status' => 'success',
            'fetch_mode' => 'full_window', 'window_days' => 90, 'requested_start_date' => $start->toDateString(),
            'start_date' => $start->toDateString(), 'end_date' => $end->toDateString(), 'search_types' => ['web'],
            'trigger_mode' => 'scheduled', 'reporting_timezone' => 'America/Los_Angeles',
            'collection_started_at' => now('UTC')->format('Y-m-d\TH:i:s\Z'), 'lag_days_requested' => 3,
            'pages_fetched' => 90, 'rows_seen' => $snapshot['row_count'], 'mapped_rows' => $snapshot['row_count'],
            'unmapped_rows' => 0, 'duplicate_natural_keys' => 0, 'quality_gate' => ['status' => 'pass'],
            'read_only_gsc' => true, 'search_submission_allowed' => false, 'restricted_egress' => ['status' => 'restricted'],
            'property_hash' => hash('sha256', (string) config('seo_intel.gsc_property_url', '')),
            'completeness' => ['pagination_complete' => true, 'truncated' => false,
                'covered_start_date' => $start->toDateString(), 'covered_end_date' => $end->toDateString(),
                'search_types' => ['web'], 'dimensions' => ['query', 'page', 'device', 'country']],
            'gsc_data_quality' => ['read_model_after' => $snapshot], 'readmodel_snapshot_hash' => $this->gscReceiptHash($snapshot),
        ];
        $receipt['receipt_hash'] = $this->gscReceiptHash($receipt);
        DB::table('seo_gsc_sync_runs')->insert([
            'status' => 'success', 'receipt_json' => json_encode($receipt, JSON_THROW_ON_ERROR),
            'created_at' => now('UTC'), 'started_at' => now('UTC'), 'finished_at' => now('UTC'),
        ]);

        return $receipt;
    }

    private function gscReceiptHash(array $value): string
    {
        return (new \ReflectionMethod(ReadOnlyMeasurementEvidenceBundleLoader::class, 'canonicalHash'))->invoke(app(ReadOnlyMeasurementEvidenceBundleLoader::class), $value);
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
            $table->id();
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
            $table->string('failure_code')->nullable();
            $table->text('receipt_json')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
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
