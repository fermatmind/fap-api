<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ArtifactLedgerBackfillCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $isolatedStoragePath;

    private string $originalStoragePath;

    private string $originalLocalRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalStoragePath = $this->app->storagePath();
        $this->originalLocalRoot = (string) config('filesystems.disks.local.root');
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-artifact-ledger-backfill-'.Str::uuid();
        File::ensureDirectoryExists($this->isolatedStoragePath.'/app/private');
        $this->app->useStoragePath($this->isolatedStoragePath);
        config()->set('filesystems.disks.local.root', $this->isolatedStoragePath.'/app/private');
        Storage::forgetDisk('local');
    }

    protected function tearDown(): void
    {
        Storage::forgetDisk('local');
        $this->app->useStoragePath($this->originalStoragePath);
        config()->set('filesystems.disks.local.root', $this->originalLocalRoot);
        Storage::forgetDisk('local');

        if (is_dir($this->isolatedStoragePath)) {
            File::deleteDirectory($this->isolatedStoragePath);
        }

        parent::tearDown();
    }

    public function test_dry_run_reports_backfillable_counts_and_classifications(): void
    {
        $attemptId = 'attempt-ledger-'.Str::lower(Str::random(8));
        $this->seedHistoricalArtifacts($attemptId);

        $this->assertSame(0, Artisan::call('storage:backfill-artifact-ledger', [
            '--dry-run' => true,
        ]));

        $output = Artisan::output();
        $this->assertStringContainsString('status=planned', $output);
        $this->assertStringContainsString('mode=dry_run', $output);
        $this->assertStringContainsString('candidate_count=4', $output);
        $this->assertStringContainsString('classification_counts={"alias_or_legacy_path":2,"db_only":1,"matched_db_and_file":1}', $output);
        $this->assertStringContainsString('slot_counts={"report_json_free":1,"report_json_full":1,"report_pdf_free":1,"report_pdf_full":1}', $output);
        $this->assertStringContainsString('source_counts={"file":3,"snapshot":1}', $output);
        $this->assertStringContainsString('alias_or_legacy_path_count=2', $output);
        $this->assertStringContainsString('nohash_count=2', $output);
        $this->assertStringContainsString('slot_backfillable_count=4', $output);
        $this->assertStringContainsString('version_backfillable_count=4', $output);
        $this->assertStringContainsString('attempt_receipts_backfillable_count=4', $output);

        $this->assertDatabaseCount('attempt_receipts', 0);
        $this->assertDatabaseCount('report_artifact_slots', 0);
        $this->assertDatabaseCount('report_artifact_versions', 0);
        $this->assertDatabaseCount('storage_blobs', 0);
        $this->assertDatabaseCount('storage_blob_locations', 0);
    }

    public function test_execute_backfills_rows_and_is_idempotent(): void
    {
        $attemptId = 'attempt-ledger-'.Str::lower(Str::random(8));
        $this->seedHistoricalArtifacts($attemptId);

        $this->assertSame(0, Artisan::call('storage:backfill-artifact-ledger', [
            '--execute' => true,
        ]));

        $output = Artisan::output();
        $this->assertStringContainsString('status=executed', $output);
        $this->assertStringContainsString('mode=execute', $output);
        $this->assertStringContainsString('slots_upserted=4', $output);
        $this->assertStringContainsString('versions_inserted=4', $output);
        $this->assertStringContainsString('blobs_upserted=3', $output);
        $this->assertStringContainsString('blob_locations_upserted=3', $output);
        $this->assertStringContainsString('attempt_receipts_inserted=4', $output);

        $this->assertDatabaseCount('attempt_receipts', 4);
        $this->assertDatabaseCount('report_artifact_slots', 4);
        $this->assertDatabaseCount('report_artifact_versions', 4);
        $this->assertDatabaseCount('storage_blobs', 3);
        $this->assertDatabaseCount('storage_blob_locations', 3);

        $this->assertSame(0, Artisan::call('storage:backfill-artifact-ledger', [
            '--execute' => true,
        ]));

        $rerunOutput = Artisan::output();
        $this->assertStringContainsString('status=executed', $rerunOutput);
        $this->assertStringContainsString('versions_reused=4', $rerunOutput);
        $this->assertStringContainsString('attempt_receipts_reused=4', $rerunOutput);

        $this->assertDatabaseCount('attempt_receipts', 4);
        $this->assertDatabaseCount('report_artifact_slots', 4);
        $this->assertDatabaseCount('report_artifact_versions', 4);
        $this->assertDatabaseCount('storage_blobs', 3);
        $this->assertDatabaseCount('storage_blob_locations', 3);
    }

    private function seedHistoricalArtifacts(string $attemptId): void
    {
        $now = now();
        $reportJsonPath = storage_path('app/private/artifacts/reports/MBTI/'.$attemptId.'/report.json');
        $reportJsonPayload = [
            'attempt_id' => $attemptId,
            'artifact' => 'report_json_full',
        ];
        $reportFreePayload = [
            'attempt_id' => $attemptId,
            'artifact' => 'report_json_free',
        ];
        $pdfFreeAttemptId = 'attempt-ledger-pdf-free-'.Str::lower(Str::random(8));
        $pdfFullAttemptId = 'attempt-ledger-pdf-full-'.Str::lower(Str::random(8));
        $pdfFreePath = storage_path('app/private/artifacts/pdf/BIG5/'.$pdfFreeAttemptId.'/nohash/report_free.pdf');
        $pdfFullPath = storage_path('app/private/artifacts/pdf/BIG5_OCEAN/'.$pdfFullAttemptId.'/nohash/report_full.pdf');

        File::ensureDirectoryExists(dirname($reportJsonPath));
        File::ensureDirectoryExists(dirname($pdfFreePath));
        File::ensureDirectoryExists(dirname($pdfFullPath));
        File::put($reportJsonPath, $this->encodeJson($reportJsonPayload));
        File::put($pdfFreePath, '%PDF-1.4 free nohash');
        File::put($pdfFullPath, '%PDF-1.4 full nohash');

        DB::table('report_snapshots')->insert([
            'org_id' => 1,
            'attempt_id' => $attemptId,
            'order_no' => 'order-'.$attemptId,
            'scale_code' => 'MBTI',
            'pack_id' => 'MBTI',
            'dir_version' => 'v1',
            'scoring_spec_version' => 'spec-v1',
            'report_engine_version' => 'engine-v1',
            'snapshot_version' => 'v1',
            'report_json' => $this->encodeJson(['attempt_id' => $attemptId, 'artifact' => 'snapshot']),
            'report_free_json' => $this->encodeJson($reportFreePayload),
            'report_full_json' => $this->encodeJson($reportJsonPayload),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function encodeJson(array $payload): string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded)) {
            throw new \RuntimeException('failed to encode test json payload.');
        }

        return $encoded;
    }
}
