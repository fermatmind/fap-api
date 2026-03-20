<?php

declare(strict_types=1);

namespace Tests\Unit\Content;

use App\Services\Content\ContentPackV2Resolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ContentPackV2ResolverMaterializationTest extends TestCase
{
    use RefreshDatabase;

    private string $isolatedStoragePath;

    private string $originalStoragePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalStoragePath = $this->app->storagePath();
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-packs2-materialization-'.Str::uuid();
        File::ensureDirectoryExists($this->isolatedStoragePath);
        $this->app->useStoragePath($this->isolatedStoragePath);
    }

    protected function tearDown(): void
    {
        $this->app->useStoragePath($this->originalStoragePath);

        if (is_dir($this->isolatedStoragePath)) {
            File::deleteDirectory($this->isolatedStoragePath);
        }

        parent::tearDown();
    }

    public function test_flags_disabled_resolver_returns_today_source_path_without_materializing(): void
    {
        $releaseId = (string) Str::uuid();
        $manifestHash = str_repeat('a', 64);
        $storagePath = 'private/packs_v2/BIG5_OCEAN/v1/'.$releaseId;
        $sourceCompiledDir = storage_path('app/'.$storagePath.'/compiled');

        $this->insertRelease($releaseId, $manifestHash, $storagePath);
        $this->activateRelease($releaseId);
        $this->writeCompiledTree($sourceCompiledDir, [
            'manifest.json' => json_encode(['compiled_hash' => $manifestHash], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'questions.compiled.json' => '{"source":"primary"}',
        ]);

        /** @var ContentPackV2Resolver $resolver */
        $resolver = app(ContentPackV2Resolver::class);
        $resolved = $resolver->resolveActiveCompiledPath('BIG5_OCEAN', 'v1');

        $this->assertSame($sourceCompiledDir, $resolved);
        $this->assertDirectoryDoesNotExist(storage_path('app/private/packs_v2_materialized'));
    }

    public function test_flags_enabled_resolver_materializes_from_primary_and_reuses_fresh_target(): void
    {
        config()->set('storage_rollout.resolver_materialization_enabled', true);

        $releaseId = (string) Str::uuid();
        $manifestHash = str_repeat('b', 64);
        $storagePath = 'private/packs_v2/BIG5_OCEAN/v1/'.$releaseId;
        $sourceCompiledDir = storage_path('app/'.$storagePath.'/compiled');

        $this->insertRelease($releaseId, $manifestHash, $storagePath);
        $this->activateRelease($releaseId);
        $this->writeCompiledTree($sourceCompiledDir, [
            'manifest.json' => json_encode(['compiled_hash' => $manifestHash], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'questions.compiled.json' => '{"source":"primary"}',
        ]);

        $storageIdentity = hash('sha256', $storagePath);
        $expectedMaterializedDir = storage_path('app/private/packs_v2_materialized/BIG5_OCEAN/v1/'.$storageIdentity.'/'.$manifestHash.'/compiled');

        /** @var ContentPackV2Resolver $resolver */
        $resolver = app(ContentPackV2Resolver::class);
        $firstResolved = $resolver->resolveActiveCompiledPath('BIG5_OCEAN', 'v1');

        $this->assertSame($expectedMaterializedDir, $firstResolved);
        $this->assertFileExists($expectedMaterializedDir.'/manifest.json');
        $this->assertSame('{"source":"primary"}', (string) File::get($expectedMaterializedDir.'/questions.compiled.json'));
        $this->assertDirectoryDoesNotExist(storage_path('app/private/blobs'));

        $targetRoot = dirname($expectedMaterializedDir);
        File::put($targetRoot.'/marker.txt', 'keep-me');

        $secondResolved = $resolver->resolveActiveCompiledPath('BIG5_OCEAN', 'v1');

        $this->assertSame($expectedMaterializedDir, $secondResolved);
        $this->assertFileExists($targetRoot.'/marker.txt');
    }

    public function test_flags_enabled_materializes_from_mirror_and_replaces_stale_target_when_sentinel_mismatches(): void
    {
        config()->set('storage_rollout.resolver_materialization_enabled', true);

        $releaseId = (string) Str::uuid();
        $manifestHash = str_repeat('c', 64);
        $primaryStoragePath = 'private/packs_v2/BIG5_OCEAN/v1/'.$releaseId;
        $mirrorStoragePath = 'content_packs_v2/BIG5_OCEAN/v1/'.$releaseId;
        $mirrorCompiledDir = storage_path('app/'.$mirrorStoragePath.'/compiled');

        $this->insertRelease($releaseId, $manifestHash, $primaryStoragePath);
        $this->writeCompiledTree($mirrorCompiledDir, [
            'manifest.json' => json_encode(['compiled_hash' => $manifestHash], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'questions.compiled.json' => '{"source":"mirror"}',
        ]);

        $storageIdentity = hash('sha256', $primaryStoragePath);
        $targetRoot = storage_path('app/private/packs_v2_materialized/BIG5_OCEAN/v1/'.$storageIdentity.'/'.$manifestHash);
        $staleCompiledDir = $targetRoot.'/compiled';
        $this->writeCompiledTree($staleCompiledDir, [
            'manifest.json' => '{"compiled_hash":"stale"}',
            'questions.compiled.json' => '{"source":"stale"}',
        ]);
        File::put($targetRoot.'/.materialization.json', json_encode([
            'release_id' => 'stale-release',
            'manifest_hash' => 'stale-hash',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        /** @var ContentPackV2Resolver $resolver */
        $resolver = app(ContentPackV2Resolver::class);
        $resolved = $resolver->resolveCompiledPathByManifestHash('BIG5_OCEAN', 'v1', $manifestHash);

        $this->assertSame($staleCompiledDir, $resolved);
        $this->assertSame('{"source":"mirror"}', (string) File::get($staleCompiledDir.'/questions.compiled.json'));
        $this->assertStringContainsString($releaseId, (string) File::get($targetRoot.'/.materialization.json'));
        $this->assertDirectoryDoesNotExist(storage_path('app/private/blobs'));
    }

    public function test_flags_enabled_reuses_materialized_target_for_latest_history_row_with_same_storage_path_and_manifest_hash(): void
    {
        config()->set('storage_rollout.resolver_materialization_enabled', true);

        $releaseId = (string) Str::uuid();
        $historyRowId = (string) Str::uuid();
        $manifestHash = str_repeat('d', 64);
        $storagePath = 'private/packs_v2/BIG5_OCEAN/v1/source-tree-1';
        $sourceCompiledDir = storage_path('app/'.$storagePath.'/compiled');
        $storageIdentity = hash('sha256', $storagePath);
        $expectedMaterializedDir = storage_path('app/private/packs_v2_materialized/BIG5_OCEAN/v1/'.$storageIdentity.'/'.$manifestHash.'/compiled');

        $this->insertRelease($releaseId, $manifestHash, $storagePath, createdAt: now()->subMinute());
        $this->insertRelease($historyRowId, $manifestHash, $storagePath, action: 'packs2_rollback', createdAt: now());
        $this->writeCompiledTree($sourceCompiledDir, [
            'manifest.json' => json_encode(['compiled_hash' => $manifestHash], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'questions.compiled.json' => '{"source":"shared-tree"}',
        ]);

        /** @var ContentPackV2Resolver $resolver */
        $resolver = app(ContentPackV2Resolver::class);
        $resolved = $resolver->resolveCompiledPathByManifestHash('BIG5_OCEAN', 'v1', $manifestHash);

        $this->assertSame($expectedMaterializedDir, $resolved);
        $this->assertSame('{"source":"shared-tree"}', (string) File::get($expectedMaterializedDir.'/questions.compiled.json'));

        $sentinel = json_decode((string) File::get(dirname($expectedMaterializedDir).'/.materialization.json'), true);
        $this->assertIsArray($sentinel);
        $this->assertSame($storagePath, (string) ($sentinel['storage_path'] ?? ''));
        $this->assertSame($historyRowId, (string) ($sentinel['release_id'] ?? ''));
    }

    private function insertRelease(
        string $releaseId,
        string $manifestHash,
        string $storagePath,
        string $action = 'packs2_publish',
        mixed $createdAt = null,
    ): void {
        $createdAt ??= now();

        DB::table('content_pack_releases')->insert([
            'id' => $releaseId,
            'action' => $action,
            'region' => 'GLOBAL',
            'locale' => 'global',
            'dir_alias' => 'v1',
            'from_version_id' => null,
            'to_version_id' => null,
            'from_pack_id' => null,
            'to_pack_id' => 'BIG5_OCEAN',
            'status' => 'success',
            'message' => 'test',
            'created_by' => 'test',
            'manifest_hash' => $manifestHash,
            'compiled_hash' => $manifestHash,
            'content_hash' => null,
            'norms_version' => null,
            'git_sha' => null,
            'pack_version' => 'v1',
            'manifest_json' => json_encode(['compiled_hash' => $manifestHash], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'storage_path' => $storagePath,
            'source_commit' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function activateRelease(string $releaseId): void
    {
        DB::table('content_pack_activations')->updateOrInsert(
            [
                'pack_id' => 'BIG5_OCEAN',
                'pack_version' => 'v1',
            ],
            [
                'release_id' => $releaseId,
                'activated_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    /**
     * @param  array<string,string>  $files
     */
    private function writeCompiledTree(string $compiledDir, array $files): void
    {
        File::ensureDirectoryExists($compiledDir);

        foreach ($files as $relativePath => $contents) {
            $absolutePath = $compiledDir.'/'.ltrim($relativePath, '/');
            File::ensureDirectoryExists(dirname($absolutePath));
            File::put($absolutePath, $contents);
        }
    }
}
