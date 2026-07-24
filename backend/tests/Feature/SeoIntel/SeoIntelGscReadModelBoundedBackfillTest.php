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

final class SeoIntelGscReadModelBoundedBackfillTest extends TestCase
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
        Http::fake();
    }

    #[Test]
    public function dry_run_supports_all_cohorts_and_emits_sanitized_bounded_receipts(): void
    {
        $artifactPath = $this->writeArtifact($this->validArtifact(5));

        foreach (GscReadModelControlledImportCanary::BACKFILL_COHORTS as $cohort) {
            [$exitCode, $payload, $raw] = $this->runCommand([
                '--artifact' => $artifactPath,
                '--backfill' => true,
                '--cohort' => $cohort,
                '--batch-size' => 2,
                '--hard-max-rows' => 5,
                '--resume-key' => 'operator-run-2026-07-24',
                '--json' => true,
            ]);

            $this->assertSame(0, $exitCode);
            $this->assertSame('success', $payload['status'] ?? null);
            $this->assertSame($cohort, $payload['cohort'] ?? null);
            $this->assertSame(2, $payload['rows_in_batch'] ?? null);
            $this->assertTrue((bool) ($payload['has_more'] ?? false));
            $this->assertNotSame('', $payload['next_cursor'] ?? '');
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) ($payload['batch_idempotency_key'] ?? ''));
            $this->assertStringNotContainsString('operator-run-2026-07-24', $raw);
            $this->assertStringNotContainsString('mbti raw query', $raw);
            $this->assertStringNotContainsString('https://fermatmind.com/zh/articles/secret-', $raw);
        }

        $this->assertSame(0, DB::connection('seo_intel')->table('seo_gsc_daily')->count());
        Http::assertNothingSent();
    }

    #[Test]
    public function cursor_is_stable_and_bound_to_artifact_cohort_resume_key_and_hard_max(): void
    {
        $artifactPath = $this->writeArtifact($this->validArtifact(5));
        [, $first] = $this->runCommand($this->planArguments($artifactPath));
        $cursor = (string) ($first['next_cursor'] ?? '');

        [$exitCode, $second] = $this->runCommand([
            ...$this->planArguments($artifactPath),
            '--cursor' => $cursor,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(2, $second['cursor_offset'] ?? null);
        $this->assertSame(2, $second['rows_in_batch'] ?? null);

        foreach ([
            ['--cohort' => 'query'],
            ['--resume-key' => 'different-resume-key'],
            ['--hard-max-rows' => 4],
        ] as $override) {
            [$blockedExitCode, $blocked] = $this->runCommand([
                ...$this->planArguments($artifactPath),
                ...$override,
                '--cursor' => $cursor,
            ]);

            $this->assertSame(1, $blockedExitCode);
            $this->assertContains('cursor_binding_mismatch', $blocked['issues'] ?? []);
        }
    }

    #[Test]
    public function reset_starts_at_zero_and_rejects_an_ambiguous_cursor_combination(): void
    {
        $artifactPath = $this->writeArtifact($this->validArtifact(5));
        [, $first] = $this->runCommand($this->planArguments($artifactPath));

        [$blockedExitCode, $blocked] = $this->runCommand([
            ...$this->planArguments($artifactPath),
            '--cursor' => (string) $first['next_cursor'],
            '--reset' => true,
        ]);
        $this->assertSame(1, $blockedExitCode);
        $this->assertContains('cursor_forbidden_with_reset', $blocked['issues'] ?? []);

        [$resetExitCode, $reset] = $this->runCommand([
            ...$this->planArguments($artifactPath),
            '--reset' => true,
        ]);
        $this->assertSame(0, $resetExitCode);
        $this->assertSame(0, $reset['cursor_offset'] ?? null);
        $this->assertTrue((bool) ($reset['reset'] ?? false));
        $this->assertSame($first['batch_idempotency_key'] ?? null, $reset['batch_idempotency_key'] ?? null);
    }

    #[Test]
    public function execute_fails_closed_without_artifact_write_and_production_confirmations(): void
    {
        $artifact = $this->validArtifact(2);
        $raw = json_encode($artifact, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $sha256 = hash('sha256', $raw);
        $service = app(GscReadModelControlledImportCanary::class);

        $blocked = $service->executeBackfill(
            $artifact,
            $sha256,
            'query-page',
            2,
            2,
            'production-run',
            null,
            false,
            null,
            null,
            true,
            false,
        );

        $this->assertSame('blocked', $blocked['status'] ?? null);
        $this->assertContains('artifact_sha256_confirmation_required', $blocked['issues'] ?? []);
        $this->assertContains('exact_write_confirmation_required', $blocked['issues'] ?? []);
        $this->assertContains('production_write_confirmation_required', $blocked['issues'] ?? []);
        $this->assertFalse((bool) ($blocked['writes_attempted'] ?? true));
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_gsc_daily')->count());
        Http::assertNothingSent();
    }

    #[Test]
    public function confirmed_execution_is_duplicate_safe_and_includes_readback_receipt(): void
    {
        $artifactPath = $this->writeArtifact($this->validArtifact(3));
        $arguments = $this->executeArguments($artifactPath, 3, 3);

        [$firstExitCode, $first] = $this->runCommand($arguments);
        [$secondExitCode, $second] = $this->runCommand($arguments);

        $this->assertSame(0, $firstExitCode, json_encode($first, JSON_UNESCAPED_SLASHES));
        $this->assertSame(3, $first['rows_inserted'] ?? null);
        $this->assertSame(0, $first['rows_skipped_existing'] ?? null);
        $this->assertSame('pass', data_get($first, 'readback_receipt.status'));
        $this->assertSame(3, data_get($first, 'readback_receipt.rows_found'));
        $this->assertSame(0, data_get($first, 'readback_receipt.rows_missing'));

        $this->assertSame(0, $secondExitCode);
        $this->assertSame(0, $second['rows_inserted'] ?? null);
        $this->assertSame(3, $second['rows_skipped_existing'] ?? null);
        $this->assertSame($first['batch_idempotency_key'] ?? null, $second['batch_idempotency_key'] ?? null);
        $this->assertSame(3, DB::connection('seo_intel')->table('seo_gsc_daily')->count());
        Http::assertNothingSent();
    }

    #[Test]
    public function rerun_skips_a_semantically_identical_row_with_a_legacy_idempotency_key(): void
    {
        $artifactPath = $this->writeArtifact($this->validArtifact(1));
        $arguments = $this->executeArguments($artifactPath, 1, 1);
        $this->runCommand($arguments);
        DB::connection('seo_intel')->table('seo_gsc_daily')->update([
            'idempotency_key' => hash('sha256', 'legacy-delimiter-key'),
        ]);

        [$exitCode, $payload] = $this->runCommand($arguments);

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, $payload['rows_inserted'] ?? null);
        $this->assertSame(1, $payload['rows_skipped_existing'] ?? null);
        $this->assertSame('pass', data_get($payload, 'readback_receipt.status'));
        $this->assertSame(1, DB::connection('seo_intel')->table('seo_gsc_daily')->count());
    }

    #[Test]
    public function partial_failure_receipt_keeps_cursor_at_failed_row_for_safe_resume(): void
    {
        $artifactPath = $this->writeArtifact($this->validArtifact(5));
        DB::connection('seo_intel')->statement(
            "CREATE TRIGGER fail_one_gsc_row BEFORE INSERT ON seo_gsc_daily
             WHEN NEW.impressions = 62
             BEGIN SELECT RAISE(ABORT, 'forced test failure'); END",
        );

        [$exitCode, $failed] = $this->runCommand($this->executeArguments($artifactPath, 5, 5));

        $this->assertSame(1, $exitCode);
        $this->assertSame('partial_failure', $failed['status'] ?? null, json_encode($failed, JSON_UNESCAPED_SLASHES));
        $this->assertSame('database_write_failed', data_get($failed, 'rows_failed.0.error_code'));
        $this->assertTrue((bool) data_get($failed, 'partial_failure_receipt.safe_to_retry'));
        $this->assertNotSame('', data_get($failed, 'partial_failure_receipt.retry_cursor', ''));
        $this->assertSame(
            $failed['rows_processed'] ?? null,
            data_get($failed, 'readback_receipt.rows_found'),
        );

        DB::connection('seo_intel')->statement('DROP TRIGGER fail_one_gsc_row');
        [$resumeExitCode, $resumed] = $this->runCommand($this->executeArguments(
            $artifactPath,
            5,
            5,
            (string) data_get($failed, 'partial_failure_receipt.retry_cursor'),
        ));

        $this->assertSame(0, $resumeExitCode);
        $this->assertSame('success', $resumed['status'] ?? null);
        $this->assertTrue((bool) ($resumed['complete'] ?? false));
        $this->assertSame(5, DB::connection('seo_intel')->table('seo_gsc_daily')->count());
    }

    #[Test]
    public function hard_max_caps_the_total_cohort_and_reports_deferred_rows(): void
    {
        $artifactPath = $this->writeArtifact($this->validArtifact(5));

        [$exitCode, $payload] = $this->runCommand([
            '--artifact' => $artifactPath,
            '--backfill' => true,
            '--cohort' => 'page',
            '--batch-size' => 10,
            '--hard-max-rows' => 3,
            '--resume-key' => 'hard-cap-run',
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(3, $payload['rows_available'] ?? null);
        $this->assertSame(3, $payload['rows_in_batch'] ?? null);
        $this->assertSame(2, $payload['rows_deferred_by_hard_max'] ?? null);
        $this->assertTrue((bool) ($payload['complete'] ?? false));
        $this->assertFalse((bool) ($payload['has_more'] ?? true));
    }

    #[Test]
    public function artifact_schema_mode_and_origin_mismatches_fail_closed(): void
    {
        $mutations = [
            'schema' => ['schema_version', 'unexpected.v1', 'artifact_schema_mismatch'],
            'mode' => ['mode', 'fixture', 'artifact_mode_mismatch'],
            'origin' => ['payload.metadata.data_origin', 'fixture', 'artifact_validation_failed'],
        ];

        foreach ($mutations as [$path, $value, $expectedIssue]) {
            $artifact = $this->validArtifact(2);
            data_set($artifact, $path, $value);
            $artifactPath = $this->writeArtifact($artifact);

            [$exitCode, $payload] = $this->runCommand($this->planArguments($artifactPath));

            $this->assertSame(1, $exitCode);
            $this->assertContains($expectedIssue, $payload['issues'] ?? []);
            $this->assertFalse((bool) ($payload['writes_attempted'] ?? true));
        }
    }

    #[Test]
    public function non_duplicate_database_constraint_error_is_not_misreported_as_a_skip(): void
    {
        $artifactPath = $this->writeArtifact($this->validArtifact(2));
        DB::connection('seo_intel')->statement(
            "CREATE TRIGGER reject_all_gsc_rows BEFORE INSERT ON seo_gsc_daily
             BEGIN SELECT RAISE(ABORT, 'non duplicate constraint'); END",
        );

        [$exitCode, $payload] = $this->runCommand($this->executeArguments($artifactPath, 2, 2));

        $this->assertSame(1, $exitCode);
        $this->assertSame('partial_failure', $payload['status'] ?? null);
        $this->assertSame(0, $payload['rows_skipped_existing'] ?? null);
        $this->assertSame(0, $payload['rows_inserted'] ?? null);
        $this->assertSame('database_write_failed', data_get($payload, 'rows_failed.0.error_code'));
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_gsc_daily')->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function planArguments(string $artifactPath): array
    {
        return [
            '--artifact' => $artifactPath,
            '--backfill' => true,
            '--cohort' => 'query-page',
            '--batch-size' => 2,
            '--hard-max-rows' => 5,
            '--resume-key' => 'stable-resume-key',
            '--json' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function executeArguments(
        string $artifactPath,
        int $batchSize,
        int $hardMaxRows,
        ?string $cursor = null,
    ): array {
        $sha256 = (string) hash_file('sha256', $artifactPath);
        $resumeKey = 'confirmed-resume-key';
        $service = app(GscReadModelControlledImportCanary::class);
        $arguments = [
            '--artifact' => $artifactPath,
            '--backfill' => true,
            '--cohort' => 'query-page',
            '--batch-size' => $batchSize,
            '--hard-max-rows' => $hardMaxRows,
            '--resume-key' => $resumeKey,
            '--execute' => true,
            '--confirm-production-write' => true,
            '--confirm-artifact-sha256' => $sha256,
            '--confirm-write' => $service->backfillConfirmationPhrase(
                $sha256,
                'query-page',
                $batchSize,
                $hardMaxRows,
                hash('sha256', $resumeKey),
            ),
            '--json' => true,
        ];
        if ($cursor !== null) {
            $arguments['--cursor'] = $cursor;
        }

        return $arguments;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{0:int,1:array<string,mixed>,2:string}
     */
    private function runCommand(array $arguments): array
    {
        $exitCode = Artisan::call('seo-intel:gsc-readmodel-import-canary', $arguments);
        $raw = trim(Artisan::output());
        $this->assertNotSame('', $raw);
        $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);

        return [$exitCode, $payload, $raw];
    }

    /**
     * @return array<string, mixed>
     */
    private function validArtifact(int $rowCount): array
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
                'items_seen' => $rowCount,
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
                    'safe_row_preview' => array_map(
                        fn (int $index): array => $this->safeRow($index),
                        range(0, $rowCount - 1),
                    ),
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
     * @return array<string, mixed>
     */
    private function safeRow(int $index): array
    {
        return [
            'report_date' => '2026-06-17',
            'canonical_url_hash' => hash('sha256', 'https://fermatmind.com/zh/articles/secret-'.$index),
            'query_hash' => hash('sha256', 'mbti raw query '.$index),
            'query_display_masked' => 'm****'.$index,
            'locale' => 'zh-CN',
            'source_engine' => 'google',
            'device' => null,
            'country' => null,
            'search_type' => 'web',
            'clicks' => $index,
            'impressions' => 60 + $index,
            'ctr_ppm' => 0,
            'average_position_milli' => 9000 + $index,
            'is_brand_query' => false,
            'query_type' => 'non_brand',
            'data_state' => 'final',
        ];
    }

    /**
     * @param  array<string, mixed>  $artifact
     */
    private function writeArtifact(array $artifact): string
    {
        $dir = storage_path('framework/testing/gsc-bounded-backfill-'.Str::uuid()->toString());
        File::ensureDirectoryExists($dir);
        $path = $dir.'/artifact.json';
        File::put($path, json_encode($artifact, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    private function createSeoGscDailyTable(): void
    {
        Schema::connection('seo_intel')->create('seo_gsc_daily', function (Blueprint $table): void {
            $table->id();
            $table->char('idempotency_key', 64)->unique('seo_gsc_daily_idempotency_key_unique');
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
}
