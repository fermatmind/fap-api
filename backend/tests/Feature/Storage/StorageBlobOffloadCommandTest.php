<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Models\StorageBlobLocation;
use App\Services\Storage\BlobCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StorageBlobOffloadCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $isolatedStoragePath;

    private string $originalStoragePath;

    private string $originalLocalRoot;

    private string $isolatedPacksRoot;

    private string $originalPacksRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalStoragePath = $this->app->storagePath();
        $this->originalLocalRoot = (string) config('filesystems.disks.local.root');
        $this->originalPacksRoot = (string) config('content_packs.root');

        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-storage-blob-offload-'.Str::uuid();
        $this->isolatedPacksRoot = sys_get_temp_dir().'/fap-packs-offload-root-'.Str::uuid();
        File::ensureDirectoryExists($this->isolatedStoragePath.'/app/private');
        File::ensureDirectoryExists($this->isolatedPacksRoot);

        $this->app->useStoragePath($this->isolatedStoragePath);
        config()->set('filesystems.disks.local.root', $this->isolatedStoragePath.'/app/private');
        config()->set('content_packs.root', $this->isolatedPacksRoot);
        config()->set('storage_rollout.blob_offload_prefix', 'rollout/blobs');
        config()->set('storage_rollout.blob_offload_storage_class', 'STANDARD_IA');
        config()->set('storage_rollout.blob_offload_disk', 's3');
        config()->set('filesystems.disks.s3.bucket', 'test-offload-bucket');
        config()->set('filesystems.disks.s3.region', 'ap-guangzhou');
        config()->set('filesystems.disks.s3.endpoint', 'https://cos.example.test');
        Storage::forgetDisk('local');
        Storage::fake('s3');
    }

    protected function tearDown(): void
    {
        Storage::forgetDisk('local');
        Storage::forgetDisk('s3');
        $this->app->useStoragePath($this->originalStoragePath);
        config()->set('filesystems.disks.local.root', $this->originalLocalRoot);
        config()->set('content_packs.root', $this->originalPacksRoot);
        Storage::forgetDisk('local');
        Storage::forgetDisk('s3');

        foreach ([$this->isolatedStoragePath, $this->isolatedPacksRoot] as $path) {
            if (is_dir($path)) {
                File::deleteDirectory($path);
            }
        }

        parent::tearDown();
    }

    public function test_blob_offload_builds_plan_and_executes_copy_only_with_source_fallback_and_idempotency(): void
    {
        $blobCatalog = app(BlobCatalogService::class);

        $releaseId = (string) Str::uuid();
        $releaseRoot = storage_path('app/private/content_releases/'.Str::uuid().'/source_pack');
        $releaseManifest = $this->createCompiledTree($releaseRoot, 'BIG5_OCEAN', 'v1', 'release_source');
        $this->catalogRootFiles($blobCatalog, $releaseRoot);
        DB::table('content_pack_releases')->insert([
            'id' => $releaseId,
            'action' => 'publish',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'dir_alias' => 'BIG5-OFFLOAD',
            'from_version_id' => null,
            'to_version_id' => null,
            'from_pack_id' => null,
            'to_pack_id' => 'BIG5_OCEAN',
            'status' => 'success',
            'message' => '',
            'created_by' => 'test',
            'probe_ok' => false,
            'probe_json' => null,
            'probe_run_at' => null,
            'manifest_hash' => $releaseManifest['manifest_hash'],
            'compiled_hash' => $releaseManifest['compiled_hash'],
            'content_hash' => $releaseManifest['content_hash'],
            'norms_version' => $releaseManifest['norms_version'],
            'git_sha' => 'git-release',
            'pack_version' => 'v1',
            'manifest_json' => json_encode($releaseManifest['decoded'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'storage_path' => $releaseRoot,
            'source_commit' => 'git-release',
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);
        DB::table('content_pack_activations')->insert([
            'pack_id' => 'BIG5_OCEAN',
            'pack_version' => 'v1',
            'release_id' => $releaseId,
            'activated_at' => now()->subMinutes(5),
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);

        $backupRoot = storage_path('app/private/content_releases/backups/'.Str::uuid().'/current_pack');
        $backupManifest = $this->createCompiledTree($backupRoot, 'BIG5_OCEAN', 'v1', 'backup_current');
        $this->catalogRootFiles($blobCatalog, $backupRoot);

        $v2ReleaseId = (string) Str::uuid();
        $v2MirrorRoot = storage_path('app/content_packs_v2/SDS_20/v1/'.$v2ReleaseId);
        $v2Manifest = $this->createCompiledTree($v2MirrorRoot, 'SDS_20', 'v1', 'v2_mirror');
        $this->catalogRootFiles($blobCatalog, $v2MirrorRoot);
        DB::table('content_pack_releases')->insert([
            'id' => $v2ReleaseId,
            'action' => 'packs2_publish',
            'region' => 'GLOBAL',
            'locale' => 'global',
            'dir_alias' => 'v1',
            'from_version_id' => null,
            'to_version_id' => null,
            'from_pack_id' => null,
            'to_pack_id' => 'SDS_20',
            'status' => 'success',
            'message' => '',
            'created_by' => 'test',
            'probe_ok' => false,
            'probe_json' => null,
            'probe_run_at' => null,
            'manifest_hash' => $v2Manifest['manifest_hash'],
            'compiled_hash' => $v2Manifest['compiled_hash'],
            'content_hash' => $v2Manifest['content_hash'],
            'norms_version' => $v2Manifest['norms_version'],
            'git_sha' => 'git-v2',
            'pack_version' => 'v1',
            'manifest_json' => json_encode($v2Manifest['decoded'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'storage_path' => 'private/packs_v2/SDS_20/v1/'.$v2ReleaseId,
            'source_commit' => 'git-v2',
            'created_at' => now()->subMinutes(4),
            'updated_at' => now()->subMinutes(4),
        ]);

        Storage::disk('local')->put('artifacts/reports/MBTI/attempt-offload/report.json', '{"artifact":"canonical"}');
        $artifactBytes = (string) Storage::disk('local')->get('artifacts/reports/MBTI/attempt-offload/report.json');
        $artifactHash = hash('sha256', $artifactBytes);
        $blobCatalog->upsertBlob([
            'hash' => $artifactHash,
            'disk' => 'local',
            'storage_path' => $blobCatalog->storagePathForHash($artifactHash),
            'size_bytes' => strlen($artifactBytes),
            'content_type' => 'application/json',
            'encoding' => 'identity',
            'ref_count' => 0,
            'last_verified_at' => now(),
        ]);

        $unreachableHash = str_repeat('f', 64);
        $blobCatalog->upsertBlob([
            'hash' => $unreachableHash,
            'disk' => 'local',
            'storage_path' => $blobCatalog->storagePathForHash($unreachableHash),
            'size_bytes' => 123,
            'content_type' => 'application/octet-stream',
            'encoding' => 'identity',
            'ref_count' => 0,
            'last_verified_at' => now(),
        ]);

        $liveAliasRoot = $this->isolatedPacksRoot.'/default/CN_MAINLAND/zh-CN/BIG5-LIVE-OFFLOAD';
        $liveAliasManifest = $this->createCompiledTree($liveAliasRoot, 'BIG5_OCEAN', 'v1', 'live_alias_only');
        $this->catalogRootFiles($blobCatalog, $liveAliasRoot);

        $this->assertSame(0, Artisan::call('storage:blob-offload', [
            '--dry-run' => true,
            '--disk' => 's3',
        ]));
        $dryRunOutput = Artisan::output();
        $this->assertStringContainsString('status=planned', $dryRunOutput);
        $this->assertStringContainsString('disk=s3', $dryRunOutput);
        $this->assertStringContainsString('candidate_count=7', $dryRunOutput);
        $this->assertStringContainsString('skipped_count=2', $dryRunOutput);

        $planPath = $this->extractPlanPath($dryRunOutput);
        $this->assertFileExists($planPath);
        $plan = json_decode((string) file_get_contents($planPath), true);
        $this->assertIsArray($plan);
        $this->assertSame('storage_blob_offload_plan.v1', (string) ($plan['schema'] ?? ''));
        $this->assertSame('s3', (string) ($plan['disk'] ?? ''));
        $this->assertCount(7, (array) ($plan['candidates'] ?? []));
        $this->assertCount(2, (array) ($plan['skipped'] ?? []));
        $this->assertContains('live_alias_only_source', array_column((array) ($plan['skipped'] ?? []), 'reason'));

        $this->assertSame(0, Artisan::call('storage:blob-offload', [
            '--execute' => true,
            '--disk' => 's3',
        ]));
        $executeOutput = Artisan::output();
        $this->assertStringContainsString('status=executed', $executeOutput);
        $this->assertStringContainsString('disk=s3', $executeOutput);
        $this->assertStringContainsString('uploaded_count=7', $executeOutput);
        $this->assertStringContainsString('failed_count=0', $executeOutput);

        $this->assertSame(7, StorageBlobLocation::query()->count());

        $releaseRemotePath = $this->remotePathForHash($releaseManifest['manifest_hash']);
        $backupRemotePath = $this->remotePathForHash($backupManifest['manifest_hash']);
        $v2RemotePath = $this->remotePathForHash($v2Manifest['manifest_hash']);
        $artifactRemotePath = $this->remotePathForHash($artifactHash);

        $this->assertTrue(Storage::disk('s3')->exists($releaseRemotePath));
        $this->assertTrue(Storage::disk('s3')->exists($backupRemotePath));
        $this->assertTrue(Storage::disk('s3')->exists($v2RemotePath));
        $this->assertTrue(Storage::disk('s3')->exists($artifactRemotePath));

        $this->assertDatabaseHas('storage_blob_locations', [
            'blob_hash' => $releaseManifest['manifest_hash'],
            'disk' => 's3',
            'storage_path' => $releaseRemotePath,
            'location_kind' => 'remote_copy',
            'storage_class' => 'STANDARD_IA',
        ]);
        $location = StorageBlobLocation::query()
            ->where('blob_hash', $v2Manifest['manifest_hash'])
            ->where('disk', 's3')
            ->where('storage_path', $v2RemotePath)
            ->first();
        $this->assertNotNull($location);
        $this->assertSame('sha256:'.$v2Manifest['manifest_hash'], (string) ($location->checksum ?? ''));
        $this->assertNotNull($location->verified_at);
        $this->assertSame('v2_mirror', data_get($location->meta_json, 'source_kind'));
        $this->assertSame('test-offload-bucket', data_get($location->meta_json, 'bucket'));
        $this->assertSame('ap-guangzhou', data_get($location->meta_json, 'region'));
        $this->assertSame('https://cos.example.test', data_get($location->meta_json, 'endpoint'));

        $artifactLocation = StorageBlobLocation::query()
            ->where('blob_hash', $artifactHash)
            ->where('disk', 's3')
            ->where('storage_path', $artifactRemotePath)
            ->first();
        $this->assertNotNull($artifactLocation);
        $this->assertSame('artifact_canonical', data_get($artifactLocation->meta_json, 'source_kind'));

        $this->assertFalse(Storage::disk('s3')->exists($this->remotePathForHash($liveAliasManifest['manifest_hash'])));
        $this->assertDatabaseMissing('storage_blob_locations', [
            'blob_hash' => $unreachableHash,
            'disk' => 's3',
        ]);
        $this->assertDirectoryDoesNotExist(storage_path('app/private/blobs'));
        $this->assertDirectoryDoesNotExist(storage_path('app/private/offload'));

        $this->assertSame(0, Artisan::call('storage:blob-offload', [
            '--execute' => true,
            '--disk' => 's3',
        ]));
        $secondExecuteOutput = Artisan::output();
        $this->assertStringContainsString('uploaded_count=0', $secondExecuteOutput);
        $this->assertStringContainsString('failed_count=0', $secondExecuteOutput);
        $this->assertSame(7, StorageBlobLocation::query()->count());

        $audit = DB::table('audit_logs')
            ->where('action', 'storage_blob_offload')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($audit);
        $auditMeta = json_decode((string) ($audit->meta_json ?? '{}'), true);
        $this->assertIsArray($auditMeta);
        $this->assertSame('executed', (string) ($auditMeta['mode'] ?? ''));
        $this->assertSame('s3', (string) ($auditMeta['disk'] ?? ''));
        $this->assertTrue((bool) ($auditMeta['copy_only'] ?? false));
    }

    public function test_blob_offload_requires_exactly_one_mode(): void
    {
        $this->artisan('storage:blob-offload')->assertExitCode(1);
        $this->artisan('storage:blob-offload --dry-run --execute')->assertExitCode(1);
    }

    public function test_blob_offload_rejects_local_target_disk(): void
    {
        config()->set('storage_rollout.blob_offload_disk', 'local');

        $this->artisan('storage:blob-offload --dry-run')
            ->expectsOutputToContain('blob offload target disk must be remote: local disks are not allowed.')
            ->assertExitCode(1);
    }

    private function extractPlanPath(string $output): string
    {
        preg_match('/^plan=(.+)$/m', $output, $matches);

        return trim((string) ($matches[1] ?? ''));
    }

    /**
     * @return array{
     *   decoded:array<string,mixed>,
     *   manifest_hash:string,
     *   compiled_hash:string,
     *   content_hash:string,
     *   norms_version:string
     * }
     */
    private function createCompiledTree(string $root, string $packId, string $packVersion, string $suffix): array
    {
        $compiledDir = $root.'/compiled';
        File::ensureDirectoryExists($compiledDir);

        $payload = json_encode([
            'suffix' => $suffix,
            'pack_id' => $packId,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $payload = is_string($payload) ? $payload : '{}';
        File::put($compiledDir.'/payload.compiled.json', $payload);

        $compiledHash = hash('sha256', $payload.'|compiled|'.$suffix);
        $contentHash = hash('sha256', $payload.'|content|'.$suffix);
        $normsVersion = '2026Q1_'.$suffix;
        $manifest = [
            'schema' => 'storage.offload.test.v1',
            'pack_id' => $packId,
            'pack_version' => $packVersion,
            'content_package_version' => $packVersion,
            'compiled_hash' => $compiledHash,
            'content_hash' => $contentHash,
            'norms_version' => $normsVersion,
        ];
        $manifestJson = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $manifestJson = is_string($manifestJson) ? $manifestJson : '{}';
        File::put($compiledDir.'/manifest.json', $manifestJson);

        return [
            'decoded' => $manifest,
            'manifest_hash' => hash('sha256', $manifestJson),
            'compiled_hash' => $compiledHash,
            'content_hash' => $contentHash,
            'norms_version' => $normsVersion,
        ];
    }

    private function catalogRootFiles(BlobCatalogService $blobCatalog, string $root): void
    {
        foreach (File::allFiles($root.'/compiled') as $file) {
            $bytes = (string) File::get($file->getPathname());
            $hash = hash('sha256', $bytes);
            $blobCatalog->upsertBlob([
                'hash' => $hash,
                'disk' => 'local',
                'storage_path' => $blobCatalog->storagePathForHash($hash),
                'size_bytes' => strlen($bytes),
                'content_type' => 'application/json',
                'encoding' => 'identity',
                'ref_count' => 0,
                'last_verified_at' => now(),
            ]);
        }
    }

    private function remotePathForHash(string $hash): string
    {
        return 'rollout/blobs/sha256/'.substr($hash, 0, 2).'/'.$hash;
    }
}
