<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Services\Storage\ExactReleaseFileSetCatalogService;
use App\Services\Storage\ExactRootQuarantineService;
use App\Services\Storage\ExactRootRetirementOrchestratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ExactRootRetirementOrchestratorServiceTest extends TestCase
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
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-retirement-orchestrator-'.Str::uuid();
        $this->isolatedPacksRoot = sys_get_temp_dir().'/fap-retirement-orchestrator-packs-'.Str::uuid();
        File::ensureDirectoryExists($this->isolatedStoragePath.'/app/private');
        File::ensureDirectoryExists($this->isolatedPacksRoot);

        $this->app->useStoragePath($this->isolatedStoragePath);
        config()->set('filesystems.disks.local.root', $this->isolatedStoragePath.'/app/private');
        config()->set('content_packs.root', $this->isolatedPacksRoot);
        config()->set('storage_rollout.blob_offload_disk', 's3');
        config()->set('filesystems.disks.s3.bucket', 'retirement-orchestrator-bucket');
        config()->set('filesystems.disks.s3.region', 'ap-guangzhou');
        config()->set('filesystems.disks.s3.endpoint', 'https://cos.retirement-orchestrator.test');
        Storage::forgetDisk('local');
        Storage::fake('s3');
    }

    protected function tearDown(): void
    {
        Storage::forgetDisk('local');
        Storage::forgetDisk('s3');
        $this->app->useStoragePath($this->originalStoragePath);
        config()->set('filesystems.disks.local.root', $this->originalLocalRoot);
        config()->set('content_packs.root', $this->originalPacksRoot);
        Storage::forgetDisk('local');
        Storage::forgetDisk('s3');

        foreach ([$this->isolatedStoragePath, $this->isolatedPacksRoot] as $path) {
            if (is_dir($path)) {
                File::deleteDirectory($path);
            }
        }

        parent::tearDown();
    }

    public function test_service_builds_and_executes_quarantine_batch_plan_for_filtered_candidates(): void
    {
        $legacyFixture = $this->seedLegacyExactRootFixture('retire_quarantine_legacy');
        $primaryFixture = $this->seedV2PrimaryExactRootFixture('retire_quarantine_v2_primary');

        $service = app(ExactRootRetirementOrchestratorService::class);
        $plan = $service->buildPlan('quarantine', 's3', ['v2.primary']);

        $this->assertSame('storage_retire_exact_roots_plan.v1', (string) ($plan['schema'] ?? ''));
        $this->assertSame('quarantine', (string) ($plan['action'] ?? ''));
        $this->assertSame('s3', (string) ($plan['disk'] ?? ''));
        $this->assertSame(1, (int) data_get($plan, 'summary.candidate_count', 0));
        $this->assertSame(1, (int) data_get($plan, 'summary.skipped_count', 0));

        $candidate = collect((array) ($plan['candidates'] ?? []))->first();
        $this->assertIsArray($candidate);
        $this->assertSame('v2.primary', (string) ($candidate['source_kind'] ?? ''));
        $this->assertDirectoryExists($primaryFixture['primary_root']);
        $this->assertDirectoryExists($legacyFixture['source_root']);

        $releaseStoragePathBefore = DB::table('content_pack_releases')
            ->where('id', $primaryFixture['release_id'])
            ->value('storage_path');

        $result = $service->executePlan($plan);

        $this->assertSame('partial', (string) ($result['status'] ?? ''));
        $this->assertSame(1, (int) ($result['success_count'] ?? 0));
        $this->assertSame(0, (int) ($result['failure_count'] ?? 0));
        $this->assertSame(0, (int) ($result['blocked_count'] ?? 0));
        $this->assertSame(1, (int) ($result['skipped_count'] ?? 0));
        $this->assertFileExists((string) ($result['run_dir'] ?? '').'/run.json');
        $this->assertDirectoryDoesNotExist($primaryFixture['primary_root']);
        $this->assertDirectoryExists($primaryFixture['mirror_root']);
        $this->assertDirectoryExists($legacyFixture['source_root']);
        $this->assertSame($releaseStoragePathBefore, DB::table('content_pack_releases')
            ->where('id', $primaryFixture['release_id'])
            ->value('storage_path'));
        $this->assertSame(0, DB::table('content_pack_activations')->count());
        $this->assertDirectoryDoesNotExist(storage_path('app/private/blobs'));
    }

    public function test_service_builds_and_executes_purge_batch_plan_from_quarantined_item_roots(): void
    {
        $fixture = $this->quarantineLegacyFixture('retire_purge_legacy');
        $service = app(ExactRootRetirementOrchestratorService::class);

        $plan = $service->buildPlan('purge', 's3');

        $this->assertSame('purge', (string) ($plan['action'] ?? ''));
        $this->assertSame(1, (int) data_get($plan, 'summary.candidate_count', 0));

        $candidate = collect((array) ($plan['candidates'] ?? []))->first();
        $this->assertIsArray($candidate);
        $this->assertSame('purge', (string) ($candidate['action'] ?? ''));
        $this->assertSame($fixture['item_root'], (string) ($candidate['item_root'] ?? ''));
        $this->assertDirectoryExists($fixture['item_root']);

        $releaseStoragePathBefore = DB::table('content_pack_releases')
            ->where('id', $fixture['release_id'])
            ->value('storage_path');

        $result = $service->executePlan($plan);

        $this->assertSame('success', (string) ($result['status'] ?? ''));
        $this->assertSame(1, (int) ($result['success_count'] ?? 0));
        $this->assertSame(0, (int) ($result['failure_count'] ?? 0));
        $this->assertSame(0, (int) ($result['blocked_count'] ?? 0));
        $this->assertFileExists((string) ($result['run_dir'] ?? '').'/run.json');
        $this->assertDirectoryDoesNotExist($fixture['item_root']);

        $resultEntry = collect((array) ($result['results'] ?? []))
            ->firstWhere('item_root', $fixture['item_root']);
        $this->assertIsArray($resultEntry);
        $this->assertFileExists((string) ($resultEntry['receipt_path'] ?? ''));
        $this->assertSame($releaseStoragePathBefore, DB::table('content_pack_releases')
            ->where('id', $fixture['release_id'])
            ->value('storage_path'));
        $this->assertDirectoryDoesNotExist(storage_path('app/private/blobs'));
    }

    public function test_service_rejects_mixed_action_execute_plan(): void
    {
        $this->seedLegacyExactRootFixture('retire_mixed_action');
        $service = app(ExactRootRetirementOrchestratorService::class);
        $plan = $service->buildPlan('quarantine', 's3');

        $plan['candidates'][0]['action'] = 'purge';

        $this->expectExceptionObject(new \RuntimeException('mixed_action_plan_not_executable'));
        $service->executePlan($plan);
    }

    /**
     * @return array{release_id:string,source_root:string,exact_manifest_id:int}
     */
    private function seedLegacyExactRootFixture(string $suffix): array
    {
        $releaseId = (string) Str::uuid();
        $sourceRoot = storage_path('app/private/content_releases/'.Str::uuid().'/source_pack');
        $files = $this->createCompiledTree($sourceRoot, 'BIG5_OCEAN', 'v1', $suffix);
        $this->insertRelease($releaseId, 'BIG5_OCEAN', 'v1', $sourceRoot);

        $manifest = app(ExactReleaseFileSetCatalogService::class)->upsertExactManifest([
            'content_pack_release_id' => $releaseId,
            'schema_version' => (string) config('storage_rollout.exact_manifest_schema_version', 'storage_exact_manifest.v1'),
            'source_kind' => 'legacy.source_pack',
            'source_disk' => 'local',
            'source_storage_path' => $sourceRoot,
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

        $this->seedRemoteCopies($files, $suffix);

        return [
            'release_id' => $releaseId,
            'source_root' => $sourceRoot,
            'exact_manifest_id' => (int) $manifest->getKey(),
        ];
    }

    /**
     * @return array{
     *   release_id:string,
     *   primary_root:string,
     *   mirror_root:string,
     *   exact_manifest_id:int
     * }
     */
    private function seedV2PrimaryExactRootFixture(string $suffix): array
    {
        $releaseId = (string) Str::uuid();
        $storagePath = 'private/packs_v2/BIG5_OCEAN/v1/'.$releaseId;
        $primaryRoot = storage_path('app/'.$storagePath);
        $mirrorRoot = storage_path('app/content_packs_v2/BIG5_OCEAN/v1/'.$releaseId);
        $files = $this->createCompiledTree($primaryRoot, 'BIG5_OCEAN', 'v1', $suffix);
        $this->createCompiledTree($mirrorRoot, 'BIG5_OCEAN', 'v1', $suffix);
        $manifestHash = hash('sha256', $files['compiled/manifest.json']);
        $this->insertV2Release($releaseId, 'BIG5_OCEAN', 'v1', $storagePath, $manifestHash);

        $manifest = app(ExactReleaseFileSetCatalogService::class)->upsertExactManifest([
            'content_pack_release_id' => $releaseId,
            'schema_version' => (string) config('storage_rollout.exact_manifest_schema_version', 'storage_exact_manifest.v1'),
            'source_kind' => 'v2.primary',
            'source_disk' => 'local',
            'source_storage_path' => $primaryRoot,
            'manifest_hash' => $manifestHash,
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

        $this->seedRemoteCopies($files, $suffix);

        return [
            'release_id' => $releaseId,
            'primary_root' => $primaryRoot,
            'mirror_root' => $mirrorRoot,
            'exact_manifest_id' => (int) $manifest->getKey(),
        ];
    }

    /**
     * @return array{item_root:string,release_id:string}
     */
    private function quarantineLegacyFixture(string $suffix): array
    {
        $fixture = $this->seedLegacyExactRootFixture($suffix);
        $service = app(ExactRootQuarantineService::class);
        $plan = $service->buildPlan('s3');
        $result = $service->executePlan($plan);

        $quarantined = collect((array) ($result['quarantined'] ?? []))
            ->firstWhere('exact_manifest_id', $fixture['exact_manifest_id']);

        return [
            'item_root' => (string) ($quarantined['target_root'] ?? ''),
            'release_id' => $fixture['release_id'],
        ];
    }

    /**
     * @param  array<string,string>  $files
     */
    private function seedRemoteCopies(array $files, string $suffix): void
    {
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

            $remotePath = 'rollout/blobs/sha256/'.substr($hash, 0, 2).'/'.$hash;
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
                    'bucket' => 'retirement-orchestrator-bucket',
                    'region' => 'ap-guangzhou',
                    'endpoint' => 'https://cos.retirement-orchestrator.test',
                    'url' => 'https://cos.retirement-orchestrator.test/'.$remotePath,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * @return array<string,string>
     */
    private function createCompiledTree(string $root, string $packId, string $packVersion, string $suffix): array
    {
        File::ensureDirectoryExists($root.'/compiled');

        $files = [
            'compiled/manifest.json' => json_encode([
                'pack_id' => $packId,
                'pack_version' => $packVersion,
                'suffix' => $suffix,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            'compiled/payload.compiled.json' => json_encode([
                'suffix' => $suffix,
                'kind' => 'payload',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            'compiled/meta.compiled.json' => json_encode([
                'suffix' => $suffix,
                'kind' => 'meta',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        ];

        foreach ($files as $logicalPath => $payload) {
            $absolutePath = $root.'/'.str_replace('/', DIRECTORY_SEPARATOR, $logicalPath);
            File::ensureDirectoryExists(dirname($absolutePath));
            File::put($absolutePath, $payload);
        }

        return $files;
    }

    private function insertRelease(string $releaseId, string $packId, string $packVersion, string $sourceRoot): void
    {
        DB::table('content_pack_versions')->updateOrInsert(
            ['id' => 'version-'.$releaseId],
            [
                'region' => 'CN_MAINLAND',
                'locale' => 'zh-CN',
                'pack_id' => $packId,
                'content_package_version' => $packVersion,
                'dir_version_alias' => $packVersion,
                'source_type' => 'upload',
                'source_ref' => 'test',
                'sha256' => hash('sha256', $sourceRoot),
                'manifest_json' => '{}',
                'extracted_rel_path' => ltrim(str_replace('\\', '/', substr($sourceRoot, strlen(storage_path('app')))), '/'),
                'created_by' => 'test',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('content_pack_releases')->insert([
            'id' => $releaseId,
            'action' => 'publish',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'dir_alias' => $packVersion,
            'from_version_id' => null,
            'to_version_id' => 'version-'.$releaseId,
            'from_pack_id' => null,
            'to_pack_id' => $packId,
            'status' => 'success',
            'message' => null,
            'created_by' => 'test',
            'manifest_hash' => null,
            'compiled_hash' => null,
            'content_hash' => null,
            'norms_version' => '2026Q1',
            'git_sha' => 'git-test',
            'pack_version' => $packVersion,
            'manifest_json' => '{}',
            'storage_path' => $sourceRoot,
            'source_commit' => 'git-test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertV2Release(string $releaseId, string $packId, string $packVersion, string $storagePath, string $manifestHash): void
    {
        DB::table('content_pack_versions')->updateOrInsert(
            ['id' => 'version-'.$releaseId],
            [
                'region' => 'GLOBAL',
                'locale' => 'global',
                'pack_id' => $packId,
                'content_package_version' => $packVersion,
                'dir_version_alias' => $packVersion,
                'source_type' => 'publish',
                'source_ref' => 'test',
                'sha256' => hash('sha256', $storagePath),
                'manifest_json' => '{}',
                'extracted_rel_path' => ltrim(str_replace('\\', '/', $storagePath), '/'),
                'created_by' => 'test',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('content_pack_releases')->updateOrInsert(
            ['id' => $releaseId],
            [
                'action' => 'packs2_publish',
                'region' => 'GLOBAL',
                'locale' => 'global',
                'dir_alias' => $packVersion,
                'from_version_id' => null,
                'to_version_id' => 'version-'.$releaseId,
                'from_pack_id' => null,
                'to_pack_id' => $packId,
                'status' => 'success',
                'message' => null,
                'created_by' => 'test',
                'manifest_hash' => $manifestHash,
                'compiled_hash' => $manifestHash,
                'content_hash' => hash('sha256', 'content|'.$releaseId),
                'norms_version' => '2026Q1',
                'git_sha' => 'git-test',
                'pack_version' => $packVersion,
                'manifest_json' => '{}',
                'storage_path' => $storagePath,
                'source_commit' => 'git-test',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}
