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

final class StorageBackfillExactReleaseFileSetsCommandTest extends TestCase
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
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-storage-exact-backfill-'.Str::uuid();
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

    public function test_command_backfills_exact_release_file_sets_for_legacy_backup_and_v2_roots_idempotently(): void
    {
        $publishVersionId = (string) Str::uuid();
        $publishReleaseId = (string) Str::uuid();
        $rollbackSourceReleaseId = (string) Str::uuid();
        $rollbackReleaseId = (string) Str::uuid();
        $rollbackCurrentReleaseId = (string) Str::uuid();
        $v2ReleaseId = (string) Str::uuid();
        $missingReleaseId = (string) Str::uuid();

        $publishRoot = storage_path('app/private/content_releases/'.$publishVersionId.'/source_pack');
        $publishManifest = $this->createCompiledTree($publishRoot, 'BIG5_OCEAN', 'v1', 'legacy_publish');

        DB::table('content_pack_versions')->insert([
            'id' => $publishVersionId,
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'pack_id' => 'BIG5_OCEAN',
            'content_package_version' => 'v1',
            'dir_version_alias' => 'BIG5-OCEAN-EXACT',
            'source_type' => 'repo',
            'source_ref' => 'backend/content_packs/BIG5_OCEAN/v1',
            'sha256' => str_repeat('a', 64),
            'manifest_json' => json_encode($publishManifest['decoded'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'extracted_rel_path' => 'private/content_releases/'.$publishVersionId.'/source_pack',
            'created_by' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('content_pack_releases')->insert([
            'id' => $publishReleaseId,
            'action' => 'publish',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'dir_alias' => 'BIG5-OCEAN-EXACT',
            'from_version_id' => null,
            'to_version_id' => $publishVersionId,
            'from_pack_id' => null,
            'to_pack_id' => 'BIG5_OCEAN',
            'status' => 'success',
            'message' => '',
            'created_by' => 'test',
            'probe_ok' => false,
            'probe_json' => null,
            'probe_run_at' => null,
            'manifest_hash' => $publishManifest['manifest_hash'],
            'compiled_hash' => $publishManifest['compiled_hash'],
            'content_hash' => $publishManifest['content_hash'],
            'norms_version' => $publishManifest['norms_version'],
            'git_sha' => 'git-publish-sha',
            'pack_version' => null,
            'manifest_json' => null,
            'storage_path' => null,
            'source_commit' => null,
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);

        $rollbackRoot = storage_path('app/private/content_releases/backups/'.$rollbackSourceReleaseId.'/previous_pack');
        $rollbackManifest = $this->createCompiledTree($rollbackRoot, 'BIG5_OCEAN', 'v1', 'legacy_rollback');

        DB::table('content_pack_releases')->insert([
            'id' => $rollbackReleaseId,
            'action' => 'rollback',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'dir_alias' => 'BIG5-OCEAN-EXACT',
            'from_version_id' => $publishVersionId,
            'to_version_id' => null,
            'from_pack_id' => 'BIG5_OCEAN',
            'to_pack_id' => 'BIG5_OCEAN',
            'status' => 'success',
            'message' => '',
            'created_by' => 'test',
            'probe_ok' => false,
            'probe_json' => null,
            'probe_run_at' => null,
            'manifest_hash' => $rollbackManifest['manifest_hash'],
            'compiled_hash' => $rollbackManifest['compiled_hash'],
            'content_hash' => $rollbackManifest['content_hash'],
            'norms_version' => $rollbackManifest['norms_version'],
            'git_sha' => 'git-rollback-sha',
            'pack_version' => null,
            'manifest_json' => null,
            'storage_path' => null,
            'source_commit' => null,
            'created_at' => now()->subMinutes(4),
            'updated_at' => now()->subMinutes(4),
        ]);

        DB::table('audit_logs')->insert([
            'org_id' => 0,
            'actor_admin_id' => null,
            'action' => 'big5_pack_rollback',
            'target_type' => 'content_pack_release',
            'target_id' => $rollbackReleaseId,
            'meta_json' => json_encode(['source_release_id' => $rollbackSourceReleaseId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => null,
            'user_agent' => 'test',
            'request_id' => null,
            'reason' => 'test',
            'result' => 'success',
            'created_at' => now()->subMinutes(4),
        ]);

        $rollbackCurrentRoot = storage_path('app/private/content_releases/backups/'.$rollbackCurrentReleaseId.'/current_pack');
        $rollbackCurrentManifest = $this->createCompiledTree($rollbackCurrentRoot, 'BIG5_OCEAN', 'v1', 'legacy_current_pack');

        DB::table('content_pack_releases')->insert([
            'id' => $rollbackCurrentReleaseId,
            'action' => 'rollback',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'dir_alias' => 'BIG5-OCEAN-EXACT',
            'from_version_id' => $publishVersionId,
            'to_version_id' => null,
            'from_pack_id' => 'BIG5_OCEAN',
            'to_pack_id' => 'BIG5_OCEAN',
            'status' => 'success',
            'message' => '',
            'created_by' => 'test',
            'probe_ok' => false,
            'probe_json' => null,
            'probe_run_at' => null,
            'manifest_hash' => null,
            'compiled_hash' => null,
            'content_hash' => null,
            'norms_version' => null,
            'git_sha' => 'git-rollback-current-sha',
            'pack_version' => null,
            'manifest_json' => null,
            'storage_path' => null,
            'source_commit' => null,
            'created_at' => now()->subMinutes(3)->subSeconds(30),
            'updated_at' => now()->subMinutes(3)->subSeconds(30),
        ]);

        $v2MirrorRoot = storage_path('app/content_packs_v2/SDS_20/v1/'.$v2ReleaseId);
        $v2Manifest = $this->createCompiledTree($v2MirrorRoot, 'SDS_20', 'v1', 'v2_mirror');

        DB::table('content_pack_releases')->insert([
            'id' => $v2ReleaseId,
            'action' => 'packs2_publish',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'dir_alias' => 'SDS-20-EXACT',
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
            'git_sha' => 'git-v2-sha',
            'pack_version' => null,
            'manifest_json' => null,
            'storage_path' => 'private/packs_v2/SDS_20/v1/'.$v2ReleaseId,
            'source_commit' => null,
            'created_at' => now()->subMinutes(3),
            'updated_at' => now()->subMinutes(3),
        ]);

        DB::table('content_pack_releases')->insert([
            'id' => $missingReleaseId,
            'action' => 'publish',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'dir_alias' => 'MISSING-EXACT',
            'from_version_id' => null,
            'to_version_id' => (string) Str::uuid(),
            'from_pack_id' => null,
            'to_pack_id' => 'BIG5_OCEAN',
            'status' => 'success',
            'message' => '',
            'created_by' => 'test',
            'probe_ok' => false,
            'probe_json' => null,
            'probe_run_at' => null,
            'manifest_hash' => str_repeat('f', 64),
            'compiled_hash' => str_repeat('e', 64),
            'content_hash' => str_repeat('d', 64),
            'norms_version' => '2026Q1',
            'git_sha' => 'git-missing-sha',
            'pack_version' => null,
            'manifest_json' => null,
            'storage_path' => null,
            'source_commit' => null,
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinutes(2),
        ]);

        $this->assertSame(0, Artisan::call('storage:backfill-exact-release-file-sets', [
            '--dry-run' => true,
        ]));
        $dryRunOutput = Artisan::output();
        $this->assertStringContainsString('status=planned', $dryRunOutput);
        $this->assertStringContainsString('release_rows_backfillable=3', $dryRunOutput);
        $this->assertStringContainsString('backup_roots_backfillable=1', $dryRunOutput);
        $this->assertDatabaseCount('content_release_exact_manifests', 0);
        $this->assertDatabaseCount('content_release_exact_manifest_files', 0);

        $this->assertSame(0, Artisan::call('storage:backfill-exact-release-file-sets', [
            '--execute' => true,
        ]));
        $executeOutput = Artisan::output();
        $this->assertStringContainsString('status=executed', $executeOutput);
        $this->assertStringContainsString('release_rows_backfilled=3', $executeOutput);
        $this->assertStringContainsString('backup_roots_backfilled=1', $executeOutput);

        $this->assertDatabaseCount('content_release_exact_manifests', 4);
        $this->assertDatabaseCount('content_release_exact_manifest_files', 8);
        $this->assertDirectoryDoesNotExist(storage_path('app/private/blobs'));
        $this->assertDirectoryDoesNotExist(storage_path('app/private/offload'));

        $this->assertDatabaseHas('content_release_exact_manifests', [
            'content_pack_release_id' => $publishReleaseId,
            'source_kind' => 'legacy.source_pack',
            'source_storage_path' => $publishRoot,
            'manifest_hash' => $publishManifest['manifest_hash'],
            'pack_id' => 'BIG5_OCEAN',
            'pack_version' => 'v1',
            'file_count' => 2,
        ]);
        $this->assertDatabaseHas('content_release_exact_manifests', [
            'content_pack_release_id' => $rollbackReleaseId,
            'source_kind' => 'legacy.previous_pack',
            'source_storage_path' => $rollbackRoot,
            'manifest_hash' => $rollbackManifest['manifest_hash'],
        ]);
        $this->assertDatabaseHas('content_release_exact_manifests', [
            'content_pack_release_id' => $rollbackCurrentReleaseId,
            'source_kind' => 'legacy.current_pack',
            'source_storage_path' => $rollbackCurrentRoot,
            'manifest_hash' => $rollbackCurrentManifest['manifest_hash'],
        ]);
        $this->assertDatabaseHas('content_release_exact_manifests', [
            'content_pack_release_id' => $v2ReleaseId,
            'source_kind' => 'v2.mirror',
            'source_storage_path' => $v2MirrorRoot,
            'manifest_hash' => $v2Manifest['manifest_hash'],
            'pack_id' => 'SDS_20',
            'pack_version' => 'v1',
        ]);

        $manifestCount = (int) DB::table('content_release_exact_manifests')->count();
        $manifestFileCount = (int) DB::table('content_release_exact_manifest_files')->count();
        $this->assertSame(0, Artisan::call('storage:backfill-exact-release-file-sets', [
            '--execute' => true,
        ]));
        $this->assertSame($manifestCount, (int) DB::table('content_release_exact_manifests')->count());
        $this->assertSame($manifestFileCount, (int) DB::table('content_release_exact_manifest_files')->count());

        $currentExactManifestId = (int) DB::table('content_release_exact_manifests')
            ->where('content_pack_release_id', $rollbackCurrentReleaseId)
            ->value('id');
        $this->assertSame(1, (int) DB::table('content_release_exact_manifest_files')
            ->where('content_release_exact_manifest_id', $currentExactManifestId)
            ->where('logical_path', 'compiled/manifest.json')
            ->count());
        $this->assertSame(1, (int) DB::table('content_release_exact_manifest_files')
            ->where('content_release_exact_manifest_id', $currentExactManifestId)
            ->where('logical_path', 'compiled/payload.compiled.json')
            ->count());

        $audit = DB::table('audit_logs')
            ->where('action', 'storage_backfill_exact_release_file_sets')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($audit);
        $auditMeta = json_decode((string) ($audit->meta_json ?? '{}'), true);
        $this->assertIsArray($auditMeta);
        $this->assertSame('execute', (string) ($auditMeta['mode'] ?? ''));
        $this->assertSame(5, (int) ($auditMeta['release_rows_scanned'] ?? 0));
        $this->assertSame(1, (int) ($auditMeta['backup_roots_backfilled'] ?? 0));
        $this->assertSame(2, (int) ($auditMeta['missing_physical_sources'] ?? 0));
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
            'schema' => 'storage.exact.test.v1',
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
}
