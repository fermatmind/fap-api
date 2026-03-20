<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\Content\ContentPackV2Resolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class LegacyContentPackBridgeMetadataTest extends TestCase
{
    use RefreshDatabase;

    private const PUBLISH_ALIAS = 'BIG5-OCEAN-LEGACY-BRIDGE-PUBLISH';

    private const ROLLBACK_ALIAS = 'BIG5-OCEAN-LEGACY-BRIDGE-ROLLBACK';

    protected function tearDown(): void
    {
        foreach ([self::PUBLISH_ALIAS, self::ROLLBACK_ALIAS] as $alias) {
            $target = $this->targetDir($alias);
            if (File::isDirectory($target)) {
                File::deleteDirectory($target);
            }

            $releaseIds = DB::table('content_pack_releases')
                ->where('dir_alias', $alias)
                ->pluck('id');
            foreach ($releaseIds as $releaseId) {
                File::deleteDirectory(storage_path('app/private/content_releases/backups/'.(string) $releaseId));
            }

            $versionIds = DB::table('content_pack_versions')
                ->where('dir_version_alias', $alias)
                ->pluck('id');
            foreach ($versionIds as $versionId) {
                File::deleteDirectory(storage_path('app/private/content_releases/'.(string) $versionId));
            }
        }

        parent::tearDown();
    }

    public function test_dual_mode_publish_bridges_release_fields_and_rollout_metadata(): void
    {
        config()->set('scale_identity.content_publish_mode', 'dual');
        config()->set('storage_rollout.blob_catalog_enabled', true);
        config()->set('storage_rollout.manifest_catalog_enabled', true);

        $target = $this->targetDir(self::PUBLISH_ALIAS);
        if (File::isDirectory($target)) {
            File::deleteDirectory($target);
        }

        $this->artisan(sprintf(
            'packs:publish --scale=BIG5_OCEAN --pack=BIG5_OCEAN --pack-version=v1 --region=CN_MAINLAND --locale=zh-CN --dir_alias=%s --probe=0',
            self::PUBLISH_ALIAS
        ))->assertExitCode(0);

        $release = DB::table('content_pack_releases')
            ->where('action', 'publish')
            ->where('dir_alias', self::PUBLISH_ALIAS)
            ->orderByDesc('created_at')
            ->first();

        $this->assertNotNull($release);
        $this->assertSame('success', (string) ($release->status ?? ''));
        $expectedSourcePath = storage_path('app/private/content_releases/'.(string) ($release->to_version_id ?? '').'/source_pack');
        $this->assertSame('v1', (string) ($release->pack_version ?? ''));
        $this->assertSame($expectedSourcePath, (string) ($release->storage_path ?? ''));
        $this->assertSame((string) ($release->git_sha ?? ''), (string) ($release->source_commit ?? ''));

        $releaseManifest = json_decode((string) ($release->manifest_json ?? '{}'), true);
        $this->assertIsArray($releaseManifest);
        $this->assertSame('BIG5_OCEAN', (string) ($releaseManifest['pack_id'] ?? ''));
        $this->assertSame('v1', (string) ($releaseManifest['content_package_version'] ?? ''));

        $manifest = DB::table('content_release_manifests')
            ->where('manifest_hash', (string) ($release->manifest_hash ?? ''))
            ->first();
        $this->assertNotNull($manifest);
        $this->assertSame((string) $release->id, (string) ($manifest->content_pack_release_id ?? ''));
        $this->assertSame('local', (string) ($manifest->storage_disk ?? ''));
        $this->assertSame($expectedSourcePath, (string) ($manifest->storage_path ?? ''));
        $this->assertSame('BIG5_OCEAN', (string) ($manifest->pack_id ?? ''));
        $this->assertSame('v1', (string) ($manifest->pack_version ?? ''));

        $manifestFile = DB::table('content_release_manifest_files')
            ->where('content_release_manifest_id', (int) $manifest->id)
            ->where('logical_path', 'compiled/manifest.json')
            ->first();
        $this->assertNotNull($manifestFile);
        $this->assertSame('manifest', (string) ($manifestFile->role ?? ''));
        $this->assertSame('application/json', (string) ($manifestFile->content_type ?? ''));

        $blob = DB::table('storage_blobs')
            ->where('hash', (string) ($manifestFile->blob_hash ?? ''))
            ->first();
        $this->assertNotNull($blob);
        $this->assertSame('local', (string) ($blob->disk ?? ''));
        $this->assertStringStartsWith('blobs/sha256/', (string) ($blob->storage_path ?? ''));
        $this->assertDirectoryDoesNotExist(storage_path('app/private/blobs'));

        $audit = DB::table('audit_logs')
            ->where('action', 'big5_pack_publish')
            ->where('target_id', (string) $release->id)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($audit);
        $auditMeta = json_decode((string) ($audit->meta_json ?? '{}'), true);
        $this->assertIsArray($auditMeta);
        $this->assertSame('dual', (string) ($auditMeta['content_publish_mode'] ?? ''));
        $this->assertSame('BIG5_OCEAN', (string) ($auditMeta['to_pack_id'] ?? ''));

        $this->assertDatabaseHas('content_pack_activations', [
            'pack_id' => 'BIG5_OCEAN',
            'pack_version' => 'v1',
            'release_id' => (string) $release->id,
        ]);

        /** @var ContentPackV2Resolver $resolver */
        $resolver = app(ContentPackV2Resolver::class);
        $this->assertSame($expectedSourcePath.'/compiled', $resolver->resolveActiveCompiledPath('BIG5_OCEAN', 'v1'));
    }

    public function test_dual_mode_rollback_keeps_backup_selection_and_bridges_release_metadata(): void
    {
        config()->set('scale_identity.content_publish_mode', 'dual');
        config()->set('storage_rollout.blob_catalog_enabled', true);
        config()->set('storage_rollout.manifest_catalog_enabled', true);

        $target = $this->targetDir(self::ROLLBACK_ALIAS);
        if (File::isDirectory($target)) {
            File::deleteDirectory($target);
        }

        $publishCommand = sprintf(
            'packs:publish --scale=BIG5_OCEAN --pack=BIG5_OCEAN --pack-version=v1 --region=CN_MAINLAND --locale=zh-CN --dir_alias=%s --probe=0',
            self::ROLLBACK_ALIAS
        );
        $this->artisan($publishCommand)->assertExitCode(0);
        $this->artisan($publishCommand)->assertExitCode(0);

        $targetReleaseId = '';
        $sourceBackupPath = '';
        $publishRows = DB::table('content_pack_releases')
            ->where('action', 'publish')
            ->where('status', 'success')
            ->where('dir_alias', self::ROLLBACK_ALIAS)
            ->orderByDesc('created_at')
            ->orderByDesc('updated_at')
            ->get();
        foreach ($publishRows as $row) {
            $backupPath = storage_path('app/private/content_releases/backups/'.(string) $row->id.'/previous_pack');
            if (! File::isDirectory($backupPath)) {
                continue;
            }
            $targetReleaseId = (string) $row->id;
            $sourceBackupPath = $backupPath;
            break;
        }

        $this->assertNotSame('', $targetReleaseId);
        $this->assertTrue(File::isDirectory($sourceBackupPath));
        $initialPublish = DB::table('content_pack_releases')
            ->where('action', 'publish')
            ->where('status', 'success')
            ->where('dir_alias', self::ROLLBACK_ALIAS)
            ->orderBy('created_at')
            ->orderBy('updated_at')
            ->first();
        $this->assertNotNull($initialPublish);
        $initialManifestStoragePath = storage_path('app/private/content_releases/'.(string) ($initialPublish->to_version_id ?? '').'/source_pack');

        $this->artisan(sprintf(
            'packs:rollback --scale=BIG5_OCEAN --region=CN_MAINLAND --locale=zh-CN --dir_alias=%s --to_release_id=%s --probe=0',
            self::ROLLBACK_ALIAS,
            $targetReleaseId
        ))->assertExitCode(0);

        $release = DB::table('content_pack_releases')
            ->where('action', 'rollback')
            ->where('dir_alias', self::ROLLBACK_ALIAS)
            ->orderByDesc('created_at')
            ->first();

        $this->assertNotNull($release);
        $this->assertSame('success', (string) ($release->status ?? ''));
        $this->assertSame('v1', (string) ($release->pack_version ?? ''));
        $this->assertSame($sourceBackupPath, (string) ($release->storage_path ?? ''));
        $this->assertSame((string) ($release->git_sha ?? ''), (string) ($release->source_commit ?? ''));

        $releaseManifest = json_decode((string) ($release->manifest_json ?? '{}'), true);
        $this->assertIsArray($releaseManifest);
        $this->assertSame('BIG5_OCEAN', (string) ($releaseManifest['pack_id'] ?? ''));
        $this->assertSame('v1', (string) ($releaseManifest['content_package_version'] ?? ''));

        $manifest = DB::table('content_release_manifests')
            ->where('manifest_hash', (string) ($release->manifest_hash ?? ''))
            ->first();
        $this->assertNotNull($manifest);
        $this->assertSame($initialManifestStoragePath, (string) ($manifest->storage_path ?? ''));
        $this->assertGreaterThan(0, (int) DB::table('content_release_manifest_files')->where('content_release_manifest_id', (int) $manifest->id)->count());
        $this->assertGreaterThan(0, (int) DB::table('storage_blobs')->count());
        $this->assertDirectoryDoesNotExist(storage_path('app/private/blobs'));

        $rollbackBackupPath = storage_path('app/private/content_releases/backups/'.(string) $release->id.'/current_pack');
        $this->assertTrue(File::isDirectory($rollbackBackupPath));
        $this->assertTrue(File::isDirectory($sourceBackupPath));

        $audit = DB::table('audit_logs')
            ->where('action', 'big5_pack_rollback')
            ->where('target_id', (string) $release->id)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($audit);
        $auditMeta = json_decode((string) ($audit->meta_json ?? '{}'), true);
        $this->assertIsArray($auditMeta);
        $this->assertSame($targetReleaseId, (string) ($auditMeta['source_release_id'] ?? ''));
        $this->assertSame(self::ROLLBACK_ALIAS, (string) ($auditMeta['dir_alias'] ?? ''));

        $this->assertDatabaseHas('content_pack_activations', [
            'pack_id' => 'BIG5_OCEAN',
            'pack_version' => 'v1',
            'release_id' => (string) $release->id,
        ]);

        /** @var ContentPackV2Resolver $resolver */
        $resolver = app(ContentPackV2Resolver::class);
        $this->assertSame($sourceBackupPath.'/compiled', $resolver->resolveActiveCompiledPath('BIG5_OCEAN', 'v1'));
    }

    private function targetDir(string $dirAlias): string
    {
        return base_path('../content_packages/default/CN_MAINLAND/zh-CN/'.$dirAlias);
    }
}
