<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Services\Storage\BlobCatalogService;
use App\Services\Storage\ContentReleaseManifestCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Tests\TestCase;

final class StorageBlobGcCommandTest extends TestCase
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

        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-storage-blob-gc-'.Str::uuid();
        $this->isolatedPacksRoot = sys_get_temp_dir().'/fap-packs-root-'.Str::uuid();
        File::ensureDirectoryExists($this->isolatedStoragePath.'/app/private');
        File::ensureDirectoryExists($this->isolatedPacksRoot);

        $this->app->useStoragePath($this->isolatedStoragePath);
        config()->set('filesystems.disks.local.root', $this->isolatedStoragePath.'/app/private');
        config()->set('content_packs.root', $this->isolatedPacksRoot);
        Storage::forgetDisk('local');
    }

    protected function tearDown(): void
    {
        Storage::forgetDisk('local');
        $this->app->useStoragePath($this->originalStoragePath);
        config()->set('filesystems.disks.local.root', $this->originalLocalRoot);
        config()->set('content_packs.root', $this->originalPacksRoot);
        Storage::forgetDisk('local');

        foreach ([$this->isolatedStoragePath, $this->isolatedPacksRoot] as $path) {
            if (is_dir($path)) {
                File::deleteDirectory($path);
            }
        }

        parent::tearDown();
    }

    public function test_blob_gc_dry_run_builds_conservative_plan_without_planned_deletions(): void
    {
        $blobCatalog = app(BlobCatalogService::class);
        $manifestCatalog = app(ContentReleaseManifestCatalogService::class);

        $activeReleaseId = (string) Str::uuid();
        $snapshotReleaseId = (string) Str::uuid();
        $v2ReleaseId = (string) Str::uuid();

        $activeRoot = storage_path('app/private/content_releases/'.Str::uuid().'/source_pack');
        $activeManifest = $this->createCompiledTree($activeRoot, 'BIG5_OCEAN', 'v1', 'active');
        $this->catalogRootFiles($blobCatalog, $activeRoot);
        DB::table('content_pack_releases')->insert([
            'id' => $activeReleaseId,
            'action' => 'publish',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'dir_alias' => 'BIG5-GC-ACTIVE',
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
            'manifest_hash' => $activeManifest['manifest_hash'],
            'compiled_hash' => $activeManifest['compiled_hash'],
            'content_hash' => $activeManifest['content_hash'],
            'norms_version' => $activeManifest['norms_version'],
            'git_sha' => 'git-active',
            'pack_version' => 'v1',
            'manifest_json' => json_encode($activeManifest['decoded'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'storage_path' => $activeRoot,
            'source_commit' => 'git-active',
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);
        DB::table('content_pack_activations')->insert([
            'pack_id' => 'BIG5_OCEAN',
            'pack_version' => 'v1',
            'release_id' => $activeReleaseId,
            'activated_at' => now()->subMinutes(5),
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);

        $staleHash = hash('sha256', 'stale-file-map-hash');
        $blobCatalog->upsertBlob([
            'hash' => $staleHash,
            'disk' => 'local',
            'storage_path' => $blobCatalog->storagePathForHash($staleHash),
            'size_bytes' => strlen('stale-file-map-hash'),
            'content_type' => 'application/json',
            'encoding' => 'identity',
            'ref_count' => 0,
            'last_verified_at' => now(),
        ]);
        $manifestCatalog->upsertManifest([
            'content_pack_release_id' => $activeReleaseId,
            'manifest_hash' => $activeManifest['manifest_hash'],
            'storage_disk' => 'local',
            'storage_path' => $activeRoot,
            'pack_id' => 'BIG5_OCEAN',
            'pack_version' => 'v1',
            'compiled_hash' => $activeManifest['compiled_hash'],
            'content_hash' => $activeManifest['content_hash'],
            'norms_version' => $activeManifest['norms_version'],
            'source_commit' => 'git-active',
            'payload_json' => $activeManifest['decoded'],
        ], [
            [
                'logical_path' => 'compiled/stale.compiled.json',
                'blob_hash' => $staleHash,
                'size_bytes' => strlen('stale-file-map-hash'),
                'role' => null,
                'content_type' => 'application/json',
                'encoding' => 'identity',
                'checksum' => 'sha256:'.$staleHash,
            ],
        ]);

        $snapshotRoot = storage_path('app/private/content_releases/'.Str::uuid().'/source_pack');
        $snapshotManifest = $this->createCompiledTree($snapshotRoot, 'BIG5_OCEAN', 'v1', 'snapshot');
        $this->catalogRootFiles($blobCatalog, $snapshotRoot);
        DB::table('content_pack_releases')->insert([
            'id' => $snapshotReleaseId,
            'action' => 'publish',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'dir_alias' => 'BIG5-GC-SNAPSHOT',
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
            'manifest_hash' => $snapshotManifest['manifest_hash'],
            'compiled_hash' => $snapshotManifest['compiled_hash'],
            'content_hash' => $snapshotManifest['content_hash'],
            'norms_version' => $snapshotManifest['norms_version'],
            'git_sha' => 'git-snapshot',
            'pack_version' => 'v1',
            'manifest_json' => json_encode($snapshotManifest['decoded'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'storage_path' => $snapshotRoot,
            'source_commit' => 'git-snapshot',
            'created_at' => now()->subMinutes(4),
            'updated_at' => now()->subMinutes(4),
        ]);
        DB::table('content_release_snapshots')->insert([
            'pack_id' => 'BIG5_OCEAN',
            'pack_version' => 'v1',
            'from_content_pack_release_id' => null,
            'to_content_pack_release_id' => $snapshotReleaseId,
            'activation_before_release_id' => null,
            'activation_after_release_id' => $snapshotReleaseId,
            'reason' => 'test',
            'created_by' => 'test',
            'meta_json' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now()->subMinutes(4),
            'updated_at' => now()->subMinutes(4),
        ]);

        $backupCurrentRoot = storage_path('app/private/content_releases/backups/'.$activeReleaseId.'/current_pack');
        $backupManifest = $this->createCompiledTree($backupCurrentRoot, 'BIG5_OCEAN', 'v1', 'backup_current');
        $this->catalogRootFiles($blobCatalog, $backupCurrentRoot);

        $v2MirrorRelative = 'content_packs_v2/SDS_20/v1/'.$v2ReleaseId;
        $v2MirrorRoot = storage_path('app/'.$v2MirrorRelative);
        $v2Manifest = $this->createCompiledTree($v2MirrorRoot, 'SDS_20', 'v1', 'v2_mirror');
        $this->catalogRootFiles($blobCatalog, $v2MirrorRoot);
        DB::table('content_pack_releases')->insert([
            'id' => $v2ReleaseId,
            'action' => 'packs2_publish',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'dir_alias' => 'SDS-GC',
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
            'created_at' => now()->subMinutes(3),
            'updated_at' => now()->subMinutes(3),
        ]);

        $liveAliasRoot = $this->isolatedPacksRoot.'/default/CN_MAINLAND/zh-CN/BIG5-LIVE-GC';
        $liveAliasManifest = $this->createCompiledTree($liveAliasRoot, 'BIG5_OCEAN', 'v1', 'live_alias');
        $this->catalogRootFiles($blobCatalog, $liveAliasRoot);

        $deprecatedRoot = $this->isolatedPacksRoot.'/_deprecated/MBTI/CN_MAINLAND/BIG5-DEPRECATED-GC';
        $deprecatedManifest = $this->createCompiledTree($deprecatedRoot, 'BIG5_OCEAN', 'v1', 'deprecated_alias');
        $this->catalogRootFiles($blobCatalog, $deprecatedRoot);

        Storage::disk('local')->put('artifacts/reports/MBTI/attempt-gc/report.json', '{"artifact":"reachable"}');
        $artifactHash = hash('sha256', '{"artifact":"reachable"}');
        $blobCatalog->upsertBlob([
            'hash' => $artifactHash,
            'disk' => 'local',
            'storage_path' => $blobCatalog->storagePathForHash($artifactHash),
            'size_bytes' => strlen('{"artifact":"reachable"}'),
            'content_type' => 'application/json',
            'encoding' => 'identity',
            'ref_count' => 0,
            'last_verified_at' => now(),
        ]);

        $orphanHash = hash('sha256', 'orphan-blob');
        $blobCatalog->upsertBlob([
            'hash' => $orphanHash,
            'disk' => 'local',
            'storage_path' => $blobCatalog->storagePathForHash($orphanHash),
            'size_bytes' => strlen('orphan-blob'),
            'content_type' => 'application/json',
            'encoding' => 'identity',
            'ref_count' => 0,
            'last_verified_at' => now(),
        ]);

        $this->assertSame(0, Artisan::call('storage:blob-gc', [
            '--dry-run' => true,
        ]));
        $output = Artisan::output();
        $this->assertStringContainsString('status=planned', $output);
        $this->assertStringContainsString('planned_deletions=0', $output);
        $this->assertStringContainsString('dry_run_only=1', $output);

        preg_match('/^plan=(.+)$/m', $output, $matches);
        $this->assertNotEmpty($matches);
        $planPath = trim((string) ($matches[1] ?? ''));
        $this->assertFileExists($planPath);

        $plan = json_decode((string) file_get_contents($planPath), true);
        $this->assertIsArray($plan);
        $this->assertSame('storage_blob_gc_plan.v1', (string) ($plan['schema'] ?? ''));
        $this->assertTrue((bool) data_get($plan, 'planned_deletions.dry_run_only'));
        $this->assertSame([], data_get($plan, 'planned_deletions.blob_hashes', []));

        $this->assertContains($activeReleaseId, data_get($plan, 'roots.active_release_ids', []));
        $this->assertContains($snapshotReleaseId, data_get($plan, 'roots.snapshot_release_ids', []));
        $this->assertContains($backupCurrentRoot, data_get($plan, 'roots.release_storage_paths', []));
        $this->assertContains($v2MirrorRoot, data_get($plan, 'roots.release_storage_paths', []));
        $this->assertContains($liveAliasRoot, data_get($plan, 'roots.live_alias_paths', []));
        $this->assertNotContains($deprecatedRoot, data_get($plan, 'roots.live_alias_paths', []));
        $this->assertContains('artifacts/reports/MBTI/attempt-gc/report.json', data_get($plan, 'roots.artifact_paths', []));

        $reachable = data_get($plan, 'reachable.blob_hashes', []);
        $this->assertContains($activeManifest['manifest_hash'], $reachable);
        $this->assertContains($snapshotManifest['manifest_hash'], $reachable);
        $this->assertContains($backupManifest['manifest_hash'], $reachable);
        $this->assertContains($v2Manifest['manifest_hash'], $reachable);
        $this->assertContains($liveAliasManifest['manifest_hash'], $reachable);
        $this->assertContains($artifactHash, $reachable);
        $this->assertContains($staleHash, $reachable);
        $this->assertNotContains($deprecatedManifest['manifest_hash'], $reachable);
        $this->assertContains($orphanHash, data_get($plan, 'unreachable.blob_hashes', []));
        $this->assertContains($deprecatedManifest['manifest_hash'], data_get($plan, 'unreachable.blob_hashes', []));

        $audit = DB::table('audit_logs')
            ->where('action', 'storage_blob_gc')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($audit);
        $auditMeta = json_decode((string) ($audit->meta_json ?? '{}'), true);
        $this->assertIsArray($auditMeta);
        $this->assertSame($planPath, (string) ($auditMeta['plan'] ?? ''));
        $this->assertTrue((bool) ($auditMeta['dry_run_only'] ?? false));
    }

    public function test_blob_gc_requires_dry_run_and_does_not_support_execute(): void
    {
        $this->artisan('storage:blob-gc')->assertExitCode(1);

        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('The "--execute" option does not exist.');

        Artisan::call('storage:blob-gc', [
            '--execute' => true,
        ]);
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
            'schema' => 'storage.gc.test.v1',
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

    private function catalogRootFiles(BlobCatalogService $blobCatalogService, string $root): void
    {
        foreach (File::allFiles($root.'/compiled') as $file) {
            $bytes = (string) File::get($file->getPathname());
            $hash = hash('sha256', $bytes);
            $blobCatalogService->upsertBlob([
                'hash' => $hash,
                'disk' => 'local',
                'storage_path' => $blobCatalogService->storagePathForHash($hash),
                'size_bytes' => strlen($bytes),
                'content_type' => str_ends_with($file->getFilename(), '.json') ? 'application/json' : null,
                'encoding' => 'identity',
                'ref_count' => 0,
                'last_verified_at' => now(),
            ]);
        }
    }
}
