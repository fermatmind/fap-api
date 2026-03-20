<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Services\Content\ContentPackV2Resolver;
use App\Services\Storage\ExactReleaseFileSetCatalogService;
use App\Services\Storage\ExactRootQuarantineService;
use App\Services\Storage\QuarantinedRootPurgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class QuarantinedRootPurgeServiceTest extends TestCase
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
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-quarantine-purge-service-'.Str::uuid();
        $this->isolatedPacksRoot = sys_get_temp_dir().'/fap-quarantine-purge-packs-'.Str::uuid();
        File::ensureDirectoryExists($this->isolatedStoragePath.'/app/private');
        File::ensureDirectoryExists($this->isolatedPacksRoot);

        $this->app->useStoragePath($this->isolatedStoragePath);
        config()->set('filesystems.disks.local.root', $this->isolatedStoragePath.'/app/private');
        config()->set('content_packs.root', $this->isolatedPacksRoot);
        config()->set('storage_rollout.blob_offload_disk', 's3');
        config()->set('filesystems.disks.s3.bucket', 'purge-service-bucket');
        config()->set('filesystems.disks.s3.region', 'ap-guangzhou');
        config()->set('filesystems.disks.s3.endpoint', 'https://cos.purge-service.test');
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

    public function test_service_builds_plan_and_purges_valid_quarantined_legacy_source_pack(): void
    {
        $fixture = $this->quarantineLegacySourcePackFixture('purge_valid');
        $service = app(QuarantinedRootPurgeService::class);

        $plan = $service->buildPlan($fixture['item_root'], 's3');

        $this->assertSame('planned', (string) ($plan['status'] ?? ''));
        $this->assertSame('legacy.source_pack', (string) ($plan['source_kind'] ?? ''));
        $this->assertSame($fixture['item_root'], (string) ($plan['item_root'] ?? ''));
        $this->assertSame($fixture['exact_manifest_id'], (int) ($plan['exact_manifest_id'] ?? 0));

        $releaseStoragePathBefore = DB::table('content_pack_releases')
            ->where('id', $fixture['release_id'])
            ->value('storage_path');

        $result = $service->executePlan($plan, $fixture['item_root']);

        $this->assertSame('success', (string) ($result['status'] ?? ''));
        $this->assertDirectoryDoesNotExist($fixture['item_root']);
        $this->assertFileExists((string) ($result['run_dir'] ?? '').'/run.json');
        $this->assertFileExists((string) ($result['receipt_path'] ?? ''));
        $this->assertSame($releaseStoragePathBefore, DB::table('content_pack_releases')
            ->where('id', $fixture['release_id'])
            ->value('storage_path'));
        $this->assertSame(0, DB::table('content_pack_activations')->count());
        $this->assertDirectoryDoesNotExist(storage_path('app/private/blobs'));
    }

    public function test_service_builds_plan_and_purges_valid_quarantined_v2_mirror_without_affecting_primary_runtime_resolution(): void
    {
        $fixture = $this->quarantineV2MirrorFixture('purge_v2_mirror_valid');
        $service = app(QuarantinedRootPurgeService::class);

        $plan = $service->buildPlan($fixture['item_root'], 's3');

        $this->assertSame('planned', (string) ($plan['status'] ?? ''));
        $this->assertSame('v2.mirror', (string) ($plan['source_kind'] ?? ''));
        $this->assertSame($fixture['item_root'], (string) ($plan['item_root'] ?? ''));
        $this->assertSame($fixture['exact_manifest_id'], (int) ($plan['exact_manifest_id'] ?? 0));

        $releaseStoragePathBefore = DB::table('content_pack_releases')
            ->where('id', $fixture['release_id'])
            ->value('storage_path');

        /** @var ContentPackV2Resolver $resolver */
        $resolver = app(ContentPackV2Resolver::class);
        $resolvedBefore = $resolver->resolveCompiledPathByManifestHash('BIG5_OCEAN', 'v1', $fixture['manifest_hash']);
        $this->assertSame($fixture['primary_root'].'/compiled', $resolvedBefore);

        $result = $service->executePlan($plan, $fixture['item_root']);

        $this->assertSame('success', (string) ($result['status'] ?? ''));
        $this->assertDirectoryDoesNotExist($fixture['item_root']);
        $this->assertDirectoryExists($fixture['primary_root']);
        $this->assertFileExists((string) ($result['run_dir'] ?? '').'/run.json');
        $this->assertFileExists((string) ($result['receipt_path'] ?? ''));
        $this->assertSame($releaseStoragePathBefore, DB::table('content_pack_releases')
            ->where('id', $fixture['release_id'])
            ->value('storage_path'));

        $resolvedAfter = $resolver->resolveCompiledPathByManifestHash('BIG5_OCEAN', 'v1', $fixture['manifest_hash']);
        $this->assertSame($fixture['primary_root'].'/compiled', $resolvedAfter);
        $this->assertDirectoryDoesNotExist(storage_path('app/private/blobs'));
    }

    public function test_service_blocks_v2_mirror_purge_when_primary_is_missing_and_remote_fallback_is_unavailable(): void
    {
        $fixture = $this->quarantineV2MirrorFixture('purge_v2_mirror_primary_missing_blocked');
        File::deleteDirectory($fixture['primary_root']);

        $service = app(QuarantinedRootPurgeService::class);
        $plan = $service->buildPlan($fixture['item_root'], 's3');

        $this->assertSame('blocked', (string) ($plan['status'] ?? ''));
        $this->assertSame(
            'v2 mirror removal is not runtime safe: PACKS2_REMOTE_REHYDRATE_DISABLED',
            (string) ($plan['blocked_reason'] ?? '')
        );
        $this->assertDirectoryExists($fixture['item_root']);
        $this->assertDirectoryDoesNotExist(storage_path('app/private/packs_v2_materialized'));
    }

    public function test_service_purges_v2_mirror_when_primary_is_missing_and_remote_fallback_is_available(): void
    {
        config()->set('storage_rollout.packs_v2_remote_rehydrate_enabled', true);

        $fixture = $this->quarantineV2MirrorFixture('purge_v2_mirror_primary_missing_remote_ok');
        File::deleteDirectory($fixture['primary_root']);

        $service = app(QuarantinedRootPurgeService::class);
        $plan = $service->buildPlan($fixture['item_root'], 's3');

        $this->assertSame('planned', (string) ($plan['status'] ?? ''));
        $this->assertSame('v2.mirror', (string) ($plan['source_kind'] ?? ''));

        $result = $service->executePlan($plan, $fixture['item_root']);
        $this->assertSame('success', (string) ($result['status'] ?? ''));
        $this->assertDirectoryDoesNotExist($fixture['item_root']);
        $this->assertFileExists((string) ($result['run_dir'] ?? '').'/run.json');
        $this->assertFileExists((string) ($result['receipt_path'] ?? ''));

        /** @var ContentPackV2Resolver $resolver */
        $resolver = app(ContentPackV2Resolver::class);
        $resolved = $resolver->resolveCompiledPathByManifestHash('BIG5_OCEAN', 'v1', $fixture['manifest_hash']);
        $expectedMaterializedDir = storage_path('app/private/packs_v2_materialized/BIG5_OCEAN/v1/'.hash('sha256', 'private/packs_v2/BIG5_OCEAN/v1/'.$fixture['release_id']).'/'.$fixture['manifest_hash'].'/compiled');
        $this->assertSame($expectedMaterializedDir, $resolved);
        $this->assertDirectoryDoesNotExist(storage_path('app/private/blobs'));
        $this->assertSame(0, DB::table('content_pack_activations')->count());
    }

    public function test_service_builds_plan_and_purges_valid_quarantined_v2_primary_when_runtime_safe_if_primary_removed(): void
    {
        $fixture = $this->quarantineV2PrimaryFixture('purge_v2_primary_valid');
        $service = app(QuarantinedRootPurgeService::class);

        $plan = $service->buildPlan($fixture['item_root'], 's3');

        $this->assertSame('planned', (string) ($plan['status'] ?? ''));
        $this->assertSame('v2.primary', (string) ($plan['source_kind'] ?? ''));
        $this->assertSame($fixture['item_root'], (string) ($plan['item_root'] ?? ''));
        $this->assertSame($fixture['exact_manifest_id'], (int) ($plan['exact_manifest_id'] ?? 0));

        $releaseStoragePathBefore = DB::table('content_pack_releases')
            ->where('id', $fixture['release_id'])
            ->value('storage_path');

        /** @var ContentPackV2Resolver $resolver */
        $resolver = app(ContentPackV2Resolver::class);
        $resolvedBefore = $resolver->resolveCompiledPathByManifestHash('BIG5_OCEAN', 'v1', $fixture['manifest_hash']);
        $this->assertSame($fixture['mirror_root'].'/compiled', $resolvedBefore);

        $result = $service->executePlan($plan, $fixture['item_root']);

        $this->assertSame('success', (string) ($result['status'] ?? ''));
        $this->assertDirectoryDoesNotExist($fixture['item_root']);
        $this->assertDirectoryExists($fixture['mirror_root']);
        $this->assertFileExists((string) ($result['run_dir'] ?? '').'/run.json');
        $this->assertFileExists((string) ($result['receipt_path'] ?? ''));
        $this->assertSame($releaseStoragePathBefore, DB::table('content_pack_releases')
            ->where('id', $fixture['release_id'])
            ->value('storage_path'));

        $resolvedAfter = $resolver->resolveCompiledPathByManifestHash('BIG5_OCEAN', 'v1', $fixture['manifest_hash']);
        $this->assertSame($fixture['mirror_root'].'/compiled', $resolvedAfter);
        $this->assertDirectoryDoesNotExist(storage_path('app/private/blobs'));
        $this->assertSame(0, DB::table('content_pack_activations')->count());
    }

    public function test_service_blocks_v2_primary_purge_when_runtime_unsafe_if_primary_removed(): void
    {
        $fixture = $this->quarantineV2PrimaryFixture('purge_v2_primary_blocked', createMirror: false);
        $service = app(QuarantinedRootPurgeService::class);

        $plan = $service->buildPlan($fixture['item_root'], 's3');

        $this->assertSame('blocked', (string) ($plan['status'] ?? ''));
        $this->assertSame(
            'v2 primary removal is not runtime safe: PACKS2_REMOTE_REHYDRATE_DISABLED',
            (string) ($plan['blocked_reason'] ?? '')
        );
        $this->assertDirectoryExists($fixture['item_root']);
        $this->assertDirectoryDoesNotExist(storage_path('app/private/packs_v2_materialized'));
    }

    public function test_service_blocks_when_sentinel_or_exact_authority_or_remote_coverage_is_missing(): void
    {
        $fixture = $this->quarantineLegacySourcePackFixture('purge_blocked_sentinel');
        File::delete($fixture['item_root'].'/.quarantine.json');

        $service = app(QuarantinedRootPurgeService::class);
        $plan = $service->buildPlan($fixture['item_root'], 's3');
        $this->assertSame('blocked', (string) ($plan['status'] ?? ''));
        $this->assertSame('quarantine sentinel is missing.', (string) ($plan['blocked_reason'] ?? ''));

        $fixture = $this->quarantineLegacySourcePackFixture('purge_blocked_missing_manifest');
        DB::table('content_release_exact_manifest_files')->where('content_release_exact_manifest_id', $fixture['exact_manifest_id'])->delete();
        $missingChildPlan = $service->buildPlan($fixture['item_root'], 's3');
        $this->assertSame('blocked', (string) ($missingChildPlan['status'] ?? ''));
        $this->assertSame('exact manifest has no file rows.', (string) ($missingChildPlan['blocked_reason'] ?? ''));

        $fixture = $this->quarantineLegacySourcePackFixture('purge_blocked_missing_remote');
        DB::table('storage_blob_locations')
            ->where('disk', 's3')
            ->delete();
        $missingRemotePlan = $service->buildPlan($fixture['item_root'], 's3');
        $this->assertSame('blocked', (string) ($missingRemotePlan['status'] ?? ''));
        $this->assertStringContainsString('missing verified remote_copy coverage', (string) ($missingRemotePlan['blocked_reason'] ?? ''));
    }

    public function test_service_blocks_when_restore_is_not_feasible_or_linked_release_is_active_or_snapshot_referenced(): void
    {
        $service = app(QuarantinedRootPurgeService::class);

        $fixture = $this->quarantineLegacySourcePackFixture('purge_blocked_target_exists');
        File::ensureDirectoryExists($fixture['source_root'].'/compiled');
        File::put($fixture['source_root'].'/compiled/manifest.json', '{}');
        $targetExistsPlan = $service->buildPlan($fixture['item_root'], 's3');
        $this->assertSame('blocked', (string) ($targetExistsPlan['status'] ?? ''));
        $this->assertSame('restore dry-run is blocked: restore target already exists.', (string) ($targetExistsPlan['blocked_reason'] ?? ''));

        $fixture = $this->quarantineLegacySourcePackFixture('purge_blocked_active');
        DB::table('content_pack_activations')->insert([
            'pack_id' => 'BIG5_OCEAN',
            'pack_version' => 'v1',
            'release_id' => $fixture['release_id'],
            'activated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $activePlan = $service->buildPlan($fixture['item_root'], 's3');
        $this->assertSame('blocked', (string) ($activePlan['status'] ?? ''));
        $this->assertSame('restore dry-run is blocked: linked release is active; restore is blocked.', (string) ($activePlan['blocked_reason'] ?? ''));

        DB::table('content_pack_activations')->delete();
        $fixture = $this->quarantineLegacySourcePackFixture('purge_blocked_snapshot');
        DB::table('content_release_snapshots')->insert([
            'pack_id' => 'BIG5_OCEAN',
            'pack_version' => 'v1',
            'from_content_pack_release_id' => $fixture['release_id'],
            'to_content_pack_release_id' => null,
            'activation_before_release_id' => null,
            'activation_after_release_id' => null,
            'reason' => 'test',
            'created_by' => 'test',
            'meta_json' => json_encode(['source' => 'test'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $snapshotPlan = $service->buildPlan($fixture['item_root'], 's3');
        $this->assertSame('blocked', (string) ($snapshotPlan['status'] ?? ''));
        $this->assertSame('restore dry-run is blocked: linked release is snapshot referenced; restore is blocked.', (string) ($snapshotPlan['blocked_reason'] ?? ''));
    }

    public function test_service_blocks_when_quarantined_root_drifted_or_plan_is_tampered(): void
    {
        $fixture = $this->quarantineLegacySourcePackFixture('purge_blocked_drift');
        File::put($fixture['item_root'].'/compiled/payload.compiled.json', '{"suffix":"drift"}');

        $service = app(QuarantinedRootPurgeService::class);
        $driftPlan = $service->buildPlan($fixture['item_root'], 's3');
        $this->assertSame('blocked', (string) ($driftPlan['status'] ?? ''));
        $this->assertStringContainsString('blob hash mismatch', (string) ($driftPlan['blocked_reason'] ?? ''));

        $fixture = $this->quarantineLegacySourcePackFixture('purge_tampered_plan');
        $plan = $service->buildPlan($fixture['item_root'], 's3');
        $plan['source_storage_path'] = storage_path('app/private/content_releases/'.Str::uuid().'/source_pack');

        $this->expectExceptionObject(new \RuntimeException('plan_candidate_mismatch'));
        try {
            $service->executePlan($plan, $fixture['item_root']);
        } finally {
            $this->assertDirectoryExists($fixture['item_root']);
        }
    }

    /**
     * @return array{
     *   item_root:string,
     *   source_root:string,
     *   release_id:string,
     *   exact_manifest_id:int
     * }
     */
    private function quarantineLegacySourcePackFixture(string $suffix): array
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
                    'bucket' => 'purge-service-bucket',
                    'region' => 'ap-guangzhou',
                    'endpoint' => 'https://cos.purge-service.test',
                    'url' => 'https://cos.purge-service.test/'.$remotePath,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $quarantinePlan = app(ExactRootQuarantineService::class)->buildPlan('s3');
        $quarantineResult = app(ExactRootQuarantineService::class)->executePlan($quarantinePlan);
        $quarantinedEntry = collect((array) ($quarantineResult['quarantined'] ?? []))
            ->firstWhere('exact_manifest_id', (int) $manifest->getKey());
        $this->assertIsArray($quarantinedEntry);

        return [
            'item_root' => (string) ($quarantinedEntry['target_root'] ?? ''),
            'source_root' => $sourceRoot,
            'release_id' => $releaseId,
            'exact_manifest_id' => (int) $manifest->getKey(),
        ];
    }

    /**
     * @return array{
     *   item_root:string,
     *   mirror_root:string,
     *   primary_root:string,
     *   release_id:string,
     *   exact_manifest_id:int,
     *   manifest_hash:string
     * }
     */
    private function quarantineV2MirrorFixture(string $suffix): array
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
                    'bucket' => 'purge-service-bucket',
                    'region' => 'ap-guangzhou',
                    'endpoint' => 'https://cos.purge-service.test',
                    'url' => 'https://cos.purge-service.test/'.$remotePath,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $quarantinePlan = app(ExactRootQuarantineService::class)->buildPlan('s3');
        $quarantineResult = app(ExactRootQuarantineService::class)->executePlan($quarantinePlan);
        $quarantinedEntry = collect((array) ($quarantineResult['quarantined'] ?? []))
            ->firstWhere('exact_manifest_id', (int) $manifest->getKey());
        $this->assertIsArray($quarantinedEntry);

        return [
            'item_root' => (string) ($quarantinedEntry['target_root'] ?? ''),
            'mirror_root' => $mirrorRoot,
            'primary_root' => $primaryRoot,
            'release_id' => $releaseId,
            'exact_manifest_id' => (int) $manifest->getKey(),
            'manifest_hash' => $manifestHash,
        ];
    }

    /**
     * @return array{
     *   item_root:string,
     *   primary_root:string,
     *   mirror_root:string,
     *   release_id:string,
     *   exact_manifest_id:int,
     *   manifest_hash:string
     * }
     */
    private function quarantineV2PrimaryFixture(string $suffix, bool $createMirror = true): array
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
                    'bucket' => 'purge-service-bucket',
                    'region' => 'ap-guangzhou',
                    'endpoint' => 'https://cos.purge-service.test',
                    'url' => 'https://cos.purge-service.test/'.$remotePath,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $quarantinePlan = app(ExactRootQuarantineService::class)->buildPlan('s3');
        $quarantineResult = app(ExactRootQuarantineService::class)->executePlan($quarantinePlan);
        $quarantinedEntry = collect((array) ($quarantineResult['quarantined'] ?? []))
            ->firstWhere('exact_manifest_id', (int) $manifest->getKey());
        $this->assertIsArray($quarantinedEntry);

        if (! $createMirror && is_dir($mirrorRoot)) {
            File::deleteDirectory($mirrorRoot);
        }

        return [
            'item_root' => (string) ($quarantinedEntry['target_root'] ?? ''),
            'primary_root' => $primaryRoot,
            'mirror_root' => $mirrorRoot,
            'release_id' => $releaseId,
            'exact_manifest_id' => (int) $manifest->getKey(),
            'manifest_hash' => $manifestHash,
        ];
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
