<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Models\StorageBlob;
use App\Services\Content\Publisher\ContentPackV2Publisher;
use App\Services\Storage\BlobCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class Packs2MetadataDualWriteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<string>
     */
    private array $publishedStoragePaths = [];

    protected function tearDown(): void
    {
        foreach (array_values(array_unique($this->publishedStoragePaths)) as $storagePath) {
            if ($storagePath !== '') {
                File::deleteDirectory(storage_path('app/'.$storagePath));
            }
        }

        parent::tearDown();
    }

    public function test_flags_disabled_publish_compiled_skips_rollout_metadata(): void
    {
        $this->artisan('content:compile --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);

        /** @var ContentPackV2Publisher $publisher */
        $publisher = app(ContentPackV2Publisher::class);
        $release = $publisher->publishCompiled('BIG5_OCEAN', 'v1', [
            'created_by' => 'test',
        ]);

        $releaseId = (string) ($release['id'] ?? '');
        $this->rememberPublishedPaths('BIG5_OCEAN', 'v1', $releaseId);

        $primaryPath = 'private/packs_v2/BIG5_OCEAN/v1/'.$releaseId;
        $mirrorPath = 'content_packs_v2/BIG5_OCEAN/v1/'.$releaseId;

        $this->assertSame($primaryPath, (string) ($release['storage_path'] ?? ''));
        $this->assertFileExists(storage_path('app/'.$primaryPath.'/compiled/manifest.json'));
        $this->assertFileExists(storage_path('app/'.$mirrorPath.'/compiled/manifest.json'));

        $this->assertDatabaseCount('storage_blobs', 0);
        $this->assertDatabaseCount('content_release_manifests', 0);
        $this->assertDatabaseCount('content_release_manifest_files', 0);
        $this->assertDirectoryDoesNotExist(storage_path('app/private/blobs'));
    }

    public function test_flags_enabled_publish_compiled_dual_writes_blob_and_manifest_metadata(): void
    {
        config()->set('storage_rollout.content_pack_v2_dual_write_enabled', true);
        config()->set('storage_rollout.blob_catalog_enabled', true);
        config()->set('storage_rollout.manifest_catalog_enabled', true);

        $this->artisan('content:compile --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);

        /** @var ContentPackV2Publisher $publisher */
        $publisher = app(ContentPackV2Publisher::class);

        $firstRelease = $publisher->publishCompiled('BIG5_OCEAN', 'v1', [
            'created_by' => 'test',
        ]);
        $firstReleaseId = (string) ($firstRelease['id'] ?? '');
        $firstPrimaryPath = (string) ($firstRelease['storage_path'] ?? '');
        $this->rememberPublishedPaths('BIG5_OCEAN', 'v1', $firstReleaseId);

        $catalogedFiles = $this->catalogedFilesForRoot(storage_path('app/'.$firstPrimaryPath));
        $this->assertNotEmpty($catalogedFiles);

        $manifestFile = collect($catalogedFiles)->firstWhere('logical_path', 'compiled/manifest.json');
        $this->assertIsArray($manifestFile);

        $this->assertDatabaseCount('content_release_manifests', 1);
        $this->assertDatabaseCount('content_release_manifest_files', count($catalogedFiles));
        $this->assertDatabaseCount('content_release_snapshots', 0);
        $this->assertDatabaseHas('content_release_manifests', [
            'manifest_hash' => (string) ($firstRelease['manifest_hash'] ?? ''),
            'content_pack_release_id' => $firstReleaseId,
            'storage_disk' => 'local',
            'storage_path' => $firstPrimaryPath,
            'pack_id' => 'BIG5_OCEAN',
            'pack_version' => 'v1',
        ]);
        $this->assertDatabaseHas('content_release_manifest_files', [
            'logical_path' => 'compiled/manifest.json',
            'blob_hash' => (string) ($manifestFile['hash'] ?? ''),
            'size_bytes' => (int) ($manifestFile['size_bytes'] ?? 0),
            'role' => 'manifest',
            'content_type' => 'application/json',
            'encoding' => 'identity',
            'checksum' => 'sha256:'.(string) ($manifestFile['hash'] ?? ''),
        ]);
        $this->assertDatabaseHas('storage_blobs', [
            'hash' => (string) ($manifestFile['hash'] ?? ''),
            'disk' => 'local',
            'storage_path' => 'blobs/sha256/'.substr((string) ($manifestFile['hash'] ?? ''), 0, 2).'/'.(string) ($manifestFile['hash'] ?? ''),
            'content_type' => 'application/json',
            'encoding' => 'identity',
            'ref_count' => 0,
        ]);

        $blobCountAfterFirstPublish = (int) DB::table('storage_blobs')->count();

        $secondRelease = $publisher->publishCompiled('BIG5_OCEAN', 'v1', [
            'created_by' => 'test',
        ]);
        $secondReleaseId = (string) ($secondRelease['id'] ?? '');
        $secondPrimaryPath = (string) ($secondRelease['storage_path'] ?? '');
        $this->rememberPublishedPaths('BIG5_OCEAN', 'v1', $secondReleaseId);

        $this->assertDatabaseCount('content_release_manifests', 1);
        $this->assertDatabaseCount('content_release_manifest_files', count($catalogedFiles));
        $this->assertSame($blobCountAfterFirstPublish, (int) DB::table('storage_blobs')->count());
        $this->assertDatabaseHas('content_release_manifests', [
            'manifest_hash' => (string) ($secondRelease['manifest_hash'] ?? ''),
            'content_pack_release_id' => $firstReleaseId,
            'storage_path' => $firstPrimaryPath,
        ]);
        $this->assertFileExists(storage_path('app/'.$secondPrimaryPath.'/compiled/manifest.json'));
        $this->assertFileExists(storage_path('app/content_packs_v2/BIG5_OCEAN/v1/'.$secondReleaseId.'/compiled/manifest.json'));
        $this->assertDirectoryDoesNotExist(storage_path('app/private/blobs'));
    }

    public function test_manifest_catalog_is_skipped_when_any_blob_catalog_write_fails(): void
    {
        config()->set('storage_rollout.content_pack_v2_dual_write_enabled', true);
        config()->set('storage_rollout.blob_catalog_enabled', true);
        config()->set('storage_rollout.manifest_catalog_enabled', true);

        $this->artisan('content:compile --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);

        $this->app->instance(BlobCatalogService::class, new class extends BlobCatalogService
        {
            private int $calls = 0;

            public function upsertBlob(array $payload): StorageBlob
            {
                $this->calls++;
                if ($this->calls === 1) {
                    throw new \RuntimeException('SIMULATED_BLOB_CATALOG_FAILURE');
                }

                return parent::upsertBlob($payload);
            }
        });

        /** @var ContentPackV2Publisher $publisher */
        $publisher = app(ContentPackV2Publisher::class);
        $release = $publisher->publishCompiled('BIG5_OCEAN', 'v1', [
            'created_by' => 'test',
        ]);

        $releaseId = (string) ($release['id'] ?? '');
        $this->rememberPublishedPaths('BIG5_OCEAN', 'v1', $releaseId);

        $this->assertFileExists(storage_path('app/private/packs_v2/BIG5_OCEAN/v1/'.$releaseId.'/compiled/manifest.json'));
        $this->assertFileExists(storage_path('app/content_packs_v2/BIG5_OCEAN/v1/'.$releaseId.'/compiled/manifest.json'));
        $this->assertDatabaseCount('content_release_manifests', 0);
        $this->assertDatabaseCount('content_release_manifest_files', 0);
        $this->assertGreaterThan(0, (int) DB::table('storage_blobs')->count());
        $this->assertDirectoryDoesNotExist(storage_path('app/private/blobs'));
    }

    private function rememberPublishedPaths(string $packId, string $packVersion, string $releaseId): void
    {
        if ($releaseId === '') {
            return;
        }

        $this->publishedStoragePaths[] = 'private/packs_v2/'.$packId.'/'.$packVersion.'/'.$releaseId;
        $this->publishedStoragePaths[] = 'content_packs_v2/'.$packId.'/'.$packVersion.'/'.$releaseId;
    }

    /**
     * @return list<array{logical_path:string,hash:string,size_bytes:int}>
     */
    private function catalogedFilesForRoot(string $root): array
    {
        $compiledDir = $root.'/compiled';
        $files = [];
        foreach (File::allFiles($compiledDir) as $file) {
            $absolutePath = $file->getPathname();
            $logicalPath = ltrim(str_replace('\\', '/', substr($absolutePath, strlen(rtrim($root, '/\\')))), '/');
            $bytes = (string) File::get($absolutePath);
            $files[] = [
                'logical_path' => $logicalPath,
                'hash' => hash('sha256', $bytes),
                'size_bytes' => strlen($bytes),
            ];
        }

        usort($files, static fn (array $a, array $b): int => strcmp($a['logical_path'], $b['logical_path']));

        return $files;
    }
}
