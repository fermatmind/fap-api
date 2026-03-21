<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Services\Content\ContentPackV2Resolver;
use App\Services\Storage\ExactReleaseFileSetCatalogService;
use App\Services\Storage\MaterializedCacheJanitorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MaterializedCacheJanitorServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $isolatedStoragePath;

    private string $originalStoragePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalStoragePath = $this->app->storagePath();
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-materialized-janitor-service-'.Str::uuid();
        File::ensureDirectoryExists($this->isolatedStoragePath.'/app/private');
        $this->app->useStoragePath($this->isolatedStoragePath);

        config()->set('storage_rollout.packs_v2_remote_rehydrate_enabled', true);
        config()->set('storage_rollout.resolver_materialization_enabled', true);
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

    public function test_local_source_candidate_is_deleted_as_whole_bucket_and_can_be_rematerialized(): void
    {
        $fixture = $this->seedLocalSourceFixture('local-proof');
        $bucketRoot = $fixture['bucket_root'];
        $otherRoots = $this->seedNoTouchRoots();

        /** @var MaterializedCacheJanitorService $service */
        $service = app(MaterializedCacheJanitorService::class);

        $dryRun = $service->run(false);
        $this->assertSame('planned', $dryRun['status']);
        $this->assertSame(1, data_get($dryRun, 'summary.scanned_bucket_count'));
        $this->assertSame(1, data_get($dryRun, 'summary.candidate_delete_count'));
        $this->assertSame(0, data_get($dryRun, 'summary.deleted_bucket_count'));
        $this->assertFileExists($bucketRoot.'/.materialization.json');
        $this->assertFileExists($bucketRoot.'/compiled/questions.compiled.json');

        $result = $service->run(true);
        $this->assertSame('executed', $result['status']);
        $this->assertSame(1, data_get($result, 'summary.deleted_bucket_count'));
        $this->assertDirectoryDoesNotExist($bucketRoot);
        $this->assertFileExists($fixture['source_compiled_dir'].'/manifest.json');
        $this->assertFileExists($otherRoots['private_v2_root'].'/compiled/manifest.json');
        $this->assertFileExists($otherRoots['content_v2_root'].'/compiled/manifest.json');
        $this->assertFileExists($otherRoots['blobs_file']);
        $this->assertFileExists($otherRoots['quarantine_file']);
        $this->assertFileExists($otherRoots['control_plane_file']);
        $this->assertFileExists($otherRoots['offload_file']);

        /** @var ContentPackV2Resolver $resolver */
        $resolver = app(ContentPackV2Resolver::class);
        $resolved = $resolver->resolveActiveCompiledPath('BIG5_OCEAN', 'v1');
        $this->assertSame($fixture['bucket_compiled_dir'], $resolved);
        $this->assertFileExists($bucketRoot.'/compiled/questions.compiled.json');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'storage_janitor_materialized_cache',
            'target_id' => 'materialized_cache',
            'result' => 'success',
        ]);
    }

    public function test_remote_proof_candidate_is_deleted_and_resolver_can_regenerate_via_remote_fallback(): void
    {
        $fixture = $this->seedRemoteFallbackFixture('remote-proof');
        $bucketRoot = $fixture['bucket_root'];
        File::deleteDirectory($fixture['source_root']);
        File::deleteDirectory($fixture['mirror_root']);

        /** @var MaterializedCacheJanitorService $service */
        $service = app(MaterializedCacheJanitorService::class);
        $result = $service->run(true);

        $this->assertSame(1, data_get($result, 'summary.candidate_delete_count'));
        $this->assertSame(1, data_get($result, 'summary.deleted_bucket_count'));
        $this->assertDirectoryDoesNotExist($bucketRoot);

        /** @var ContentPackV2Resolver $resolver */
        $resolver = app(ContentPackV2Resolver::class);
        $resolved = $resolver->resolveActiveCompiledPath('BIG5_OCEAN', 'v1');
        $this->assertSame($fixture['bucket_compiled_dir'], $resolved);
        $this->assertSame('{"source":"remote-proof"}', (string) File::get($fixture['bucket_compiled_dir'].'/questions.compiled.json'));
    }

    public function test_remote_proof_failure_skips_bucket_without_deleting_it(): void
    {
        $fixture = $this->seedRemoteFallbackFixture('remote-missing', createRemoteCoverage: false);
        File::deleteDirectory($fixture['source_root']);
        File::deleteDirectory($fixture['mirror_root']);

        /** @var MaterializedCacheJanitorService $service */
        $service = app(MaterializedCacheJanitorService::class);
        $result = $service->run(true);

        $this->assertSame(0, data_get($result, 'summary.candidate_delete_count'));
        $this->assertSame(0, data_get($result, 'summary.deleted_bucket_count'));
        $this->assertSame(1, data_get($result, 'summary.skipped_bucket_count'));
        $this->assertDirectoryExists($fixture['bucket_root']);
        $this->assertSame('PACKS2_REMOTE_REHYDRATE_REMOTE_COVERAGE_INCOMPLETE', data_get($result, 'skipped.0.reason'));
    }

    public function test_invalid_sentinel_and_missing_compiled_manifest_are_skipped_and_partial_paths_are_not_deleted(): void
    {
        $invalidSentinel = $this->seedMalformedBucket([
            'manifest_hash' => str_repeat('1', 64),
            'with_sentinel' => true,
            'sentinel' => ['manifest_hash' => str_repeat('1', 64)],
            'with_compiled_manifest' => true,
        ]);
        $missingCompiledManifest = $this->seedMalformedBucket([
            'manifest_hash' => str_repeat('2', 64),
            'with_sentinel' => true,
            'sentinel' => [
                'storage_path' => 'private/packs_v2/BIG5_OCEAN/v1/missing-compiled',
                'manifest_hash' => str_repeat('2', 64),
                'release_id' => (string) Str::uuid(),
            ],
            'with_compiled_manifest' => false,
        ]);
        $partialFile = storage_path('app/private/packs_v2_materialized/BIG5_OCEAN/v1/partial-only.txt');
        File::ensureDirectoryExists(dirname($partialFile));
        File::put($partialFile, 'partial');

        /** @var MaterializedCacheJanitorService $service */
        $service = app(MaterializedCacheJanitorService::class);
        $result = $service->run(true);

        $this->assertSame(2, data_get($result, 'summary.scanned_bucket_count'));
        $this->assertSame(0, data_get($result, 'summary.deleted_bucket_count'));
        $this->assertSame(2, data_get($result, 'summary.skipped_bucket_count'));
        $this->assertDirectoryExists($invalidSentinel['bucket_root']);
        $this->assertDirectoryExists($missingCompiledManifest['bucket_root']);
        $this->assertFileExists($partialFile);
    }

    /**
     * @return array<string,string>
     */
    private function seedLocalSourceFixture(string $suffix): array
    {
        $releaseId = (string) Str::uuid();
        $manifestPayload = $this->manifestPayload('BIG5_OCEAN', 'v1', $suffix);
        $manifestHash = hash('sha256', $manifestPayload);
        $storagePath = 'private/packs_v2/BIG5_OCEAN/v1/'.$releaseId;
        $sourceRoot = storage_path('app/'.$storagePath);
        $sourceCompiledDir = $sourceRoot.'/compiled';

        $this->insertRelease($releaseId, $manifestHash, $storagePath);
        $this->activateRelease($releaseId);
        $this->writeCompiledTree($sourceCompiledDir, [
            'manifest.json' => $manifestPayload,
            'questions.compiled.json' => '{"source":"'.$suffix.'"}',
        ]);

        $bucketRoot = $this->materializedBucketRoot('BIG5_OCEAN', 'v1', $storagePath, $manifestHash);
        $this->writeMaterializedBucket($bucketRoot, [
            'release_id' => $releaseId,
            'storage_path' => $storagePath,
            'manifest_hash' => $manifestHash,
            'source_compiled_dir' => $sourceCompiledDir,
            'materialized_at' => now()->toIso8601String(),
        ], [
            'manifest.json' => $manifestPayload,
            'questions.compiled.json' => '{"source":"'.$suffix.'"}',
        ]);

        return [
            'release_id' => $releaseId,
            'storage_path' => $storagePath,
            'manifest_hash' => $manifestHash,
            'source_root' => $sourceRoot,
            'source_compiled_dir' => $sourceCompiledDir,
            'bucket_root' => $bucketRoot,
            'bucket_compiled_dir' => $bucketRoot.'/compiled',
        ];
    }

    /**
     * @return array<string,string>
     */
    private function seedRemoteFallbackFixture(string $suffix, bool $createRemoteCoverage = true): array
    {
        $releaseId = (string) Str::uuid();
        ['manifest_hash' => $manifestHash, 'files' => $files] = $this->buildRemoteFiles($suffix);
        $storagePath = 'private/packs_v2/BIG5_OCEAN/v1/'.$releaseId;
        $sourceRoot = storage_path('app/'.$storagePath);
        $mirrorRoot = storage_path('app/content_packs_v2/BIG5_OCEAN/v1/'.$releaseId);

        $this->insertRelease($releaseId, $manifestHash, $storagePath);
        $this->activateRelease($releaseId);
        $manifest = $this->seedExactManifest(
            $releaseId,
            'v2.primary',
            $sourceRoot,
            $manifestHash,
            $files,
            $createRemoteCoverage
        );

        $bucketRoot = $this->materializedBucketRoot('BIG5_OCEAN', 'v1', $storagePath, $manifestHash);
        $this->writeMaterializedBucket($bucketRoot, [
            'release_id' => $releaseId,
            'storage_path' => $storagePath,
            'manifest_hash' => $manifestHash,
            'source_compiled_dir' => 'remote_rehydrate://exact_manifest/'.(int) $manifest->getKey(),
            'materialized_at' => now()->toIso8601String(),
            'remote_fallback' => true,
            'exact_manifest_id' => (int) $manifest->getKey(),
            'exact_identity_hash' => (string) $manifest->exact_identity_hash,
            'source_kind' => 'v2.primary',
        ], [
            'manifest.json' => $files['compiled/manifest.json'],
            'questions.compiled.json' => $files['compiled/questions.compiled.json'],
            'layout.compiled.json' => $files['compiled/layout.compiled.json'],
        ]);

        return [
            'release_id' => $releaseId,
            'storage_path' => $storagePath,
            'manifest_hash' => $manifestHash,
            'source_root' => $sourceRoot,
            'mirror_root' => $mirrorRoot,
            'bucket_root' => $bucketRoot,
            'bucket_compiled_dir' => $bucketRoot.'/compiled',
        ];
    }

    /**
     * @param  array{manifest_hash:string,with_sentinel:bool,sentinel:array<string,mixed>,with_compiled_manifest:bool}  $options
     * @return array<string,string>
     */
    private function seedMalformedBucket(array $options): array
    {
        $storagePath = 'private/packs_v2/BIG5_OCEAN/v1/'.Str::uuid();
        $bucketRoot = $this->materializedBucketRoot('BIG5_OCEAN', 'v1', $storagePath, $options['manifest_hash']);
        File::ensureDirectoryExists($bucketRoot.'/compiled');

        if ($options['with_sentinel']) {
            File::put(
                $bucketRoot.'/.materialization.json',
                json_encode($options['sentinel'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT).PHP_EOL
            );
        }

        if ($options['with_compiled_manifest']) {
            File::put($bucketRoot.'/compiled/manifest.json', $this->manifestPayload('BIG5_OCEAN', 'v1', 'malformed-'.$options['manifest_hash']));
        }

        File::put($bucketRoot.'/compiled/questions.compiled.json', '{"source":"malformed"}');

        return [
            'bucket_root' => $bucketRoot,
        ];
    }

    /**
     * @return array<string,string>
     */
    private function seedNoTouchRoots(): array
    {
        $privateV2Root = storage_path('app/private/packs_v2/BIG5_OCEAN/v1/no-touch-private');
        $contentV2Root = storage_path('app/content_packs_v2/BIG5_OCEAN/v1/no-touch-mirror');
        $blobsFile = storage_path('app/private/blobs/no-touch.bin');
        $quarantineFile = storage_path('app/private/quarantine/release_roots/run-1/items/item-1/root/.quarantine.json');
        $controlPlaneFile = storage_path('app/private/control_plane_snapshots/no-touch.json');
        $offloadFile = storage_path('app/private/offload/blobs/no-touch.bin');

        $this->writeCompiledTree($privateV2Root.'/compiled', ['manifest.json' => $this->manifestPayload('BIG5_OCEAN', 'v1', 'private-no-touch')]);
        $this->writeCompiledTree($contentV2Root.'/compiled', ['manifest.json' => $this->manifestPayload('BIG5_OCEAN', 'v1', 'mirror-no-touch')]);
        File::ensureDirectoryExists(dirname($blobsFile));
        File::put($blobsFile, 'blob');
        File::ensureDirectoryExists(dirname($quarantineFile));
        File::put($quarantineFile, '{}');
        File::ensureDirectoryExists(dirname($controlPlaneFile));
        File::put($controlPlaneFile, '{}');
        File::ensureDirectoryExists(dirname($offloadFile));
        File::put($offloadFile, 'offload');

        return [
            'private_v2_root' => $privateV2Root,
            'content_v2_root' => $contentV2Root,
            'blobs_file' => $blobsFile,
            'quarantine_file' => $quarantineFile,
            'control_plane_file' => $controlPlaneFile,
            'offload_file' => $offloadFile,
        ];
    }

    /**
     * @param  array<string,mixed>  $sentinel
     * @param  array<string,string>  $compiledFiles
     */
    private function writeMaterializedBucket(string $bucketRoot, array $sentinel, array $compiledFiles): void
    {
        File::ensureDirectoryExists($bucketRoot.'/compiled');
        File::put(
            $bucketRoot.'/.materialization.json',
            json_encode($sentinel, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT).PHP_EOL
        );

        foreach ($compiledFiles as $relativePath => $payload) {
            File::put($bucketRoot.'/compiled/'.ltrim($relativePath, '/'), $payload);
        }
    }

    private function materializedBucketRoot(string $packId, string $packVersion, string $storagePath, string $manifestHash): string
    {
        return storage_path('app/private/packs_v2_materialized/'.$packId.'/'.$packVersion.'/'.hash('sha256', $storagePath).'/'.$manifestHash);
    }

    private function manifestPayload(string $packId, string $packVersion, string $suffix): string
    {
        $payload = json_encode([
            'pack_id' => $packId,
            'pack_version' => $packVersion,
            'compiled_hash' => hash('sha256', 'compiled|'.$suffix),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertIsString($payload);

        return $payload;
    }

    /**
     * @return array{manifest_hash:string,files:array<string,string>}
     */
    private function buildRemoteFiles(string $suffix): array
    {
        $manifestPayload = $this->manifestPayload('BIG5_OCEAN', 'v1', $suffix);

        return [
            'manifest_hash' => hash('sha256', $manifestPayload),
            'files' => [
                'compiled/manifest.json' => $manifestPayload,
                'compiled/questions.compiled.json' => json_encode(['source' => $suffix], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'compiled/layout.compiled.json' => json_encode(['layout' => $suffix], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
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
        bool $createRemoteCoverage,
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
