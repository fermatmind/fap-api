<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Console\Commands\StorageShrinkOffloadLocalCopies;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StorageShrinkOffloadLocalCopiesCommandTest extends TestCase
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
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-offload-local-copy-shrink-command-'.Str::uuid();
        File::ensureDirectoryExists($this->isolatedStoragePath.'/app/private');

        $this->app->useStoragePath($this->isolatedStoragePath);
        config()->set('filesystems.disks.local.root', $this->isolatedStoragePath.'/app/private');
        config()->set('storage_rollout.blob_offload_prefix', 'rollout/blobs');
        config()->set('filesystems.disks.s3.bucket', 'offload-shrink-command-bucket');
        Storage::forgetDisk('local');
        Storage::fake('s3');

        $this->app->make(ConsoleKernel::class)->registerCommand(
            $this->app->make(StorageShrinkOffloadLocalCopies::class)
        );
    }

    protected function tearDown(): void
    {
        Storage::forgetDisk('local');
        Storage::forgetDisk('s3');
        $this->app->useStoragePath($this->originalStoragePath);
        config()->set('filesystems.disks.local.root', $this->originalLocalRoot);
        Storage::forgetDisk('local');
        Storage::forgetDisk('s3');

        if (is_dir($this->isolatedStoragePath)) {
            File::deleteDirectory($this->isolatedStoragePath);
        }

        parent::tearDown();
    }

    public function test_command_requires_exactly_one_mode_and_plan_for_execute(): void
    {
        $this->artisan('storage:shrink-offload-local-copies')
            ->expectsOutputToContain('exactly one of --dry-run or --execute is required.')
            ->assertExitCode(1);

        $this->artisan('storage:shrink-offload-local-copies --dry-run --execute')
            ->expectsOutputToContain('exactly one of --dry-run or --execute is required.')
            ->assertExitCode(1);

        $this->artisan('storage:shrink-offload-local-copies --execute --disk=s3')
            ->expectsOutputToContain('--execute requires --plan.')
            ->assertExitCode(1);
    }

    public function test_command_dry_run_and_execute_emit_summary_and_json_payload(): void
    {
        $hash = hash('sha256', '{"suffix":"command-both"}');
        $localPath = 'offload/blobs/sha256/'.substr($hash, 0, 2).'/'.$hash;
        $targetPath = 'rollout/blobs/sha256/'.substr($hash, 0, 2).'/'.$hash;
        $bytes = '{"suffix":"command-both"}';

        \DB::table('storage_blobs')->insert([
            'hash' => $hash,
            'disk' => 'local',
            'storage_path' => 'blobs/sha256/'.substr($hash, 0, 2).'/'.$hash,
            'size_bytes' => strlen($bytes),
            'content_type' => 'application/json',
            'encoding' => 'identity',
            'ref_count' => 1,
            'first_seen_at' => now(),
            'last_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Storage::disk('local')->put($localPath, $bytes);
        Storage::disk('s3')->put($targetPath, $bytes);
        $this->seedVerifiedRemoteCopyLocation($hash, 'local', $localPath, $bytes);
        $this->seedVerifiedRemoteCopyLocation($hash, 's3', $targetPath, $bytes);

        $this->assertSame(0, Artisan::call('storage:shrink-offload-local-copies', [
            '--dry-run' => true,
            '--disk' => 's3',
        ]));
        $dryRunOutput = Artisan::output();
        $this->assertStringContainsString('status=planned', $dryRunOutput);
        $this->assertStringContainsString('disk=s3', $dryRunOutput);
        $this->assertStringContainsString('both_candidate_count=1', $dryRunOutput);
        $this->assertStringContainsString('blocked_count=0', $dryRunOutput);
        $this->assertStringContainsString('deleted_local_files_count=0', $dryRunOutput);
        $this->assertStringContainsString('deleted_local_rows_count=0', $dryRunOutput);

        preg_match('/^plan=(.+)$/m', $dryRunOutput, $matches);
        $planPath = trim((string) ($matches[1] ?? ''));
        $this->assertFileExists($planPath);

        $this->assertSame(0, Artisan::call('storage:shrink-offload-local-copies', [
            '--execute' => true,
            '--disk' => 's3',
            '--plan' => $planPath,
        ]));
        $executeOutput = Artisan::output();
        $this->assertStringContainsString('status=executed', $executeOutput);
        $this->assertStringContainsString('both_candidate_count=1', $executeOutput);
        $this->assertStringContainsString('deleted_local_files_count=1', $executeOutput);
        $this->assertStringContainsString('deleted_local_rows_count=1', $executeOutput);
        $this->assertStringContainsString('run_path=', $executeOutput);

        $this->assertFalse(Storage::disk('local')->exists($localPath));
        Storage::disk('s3')->assertExists($targetPath);
        $this->assertDatabaseMissing('storage_blob_locations', [
            'blob_hash' => $hash,
            'disk' => 'local',
            'storage_path' => $localPath,
            'location_kind' => 'remote_copy',
        ]);
        $this->assertDatabaseHas('storage_blob_locations', [
            'blob_hash' => $hash,
            'disk' => 's3',
            'storage_path' => $targetPath,
            'location_kind' => 'remote_copy',
        ]);

        $this->assertSame(0, Artisan::call('storage:shrink-offload-local-copies', [
            '--dry-run' => true,
            '--disk' => 's3',
            '--json' => true,
        ]));
        $jsonPayload = json_decode(Artisan::output(), true);
        $this->assertIsArray($jsonPayload);
        $this->assertSame('storage_shrink_offload_local_copies_plan.v1', $jsonPayload['schema'] ?? null);
        $this->assertSame('planned', $jsonPayload['status'] ?? null);
    }

    private function seedVerifiedRemoteCopyLocation(string $hash, string $disk, string $storagePath, string $bytes): void
    {
        \DB::table('storage_blob_locations')->insert([
            'blob_hash' => $hash,
            'disk' => $disk,
            'storage_path' => $storagePath,
            'location_kind' => 'remote_copy',
            'size_bytes' => strlen($bytes),
            'checksum' => 'sha256:'.hash('sha256', $bytes),
            'etag' => null,
            'storage_class' => $disk === 's3' ? 'STANDARD_IA' : null,
            'verified_at' => now(),
            'meta_json' => json_encode([
                'driver' => $disk,
                'source_kind' => 'seeded_test_fixture',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
