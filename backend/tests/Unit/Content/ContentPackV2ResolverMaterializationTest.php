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
        $manifestPayload = $this->manifestPayload(str_repeat('a', 64));
        $manifestHash = hash('sha256', $manifestPayload);
        $storagePath = 'private/packs_v2/BIG5_OCEAN/v1/'.$releaseId;
        $sourceCompiledDir = storage_path('app/'.$storagePath.'/compiled');

        $this->insertRelease($releaseId, $manifestHash, $storagePath, manifestJson: $manifestPayload);
        $this->activateRelease($releaseId);
        $this->writeCompiledTree($sourceCompiledDir, [
            'manifest.json' => $manifestPayload,
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
        $manifestPayload = $this->manifestPayload(str_repeat('b', 64));
        $manifestHash = hash('sha256', $manifestPayload);
        $storagePath = 'private/packs_v2/BIG5_OCEAN/v1/'.$releaseId;
        $sourceCompiledDir = storage_path('app/'.$storagePath.'/compiled');

        $this->insertRelease($releaseId, $manifestHash, $storagePath, manifestJson: $manifestPayload);
        $this->activateRelease($releaseId);
        $this->writeCompiledTree($sourceCompiledDir, [
            'manifest.json' => $manifestPayload,
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
        $manifestPayload = $this->manifestPayload(str_repeat('c', 64));
        $manifestHash = hash('sha256', $manifestPayload);
        $primaryStoragePath = 'private/packs_v2/BIG5_OCEAN/v1/'.$releaseId;
        $mirrorStoragePath = 'content_packs_v2/BIG5_OCEAN/v1/'.$releaseId;
        $mirrorCompiledDir = storage_path('app/'.$mirrorStoragePath.'/compiled');

        $this->insertRelease($releaseId, $manifestHash, $primaryStoragePath, manifestJson: $manifestPayload);
        $this->writeCompiledTree($mirrorCompiledDir, [
            'manifest.json' => $manifestPayload,
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
        $manifestPayload = $this->manifestPayload(str_repeat('d', 64));
        $manifestHash = hash('sha256', $manifestPayload);
        $storagePath = 'private/packs_v2/BIG5_OCEAN/v1/source-tree-1';
        $sourceCompiledDir = storage_path('app/'.$storagePath.'/compiled');
        $storageIdentity = hash('sha256', $storagePath);
        $expectedMaterializedDir = storage_path('app/private/packs_v2_materialized/BIG5_OCEAN/v1/'.$storageIdentity.'/'.$manifestHash.'/compiled');

        $this->insertRelease($releaseId, $manifestHash, $storagePath, createdAt: now()->subMinute(), manifestJson: $manifestPayload);
        $this->insertRelease($historyRowId, $manifestHash, $storagePath, action: 'packs2_rollback', createdAt: now(), manifestJson: $manifestPayload);
        $this->writeCompiledTree($sourceCompiledDir, [
            'manifest.json' => $manifestPayload,
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

    public function test_invalid_new_version_keeps_and_serves_versioned_last_known_good(): void
    {
        config()->set('storage_rollout.resolver_materialization_enabled', true);

        $goodReleaseId = (string) Str::uuid();
        $goodManifestPayload = $this->manifestPayload(str_repeat('e', 64));
        $goodHash = hash('sha256', $goodManifestPayload);
        $goodStoragePath = 'private/packs_v2/BIG5_OCEAN/v1/'.$goodReleaseId;
        $this->insertRelease($goodReleaseId, $goodHash, $goodStoragePath, createdAt: now()->subMinute(), manifestJson: $goodManifestPayload);
        $this->activateRelease($goodReleaseId);
        $this->writeCompiledTree(storage_path('app/'.$goodStoragePath.'/compiled'), [
            'manifest.json' => $goodManifestPayload,
            'questions.compiled.json' => '{"source":"lkg"}',
        ]);

        /** @var ContentPackV2Resolver $resolver */
        $resolver = app(ContentPackV2Resolver::class);
        $lastKnownGood = $resolver->resolveActiveCompiledPath('BIG5_OCEAN', 'v1');
        $this->assertNotNull($lastKnownGood);

        $badReleaseId = (string) Str::uuid();
        $badManifestPayload = $this->manifestPayload(str_repeat('f', 64));
        $badHash = hash('sha256', $badManifestPayload);
        $badStoragePath = 'private/packs_v2/BIG5_OCEAN/v1/'.$badReleaseId;
        $this->insertRelease($badReleaseId, $badHash, $badStoragePath, createdAt: now(), manifestJson: $badManifestPayload);
        $this->activateRelease($badReleaseId);
        $this->writeCompiledTree(storage_path('app/'.$badStoragePath.'/compiled'), [
            'manifest.json' => $badManifestPayload,
            'questions.compiled.json' => '{invalid-json',
        ]);

        $resolved = $resolver->resolveActiveCompiledPath('BIG5_OCEAN', 'v1');

        $this->assertSame($lastKnownGood, $resolved);
        $this->assertSame('{"source":"lkg"}', (string) File::get($resolved.'/questions.compiled.json'));
        $this->assertDirectoryDoesNotExist(dirname($this->expectedMaterializedDir($badStoragePath, $badHash)));
    }

    private function expectedMaterializedDir(string $storagePath, string $manifestHash): string
    {
        return storage_path('app/private/packs_v2_materialized/BIG5_OCEAN/v1/'.hash('sha256', $storagePath).'/'.$manifestHash.'/compiled');
    }

    private function insertRelease(
        string $releaseId,
        string $manifestHash,
        string $storagePath,
        string $action = 'packs2_publish',
        mixed $createdAt = null,
        ?string $manifestJson = null,
    ): void {
        $createdAt ??= now();
        $manifestJson ??= $this->manifestPayload($manifestHash);
        $manifest = json_decode($manifestJson, true, 512, JSON_THROW_ON_ERROR);

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
            'compiled_hash' => (string) ($manifest['compiled_hash'] ?? ''),
            'content_hash' => null,
            'norms_version' => null,
            'git_sha' => null,
            'pack_version' => 'v1',
            'manifest_json' => $manifestJson,
            'storage_path' => $storagePath,
            'source_commit' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function manifestPayload(string $compiledHash): string
    {
        return json_encode(
            ['compiled_hash' => $compiledHash],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
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
