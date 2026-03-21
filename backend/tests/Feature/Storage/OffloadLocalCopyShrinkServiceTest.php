<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Services\Storage\ExactReleaseFileSetCatalogService;
use App\Services\Storage\ExactReleaseRehydrateService;
use App\Services\Storage\OffloadLocalCopyShrinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OffloadLocalCopyShrinkServiceTest extends TestCase
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
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-offload-local-copy-shrink-service-'.Str::uuid();
        File::ensureDirectoryExists($this->isolatedStoragePath.'/app/private');
        $this->app->useStoragePath($this->isolatedStoragePath);
        config()->set('filesystems.disks.local.root', $this->isolatedStoragePath.'/app/private');
        config()->set('storage_rollout.blob_offload_prefix', 'rollout/blobs');
        config()->set('storage_rollout.blob_offload_disk', 's3');
        config()->set('filesystems.disks.s3.bucket', 'offload-shrink-test-bucket');
        config()->set('filesystems.disks.s3.region', 'ap-guangzhou');
        config()->set('filesystems.disks.s3.endpoint', 'https://cos.offload-shrink.test');
        Storage::forgetDisk('local');
        Storage::fake('s3');
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

    public function test_service_dry_run_and_execute_only_delete_both_local_side(): void
    {
        $localOnly = $this->seedBlob('local-only', withLocal: true, withTarget: false);
        $both = $this->seedBlob('both', withLocal: true, withTarget: true);
        $targetOnly = $this->seedBlob('target-only', withLocal: false, withTarget: true);

        /** @var OffloadLocalCopyShrinkService $service */
        $service = app(OffloadLocalCopyShrinkService::class);

        $plan = $service->buildPlan('s3');
        $this->assertSame('storage_shrink_offload_local_copies_plan.v1', (string) ($plan['schema'] ?? ''));
        $this->assertSame(1, data_get($plan, 'summary.both_candidate_count'));
        $this->assertSame(1, data_get($plan, 'summary.blocked_count'));
        $this->assertSame(1, data_get($plan, 'summary.local_only_count'));
        $this->assertSame(1, data_get($plan, 'summary.target_only_count'));
        $this->assertSame(1, data_get($plan, 'summary.both_count'));
        $this->assertSame([$both['hash']], array_column((array) ($plan['candidates'] ?? []), 'blob_hash'));
        $this->assertSame('LOCAL_ONLY_TARGET_VERIFIED_ROW_MISSING', data_get($plan, 'blocked.0.reason'));

        $plan['_meta'] = ['plan_path' => storage_path('app/private/offload_local_copy_shrink_plans/test-plan.json')];
        $result = $service->executePlan($plan);

        $this->assertSame('executed', $result['status']);
        $this->assertSame(1, data_get($result, 'summary.deleted_local_files_count'));
        $this->assertSame(1, data_get($result, 'summary.deleted_local_rows_count'));
        $this->assertSame(0, data_get($result, 'summary.blocked_count'));
        $this->assertFileExists((string) ($result['run_path'] ?? ''));

        Storage::disk('s3')->assertExists($both['target_path']);
        $this->assertFalse(Storage::disk('local')->exists($both['local_path']));
        $this->assertTrue(Storage::disk('local')->exists($localOnly['local_path']));

        $this->assertDatabaseMissing('storage_blob_locations', [
            'blob_hash' => $both['hash'],
            'disk' => 'local',
            'storage_path' => $both['local_path'],
            'location_kind' => 'remote_copy',
        ]);
        $this->assertDatabaseHas('storage_blob_locations', [
            'blob_hash' => $both['hash'],
            'disk' => 's3',
            'storage_path' => $both['target_path'],
            'location_kind' => 'remote_copy',
        ]);
        $this->assertDatabaseHas('storage_blob_locations', [
            'blob_hash' => $localOnly['hash'],
            'disk' => 'local',
            'storage_path' => $localOnly['local_path'],
            'location_kind' => 'remote_copy',
        ]);
        $this->assertDatabaseHas('storage_blob_locations', [
            'blob_hash' => $targetOnly['hash'],
            'disk' => 's3',
            'storage_path' => $targetOnly['target_path'],
            'location_kind' => 'remote_copy',
        ]);

        $this->assertDirectoryExists(storage_path('app/private/offload'));
        $this->assertDirectoryExists(storage_path('app/private/offload/blobs'));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'storage_shrink_offload_local_copies',
            'target_id' => 'offload_local_copies',
            'result' => 'success',
        ]);
    }

    public function test_service_blocks_missing_local_file_and_target_only_rows(): void
    {
        $missingLocalFile = $this->seedBlob('missing-local-file', withLocal: true, withTarget: true, createLocalFile: false);
        $this->seedBlob('target-only', withLocal: false, withTarget: true);

        /** @var OffloadLocalCopyShrinkService $service */
        $service = app(OffloadLocalCopyShrinkService::class);

        $plan = $service->buildPlan('s3');
        $this->assertSame(0, data_get($plan, 'summary.both_candidate_count'));
        $this->assertSame(1, data_get($plan, 'summary.blocked_count'));
        $this->assertSame(1, data_get($plan, 'summary.target_only_count'));
        $this->assertSame(1, data_get($plan, 'summary.both_count'));
        $this->assertSame([], array_column((array) ($plan['candidates'] ?? []), 'blob_hash'));
        $this->assertSame($missingLocalFile['hash'], data_get($plan, 'blocked.0.blob_hash'));
        $this->assertSame('LOCAL_FILE_MISSING', data_get($plan, 'blocked.0.reason'));
    }

    public function test_service_cleanup_keeps_exact_rehydrate_working_via_target_verified_row(): void
    {
        $fixture = $this->seedExactRehydrateFixture('rehydrate-after-shrink');

        /** @var OffloadLocalCopyShrinkService $service */
        $service = app(OffloadLocalCopyShrinkService::class);
        $plan = $service->buildPlan('s3');
        $this->assertSame(1, data_get($plan, 'summary.both_candidate_count'));

        $plan['_meta'] = ['plan_path' => storage_path('app/private/offload_local_copy_shrink_plans/rehydrate-plan.json')];
        $result = $service->executePlan($plan);
        $this->assertSame('executed', $result['status']);

        /** @var ExactReleaseRehydrateService $rehydrate */
        $rehydrate = app(ExactReleaseRehydrateService::class);
        $rehydratePlan = $rehydrate->buildPlan($fixture['exact_manifest_id'], null, 's3', storage_path('app/private/rehydrate_runs'));
        $this->assertSame(0, (int) data_get($rehydratePlan, 'summary.missing_locations'));
        $rehydrateResult = $rehydrate->executePlan($rehydratePlan);

        $this->assertSame(1, (int) ($rehydrateResult['verified_files'] ?? 0));
        $this->assertFalse(Storage::disk('local')->exists($fixture['local_path']));
        $this->assertDatabaseMissing('storage_blob_locations', [
            'blob_hash' => $fixture['hash'],
            'disk' => 'local',
            'storage_path' => $fixture['local_path'],
            'location_kind' => 'remote_copy',
        ]);
        $this->assertDatabaseHas('storage_blob_locations', [
            'blob_hash' => $fixture['hash'],
            'disk' => 's3',
            'storage_path' => $fixture['target_path'],
            'location_kind' => 'remote_copy',
        ]);
    }

    /**
     * @return array{hash:string,bytes:string,local_path:string,target_path:string}
     */
    private function seedBlob(string $suffix, bool $withLocal, bool $withTarget, bool $createLocalFile = true): array
    {
        $payload = json_encode(['suffix' => $suffix], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $bytes = is_string($payload) ? $payload : '{}';
        $hash = hash('sha256', $bytes);

        DB::table('storage_blobs')->updateOrInsert(
            ['hash' => $hash],
            [
                'disk' => 'local',
                'storage_path' => 'blobs/sha256/'.substr($hash, 0, 2).'/'.$hash,
                'size_bytes' => strlen($bytes),
                'content_type' => 'application/json',
                'encoding' => 'identity',
                'ref_count' => 1,
                'first_seen_at' => now(),
                'last_verified_at' => now(),
            ]
        );

        $localPath = 'offload/blobs/sha256/'.substr($hash, 0, 2).'/'.$hash;
        $targetPath = 'rollout/blobs/sha256/'.substr($hash, 0, 2).'/'.$hash;

        if ($withLocal) {
            if ($createLocalFile) {
                Storage::disk('local')->put($localPath, $bytes);
            }
            $this->seedVerifiedRemoteCopyLocation($hash, 'local', $localPath, $bytes, 'local');
        }

        if ($withTarget) {
            Storage::disk('s3')->put($targetPath, $bytes);
            $this->seedVerifiedRemoteCopyLocation($hash, 's3', $targetPath, $bytes, 's3');
        }

        return [
            'hash' => $hash,
            'bytes' => $bytes,
            'local_path' => $localPath,
            'target_path' => $targetPath,
        ];
    }

    /**
     * @return array{exact_manifest_id:int,hash:string,local_path:string,target_path:string}
     */
    private function seedExactRehydrateFixture(string $suffix): array
    {
        $releaseId = (string) Str::uuid();
        DB::table('content_pack_releases')->insert([
            'id' => $releaseId,
            'action' => 'publish',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'dir_alias' => 'OFFLOAD-SHRINK',
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
            'manifest_hash' => str_repeat('a', 64),
            'compiled_hash' => str_repeat('b', 64),
            'content_hash' => str_repeat('c', 64),
            'norms_version' => '2026Q1',
            'git_sha' => 'git-'.$suffix,
            'pack_version' => 'v1',
            'manifest_json' => null,
            'storage_path' => null,
            'source_commit' => 'git-'.$suffix,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = json_encode(['suffix' => $suffix, 'kind' => 'manifest'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $bytes = is_string($payload) ? $payload : '{}';
        $hash = hash('sha256', $bytes);
        $localPath = 'offload/blobs/sha256/'.substr($hash, 0, 2).'/'.$hash;
        $targetPath = 'rollout/blobs/sha256/'.substr($hash, 0, 2).'/'.$hash;

        DB::table('storage_blobs')->insert([
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

        $catalogService = app(ExactReleaseFileSetCatalogService::class);
        $manifest = $catalogService->upsertExactManifest([
            'content_pack_release_id' => $releaseId,
            'schema_version' => (string) config('storage_rollout.exact_manifest_schema_version', 'storage_exact_manifest.v1'),
            'source_kind' => 'legacy.source_pack',
            'source_disk' => 'local',
            'source_storage_path' => storage_path('app/private/content_releases/'.$suffix.'/source_pack'),
            'manifest_hash' => $hash,
            'pack_id' => 'BIG5_OCEAN',
            'pack_version' => 'v1',
            'compiled_hash' => hash('sha256', 'compiled|'.$suffix),
            'content_hash' => hash('sha256', 'content|'.$suffix),
            'norms_version' => '2026Q1',
            'source_commit' => 'git-'.$suffix,
            'payload_json' => ['suffix' => $suffix],
            'sealed_at' => now(),
            'last_verified_at' => now(),
        ], [[
            'logical_path' => 'compiled/manifest.json',
            'blob_hash' => $hash,
            'size_bytes' => strlen($bytes),
            'role' => 'manifest',
            'content_type' => 'application/json',
            'encoding' => 'identity',
            'checksum' => 'sha256:'.$hash,
        ]]);

        Storage::disk('local')->put($localPath, $bytes);
        $this->seedVerifiedRemoteCopyLocation($hash, 'local', $localPath, $bytes, 'local');
        Storage::disk('s3')->put($targetPath, $bytes);
        $this->seedVerifiedRemoteCopyLocation($hash, 's3', $targetPath, $bytes, 's3');

        return [
            'exact_manifest_id' => (int) $manifest->getKey(),
            'hash' => $hash,
            'local_path' => $localPath,
            'target_path' => $targetPath,
        ];
    }

    private function seedVerifiedRemoteCopyLocation(string $hash, string $disk, string $storagePath, string $bytes, string $driver): void
    {
        DB::table('storage_blob_locations')->insert([
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
                'driver' => $driver,
                'source_kind' => 'seeded_test_fixture',
                'verified_checksum_sha256' => hash('sha256', $bytes),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
