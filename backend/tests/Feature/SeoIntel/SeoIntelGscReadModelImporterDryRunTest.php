<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\GscReadModelArtifactDryRunImporter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelGscReadModelImporterDryRunTest extends TestCase
{
    #[Test]
    public function importer_previews_future_seo_gsc_daily_rows_without_writing(): void
    {
        $artifact = $this->validArtifact();
        $summary = (new GscReadModelArtifactDryRunImporter)->preview($artifact);

        $this->assertTrue((bool) ($summary['ok'] ?? false));
        $this->assertTrue((bool) ($summary['dry_run'] ?? false));
        $this->assertFalse((bool) ($summary['would_write'] ?? true));
        $this->assertSame('seo_gsc_daily', $summary['target_table'] ?? null);
        $this->assertSame(1, $summary['rows_previewed'] ?? null);
        $this->assertSame(1, $summary['rows_would_insert'] ?? null);
        $this->assertSame('live_gsc_api', $summary['data_origin'] ?? null);
        $this->assertSame('pass', $summary['data_quality_gate'] ?? null);

        $row = data_get($summary, 'preview_rows.0');
        $this->assertIsArray($row);
        $this->assertSame('2026-06-17', $row['report_date'] ?? null);
        $this->assertSame(hash('sha256', 'https://fermatmind.com/zh/articles/mbti-basics'), $row['canonical_url_hash'] ?? null);
        $this->assertNull($row['canonical_url'] ?? null);
        $this->assertSame(hash('sha256', 'mbti测试'), $row['query_hash'] ?? null);
        $this->assertSame('m****试', $row['query_display_masked'] ?? null);
        $this->assertSame('google', $row['source_engine'] ?? null);
        $this->assertSame(0, $row['clicks'] ?? null);
        $this->assertSame(60, $row['impressions'] ?? null);
        $this->assertSame('live_gsc_api', data_get($row, 'metadata_json.data_origin'));
        $this->assertFalse((bool) data_get($summary, 'negative_guarantees.database_write', true));
    }

    #[Test]
    public function artisan_command_reads_artifact_and_outputs_json_preview_without_db_write(): void
    {
        $artifactPath = $this->writeArtifact($this->validArtifact());

        $exitCode = Artisan::call('seo-intel:gsc-readmodel-import-dry-run', [
            '--artifact' => $artifactPath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($decoded);
        $this->assertSame('gsc-readmodel-importer-dryrun.v1', $decoded['schema_version'] ?? null);
        $this->assertTrue((bool) ($decoded['ok'] ?? false));
        $this->assertFalse((bool) ($decoded['would_write'] ?? true));
        $this->assertSame(1, $decoded['rows_would_insert'] ?? null);
        $this->assertStringNotContainsString('mbti测试', Artisan::output());
        $this->assertStringNotContainsString('https://fermatmind.com/zh/articles/mbti-basics', Artisan::output());
        $this->assertStringNotContainsString('secret-gsc-token', Artisan::output());
    }

    #[Test]
    public function command_requires_explicit_dry_run(): void
    {
        $artifactPath = $this->writeArtifact($this->validArtifact());

        $exitCode = Artisan::call('seo-intel:gsc-readmodel-import-dry-run', [
            '--artifact' => $artifactPath,
            '--json' => true,
        ]);

        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertIsArray($decoded);
        $this->assertSame(['dry_run_required'], $decoded['errors'] ?? null);
        $this->assertFalse((bool) ($decoded['would_write'] ?? true));
    }

    #[Test]
    public function importer_fails_closed_when_forbidden_fields_are_present(): void
    {
        $artifact = $this->validArtifact();
        $artifact['payload']['metadata']['safe_row_preview'][0]['raw_query'] = 'mbti测试';
        $artifact['payload']['metadata']['preflight']['client_email'] = 'reader@example.invalid';
        $artifact['payload']['metadata']['preflight']['credential_path'] = '/secret/path.json';

        $summary = (new GscReadModelArtifactDryRunImporter)->preview($artifact);

        $this->assertFalse((bool) ($summary['ok'] ?? true));
        $this->assertSame(0, $summary['rows_would_insert'] ?? null);
        $this->assertContains('forbidden_field_present', $summary['errors'] ?? []);
        $this->assertContains('payload.metadata.safe_row_preview.0.raw_query', $summary['forbidden_fields_found'] ?? []);
        $this->assertContains('payload.metadata.preflight.client_email', $summary['forbidden_fields_found'] ?? []);
        $this->assertContains('payload.metadata.preflight.credential_path', $summary['forbidden_fields_found'] ?? []);
        $this->assertSame([], $summary['preview_rows'] ?? null);
    }

    #[Test]
    public function importer_requires_live_origin_and_passing_quality_gate(): void
    {
        $artifact = $this->validArtifact();
        data_set($artifact, 'payload.metadata.data_origin', 'fixture');
        data_set($artifact, 'payload.metadata.data_quality_gate.status', 'blocked');

        $summary = (new GscReadModelArtifactDryRunImporter)->preview($artifact);

        $this->assertFalse((bool) ($summary['ok'] ?? true));
        $this->assertContains('data_origin_must_be_live_gsc_api', $summary['errors'] ?? []);
        $this->assertContains('data_quality_gate_must_pass', $summary['errors'] ?? []);
        $this->assertSame(0, $summary['rows_would_insert'] ?? null);
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
        $dir = storage_path('framework/testing/gsc-readmodel-importer-'.Str::uuid()->toString());
        File::ensureDirectoryExists($dir);
        $path = $dir.'/artifact.json';
        File::put($path, json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $path;
    }
}
