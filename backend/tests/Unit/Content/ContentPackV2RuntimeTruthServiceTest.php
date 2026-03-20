<?php

declare(strict_types=1);

namespace Tests\Unit\Content;

use App\Services\Content\ContentPackV2RuntimeTruthService;
use App\Services\Storage\ExactReleaseFileSetCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ContentPackV2RuntimeTruthServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $isolatedStoragePath;

    private string $originalStoragePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalStoragePath = $this->app->storagePath();
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-packs2-runtime-truth-'.Str::uuid();
        File::ensureDirectoryExists($this->isolatedStoragePath);
        $this->app->useStoragePath($this->isolatedStoragePath);

        config()->set('storage_rollout.packs_v2_remote_rehydrate_enabled', false);
        config()->set('storage_rollout.resolver_materialization_enabled', false);
        config()->set('storage_rollout.blob_offload_disk', 's3');
        config()->set('filesystems.disks.s3.bucket', 'packs2-runtime-truth-bucket');
        config()->set('filesystems.disks.s3.region', 'ap-guangzhou');
        config()->set('filesystems.disks.s3.endpoint', 'https://cos.packs2-runtime-truth.test');
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

    public function test_truth_reports_mirror_removal_safe_when_primary_is_present(): void
    {
        $fixture = $this->seedV2ReleaseFixture('truth_primary_present', createPrimary: true, createMirror: true);

        /** @var ContentPackV2RuntimeTruthService $service */
        $service = app(ContentPackV2RuntimeTruthService::class);
        $truth = $service->inspectRelease($fixture['release'], 's3');

        $this->assertTrue((bool) $truth['primary_available']);
        $this->assertTrue((bool) $truth['mirror_available']);
        $this->assertFalse((bool) $truth['remote_fallback_available']);
        $this->assertTrue((bool) $truth['runtime_safe_if_primary_removed']);
        $this->assertTrue((bool) $truth['runtime_safe_if_mirror_removed']);
        $this->assertNull($truth['reason']);
    }

    public function test_truth_blocks_mirror_removal_when_primary_is_missing_and_remote_fallback_is_disabled(): void
    {
        $fixture = $this->seedV2ReleaseFixture('truth_primary_missing_disabled', createPrimary: false, createMirror: true);

        /** @var ContentPackV2RuntimeTruthService $service */
        $service = app(ContentPackV2RuntimeTruthService::class);
        $truth = $service->inspectRelease($fixture['release'], 's3');

        $this->assertFalse((bool) $truth['primary_available']);
        $this->assertTrue((bool) $truth['mirror_available']);
        $this->assertFalse((bool) $truth['remote_fallback_available']);
        $this->assertTrue((bool) $truth['runtime_safe_if_primary_removed']);
        $this->assertFalse((bool) $truth['runtime_safe_if_mirror_removed']);
        $this->assertSame('PACKS2_REMOTE_REHYDRATE_DISABLED', (string) $truth['reason']);
    }

    public function test_truth_allows_mirror_removal_when_primary_is_missing_and_remote_fallback_is_available(): void
    {
        config()->set('storage_rollout.packs_v2_remote_rehydrate_enabled', true);
        $fixture = $this->seedV2ReleaseFixture('truth_primary_missing_remote_ok', createPrimary: false, createMirror: true);

        /** @var ContentPackV2RuntimeTruthService $service */
        $service = app(ContentPackV2RuntimeTruthService::class);
        $truth = $service->inspectRelease($fixture['release'], 's3');

        $this->assertFalse((bool) $truth['primary_available']);
        $this->assertTrue((bool) $truth['mirror_available']);
        $this->assertTrue((bool) $truth['remote_fallback_available']);
        $this->assertTrue((bool) $truth['runtime_safe_if_primary_removed']);
        $this->assertTrue((bool) $truth['runtime_safe_if_mirror_removed']);
        $this->assertNull($truth['reason']);
        $this->assertDirectoryDoesNotExist(storage_path('app/private/packs_v2_materialized'));
        $this->assertDirectoryDoesNotExist(storage_path('app/private/blobs'));
        $this->assertSame($fixture['storage_path'], (string) DB::table('content_pack_releases')->where('id', $fixture['release_id'])->value('storage_path'));
    }

    public function test_truth_blocks_mirror_removal_when_exact_manifest_or_remote_coverage_is_missing(): void
    {
        config()->set('storage_rollout.packs_v2_remote_rehydrate_enabled', true);

        $missingExact = $this->seedV2ReleaseFixture('truth_missing_exact', createPrimary: false, createMirror: true, createExactManifest: false);
        /** @var ContentPackV2RuntimeTruthService $service */
        $service = app(ContentPackV2RuntimeTruthService::class);
        $missingExactTruth = $service->inspectRelease($missingExact['release'], 's3');
        $this->assertFalse((bool) $missingExactTruth['runtime_safe_if_mirror_removed']);
        $this->assertSame('PACKS2_REMOTE_REHYDRATE_EXACT_MANIFEST_NOT_FOUND', (string) $missingExactTruth['reason']);

        $missingCoverage = $this->seedV2ReleaseFixture('truth_missing_remote', createPrimary: false, createMirror: true, createRemoteCoverage: false);
        $missingCoverageTruth = $service->inspectRelease($missingCoverage['release'], 's3');
        $this->assertFalse((bool) $missingCoverageTruth['runtime_safe_if_mirror_removed']);
        $this->assertSame('PACKS2_REMOTE_REHYDRATE_REMOTE_COVERAGE_INCOMPLETE', (string) $missingCoverageTruth['reason']);
    }

    /**
     * @return array{
     *   release_id:string,
     *   release:object,
     *   storage_path:string
     * }
     */
    private function seedV2ReleaseFixture(
        string $suffix,
        bool $createPrimary,
        bool $createMirror,
        bool $createExactManifest = true,
        bool $createRemoteCoverage = true,
    ): array {
        $releaseId = (string) Str::uuid();
        $storagePath = 'private/packs_v2/BIG5_OCEAN/v1/'.$releaseId;
        $primaryRoot = storage_path('app/'.$storagePath);
        $mirrorRoot = storage_path('app/content_packs_v2/BIG5_OCEAN/v1/'.$releaseId);
        ['manifest_hash' => $manifestHash, 'files' => $files] = $this->buildRemoteFiles($suffix);

        if ($createPrimary) {
            $this->writeCompiledTree($primaryRoot, $files);
        }

        if ($createMirror) {
            $this->writeCompiledTree($mirrorRoot, $files);
        }

        $this->insertRelease($releaseId, $manifestHash, $storagePath);
        if ($createExactManifest) {
            $this->seedExactManifest($releaseId, 'v2.mirror', $mirrorRoot, $manifestHash, $files, $createRemoteCoverage);
        }

        /** @var object $release */
        $release = DB::table('content_pack_releases')->where('id', $releaseId)->first();

        return [
            'release_id' => $releaseId,
            'release' => $release,
            'storage_path' => $storagePath,
        ];
    }

    /**
     * @param  array<string,string>  $files
     */
    private function writeCompiledTree(string $root, array $files): void
    {
        foreach ($files as $logicalPath => $payload) {
            $absolutePath = $root.'/'.str_replace('/', DIRECTORY_SEPARATOR, $logicalPath);
            File::ensureDirectoryExists(dirname($absolutePath));
            File::put($absolutePath, $payload);
        }
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
            'payload_json' => ['runtime_truth' => true],
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
                        'bucket' => 'packs2-runtime-truth-bucket',
                        'region' => 'ap-guangzhou',
                        'endpoint' => 'https://cos.packs2-runtime-truth.test',
                        'url' => 'https://cos.packs2-runtime-truth.test/'.$remotePath,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        return $manifest;
    }

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

    private function insertRelease(string $releaseId, string $manifestHash, string $storagePath): void
    {
        DB::table('content_pack_releases')->insert([
            'id' => $releaseId,
            'action' => 'packs2_publish',
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
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
