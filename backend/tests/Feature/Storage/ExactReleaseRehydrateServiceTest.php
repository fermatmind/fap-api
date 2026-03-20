<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Services\Storage\ExactReleaseFileSetCatalogService;
use App\Services\Storage\ExactReleaseRehydrateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ExactReleaseRehydrateServiceTest extends TestCase
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
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-storage-exact-rehydrate-'.Str::uuid();
        File::ensureDirectoryExists($this->isolatedStoragePath.'/app/private');
        $this->app->useStoragePath($this->isolatedStoragePath);
        config()->set('filesystems.disks.local.root', $this->isolatedStoragePath.'/app/private');
        config()->set('storage_rollout.blob_offload_disk', 's3');
        config()->set('filesystems.disks.s3.bucket', 'rehydrate-test-bucket');
        config()->set('filesystems.disks.s3.region', 'ap-guangzhou');
        config()->set('filesystems.disks.s3.endpoint', 'https://cos.rehydrate.test');
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

    public function test_service_builds_plan_and_rehydrates_from_verified_remote_locations(): void
    {
        $releaseId = (string) Str::uuid();
        $fixture = $this->seedExactManifestFixture($releaseId, 'legacy.source_pack', 'service_success');

        $service = app(ExactReleaseRehydrateService::class);
        $targetRoot = storage_path('app/private/rehydrate_runs');
        $plan = $service->buildPlan($fixture['exact_manifest_id'], null, 's3', $targetRoot);

        $this->assertSame('storage_rehydrate_exact_release_plan.v1', (string) ($plan['schema'] ?? ''));
        $this->assertSame($fixture['exact_manifest_id'], (int) ($plan['exact_manifest']['id'] ?? 0));
        $this->assertSame(3, (int) ($plan['summary']['file_count'] ?? 0));
        $this->assertSame(0, (int) ($plan['summary']['missing_locations'] ?? 0));
        $this->assertSame(array_sum(array_map('strlen', $fixture['files'])), (int) ($plan['summary']['total_bytes'] ?? 0));

        $result = $service->executePlan($plan);
        $this->assertSame($fixture['exact_manifest_id'], (int) ($result['exact_manifest_id'] ?? 0));
        $this->assertSame('s3', (string) ($result['disk'] ?? ''));
        $this->assertSame(3, (int) ($result['rehydrated_files'] ?? 0));
        $this->assertSame(3, (int) ($result['verified_files'] ?? 0));

        $runDir = (string) ($result['run_dir'] ?? '');
        $this->assertDirectoryExists($runDir);
        $this->assertFileExists($runDir.'/.rehydrate.json');

        foreach ($fixture['files'] as $logicalPath => $payload) {
            $path = $runDir.'/'.$logicalPath;
            $this->assertFileExists($path);
            $this->assertSame(hash('sha256', $payload), hash_file('sha256', $path));
            $this->assertSame(strlen($payload), filesize($path));
        }

        $sentinel = json_decode((string) file_get_contents($runDir.'/.rehydrate.json'), true);
        $this->assertIsArray($sentinel);
        $this->assertSame($fixture['exact_manifest_id'], (int) ($sentinel['exact_manifest_id'] ?? 0));
        $this->assertDirectoryDoesNotExist(storage_path('app/private/blobs'));
    }

    public function test_service_reports_missing_verified_locations_and_execute_fails_without_writes(): void
    {
        $releaseId = (string) Str::uuid();
        $fixture = $this->seedExactManifestFixture($releaseId, 'v2.mirror', 'service_missing_location', [
            'compiled/payload.compiled.json',
        ]);

        $service = app(ExactReleaseRehydrateService::class);
        $targetRoot = storage_path('app/private/rehydrate_runs');
        $plan = $service->buildPlan($fixture['exact_manifest_id'], null, 's3', $targetRoot);

        $this->assertSame(1, (int) ($plan['summary']['missing_locations'] ?? 0));

        try {
            $service->executePlan($plan);
            $this->fail('expected executePlan to fail when a verified remote location is missing.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('missing verified remote locations', $e->getMessage());
        }

        $this->assertDirectoryDoesNotExist($targetRoot);
        $this->assertDirectoryDoesNotExist(storage_path('app/private/blobs'));
    }

    public function test_service_ignores_non_remote_copy_locations_and_rejects_traversal_logical_paths(): void
    {
        $releaseId = (string) Str::uuid();
        $fixture = $this->seedExactManifestFixture($releaseId, 'legacy.source_pack', 'service_non_remote_copy');

        DB::table('storage_blob_locations')->insert([
            'blob_hash' => $fixture['manifest_blob_hash'],
            'disk' => 's3',
            'storage_path' => 'rollout/blobs/bad/'.$fixture['manifest_blob_hash'].'.json',
            'location_kind' => 'local_shadow',
            'size_bytes' => strlen($fixture['files']['compiled/manifest.json']),
            'checksum' => 'sha256:'.$fixture['manifest_blob_hash'],
            'etag' => 'etag-non-remote-copy',
            'storage_class' => 'STANDARD',
            'verified_at' => now()->addSecond(),
            'meta_json' => json_encode(['bucket' => 'shadow'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now()->addSecond(),
            'updated_at' => now()->addSecond(),
        ]);

        $service = app(ExactReleaseRehydrateService::class);
        $plan = $service->buildPlan($fixture['exact_manifest_id'], null, 's3', storage_path('app/private/rehydrate_runs'));
        $manifestEntry = collect((array) ($plan['files'] ?? []))
            ->firstWhere('logical_path', 'compiled/manifest.json');
        $this->assertIsArray($manifestEntry);
        $this->assertSame('remote_copy', (string) (($manifestEntry['remote_location']['meta_json']['source_kind'] ?? 'remote_copy') ?: 'remote_copy'));
        $this->assertSame('rollout/blobs/'.substr($fixture['manifest_blob_hash'], 0, 2).'/'.$fixture['manifest_blob_hash'].'.json', (string) ($manifestEntry['remote_location']['storage_path'] ?? ''));

        $badManifest = app(ExactReleaseFileSetCatalogService::class)->upsertExactManifest([
            'content_pack_release_id' => $releaseId,
            'schema_version' => (string) config('storage_rollout.exact_manifest_schema_version', 'storage_exact_manifest.v1'),
            'source_kind' => 'legacy.source_pack',
            'source_disk' => 'local',
            'source_storage_path' => storage_path('app/private/content_releases/service_bad_path/source_pack'),
            'manifest_hash' => hash('sha256', '{"bad":"path"}'),
            'pack_id' => 'BIG5_OCEAN',
            'pack_version' => 'v1',
            'compiled_hash' => hash('sha256', 'bad-path-compiled'),
            'content_hash' => hash('sha256', 'bad-path-content'),
            'norms_version' => '2026Q1',
            'source_commit' => 'git-bad-path',
            'payload_json' => ['suffix' => 'bad_path'],
            'sealed_at' => now(),
            'last_verified_at' => now(),
        ], [[
            'logical_path' => '../content_releases/live/manifest.json',
            'blob_hash' => $fixture['manifest_blob_hash'],
            'size_bytes' => strlen($fixture['files']['compiled/manifest.json']),
            'role' => 'manifest',
            'content_type' => 'application/json',
            'encoding' => 'identity',
            'checksum' => 'sha256:'.$fixture['manifest_blob_hash'],
        ]]);

        try {
            $service->buildPlan((int) $badManifest->getKey(), null, 's3', storage_path('app/private/rehydrate_runs'));
            $this->fail('expected buildPlan to reject traversal logical_path entries.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('logical_path contains forbidden traversal segments', $e->getMessage());
        }
    }

    /**
     * @param  list<string>  $missingLocationPaths
     * @return array{
     *   exact_manifest_id:int,
     *   files:array<string,string>,
     *   manifest_blob_hash:string
     * }
     */
    private function seedExactManifestFixture(string $releaseId, string $sourceKind, string $suffix, array $missingLocationPaths = []): array
    {
        $this->insertRelease($releaseId, 'BIG5_OCEAN', 'v1');

        $files = [
            'compiled/manifest.json' => $this->manifestBytes('BIG5_OCEAN', 'v1', $suffix),
            'compiled/payload.compiled.json' => json_encode(['suffix' => $suffix, 'kind' => 'payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            'compiled/nested/cards.compiled.json' => json_encode(['suffix' => $suffix, 'kind' => 'cards'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        ];

        $catalogService = app(ExactReleaseFileSetCatalogService::class);
        $manifest = $catalogService->upsertExactManifest([
            'content_pack_release_id' => $releaseId,
            'schema_version' => (string) config('storage_rollout.exact_manifest_schema_version', 'storage_exact_manifest.v1'),
            'source_kind' => $sourceKind,
            'source_disk' => 'local',
            'source_storage_path' => storage_path('app/private/content_releases/'.$suffix.'/source_pack'),
            'manifest_hash' => hash('sha256', $files['compiled/manifest.json']),
            'pack_id' => 'BIG5_OCEAN',
            'pack_version' => 'v1',
            'compiled_hash' => hash('sha256', 'compiled|'.$suffix),
            'content_hash' => hash('sha256', 'content|'.$suffix),
            'norms_version' => '2026Q1',
            'source_commit' => 'git-'.$suffix,
            'payload_json' => ['suffix' => $suffix],
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

        foreach ($files as $logicalPath => $payload) {
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

            if (in_array($logicalPath, $missingLocationPaths, true)) {
                continue;
            }

            $remotePath = 'rollout/blobs/'.substr($hash, 0, 2).'/'.$hash.'.json';
            Storage::disk('s3')->put($remotePath, $payload);
            DB::table('storage_blob_locations')->insert([
                'blob_hash' => $hash,
                'disk' => 's3',
                'storage_path' => $remotePath,
                'location_kind' => 'remote_copy',
                'size_bytes' => strlen($payload),
                'checksum' => 'sha256:'.$hash,
                'etag' => 'etag-'.$suffix.'-'.substr($hash, 0, 8),
                'storage_class' => 'STANDARD_IA',
                'verified_at' => now(),
                'meta_json' => json_encode([
                    'bucket' => 'rehydrate-test-bucket',
                    'region' => 'ap-guangzhou',
                    'endpoint' => 'https://cos.rehydrate.test',
                    'url' => 'https://cos.rehydrate.test/'.$remotePath,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [
            'exact_manifest_id' => (int) $manifest->getKey(),
            'files' => $files,
            'manifest_blob_hash' => hash('sha256', $files['compiled/manifest.json']),
        ];
    }

    private function insertRelease(string $releaseId, string $packId, string $packVersion): void
    {
        DB::table('content_pack_releases')->insert([
            'id' => $releaseId,
            'action' => 'publish',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'dir_alias' => 'EXACT-REHYDRATE',
            'from_version_id' => null,
            'to_version_id' => null,
            'from_pack_id' => null,
            'to_pack_id' => $packId,
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
            'git_sha' => 'git-'.$packVersion,
            'pack_version' => $packVersion,
            'manifest_json' => null,
            'storage_path' => null,
            'source_commit' => 'git-'.$packVersion,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function manifestBytes(string $packId, string $packVersion, string $suffix): string
    {
        $payload = json_encode([
            'pack_id' => $packId,
            'pack_version' => $packVersion,
            'suffix' => $suffix,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($payload) ? $payload : '{}';
    }
}
