<?php

declare(strict_types=1);

namespace Tests\Unit\Content;

use App\Services\Content\ContentPackV2Resolver;
use App\Services\Storage\ExactReleaseFileSetCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ContentPackV2ResolverRemoteRehydrateFallbackTest extends TestCase
{
    use RefreshDatabase;

    private string $isolatedStoragePath;

    private string $originalStoragePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalStoragePath = $this->app->storagePath();
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-packs2-remote-rehydrate-'.Str::uuid();
        File::ensureDirectoryExists($this->isolatedStoragePath);
        $this->app->useStoragePath($this->isolatedStoragePath);

        config()->set('storage_rollout.packs_v2_remote_rehydrate_enabled', true);
        config()->set('storage_rollout.resolver_materialization_enabled', false);
        config()->set('storage_rollout.blob_offload_disk', 's3');
        config()->set('filesystems.disks.s3.bucket', 'packs2-remote-bucket');
        config()->set('filesystems.disks.s3.region', 'ap-guangzhou');
        config()->set('filesystems.disks.s3.endpoint', 'https://cos.packs2-remote.test');
        Storage::forgetDisk('s3');
        Storage::fake('s3');
    }

    protected function tearDown(): void
    {
        Storage::forgetDisk('s3');
        $this->app->useStoragePath($this->originalStoragePath);

        if (is_dir($this->isolatedStoragePath)) {
            File::deleteDirectory($this->isolatedStoragePath);
        }

        parent::tearDown();
    }

    public function test_remote_fallback_materializes_active_release_when_primary_and_mirror_are_missing(): void
    {
        $fixture = $this->seedV2RemoteFixture('remote_active_primary', 'v2.primary');
        $expectedMaterializedDir = $this->expectedMaterializedDir($fixture['storage_path'], $fixture['manifest_hash']);

        /** @var ContentPackV2Resolver $resolver */
        $resolver = app(ContentPackV2Resolver::class);
        $resolved = $resolver->resolveActiveCompiledPath('BIG5_OCEAN', 'v1');

        $this->assertSame($expectedMaterializedDir, $resolved);
        $this->assertFileExists($expectedMaterializedDir.'/manifest.json');
        $this->assertSame('{"source":"remote_active_primary"}', (string) File::get($expectedMaterializedDir.'/questions.compiled.json'));
        $this->assertDirectoryDoesNotExist(storage_path('app/'.$fixture['storage_path']));
        $this->assertDirectoryDoesNotExist(storage_path('app/content_packs_v2/BIG5_OCEAN/v1/'.$fixture['release_id']));

        $sentinel = json_decode((string) File::get(dirname($expectedMaterializedDir).'/.materialization.json'), true);
        $this->assertIsArray($sentinel);
        $this->assertTrue((bool) ($sentinel['remote_fallback'] ?? false));
        $this->assertSame('v2.primary', (string) ($sentinel['source_kind'] ?? ''));
        $this->assertSame((int) $fixture['exact_manifest_id'], (int) ($sentinel['exact_manifest_id'] ?? 0));
        $this->assertSame((string) $fixture['exact_identity_hash'], (string) ($sentinel['exact_identity_hash'] ?? ''));
        $this->assertSame($fixture['storage_path'], (string) ($sentinel['storage_path'] ?? ''));
        $this->assertDirectoryDoesNotExist(storage_path('app/private/blobs'));
        $this->assertSame($fixture['storage_path'], (string) DB::table('content_pack_releases')->where('id', $fixture['release_id'])->value('storage_path'));
    }

    public function test_remote_fallback_materializes_historical_release_and_reuses_same_cache_bucket(): void
    {
        $sharedStoragePath = 'private/packs_v2/BIG5_OCEAN/v1/shared-history-tree';
        $olderReleaseId = (string) Str::uuid();
        $latestReleaseId = (string) Str::uuid();
        ['manifest_hash' => $manifestHash, 'files' => $files] = $this->buildRemoteFiles('remote_history_mirror');

        $this->insertRelease($olderReleaseId, $manifestHash, $sharedStoragePath, createdAt: now()->subMinute());
        $this->insertRelease($latestReleaseId, $manifestHash, $sharedStoragePath, action: 'packs2_rollback', createdAt: now());
        $this->seedExactManifest($latestReleaseId, 'v2.mirror', storage_path('app/content_packs_v2/BIG5_OCEAN/v1/'.$latestReleaseId), $manifestHash, $files);

        $expectedMaterializedDir = $this->expectedMaterializedDir($sharedStoragePath, $manifestHash);

        /** @var ContentPackV2Resolver $resolver */
        $resolver = app(ContentPackV2Resolver::class);
        $firstResolved = $resolver->resolveCompiledPathByManifestHash('BIG5_OCEAN', 'v1', $manifestHash);
        $this->assertSame($expectedMaterializedDir, $firstResolved);
        $this->assertSame('{"source":"remote_history_mirror"}', (string) File::get($expectedMaterializedDir.'/questions.compiled.json'));

        $targetRoot = dirname($expectedMaterializedDir);
        File::put($targetRoot.'/marker.txt', 'keep-me');

        $secondResolved = $resolver->resolveCompiledPathByManifestHash('BIG5_OCEAN', 'v1', $manifestHash);
        $this->assertSame($expectedMaterializedDir, $secondResolved);
        $this->assertFileExists($targetRoot.'/marker.txt');

        $sentinel = json_decode((string) File::get($targetRoot.'/.materialization.json'), true);
        $this->assertIsArray($sentinel);
        $this->assertSame('v2.mirror', (string) ($sentinel['source_kind'] ?? ''));
        $this->assertSame($latestReleaseId, (string) ($sentinel['release_id'] ?? ''));
    }

    public function test_remote_fallback_returns_null_when_verified_remote_coverage_is_missing(): void
    {
        $fixture = $this->seedV2RemoteFixture('remote_missing_coverage', 'v2.primary', createRemoteCoverage: false);

        /** @var ContentPackV2Resolver $resolver */
        $resolver = app(ContentPackV2Resolver::class);
        $resolved = $resolver->resolveActiveCompiledPath('BIG5_OCEAN', 'v1');

        $this->assertNull($resolved);
        $this->assertDirectoryDoesNotExist(dirname($this->expectedMaterializedDir($fixture['storage_path'], $fixture['manifest_hash'])));
        $this->assertDirectoryDoesNotExist(storage_path('app/private/blobs'));
    }

    public function test_remote_fallback_returns_null_when_exact_manifest_is_missing_or_ambiguous(): void
    {
        $missingFixture = $this->seedV2RemoteFixture('remote_missing_manifest', 'v2.primary', createExactManifest: false);

        /** @var ContentPackV2Resolver $resolver */
        $resolver = app(ContentPackV2Resolver::class);
        $this->assertNull($resolver->resolveActiveCompiledPath('BIG5_OCEAN', 'v1'));

        $ambiguousReleaseId = (string) Str::uuid();
        $storagePath = 'private/packs_v2/BIG5_OCEAN/v1/'.Str::uuid();
        ['manifest_hash' => $manifestHash, 'files' => $files] = $this->buildRemoteFiles('remote_ambiguous');

        $this->insertRelease($ambiguousReleaseId, $manifestHash, $storagePath, createdAt: now()->addSecond());
        $this->seedExactManifest($ambiguousReleaseId, 'v2.primary', storage_path('app/private/packs_v2/BIG5_OCEAN/v1/'.Str::uuid()), $manifestHash, $files);
        $this->seedExactManifest($ambiguousReleaseId, 'v2.mirror', storage_path('app/content_packs_v2/BIG5_OCEAN/v1/'.Str::uuid()), $manifestHash, $files);

        $this->assertNull($resolver->resolveCompiledPathByManifestHash('BIG5_OCEAN', 'v1', $manifestHash));
        $this->assertDirectoryDoesNotExist(dirname($this->expectedMaterializedDir($storagePath, $manifestHash)));
        $this->assertDirectoryDoesNotExist(dirname($this->expectedMaterializedDir($missingFixture['storage_path'], $missingFixture['manifest_hash'])));
    }

    /**
     * @return array{
     *   release_id:string,
     *   exact_manifest_id:int|null,
     *   exact_identity_hash:string|null,
     *   storage_path:string,
     *   manifest_hash:string
     * }
     */
    private function seedV2RemoteFixture(
        string $suffix,
        string $sourceKind,
        bool $createExactManifest = true,
        bool $createRemoteCoverage = true,
    ): array {
        $releaseId = (string) Str::uuid();
        $storagePath = 'private/packs_v2/BIG5_OCEAN/v1/'.$releaseId;
        ['manifest_hash' => $manifestHash, 'files' => $files] = $this->buildRemoteFiles($suffix);

        $this->insertRelease($releaseId, $manifestHash, $storagePath);
        $this->activateRelease($releaseId);

        $manifest = null;
        if ($createExactManifest) {
            $sourceRoot = $sourceKind === 'v2.primary'
                ? storage_path('app/'.$storagePath)
                : storage_path('app/content_packs_v2/BIG5_OCEAN/v1/'.$releaseId);
            $manifest = $this->seedExactManifest($releaseId, $sourceKind, $sourceRoot, $manifestHash, $files, $createRemoteCoverage);
        }

        return [
            'release_id' => $releaseId,
            'exact_manifest_id' => $manifest?->getKey(),
            'exact_identity_hash' => $manifest?->exact_identity_hash,
            'storage_path' => $storagePath,
            'manifest_hash' => $manifestHash,
        ];
    }

    /**
     * @param  array<string,string>  $files
     */
    private function seedExactManifest(
        string $releaseId,
        string $sourceKind,
        string $sourceRoot,
        string $manifestHash,
        array $files,
        bool $createRemoteCoverage = true,
    ): object {
        $manifest = app(ExactReleaseFileSetCatalogService::class)->upsertExactManifest([
            'content_pack_release_id' => $releaseId,
            'schema_version' => (string) config('storage_rollout.exact_manifest_schema_version', 'storage_exact_manifest.v1'),
            'source_kind' => $sourceKind,
            'source_disk' => 'local',
            'source_storage_path' => $sourceRoot,
            'manifest_hash' => $manifestHash,
            'pack_id' => 'BIG5_OCEAN',
            'pack_version' => 'v1',
            'compiled_hash' => $manifestHash,
            'content_hash' => hash('sha256', 'content|'.$manifestHash),
            'norms_version' => '2026Q1',
            'source_commit' => 'git-'.$manifestHash,
            'payload_json' => ['remote_fallback' => true],
            'sealed_at' => now(),
            'last_verified_at' => now(),
        ], collect($files)->map(
            fn (string $payload, string $logicalPath): array => [
                'logical_path' => $logicalPath,
                'blob_hash' => hash('sha256', $payload),
                'size_bytes' => strlen($payload),
                'role' => $logicalPath === 'compiled/manifest.json' ? 'manifest' : 'compiled',
                'content_type' => 'application/json',
                'encoding' => 'identity',
                'checksum' => 'sha256:'.hash('sha256', $payload),
            ]
        )->values()->all());

        foreach ($files as $payload) {
            $hash = hash('sha256', $payload);
            DB::table('storage_blobs')->updateOrInsert(
                ['hash' => $hash],
                [
                    'disk' => 'local',
                    'storage_path' => 'blobs/sha256/'.substr($hash, 0, 2).'/'.$hash,
                    'size_bytes' => strlen($payload),
                    'content_type' => 'application/json',
                    'encoding' => 'identity',
                    'ref_count' => 1,
                    'first_seen_at' => now(),
                    'last_verified_at' => now(),
                ]
            );

            if (! $createRemoteCoverage) {
                continue;
            }

            $remotePath = 'rollout/blobs/sha256/'.substr($hash, 0, 2).'/'.$hash;
            Storage::disk('s3')->put($remotePath, $payload);
            DB::table('storage_blob_locations')->updateOrInsert(
                [
                    'disk' => 's3',
                    'storage_path' => $remotePath,
                ],
                [
                    'blob_hash' => $hash,
                    'location_kind' => 'remote_copy',
                    'size_bytes' => strlen($payload),
                    'checksum' => 'sha256:'.$hash,
                    'etag' => 'etag-'.substr($hash, 0, 8),
                    'storage_class' => 'STANDARD_IA',
                    'verified_at' => now(),
                    'meta_json' => json_encode([
                        'bucket' => 'packs2-remote-bucket',
                        'region' => 'ap-guangzhou',
                        'endpoint' => 'https://cos.packs2-remote.test',
                        'url' => 'https://cos.packs2-remote.test/'.$remotePath,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        return $manifest;
    }

    /**
     * @return array<string,string>
     */
    /**
     * @return array{manifest_hash:string,files:array<string,string>}
     */
    private function buildRemoteFiles(string $suffix): array
    {
        $manifestPayload = json_encode([
            'pack_id' => 'BIG5_OCEAN',
            'pack_version' => 'v1',
            'compiled_hash' => hash('sha256', 'compiled|'.$suffix),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertIsString($manifestPayload);

        return [
            'manifest_hash' => hash('sha256', $manifestPayload),
            'files' => [
                'compiled/manifest.json' => $manifestPayload,
                'compiled/questions.compiled.json' => json_encode([
                    'source' => $suffix,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'compiled/layout.compiled.json' => json_encode([
                    'layout' => $suffix,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
        ];
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

    private function expectedMaterializedDir(string $storagePath, string $manifestHash): string
    {
        return storage_path('app/private/packs_v2_materialized/BIG5_OCEAN/v1/'.hash('sha256', $storagePath).'/'.$manifestHash.'/compiled');
    }
}
