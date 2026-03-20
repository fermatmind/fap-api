<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Services\Storage\ExactReleaseFileSetCatalogService;
use App\Services\Storage\ExactRootQuarantineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ExactRootQuarantineServiceTest extends TestCase
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
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-storage-root-quarantine-'.Str::uuid();
        $this->isolatedPacksRoot = sys_get_temp_dir().'/fap-packs-root-quarantine-'.Str::uuid();
        File::ensureDirectoryExists($this->isolatedStoragePath.'/app/private');
        File::ensureDirectoryExists($this->isolatedPacksRoot);

        $this->app->useStoragePath($this->isolatedStoragePath);
        config()->set('filesystems.disks.local.root', $this->isolatedStoragePath.'/app/private');
        config()->set('content_packs.root', $this->isolatedPacksRoot);
        config()->set('storage_rollout.blob_offload_disk', 's3');
        config()->set('filesystems.disks.s3.bucket', 'quarantine-test-bucket');
        config()->set('filesystems.disks.s3.region', 'ap-guangzhou');
        config()->set('filesystems.disks.s3.endpoint', 'https://cos.quarantine.test');
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

    public function test_service_plans_and_quarantines_only_allowed_exact_roots(): void
    {
        $sourcePackReleaseId = (string) Str::uuid();
        $activeReleaseId = (string) Str::uuid();

        $sourcePackRoot = storage_path('app/private/content_releases/'.Str::uuid().'/source_pack');
        $v2PrimaryRoot = storage_path('app/private/packs_v2/SDS_20/v1/'.Str::uuid());
        $previousPackRoot = storage_path('app/private/content_releases/backups/'.Str::uuid().'/previous_pack');
        $currentPackRoot = storage_path('app/private/content_releases/backups/'.Str::uuid().'/current_pack');
        $liveAliasRoot = $this->isolatedPacksRoot.'/default/CN_MAINLAND/zh-CN/BIG5-LIVE';
        $artifactRoot = storage_path('app/private/artifacts/reports/MBTI/'.Str::uuid());
        $activeRoot = storage_path('app/private/content_releases/'.Str::uuid().'/source_pack');
        $missingRemoteRoot = storage_path('app/private/content_releases/'.Str::uuid().'/source_pack');

        $sourcePack = $this->seedExactRootFixture($sourcePackReleaseId, 'legacy.source_pack', $sourcePackRoot, 'source_pack_ok', 'BIG5_OCEAN', 'v1');
        $this->seedExactRootFixture((string) Str::uuid(), 'v2.primary', $v2PrimaryRoot, 'v2_primary_blocked', 'SDS_20', 'v1');
        $this->seedExactRootFixture((string) Str::uuid(), 'legacy.previous_pack', $previousPackRoot, 'blocked_previous', 'BIG5_OCEAN', 'v1');
        $this->seedExactRootFixture((string) Str::uuid(), 'legacy.current_pack', $currentPackRoot, 'blocked_current', 'BIG5_OCEAN', 'v1');
        $this->seedExactRootFixture((string) Str::uuid(), 'live_alias', $liveAliasRoot, 'blocked_live_alias', 'BIG5_OCEAN', 'v1');
        $this->seedExactRootFixture((string) Str::uuid(), 'artifact_canonical', $artifactRoot, 'blocked_artifact', 'BIG5_OCEAN', 'v1');
        $this->seedExactRootFixture($activeReleaseId, 'legacy.source_pack', $activeRoot, 'blocked_active', 'BIG5_OCEAN', 'v1');
        $this->seedExactRootFixture((string) Str::uuid(), 'legacy.source_pack', $missingRemoteRoot, 'blocked_missing_remote', 'BIG5_OCEAN', 'v1', withRemoteCopy: false);

        DB::table('content_pack_activations')->insert([
            'pack_id' => 'BIG5_OCEAN',
            'pack_version' => 'v1',
            'release_id' => $activeReleaseId,
            'activated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(ExactRootQuarantineService::class);
        $plan = $service->buildPlan('s3');

        $this->assertSame('storage_quarantine_exact_roots_plan.v1', (string) ($plan['schema'] ?? ''));
        $this->assertSame('s3', (string) ($plan['target_disk'] ?? ''));
        $this->assertCount(1, (array) ($plan['candidates'] ?? []));
        $this->assertGreaterThanOrEqual(5, count((array) ($plan['blocked'] ?? [])));

        $candidateKinds = array_column((array) ($plan['candidates'] ?? []), 'source_kind');
        sort($candidateKinds);
        $this->assertSame(['legacy.source_pack'], $candidateKinds);

        $blockedByReason = collect((array) ($plan['blocked'] ?? []))->keyBy('reason')->all();
        $this->assertArrayHasKey('source_kind_not_allowed', $blockedByReason);
        $this->assertArrayHasKey('active_release_root', $blockedByReason);
        $this->assertArrayHasKey('missing_verified_remote_copy', $blockedByReason);

        $releaseRowsBefore = DB::table('content_pack_releases')
            ->whereIn('id', [$sourcePackReleaseId])
            ->pluck('storage_path', 'id')
            ->all();

        $result = $service->executePlan($plan);

        $this->assertSame(1, (int) ($result['quarantined_root_count'] ?? 0));
        $this->assertSame(0, (int) ($result['failed_root_count'] ?? 0));

        $runDir = (string) ($result['run_dir'] ?? '');
        $this->assertDirectoryExists($runDir);
        $this->assertFileExists($runDir.'/run.json');
        $this->assertDirectoryDoesNotExist($sourcePackRoot);
        $this->assertDirectoryExists($v2PrimaryRoot);
        $this->assertDirectoryExists($previousPackRoot);
        $this->assertDirectoryExists($currentPackRoot);
        $this->assertDirectoryExists($liveAliasRoot);
        $this->assertDirectoryExists($artifactRoot);
        $this->assertDirectoryExists($activeRoot);
        $this->assertDirectoryExists($missingRemoteRoot);

        foreach ((array) ($result['quarantined'] ?? []) as $entry) {
            $targetRoot = (string) ($entry['target_root'] ?? '');
            $this->assertDirectoryExists($targetRoot);
            $this->assertFileExists($targetRoot.'/.quarantine.json');

            $meta = json_decode((string) file_get_contents($targetRoot.'/.quarantine.json'), true);
            $this->assertIsArray($meta);
            $this->assertSame('storage_quarantine_exact_root_run.v1', (string) ($meta['schema'] ?? ''));
            $this->assertSame('s3', (string) ($meta['target_disk'] ?? ''));
            $this->assertSame(0, (int) data_get($meta, 'remote_blob_coverage.missing_locations', 1));
        }

        $this->assertSame($releaseRowsBefore, DB::table('content_pack_releases')
            ->whereIn('id', [$sourcePackReleaseId])
            ->pluck('storage_path', 'id')
            ->all());
        $this->assertSame(1, DB::table('content_pack_activations')->count());
        $this->assertDirectoryDoesNotExist(storage_path('app/private/blobs'));
        $this->assertDirectoryDoesNotExist(storage_path('app/private/offload'));
    }

    public function test_service_marks_stale_plan_as_failed_without_moving_root(): void
    {
        $releaseId = (string) Str::uuid();
        $sourceRoot = storage_path('app/private/content_releases/'.Str::uuid().'/source_pack');
        $fixture = $this->seedExactRootFixture($releaseId, 'legacy.source_pack', $sourceRoot, 'stale_plan', 'BIG5_OCEAN', 'v1');

        $service = app(ExactRootQuarantineService::class);
        $plan = $service->buildPlan('s3');
        $this->assertCount(1, (array) ($plan['candidates'] ?? []));

        DB::table('storage_blob_locations')
            ->where('blob_hash', $fixture['manifest_blob_hash'])
            ->where('disk', 's3')
            ->delete();

        $result = $service->executePlan($plan);
        $this->assertSame(0, (int) ($result['quarantined_root_count'] ?? 0));
        $this->assertSame(1, (int) ($result['failed_root_count'] ?? 0));
        $this->assertDirectoryExists($sourceRoot);
        $this->assertFileExists((string) ($result['run_dir'] ?? '').'/run.json');
    }

    public function test_service_quarantines_v2_mirror_without_affecting_primary_runtime_resolution(): void
    {
        $fixture = $this->seedV2MirrorQuarantineFixture('v2_mirror_quarantine_ok');
        $service = app(ExactRootQuarantineService::class);

        $plan = $service->buildPlan('s3');
        $candidates = collect((array) ($plan['candidates'] ?? []));
        $mirrorCandidate = $candidates->firstWhere('exact_manifest_id', $fixture['exact_manifest_id']);

        $this->assertIsArray($mirrorCandidate);
        $this->assertSame('v2.mirror', (string) ($mirrorCandidate['source_kind'] ?? ''));

        $result = $service->executePlan($plan);

        $quarantinedEntry = collect((array) ($result['quarantined'] ?? []))
            ->firstWhere('exact_manifest_id', $fixture['exact_manifest_id']);
        $this->assertIsArray($quarantinedEntry);
        $this->assertDirectoryDoesNotExist($fixture['mirror_root']);
        $this->assertDirectoryExists($fixture['primary_root']);
        $this->assertFileExists($fixture['primary_root'].'/compiled/manifest.json');
        $this->assertFileExists($fixture['primary_root'].'/compiled/payload.compiled.json');
        $this->assertFileExists((string) ($quarantinedEntry['target_root'] ?? '').'/.quarantine.json');
        $this->assertSame($fixture['storage_path'], (string) DB::table('content_pack_releases')->where('id', $fixture['release_id'])->value('storage_path'));
    }

    public function test_service_rejects_tampered_plan_source_path_without_moving_arbitrary_directory(): void
    {
        $releaseId = (string) Str::uuid();
        $sourceRoot = storage_path('app/private/content_releases/'.Str::uuid().'/source_pack');
        $this->seedExactRootFixture($releaseId, 'legacy.source_pack', $sourceRoot, 'tampered_plan', 'BIG5_OCEAN', 'v1');

        $arbitraryRoot = storage_path('app/private/content_releases/'.Str::uuid().'/source_pack');
        $this->createCompiledTree($arbitraryRoot, 'BIG5_OCEAN', 'v1', 'arbitrary_root');

        $service = app(ExactRootQuarantineService::class);
        $plan = $service->buildPlan('s3');
        $this->assertCount(1, (array) ($plan['candidates'] ?? []));

        $plan['candidates'][0]['source_storage_path'] = $arbitraryRoot;

        $result = $service->executePlan($plan);

        $this->assertSame(0, (int) ($result['quarantined_root_count'] ?? 0));
        $this->assertSame(1, (int) ($result['failed_root_count'] ?? 0));
        $this->assertSame('plan_candidate_mismatch', (string) data_get($result, 'failures.0.reason', ''));
        $this->assertDirectoryExists($sourceRoot);
        $this->assertDirectoryExists($arbitraryRoot);
    }

    /**
     * @return array{exact_manifest_id:int,manifest_blob_hash:string}
     */
    private function seedExactRootFixture(
        string $releaseId,
        string $sourceKind,
        string $root,
        string $suffix,
        string $packId,
        string $packVersion,
        bool $withRemoteCopy = true,
    ): array {
        $files = $this->createCompiledTree($root, $packId, $packVersion, $suffix);
        $this->insertRelease($releaseId, $packId, $packVersion, $root);

        $manifest = app(ExactReleaseFileSetCatalogService::class)->upsertExactManifest([
            'content_pack_release_id' => $releaseId,
            'schema_version' => (string) config('storage_rollout.exact_manifest_schema_version', 'storage_exact_manifest.v1'),
            'source_kind' => $sourceKind,
            'source_disk' => 'local',
            'source_storage_path' => $root,
            'manifest_hash' => hash('sha256', $files['compiled/manifest.json']),
            'pack_id' => $packId,
            'pack_version' => $packVersion,
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

            if (! $withRemoteCopy) {
                continue;
            }

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
                    'bucket' => 'quarantine-test-bucket',
                    'region' => 'ap-guangzhou',
                    'endpoint' => 'https://cos.quarantine.test',
                    'url' => 'https://cos.quarantine.test/'.$remotePath,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [
            'exact_manifest_id' => (int) $manifest->getKey(),
            'manifest_blob_hash' => hash('sha256', $files['compiled/manifest.json']),
        ];
    }

    /**
     * @return array<string,string>
     */
    private function createCompiledTree(string $root, string $packId, string $packVersion, string $suffix): array
    {
        $compiledDir = $root.'/compiled';
        File::ensureDirectoryExists($compiledDir.'/nested');

        $payload = json_encode(['suffix' => $suffix, 'kind' => 'payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $cards = json_encode(['suffix' => $suffix, 'kind' => 'cards'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $manifest = json_encode([
            'schema' => 'storage.quarantine.test.v1',
            'pack_id' => $packId,
            'pack_version' => $packVersion,
            'content_package_version' => $packVersion,
            'compiled_hash' => hash('sha256', 'compiled|'.$suffix),
            'content_hash' => hash('sha256', 'content|'.$suffix),
            'norms_version' => '2026Q1',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

        File::put($compiledDir.'/manifest.json', $manifest);
        File::put($compiledDir.'/payload.compiled.json', $payload);
        File::put($compiledDir.'/nested/cards.compiled.json', $cards);

        return [
            'compiled/manifest.json' => $manifest,
            'compiled/payload.compiled.json' => $payload,
            'compiled/nested/cards.compiled.json' => $cards,
        ];
    }

    private function insertRelease(string $releaseId, string $packId, string $packVersion, string $storagePath): void
    {
        DB::table('content_pack_releases')->updateOrInsert(
            ['id' => $releaseId],
            [
                'action' => str_starts_with($storagePath, storage_path('app/private/packs_v2/')) || str_starts_with($storagePath, storage_path('app/content_packs_v2/'))
                    ? 'packs2_publish'
                    : 'publish',
                'region' => 'CN_MAINLAND',
                'locale' => 'zh-CN',
                'dir_alias' => $packVersion,
                'from_version_id' => null,
                'to_version_id' => null,
                'from_pack_id' => null,
                'to_pack_id' => $packId,
                'status' => 'success',
                'message' => 'test',
                'created_by' => 'test',
                'manifest_hash' => null,
                'compiled_hash' => null,
                'content_hash' => null,
                'norms_version' => null,
                'git_sha' => null,
                'pack_version' => $packVersion,
                'manifest_json' => null,
                'storage_path' => $storagePath,
                'source_commit' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    /**
     * @return array{
     *   exact_manifest_id:int,
     *   manifest_hash:string,
     *   mirror_root:string,
     *   primary_root:string,
     *   release_id:string,
     *   storage_path:string
     * }
     */
    private function seedV2MirrorQuarantineFixture(string $suffix): array
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
            'source_kind' => 'v2.mirror',
            'source_disk' => 'local',
            'source_storage_path' => $mirrorRoot,
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
                    'bucket' => 'quarantine-test-bucket',
                    'region' => 'ap-guangzhou',
                    'endpoint' => 'https://cos.quarantine.test',
                    'url' => 'https://cos.quarantine.test/'.$remotePath,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [
            'exact_manifest_id' => (int) $manifest->getKey(),
            'manifest_hash' => $manifestHash,
            'mirror_root' => $mirrorRoot,
            'primary_root' => $primaryRoot,
            'release_id' => $releaseId,
            'storage_path' => $storagePath,
        ];
    }

    private function insertV2Release(string $releaseId, string $packId, string $packVersion, string $storagePath, string $manifestHash): void
    {
        $versionId = 'version-'.$releaseId;

        DB::table('content_pack_versions')->updateOrInsert(
            ['id' => $versionId],
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
                'to_version_id' => $versionId,
                'from_pack_id' => null,
                'to_pack_id' => $packId,
                'status' => 'success',
                'message' => 'test',
                'created_by' => 'test',
                'manifest_hash' => $manifestHash,
                'compiled_hash' => $manifestHash,
                'content_hash' => hash('sha256', 'content|'.$releaseId),
                'norms_version' => '2026Q1',
                'git_sha' => null,
                'pack_version' => $packVersion,
                'manifest_json' => json_encode(['compiled_hash' => $manifestHash], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'storage_path' => $storagePath,
                'source_commit' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}
