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
        $this->assertStringContainsString('surface=coverage_convergence_backfill', $dryRunOutput);
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
        $this->assertSame('s3', (string) ($auditMeta['target_disk'] ?? ''));
        $this->assertTrue((bool) ($auditMeta['copy_only'] ?? false));
    }

    public function test_blob_offload_exposes_overlap_summary_and_keeps_local_verified_copy_after_backfill(): void
    {
        $blobCatalog = app(BlobCatalogService::class);

        $localOnly = $this->createArtifactBlob($blobCatalog, 'attempt-local-only', '{"kind":"local_only"}');
        $both = $this->createArtifactBlob($blobCatalog, 'attempt-both', '{"kind":"both"}');
        $targetOnly = $this->createArtifactBlob($blobCatalog, 'attempt-target-only', '{"kind":"target_only"}');

        $localOnlyOffloadPath = $this->localOffloadPathForHash($localOnly['hash']);
        Storage::disk('local')->put($localOnlyOffloadPath, $localOnly['bytes']);
        $this->seedVerifiedRemoteCopyLocation($localOnly['hash'], 'local', $localOnlyOffloadPath, $localOnly['bytes'], 'local');

        $bothLocalOffloadPath = $this->localOffloadPathForHash($both['hash']);
        Storage::disk('local')->put($bothLocalOffloadPath, $both['bytes']);
        $this->seedVerifiedRemoteCopyLocation($both['hash'], 'local', $bothLocalOffloadPath, $both['bytes'], 'local');

        $bothRemotePath = $this->remotePathForHash($both['hash']);
        Storage::disk('s3')->put($bothRemotePath, $both['bytes']);
        $this->seedVerifiedRemoteCopyLocation($both['hash'], 's3', $bothRemotePath, $both['bytes'], 's3');

        $targetOnlyRemotePath = $this->remotePathForHash($targetOnly['hash']);
        Storage::disk('s3')->put($targetOnlyRemotePath, $targetOnly['bytes']);
        $this->seedVerifiedRemoteCopyLocation($targetOnly['hash'], 's3', $targetOnlyRemotePath, $targetOnly['bytes'], 's3');

        $this->assertSame(0, Artisan::call('storage:blob-offload', [
            '--dry-run' => true,
            '--disk' => 's3',
        ]));

        $dryRunOutput = Artisan::output();
        $this->assertStringContainsString('status=planned', $dryRunOutput);
        $this->assertStringContainsString('surface=coverage_convergence_backfill', $dryRunOutput);
        $this->assertStringContainsString('target_disk=s3', $dryRunOutput);
        $this->assertStringContainsString('reachable_blob_count=3', $dryRunOutput);
        $this->assertStringContainsString('verified_remote_copy_counts_by_disk=local:2,s3:2', $dryRunOutput);
        $this->assertStringContainsString('local_only_count=1', $dryRunOutput);
        $this->assertStringContainsString('target_only_count=1', $dryRunOutput);
        $this->assertStringContainsString('both_count=1', $dryRunOutput);
        $this->assertStringContainsString('candidate_count=1', $dryRunOutput);
        $this->assertStringContainsString('skipped_count=2', $dryRunOutput);

        $plan = json_decode((string) file_get_contents($this->extractPlanPath($dryRunOutput)), true);
        $this->assertIsArray($plan);
        $summary = (array) ($plan['summary'] ?? []);
        $this->assertSame('s3', (string) ($summary['target_disk'] ?? ''));
        $this->assertSame(3, (int) ($summary['reachable_blob_count'] ?? -1));
        $this->assertSame(['local' => 2, 's3' => 2], $summary['verified_remote_copy_counts_by_disk'] ?? null);
        $this->assertSame(1, (int) ($summary['local_only_count'] ?? -1));
        $this->assertSame(1, (int) ($summary['target_only_count'] ?? -1));
        $this->assertSame(1, (int) ($summary['both_count'] ?? -1));
        $this->assertSame([$localOnly['hash']], array_column((array) ($plan['candidates'] ?? []), 'blob_hash'));

        $this->assertSame(0, Artisan::call('storage:blob-offload', [
            '--execute' => true,
            '--disk' => 's3',
        ]));

        $executeOutput = Artisan::output();
        $this->assertStringContainsString('status=executed', $executeOutput);
        $this->assertStringContainsString('surface=coverage_convergence_backfill', $executeOutput);
        $this->assertStringContainsString('candidate_count=1', $executeOutput);
        $this->assertStringContainsString('uploaded_count=1', $executeOutput);
        $this->assertStringContainsString('verified_count=1', $executeOutput);
        $this->assertStringContainsString('failed_count=0', $executeOutput);

        Storage::disk('s3')->assertExists($this->remotePathForHash($localOnly['hash']));
        $this->assertTrue(Storage::disk('local')->exists($localOnlyOffloadPath));
        $this->assertTrue(Storage::disk('local')->exists($bothLocalOffloadPath));

        $this->assertDatabaseHas('storage_blob_locations', [
            'blob_hash' => $localOnly['hash'],
            'disk' => 'local',
            'storage_path' => $localOnlyOffloadPath,
            'location_kind' => 'remote_copy',
        ]);
        $this->assertDatabaseHas('storage_blob_locations', [
            'blob_hash' => $localOnly['hash'],
            'disk' => 's3',
            'storage_path' => $this->remotePathForHash($localOnly['hash']),
            'location_kind' => 'remote_copy',
        ]);
        $this->assertDatabaseHas('storage_blob_locations', [
            'blob_hash' => $both['hash'],
            'disk' => 'local',
            'storage_path' => $bothLocalOffloadPath,
            'location_kind' => 'remote_copy',
        ]);
        $this->assertDatabaseHas('storage_blob_locations', [
            'blob_hash' => $both['hash'],
            'disk' => 's3',
            'storage_path' => $bothRemotePath,
            'location_kind' => 'remote_copy',
        ]);
        $this->assertDatabaseHas('storage_blob_locations', [
            'blob_hash' => $targetOnly['hash'],
            'disk' => 's3',
            'storage_path' => $targetOnlyRemotePath,
            'location_kind' => 'remote_copy',
        ]);

        $audit = DB::table('audit_logs')
            ->where('action', 'storage_blob_offload')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($audit);
        $auditMeta = json_decode((string) ($audit->meta_json ?? '{}'), true);
        $this->assertIsArray($auditMeta);
        $this->assertSame(3, (int) ($auditMeta['reachable_blob_count'] ?? -1));
        $this->assertSame(1, (int) ($auditMeta['local_only_count'] ?? -1));
        $this->assertSame(1, (int) ($auditMeta['target_only_count'] ?? -1));
        $this->assertSame(1, (int) ($auditMeta['both_count'] ?? -1));
        $this->assertSame(['local' => 2, 's3' => 2], $auditMeta['verified_remote_copy_counts_by_disk'] ?? null);
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

    /**
     * @return array{hash:string,bytes:string}
     */
    private function createArtifactBlob(BlobCatalogService $blobCatalog, string $attemptId, string $payload): array
    {
        $relativePath = 'artifacts/reports/MBTI/'.$attemptId.'/report.json';
        Storage::disk('local')->put($relativePath, $payload);

        $bytes = (string) Storage::disk('local')->get($relativePath);
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

        return [
            'hash' => $hash,
            'bytes' => $bytes,
        ];
    }

    private function localOffloadPathForHash(string $hash): string
    {
        return 'offload/blobs/sha256/'.substr($hash, 0, 2).'/'.$hash;
    }

    private function seedVerifiedRemoteCopyLocation(string $hash, string $disk, string $storagePath, string $bytes, string $driver): void
    {
        StorageBlobLocation::query()->create([
            'blob_hash' => $hash,
            'disk' => $disk,
            'storage_path' => $storagePath,
            'location_kind' => 'remote_copy',
            'size_bytes' => strlen($bytes),
            'checksum' => 'sha256:'.hash('sha256', $bytes),
            'etag' => null,
            'storage_class' => $disk === 's3' ? 'STANDARD_IA' : null,
            'verified_at' => now(),
            'meta_json' => [
                'driver' => $driver,
                'source_kind' => 'seeded_test_fixture',
                'verified_checksum_sha256' => hash('sha256', $bytes),
            ],
        ]);
    }

    private function remotePathForHash(string $hash): string
    {
        return 'rollout/blobs/sha256/'.substr($hash, 0, 2).'/'.$hash;
    }
}
