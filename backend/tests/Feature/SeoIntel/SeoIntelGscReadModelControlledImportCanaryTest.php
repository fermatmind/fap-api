<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\GscReadModelControlledImportCanary;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelGscReadModelControlledImportCanaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.seo_intel' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
            'seo_intel.connection' => 'seo_intel',
        ]);

        DB::purge('seo_intel');
        $this->createSeoGscDailyTable();
    }

    #[Test]
    public function dry_run_outputs_canary_plan_without_database_write(): void
    {
        Http::fake();
        $artifactPath = $this->writeArtifact($this->validArtifact());

        [$exitCode, $payload] = $this->runCanaryCommand([
            '--artifact' => $artifactPath,
            '--limit' => 1,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('success', $payload['status'] ?? null);
        $this->assertSame('dry_run_canary_plan', $payload['mode'] ?? null);
        $this->assertTrue((bool) ($payload['dry_run'] ?? false));
        $this->assertTrue((bool) ($payload['would_write'] ?? false));
        $this->assertFalse((bool) ($payload['writes_committed'] ?? true));
        $this->assertSame(1, $payload['rows_would_insert'] ?? null);
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_gsc_daily')->count());
        $this->assertStringContainsString(hash_file('sha256', $artifactPath), (string) ($payload['required_confirmation_phrase'] ?? ''));
        Http::assertNothingSent();
    }

    #[Test]
    public function execute_blocks_without_exact_confirmations_and_writes_nothing(): void
    {
        Http::fake();
        $artifactPath = $this->writeArtifact($this->validArtifact());

        [$exitCode, $payload] = $this->runCanaryCommand([
            '--artifact' => $artifactPath,
            '--limit' => 1,
            '--execute' => true,
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status'] ?? null);
        $this->assertContains('artifact_sha256_confirmation_required', $payload['issues'] ?? []);
        $this->assertContains('exact_write_confirmation_required', $payload['issues'] ?? []);
        $this->assertFalse((bool) ($payload['writes_committed'] ?? true));
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_gsc_daily')->count());
        Http::assertNothingSent();
    }

    #[Test]
    public function execute_blocks_when_artifact_sha256_does_not_match(): void
    {
        Http::fake();
        $artifactPath = $this->writeArtifact($this->validArtifact());
        $sha256 = hash_file('sha256', $artifactPath);

        [$exitCode, $payload] = $this->runCanaryCommand([
            '--artifact' => $artifactPath,
            '--limit' => 1,
            '--confirm-artifact-sha256' => str_repeat('0', 64),
            '--confirm-write' => app(GscReadModelControlledImportCanary::class)->confirmationPhrase((string) $sha256),
            '--execute' => true,
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status'] ?? null);
        $this->assertContains('artifact_sha256_confirmation_required', $payload['issues'] ?? []);
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_gsc_daily')->count());
        Http::assertNothingSent();
    }

    #[Test]
    public function canary_reuses_dry_run_importer_for_forbidden_field_gate(): void
    {
        Http::fake();
        $artifact = $this->validArtifact();
        data_set($artifact, 'payload.metadata.safe_row_preview.0.raw_query', 'mbti test');
        data_set($artifact, 'payload.metadata.preflight.client_email', 'reader@example.invalid');
        $artifactPath = $this->writeArtifact($artifact);

        [$exitCode, $payload] = $this->runCanaryCommand([
            '--artifact' => $artifactPath,
            '--limit' => 1,
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status'] ?? null);
        $this->assertContains('dry_run_importer_validation_failed', $payload['issues'] ?? []);
        $this->assertContains('forbidden_field_present', $payload['dry_run_importer_errors'] ?? []);
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_gsc_daily')->count());
        Http::assertNothingSent();
    }

    #[Test]
    public function canary_requires_live_origin_and_passing_data_quality_gate(): void
    {
        Http::fake();
        $artifact = $this->validArtifact();
        data_set($artifact, 'payload.metadata.data_origin', 'fixture');
        data_set($artifact, 'payload.metadata.data_quality_gate.status', 'blocked');
        $artifactPath = $this->writeArtifact($artifact);

        [$exitCode, $payload] = $this->runCanaryCommand([
            '--artifact' => $artifactPath,
            '--limit' => 1,
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status'] ?? null);
        $this->assertContains('data_origin_must_be_live_gsc_api', $payload['dry_run_importer_errors'] ?? []);
        $this->assertContains('data_quality_gate_must_pass', $payload['dry_run_importer_errors'] ?? []);
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_gsc_daily')->count());
        Http::assertNothingSent();
    }

    #[Test]
    public function canary_fails_closed_when_limit_is_not_exactly_one(): void
    {
        Http::fake();
        $artifactPath = $this->writeArtifact($this->validArtifact());

        [$exitCode, $payload] = $this->runCanaryCommand([
            '--artifact' => $artifactPath,
            '--limit' => 2,
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status'] ?? null);
        $this->assertSame(['limit_must_be_exactly_1'], $payload['issues'] ?? null);
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_gsc_daily')->count());
        Http::assertNothingSent();
    }

    #[Test]
    public function execute_inserts_one_sanitized_row_only_after_exact_confirmations(): void
    {
        Http::fake();
        $artifactPath = $this->writeArtifact($this->validArtifact());
        $sha256 = (string) hash_file('sha256', $artifactPath);

        [$exitCode, $payload] = $this->runCanaryCommand([
            '--artifact' => $artifactPath,
            '--limit' => 1,
            '--confirm-artifact-sha256' => $sha256,
            '--confirm-write' => app(GscReadModelControlledImportCanary::class)->confirmationPhrase($sha256),
            '--execute' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('success', $payload['status'] ?? null);
        $this->assertSame('canary_execute', $payload['mode'] ?? null);
        $this->assertTrue((bool) ($payload['writes_committed'] ?? false));
        $this->assertSame(1, $payload['rows_inserted'] ?? null);
        $this->assertSame(0, $payload['rows_skipped_existing'] ?? null);

        $rows = DB::connection('seo_intel')->table('seo_gsc_daily')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('2026-06-17', (string) $rows[0]->report_date);
        $this->assertSame($this->expectedIdempotencyKey(), $rows[0]->idempotency_key);
        $this->assertSame(hash('sha256', 'https://fermatmind.com/zh/articles/mbti-basics'), $rows[0]->canonical_url_hash);
        $this->assertNull($rows[0]->canonical_url);
        $this->assertSame(hash('sha256', 'mbti测试'), $rows[0]->query_hash);
        $this->assertSame('m****试', $rows[0]->query_display_masked);
        $this->assertSame(60, (int) $rows[0]->impressions);
        $this->assertStringNotContainsString('mbti测试', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        Http::assertNothingSent();
    }

    #[Test]
    public function execute_is_idempotent_for_the_same_canary_key(): void
    {
        Http::fake();
        $artifactPath = $this->writeArtifact($this->validArtifact());
        $sha256 = (string) hash_file('sha256', $artifactPath);
        $arguments = [
            '--artifact' => $artifactPath,
            '--limit' => 1,
            '--confirm-artifact-sha256' => $sha256,
            '--confirm-write' => app(GscReadModelControlledImportCanary::class)->confirmationPhrase($sha256),
            '--execute' => true,
            '--json' => true,
        ];

        $this->runCanaryCommand($arguments);
        [$exitCode, $payload] = $this->runCanaryCommand($arguments);

        $this->assertSame(0, $exitCode);
        $this->assertSame('success', $payload['status'] ?? null);
        $this->assertFalse((bool) ($payload['writes_committed'] ?? true));
        $this->assertSame(0, $payload['rows_inserted'] ?? null);
        $this->assertSame(1, $payload['rows_skipped_existing'] ?? null);
        $this->assertSame(1, DB::connection('seo_intel')->table('seo_gsc_daily')->count());
        Http::assertNothingSent();
    }

    #[Test]
    public function migration_backfills_idempotency_key_and_adds_unique_index_for_existing_rows(): void
    {
        Schema::connection('seo_intel')->drop('seo_gsc_daily');
        $this->createSeoGscDailyTable(includeIdempotencyKey: false);

        DB::connection('seo_intel')->table('seo_gsc_daily')->insert([
            'report_date' => '2026-06-17',
            'canonical_url_hash' => hash('sha256', 'https://fermatmind.com/zh/articles/mbti-basics'),
            'canonical_url' => null,
            'query_hash' => hash('sha256', 'mbti测试'),
            'query_display_masked' => 'm****试',
            'locale' => 'zh-CN',
            'source_engine' => 'google',
            'device' => null,
            'country' => null,
            'search_type' => 'web',
            'clicks' => 0,
            'impressions' => 60,
            'ctr_ppm' => 0,
            'average_position_milli' => 9000,
            'is_brand_query' => false,
            'query_type' => 'non_brand',
            'data_state' => 'final',
            'metadata_json' => json_encode(['data_origin' => 'live_gsc_api'], JSON_UNESCAPED_SLASHES),
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        $migration = require base_path('database/migrations/seo_intel/2026_06_20_130000_add_idempotency_key_to_seo_gsc_daily_table.php');
        $migration->up();

        $row = DB::connection('seo_intel')->table('seo_gsc_daily')->first();
        $this->assertSame($this->expectedIdempotencyKey(), $row->idempotency_key);

        $indexes = DB::connection('seo_intel')->select("PRAGMA index_list('seo_gsc_daily')");
        $uniqueIndexNames = array_map(
            static fn (object $index): string => (bool) $index->unique ? (string) $index->name : '',
            $indexes,
        );

        $this->assertContains('seo_gsc_daily_idempotency_key_unique', $uniqueIndexNames);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{0:int,1:array<string,mixed>,2:string}
     */
    private function runCanaryCommand(array $arguments): array
    {
        $exitCode = Artisan::call('seo-intel:gsc-readmodel-import-canary', $arguments);
        $rawOutput = trim(Artisan::output());

        $this->assertNotSame('', $rawOutput);
        $payload = json_decode($rawOutput, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);

        return [$exitCode, $payload, $rawOutput];
    }

    /**
     * @return array<string, mixed>
     */
    private function validArtifact(): array
    {
        return [
            'schema_version' => 'gsc-hk-sidecar-runner-wrapper.v1',
            'task' => 'SEO-GSC-HK-SIDECAR-RUNNER-WRAPPER-01',
            'mode' => 'live-read',
            'payload' => [
                'collector' => 'gsc_foundation',
                'status' => 'success',
                'dry_run' => true,
                'writes_attempted' => false,
                'writes_committed' => false,
                'external_calls_attempted' => true,
                'items_seen' => 1,
                'metadata' => [
                    'mode' => 'gsc_live_readonly_sidecar_read',
                    'data_origin' => 'live_gsc_api',
                    'date_window' => [
                        'start_date' => '2026-06-17',
                        'end_date' => '2026-06-17',
                    ],
                    'data_quality_gate' => [
                        'status' => 'pass',
                        'opportunity_queue_eligible' => true,
                    ],
                    'safe_row_preview' => [[
                        'report_date' => '2026-06-17',
                        'canonical_url_hash' => hash('sha256', 'https://fermatmind.com/zh/articles/mbti-basics'),
                        'query_hash' => hash('sha256', 'mbti测试'),
                        'query_display_masked' => 'm****试',
                        'locale' => 'zh-CN',
                        'source_engine' => 'google',
                        'device' => null,
                        'country' => null,
                        'search_type' => 'web',
                        'clicks' => 0,
                        'impressions' => 60,
                        'ctr_ppm' => 0,
                        'average_position_milli' => 9000,
                        'is_brand_query' => false,
                        'query_type' => 'non_brand',
                        'data_state' => 'final',
                    ]],
                    'opportunity_queue_eligible' => false,
                    'cms_write_allowed' => false,
                    'search_channel_enqueue_allowed' => false,
                    'indexing_request_allowed' => false,
                    'writes_attempted' => false,
                    'writes_committed' => false,
                    'scheduler_enabled' => false,
                    'queue_worker_enabled' => false,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $artifact
     */
    private function writeArtifact(array $artifact): string
    {
        $dir = storage_path('framework/testing/gsc-readmodel-canary-'.Str::uuid()->toString());
        File::ensureDirectoryExists($dir);
        $path = $dir.'/artifact.json';
        File::put($path, json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    private function createSeoGscDailyTable(bool $includeIdempotencyKey = true): void
    {
        Schema::connection('seo_intel')->create('seo_gsc_daily', function (Blueprint $table) use ($includeIdempotencyKey): void {
            $table->id();
            if ($includeIdempotencyKey) {
                $table->char('idempotency_key', 64)->nullable()->unique('seo_gsc_daily_idempotency_key_unique');
            }
            $table->date('report_date');
            $table->char('canonical_url_hash', 64)->nullable();
            $table->text('canonical_url')->nullable();
            $table->char('query_hash', 64)->nullable();
            $table->string('query_display_masked', 255)->nullable();
            $table->string('locale', 16)->nullable();
            $table->string('source_engine', 64)->default('google');
            $table->string('device', 32)->nullable();
            $table->string('country', 16)->nullable();
            $table->string('search_type', 32)->nullable();
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('ctr_ppm')->nullable();
            $table->unsignedInteger('average_position_milli')->nullable();
            $table->boolean('is_brand_query')->default(false);
            $table->string('query_type', 32)->default('unknown');
            $table->string('data_state', 32)->default('final');
            $table->timestamp('collected_at')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
        });
    }

    private function expectedIdempotencyKey(): string
    {
        return hash('sha256', implode('|', [
            '2026-06-17',
            hash('sha256', 'https://fermatmind.com/zh/articles/mbti-basics'),
            hash('sha256', 'mbti测试'),
            'google',
            '',
            '',
            'web',
        ]));
    }
}
