<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\Collectors\DetectorFoundationCollector;
use App\Services\SeoIntel\Detector\BoundedDetectorRunner;
use App\Services\SeoIntel\Detector\DetectorQueueMaterializer;
use App\Services\SeoIntel\Sources\DetectorFoundationEvidenceSource;
use App\Services\SeoIntel\Sources\ProductionDetectorFoundationEvidenceSource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoPlatform04DetectorFoundationCollectorTest extends TestCase
{
    private const SEO_CONNECTION = 'seo_detector_collector_test';

    private const BUSINESS_CONNECTION = 'seo_detector_business_test';

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::SEO_CONNECTION, self::BUSINESS_CONNECTION] as $connection) {
            config(['database.connections.'.$connection => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]]);
            DB::purge($connection);
        }
        config([
            'database.default' => self::BUSINESS_CONNECTION,
            'seo_intel.connection' => self::SEO_CONNECTION,
            'seo_intel.enabled' => true,
            'seo_intel.write_enabled' => true,
        ]);
        $this->createQueueSchema();
    }

    protected function tearDown(): void
    {
        DB::disconnect(self::SEO_CONNECTION);
        DB::disconnect(self::BUSINESS_CONNECTION);
        parent::tearDown();
    }

    #[Test]
    public function production_source_reads_only_aggregate_freshness_and_revision_evidence(): void
    {
        $this->createProductionSourceSchema();
        DB::connection(self::SEO_CONNECTION)->table('seo_gsc_sync_runs')->insert([
            'status' => 'success',
            'end_date' => '2026-08-22',
            'finished_at' => '2026-08-23 02:00:00',
        ]);
        DB::connection(self::SEO_CONNECTION)->table('seo_urls')->insert([
            'indexability_state' => 'indexable',
            'is_private_flow' => false,
            'updated_at' => '2026-08-24 00:00:00',
        ]);
        DB::connection(self::BUSINESS_CONNECTION)->table('analytics_seo_conversion_daily')->insert([
            'day' => '2026-08-24',
            'last_refreshed_at' => '2026-08-24 02:00:00',
        ]);

        $snapshot = (new ProductionDetectorFoundationEvidenceSource)->snapshot(
            CarbonImmutable::parse('2026-08-25T00:00:00Z'),
        );
        $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR);

        $this->assertSame('available', data_get($snapshot, 'metadata.source_state'));
        $this->assertSame(1, data_get($snapshot, 'metadata.url_truth.current_public_count'));
        $this->assertTrue(data_get($snapshot, 'metadata.aggregate_fields_only'));
        $this->assertFalse(data_get($snapshot, 'metadata.raw_rows_read'));
        $this->assertSame('gsc_funnel_freshness', data_get($snapshot, 'jobs.0.detector_id'));
        foreach (['raw_query', 'session_id_hash', 'canonical_url', 'user_agent', 'attempt_id', 'order_id'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
    }

    #[Test]
    public function dry_run_plans_without_writes_and_controlled_run_is_idempotent(): void
    {
        $collector = $this->collector($this->directEvidenceSource());
        $dryRun = $collector->collect([
            'dry_run' => true,
            'limit' => 10,
            'now' => '2026-08-25T00:00:00Z',
        ]);
        $controlled = $collector->collect([
            'dry_run' => false,
            'writes_allowed' => true,
            'limit' => 10,
            'now' => '2026-08-25T00:00:00Z',
        ]);
        $recovered = $this->collector($this->source(available: true, stale: false))->collect([
            'dry_run' => false,
            'writes_allowed' => true,
            'limit' => 10,
            'now' => '2026-08-25T00:01:00Z',
        ]);

        $this->assertSame('success', $dryRun->status);
        $this->assertSame(1, data_get($dryRun->metadata, 'first_receipt.counts.planned_issues'));
        $this->assertFalse($dryRun->writesAttempted);
        $this->assertSame('success', $controlled->status);
        $this->assertTrue($controlled->writesAttempted);
        $this->assertTrue($controlled->writesCommitted);
        $this->assertSame(1, data_get($controlled->metadata, 'first_receipt.counts.created'));
        $this->assertSame(data_get($dryRun->metadata, 'source_fingerprint'), data_get($controlled->metadata, 'source_fingerprint'));
        $this->assertSame(1, data_get($controlled->metadata, 'idempotent_rerun_receipt.counts.no_change'));
        $this->assertSame(0, data_get($controlled->metadata, 'idempotent_rerun_receipt.counts.created'));
        $this->assertSame(0, data_get($controlled->metadata, 'readback.duplicate_rows'));
        $this->assertSame(1, DB::connection(self::SEO_CONNECTION)->table('seo_issue_queue')->count());
        $this->assertSame(1, data_get($recovered->metadata, 'first_receipt.counts.closed'));
        $this->assertSame('resolved', DB::connection(self::SEO_CONNECTION)->table('seo_issue_queue')->value('status'));
        $this->assertFalse(data_get($controlled->metadata, 'search_submission_allowed'));
        $this->assertTrue(data_get($controlled->metadata, 'read_only_gsc'));
    }

    #[Test]
    public function unavailable_sources_remain_measurement_hold_without_fake_queue_rows(): void
    {
        $result = $this->collector($this->measurementHoldSource())->collect([
            'dry_run' => false,
            'writes_allowed' => true,
            'now' => '2026-08-25T00:00:00Z',
        ]);

        $this->assertSame(1, data_get($result->metadata, 'artifact.outcome_counts.measurement_hold'));
        $this->assertSame(0, data_get($result->metadata, 'first_receipt.counts.created'));
        $this->assertSame(0, data_get($result->metadata, 'readback.issue_rows'));
        $this->assertFalse($result->writesCommitted);
    }

    #[Test]
    public function narrow_command_authority_executes_controlled_materialization_and_same_input_rerun(): void
    {
        $this->createProductionSourceSchema();
        DB::connection(self::SEO_CONNECTION)->table('seo_gsc_sync_runs')->insert([
            'status' => 'success',
            'end_date' => now('UTC')->subDays(12)->toDateString(),
            'finished_at' => now('UTC')->subDays(12),
        ]);
        DB::connection(self::SEO_CONNECTION)->table('seo_urls')->insert([
            'indexability_state' => 'indexable',
            'is_private_flow' => false,
            'updated_at' => now('UTC'),
        ]);
        DB::connection(self::BUSINESS_CONNECTION)->table('analytics_seo_conversion_daily')->insert([
            'day' => now('UTC')->subDay()->toDateString(),
            'last_refreshed_at' => now('UTC')->subDay(),
        ]);
        config([
            'seo_intel.collectors_enabled' => false,
            'seo_intel.write_enabled' => false,
        ]);

        $exitCode = Artisan::call('seo-intel:collect', [
            '--collector' => 'detector_foundation',
            '--materialize-detector-queues' => true,
            '--canary' => true,
            '--limit' => 10,
            '--json' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame('success', $payload['status']);
        $this->assertFalse($payload['dry_run']);
        $this->assertTrue($payload['writes_attempted']);
        $this->assertSame(1, data_get($payload, 'metadata.first_receipt.counts.created'));
        $this->assertSame(1, data_get($payload, 'metadata.idempotent_rerun_receipt.counts.no_change'));
        $this->assertSame(0, data_get($payload, 'metadata.readback.duplicate_rows'));
    }

    #[Test]
    public function narrow_command_authority_does_not_enable_other_collector_writes(): void
    {
        config([
            'seo_intel.collectors_enabled' => true,
            'seo_intel.write_enabled' => false,
        ]);

        $exitCode = Artisan::call('seo-intel:collect', [
            '--collector' => 'noop',
            '--json' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertFalse($payload['writes_attempted']);
        $this->assertFalse($payload['writes_committed']);
    }

    #[Test]
    public function command_authority_is_narrow_and_standard_deploy_validates_both_receipts(): void
    {
        $exitCode = Artisan::call('seo-intel:collect', [
            '--collector' => 'noop',
            '--materialize-detector-queues' => true,
            '--json' => true,
        ]);
        $deploy = (string) file_get_contents(base_path('../deploy.php'));

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('seo:detector-foundation-receipt', $deploy);
        $this->assertStringContainsString('--collector=detector_foundation --dry-run --canary --limit=10', $deploy);
        $this->assertStringContainsString('--collector=detector_foundation --materialize-detector-queues --canary --limit=10', $deploy);
        $this->assertStringContainsString('"duplicate_rows"', $deploy);
        $this->assertStringContainsString('"search_submission_allowed"', $deploy);
        $this->assertStringNotContainsString('test "$dry_fingerprint" = "$controlled_fingerprint"', $deploy);
        $this->assertStringContainsString('idempotent_rerun_receipt', $deploy);
        $this->assertStringContainsString('$rerun["created"]', $deploy);
    }

    private function collector(DetectorFoundationEvidenceSource $source): DetectorFoundationCollector
    {
        return new DetectorFoundationCollector(
            $source,
            new BoundedDetectorRunner,
            new DetectorQueueMaterializer(connectionName: self::SEO_CONNECTION),
        );
    }

    private function directEvidenceSource(): DetectorFoundationEvidenceSource
    {
        return $this->source(available: true, stale: true);
    }

    private function measurementHoldSource(): DetectorFoundationEvidenceSource
    {
        return $this->source(available: false, stale: false);
    }

    private function source(bool $available, bool $stale): DetectorFoundationEvidenceSource
    {
        return new class($available, $stale) implements DetectorFoundationEvidenceSource
        {
            public function __construct(private readonly bool $available, private readonly bool $stale) {}

            public function snapshot(CarbonImmutable $observedAt): array
            {
                return [
                    'jobs' => [[
                        'detector_id' => 'gsc_funnel_freshness',
                        'evidence' => [
                            'source_state' => $this->available ? 'available' : 'unavailable',
                            'evidence_complete' => $this->available,
                            'direct_evidence' => $this->available,
                            'page_family' => 'other_public',
                            'locale' => 'en',
                            'indexability_state' => 'indexable',
                            'authority_revision' => 'authority-r1',
                            'url_truth_revision' => 'url-truth-r1',
                            'policy_version' => 'seo-page-family-policy.v1',
                            'evidence_observed_at' => $observedAt->toIso8601String(),
                            'private_negative_set_checked' => true,
                            'affected_url_count' => $this->stale ? 1 : 0,
                            'gsc_freshness_threshold_exceeded' => $this->stale,
                            'funnel_freshness_threshold_exceeded' => false,
                            'verified_impact' => 'bounded',
                            'root_cause_or_error_code' => 'gsc_funnel_pipeline_freshness',
                        ],
                    ]],
                    'metadata' => [
                        'source_state' => $this->available ? 'available' : 'measurement_hold',
                        'raw_rows_read' => false,
                        'aggregate_fields_only' => true,
                    ],
                    'issues' => $this->available ? [] : ['detector_source_measurement_hold'],
                ];
            }
        };
    }

    private function createProductionSourceSchema(): void
    {
        Schema::connection(self::SEO_CONNECTION)->create('seo_gsc_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('status');
            $table->date('end_date');
            $table->timestamp('finished_at')->nullable();
        });
        Schema::connection(self::SEO_CONNECTION)->create('seo_urls', function (Blueprint $table): void {
            $table->id();
            $table->string('indexability_state');
            $table->boolean('is_private_flow');
            $table->timestamp('updated_at')->nullable();
        });
        Schema::connection(self::BUSINESS_CONNECTION)->create('analytics_seo_conversion_daily', function (Blueprint $table): void {
            $table->id();
            $table->date('day');
            $table->timestamp('last_refreshed_at')->nullable();
        });
    }

    private function createQueueSchema(): void
    {
        Schema::connection(self::SEO_CONNECTION)->create('seo_issue_queue', function (Blueprint $table): void {
            $table->id();
            $table->string('issue_uid', 128)->unique();
            $table->string('issue_type', 80);
            $table->string('detector_id', 80)->nullable();
            $table->string('detector_version', 32)->nullable();
            $table->string('severity', 32);
            $table->string('source_system', 64);
            $table->string('source_engine', 64)->nullable();
            $table->char('canonical_url_hash', 64)->nullable();
            $table->char('query_hash', 64)->nullable();
            $table->text('canonical_url')->nullable();
            $table->string('locale', 16)->nullable();
            $table->string('page_entity_type', 80)->nullable();
            $table->string('entity_id_or_slug')->nullable();
            $table->string('cluster', 80)->nullable();
            $table->string('cluster_uid', 64)->nullable();
            $table->string('authority_revision', 160)->nullable();
            $table->string('url_truth_revision', 160)->nullable();
            $table->string('policy_version', 160)->nullable();
            $table->unsignedInteger('affected_url_count')->default(1);
            $table->string('status', 32);
            $table->string('lifecycle_state', 32);
            $table->timestamp('detected_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('summary', 512)->nullable();
            $table->string('recommendation', 512)->nullable();
            $table->char('evidence_hash', 64)->nullable();
            $table->char('artifact_hash', 64)->nullable();
            $table->timestamp('last_evidence_at')->nullable();
            $table->unsignedInteger('reopen_count')->default(0);
            $table->json('metadata_json')->nullable();
            $table->timestamps();
        });
        Schema::connection(self::SEO_CONNECTION)->create('seo_detector_opportunities', function (Blueprint $table): void {
            $table->id();
            $table->string('opportunity_uid', 128)->unique();
            $table->string('detector_id', 80);
            $table->string('detector_version', 32);
            $table->string('cluster_uid', 64);
            $table->char('canonical_url_hash', 64)->nullable();
            $table->char('query_hash', 64)->nullable();
            $table->string('locale', 16)->nullable();
            $table->string('page_family', 80);
            $table->string('authority_revision', 160);
            $table->string('url_truth_revision', 160);
            $table->string('policy_version', 160);
            $table->string('status', 32);
            $table->string('lifecycle_state', 32);
            $table->unsignedInteger('affected_url_count');
            $table->char('evidence_hash', 64);
            $table->char('artifact_hash', 64);
            $table->json('metadata_json')->nullable();
            $table->timestamp('detected_at');
            $table->timestamp('last_evidence_at');
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedInteger('reopen_count')->default(0);
            $table->timestamps();
        });
    }
}
