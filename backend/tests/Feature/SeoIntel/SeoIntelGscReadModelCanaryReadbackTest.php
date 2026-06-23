<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelGscReadModelCanaryReadbackTest extends TestCase
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
    public function command_reports_missing_canary_rows_without_writing_or_printing_raw_values(): void
    {
        Http::fake();
        $artifactPath = $this->writeArtifact($this->validArtifact(rowCount: 2));

        [$exitCode, $payload, $output] = $this->runReadbackCommand($artifactPath);

        $this->assertSame(0, $exitCode);
        $this->assertSame('success', $payload['status'] ?? null);
        $this->assertTrue((bool) ($payload['read_only'] ?? false));
        $this->assertFalse((bool) ($payload['would_write'] ?? true));
        $this->assertSame('seo_gsc_daily', $payload['target_table'] ?? null);
        $this->assertSame(2, $payload['rows_previewed'] ?? null);
        $this->assertSame(2, $payload['idempotency_key_count'] ?? null);
        $this->assertSame(0, $payload['rows_found'] ?? null);
        $this->assertSame(0, $payload['distinct_keys'] ?? null);
        $this->assertSame(2, $payload['rows_missing'] ?? null);
        $this->assertFalse((bool) ($payload['would_duplicate'] ?? true));
        $this->assertFalse((bool) ($payload['all_rows_already_present'] ?? true));
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_gsc_daily')->count());
        $this->assertStringNotContainsString('mbti测试', $output);
        $this->assertStringNotContainsString('https://fermatmind.com/zh/articles/mbti-basics', $output);
        $this->assertArrayNotHasKey('preview_rows', $payload);
        Http::assertNothingSent();
    }

    #[Test]
    public function command_reads_existing_canary_keys_and_reports_duplicate_boundary(): void
    {
        Http::fake();
        $artifact = $this->validArtifact(rowCount: 2);
        $artifactPath = $this->writeArtifact($artifact);
        $this->insertSeoGscDailyRow($this->safeRow(0));
        $this->insertSeoGscDailyRow($this->safeRow(1));

        [$exitCode, $payload, $output] = $this->runReadbackCommand($artifactPath);

        $this->assertSame(0, $exitCode);
        $this->assertSame('success', $payload['status'] ?? null);
        $this->assertSame(2, $payload['rows_found'] ?? null);
        $this->assertSame(2, $payload['distinct_keys'] ?? null);
        $this->assertSame(0, $payload['rows_missing'] ?? null);
        $this->assertTrue((bool) ($payload['would_duplicate'] ?? false));
        $this->assertTrue((bool) ($payload['all_rows_already_present'] ?? false));
        $this->assertSame(2, DB::connection('seo_intel')->table('seo_gsc_daily')->count());
        $this->assertStringNotContainsString('mbti测试', $output);
        $this->assertStringNotContainsString('https://fermatmind.com/zh/articles/mbti-basics', $output);
        Http::assertNothingSent();
    }

    #[Test]
    public function command_blocks_when_artifact_sha256_does_not_match(): void
    {
        Http::fake();
        $artifactPath = $this->writeArtifact($this->validArtifact());

        $exitCode = Artisan::call('seo-intel:gsc-readmodel-canary-readback', [
            '--artifact' => $artifactPath,
            '--artifact-sha256' => str_repeat('0', 64),
            '--json' => true,
        ]);
        $output = trim(Artisan::output());
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status'] ?? null);
        $this->assertContains('artifact_sha256_mismatch', $payload['issues'] ?? []);
        $this->assertFalse((bool) ($payload['would_write'] ?? true));
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_gsc_daily')->count());
        $this->assertStringNotContainsString('mbti测试', $output);
        $this->assertStringNotContainsString('https://fermatmind.com/zh/articles/mbti-basics', $output);
        Http::assertNothingSent();
    }

    /**
     * @return array{0:int,1:array<string,mixed>,2:string}
     */
    private function runReadbackCommand(string $artifactPath): array
    {
        $exitCode = Artisan::call('seo-intel:gsc-readmodel-canary-readback', [
            '--artifact' => $artifactPath,
            '--artifact-sha256' => hash_file('sha256', $artifactPath),
            '--json' => true,
        ]);
        $output = trim(Artisan::output());

        $this->assertNotSame('', $output);
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);

        return [$exitCode, $payload, $output];
    }

    /**
     * @return array<string, mixed>
     */
    private function validArtifact(int $rowCount = 1): array
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
            'canonical_url_hash' => hash('sha256', 'https://fermatmind.com/zh/articles/mbti-basics-'.$index),
            'query_hash' => hash('sha256', 'mbti测试'.$index),
            'query_display_masked' => 'm****试',
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
        $dir = storage_path('framework/testing/gsc-readmodel-readback-'.Str::uuid()->toString());
        File::ensureDirectoryExists($dir);
        $path = $dir.'/artifact.json';
        File::put($path, json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function insertSeoGscDailyRow(array $row): void
    {
        DB::connection('seo_intel')->table('seo_gsc_daily')->insert([
            'idempotency_key' => $this->idempotencyKey($row),
            'report_date' => (string) $row['report_date'],
            'canonical_url_hash' => (string) $row['canonical_url_hash'],
            'canonical_url' => null,
            'query_hash' => (string) $row['query_hash'],
            'query_display_masked' => $row['query_display_masked'],
            'locale' => $row['locale'],
            'source_engine' => 'google',
            'device' => $row['device'],
            'country' => $row['country'],
            'search_type' => $row['search_type'],
            'clicks' => (int) $row['clicks'],
            'impressions' => (int) $row['impressions'],
            'ctr_ppm' => $row['ctr_ppm'],
            'average_position_milli' => $row['average_position_milli'],
            'is_brand_query' => (bool) $row['is_brand_query'],
            'query_type' => (string) $row['query_type'],
            'data_state' => (string) $row['data_state'],
            'metadata_json' => json_encode(['data_origin' => 'live_gsc_api'], JSON_UNESCAPED_SLASHES),
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function idempotencyKey(array $row): string
    {
        return hash('sha256', implode('|', [
            trim((string) ($row['report_date'] ?? '')),
            trim((string) ($row['canonical_url_hash'] ?? '')),
            trim((string) ($row['query_hash'] ?? '')),
            trim((string) ($row['source_engine'] ?? 'google')),
            trim((string) ($row['device'] ?? '')),
            trim((string) ($row['country'] ?? '')),
            trim((string) ($row['search_type'] ?? '')),
        ]));
    }

    private function createSeoGscDailyTable(): void
    {
        Schema::connection('seo_intel')->create('seo_gsc_daily', function (Blueprint $table): void {
            $table->id();
            $table->char('idempotency_key', 64)->nullable()->unique('seo_gsc_daily_idempotency_key_unique');
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
