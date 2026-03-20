<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Services\Content\ContentPackV2Resolver;
use App\Services\Storage\ExactReleaseFileSetCatalogService;
use App\Services\Storage\ExactRootQuarantineService;
use App\Services\Storage\QuarantinedRootRestoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class QuarantinedRootRestoreServiceTest extends TestCase
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
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-quarantine-restore-service-'.Str::uuid();
        $this->isolatedPacksRoot = sys_get_temp_dir().'/fap-quarantine-restore-packs-'.Str::uuid();
        File::ensureDirectoryExists($this->isolatedStoragePath.'/app/private');
        File::ensureDirectoryExists($this->isolatedPacksRoot);

        $this->app->useStoragePath($this->isolatedStoragePath);
        config()->set('filesystems.disks.local.root', $this->isolatedStoragePath.'/app/private');
        config()->set('content_packs.root', $this->isolatedPacksRoot);
        config()->set('storage_rollout.blob_offload_disk', 's3');
        config()->set('filesystems.disks.s3.bucket', 'restore-service-bucket');
        config()->set('filesystems.disks.s3.region', 'ap-guangzhou');
        config()->set('filesystems.disks.s3.endpoint', 'https://cos.restore-service.test');
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

    public function test_service_builds_plan_and_restores_valid_quarantined_legacy_source_pack(): void
    {
        $fixture = $this->quarantineLegacySourcePackFixture('restore_valid');
        $service = app(QuarantinedRootRestoreService::class);

        $plan = $service->buildPlan($fixture['item_root']);

        $this->assertSame('planned', (string) ($plan['status'] ?? ''));
        $this->assertSame('legacy.source_pack', (string) ($plan['source_kind'] ?? ''));
        $this->assertSame($fixture['source_root'], (string) ($plan['target_root'] ?? ''));
        $this->assertSame($fixture['exact_manifest_id'], (int) ($plan['exact_manifest_id'] ?? 0));

        $releaseStoragePathBefore = DB::table('content_pack_releases')
            ->where('id', $fixture['release_id'])
            ->value('storage_path');

        $result = $service->executePlan($plan, $fixture['item_root']);

        $this->assertSame('success', (string) ($result['status'] ?? ''));
        $this->assertDirectoryExists($fixture['source_root']);
        $this->assertDirectoryDoesNotExist($fixture['item_root']);
        $this->assertFileExists((string) ($result['run_dir'] ?? '').'/run.json');
        $this->assertFileDoesNotExist($fixture['source_root'].'/.quarantine.json');
        $this->assertRootMatchesExactAuthority($fixture['source_root'], $fixture['files'], $fixture['manifest_hash']);
        $this->assertSame($releaseStoragePathBefore, DB::table('content_pack_releases')
            ->where('id', $fixture['release_id'])
            ->value('storage_path'));
        $this->assertSame(0, DB::table('content_pack_activations')->count());
        $this->assertDirectoryDoesNotExist(storage_path('app/private/blobs'));
    }

    public function test_service_restores_valid_quarantined_v2_mirror_back_to_original_mirror_path(): void
    {
        $fixture = $this->quarantineV2MirrorFixture('restore_v2_mirror_success');
        $service = app(QuarantinedRootRestoreService::class);

        $plan = $service->buildPlan($fixture['item_root']);

        $this->assertSame('planned', (string) ($plan['status'] ?? ''));
        $this->assertSame('v2.mirror', (string) ($plan['source_kind'] ?? ''));
        $this->assertSame($fixture['mirror_root'], (string) ($plan['target_root'] ?? ''));

        $releaseStoragePathBefore = DB::table('content_pack_releases')
            ->where('id', $fixture['release_id'])
            ->value('storage_path');

        $result = $service->executePlan($plan, $fixture['item_root']);

        $this->assertSame('success', (string) ($result['status'] ?? ''));
        $this->assertDirectoryExists($fixture['mirror_root']);
        $this->assertDirectoryDoesNotExist($fixture['item_root']);
        $this->assertDirectoryExists($fixture['primary_root']);
        $this->assertFileDoesNotExist($fixture['mirror_root'].'/.quarantine.json');
        $this->assertRootMatchesExactAuthority($fixture['mirror_root'], $fixture['files'], $fixture['manifest_hash']);
        $this->assertSame($releaseStoragePathBefore, DB::table('content_pack_releases')
            ->where('id', $fixture['release_id'])
            ->value('storage_path'));

        /** @var ContentPackV2Resolver $resolver */
        $resolver = app(ContentPackV2Resolver::class);
        $resolved = $resolver->resolveCompiledPathByManifestHash('BIG5_OCEAN', 'v1', $fixture['manifest_hash']);
        $this->assertSame($fixture['primary_root'].'/compiled', $resolved);
    }

    public function test_service_restores_valid_quarantined_v2_primary_back_to_original_primary_path(): void
    {
        $fixture = $this->quarantineV2PrimaryFixture('restore_v2_primary_success');
        $service = app(QuarantinedRootRestoreService::class);

        $plan = $service->buildPlan($fixture['item_root']);

        $this->assertSame('planned', (string) ($plan['status'] ?? ''));
        $this->assertSame('v2.primary', (string) ($plan['source_kind'] ?? ''));
        $this->assertSame($fixture['primary_root'], (string) ($plan['target_root'] ?? ''));

        $releaseStoragePathBefore = DB::table('content_pack_releases')
            ->where('id', $fixture['release_id'])
            ->value('storage_path');

        $result = $service->executePlan($plan, $fixture['item_root']);

        $this->assertSame('success', (string) ($result['status'] ?? ''));
        $this->assertDirectoryExists($fixture['primary_root']);
        $this->assertDirectoryDoesNotExist($fixture['item_root']);
        $this->assertDirectoryExists($fixture['mirror_root']);
        $this->assertFileDoesNotExist($fixture['primary_root'].'/.quarantine.json');
        $this->assertRootMatchesExactAuthority($fixture['primary_root'], $fixture['files'], $fixture['manifest_hash']);
        $this->assertSame($releaseStoragePathBefore, DB::table('content_pack_releases')
            ->where('id', $fixture['release_id'])
            ->value('storage_path'));

        /** @var ContentPackV2Resolver $resolver */
        $resolver = app(ContentPackV2Resolver::class);
        $resolved = $resolver->resolveCompiledPathByManifestHash('BIG5_OCEAN', 'v1', $fixture['manifest_hash']);
        $this->assertSame($fixture['primary_root'].'/compiled', $resolved);
    }

    public function test_service_blocks_when_target_exists_or_sentinel_is_invalid_or_source_kind_not_allowed(): void
    {
        $fixture = $this->quarantineLegacySourcePackFixture('restore_blocked_target_exists');
        File::ensureDirectoryExists($fixture['source_root'].'/compiled');
        File::put($fixture['source_root'].'/compiled/manifest.json', '{}');

        $service = app(QuarantinedRootRestoreService::class);
        $plan = $service->buildPlan($fixture['item_root']);
        $this->assertSame('blocked', (string) ($plan['status'] ?? ''));
        $this->assertSame('restore target already exists.', (string) ($plan['blocked_reason'] ?? ''));

        File::deleteDirectory($fixture['source_root']);
        $sentinel = json_decode((string) File::get($fixture['item_root'].'/.quarantine.json'), true);
        $this->assertIsArray($sentinel);
        unset($sentinel['source_storage_path']);
        File::put($fixture['item_root'].'/.quarantine.json', json_encode($sentinel, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT).PHP_EOL);

        $missingFieldPlan = $service->buildPlan($fixture['item_root']);
        $this->assertSame('blocked', (string) ($missingFieldPlan['status'] ?? ''));
        $this->assertSame('quarantine sentinel source_storage_path is missing.', (string) ($missingFieldPlan['blocked_reason'] ?? ''));

        $fixture = $this->quarantineLegacySourcePackFixture('restore_blocked_source_kind');
        $sentinel = json_decode((string) File::get($fixture['item_root'].'/.quarantine.json'), true);
        $sentinel['source_kind'] = 'v2.primary.shadow';
        File::put($fixture['item_root'].'/.quarantine.json', json_encode($sentinel, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT).PHP_EOL);

        $sourceKindPlan = $service->buildPlan($fixture['item_root']);
        $this->assertSame('blocked', (string) ($sourceKindPlan['status'] ?? ''));
        $this->assertSame('restore source_kind is not allowed.', (string) ($sourceKindPlan['blocked_reason'] ?? ''));
    }

    public function test_service_blocks_when_quarantined_root_drifted_or_linked_release_became_active_or_snapshot_referenced(): void
    {
        $fixture = $this->quarantineLegacySourcePackFixture('restore_blocked_drift');
        File::put($fixture['item_root'].'/compiled/payload.compiled.json', '{"suffix":"drift"}');

        $service = app(QuarantinedRootRestoreService::class);
        $driftPlan = $service->buildPlan($fixture['item_root']);
        $this->assertSame('blocked', (string) ($driftPlan['status'] ?? ''));
        $this->assertStringContainsString('blob hash mismatch', (string) ($driftPlan['blocked_reason'] ?? ''));

        $fixture = $this->quarantineLegacySourcePackFixture('restore_blocked_active');
        DB::table('content_pack_activations')->insert([
            'pack_id' => 'BIG5_OCEAN',
            'pack_version' => 'v1',
            'release_id' => $fixture['release_id'],
            'activated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $activePlan = $service->buildPlan($fixture['item_root']);
        $this->assertSame('blocked', (string) ($activePlan['status'] ?? ''));
        $this->assertSame('linked release is active; restore is blocked.', (string) ($activePlan['blocked_reason'] ?? ''));

        DB::table('content_pack_activations')->delete();
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

        $snapshotPlan = $service->buildPlan($fixture['item_root']);
        $this->assertSame('blocked', (string) ($snapshotPlan['status'] ?? ''));
        $this->assertSame('linked release is snapshot referenced; restore is blocked.', (string) ($snapshotPlan['blocked_reason'] ?? ''));
    }

    public function test_service_blocks_when_linked_release_currently_resolves_elsewhere_or_target_shape_is_invalid(): void
    {
        $fixture = $this->quarantineLegacySourcePackFixture('restore_blocked_runtime_fallback');
        $fallbackRoot = storage_path('app/private/content_releases/'.Str::uuid().'/source_pack');
        $this->createCompiledTree($fallbackRoot, 'BIG5_OCEAN', 'v1', 'runtime_fallback_root');
        DB::table('content_pack_versions')->updateOrInsert(
            ['id' => $fixture['version_id']],
            [
                'region' => 'CN_MAINLAND',
                'locale' => 'zh-CN',
                'pack_id' => 'BIG5_OCEAN',
                'content_package_version' => 'v1',
                'dir_version_alias' => 'v1',
                'source_type' => 'upload',
                'source_ref' => 'test',
                'sha256' => hash('sha256', 'runtime_fallback_root'),
                'manifest_json' => '{}',
                'extracted_rel_path' => ltrim(str_replace('\\', '/', substr($fallbackRoot, strlen(storage_path('app')))), '/'),
                'created_by' => 'test',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $service = app(QuarantinedRootRestoreService::class);
        $fallbackPlan = $service->buildPlan($fixture['item_root']);
        $this->assertSame('blocked', (string) ($fallbackPlan['status'] ?? ''));
        $this->assertSame('linked release currently resolves to a different runtime root.', (string) ($fallbackPlan['blocked_reason'] ?? ''));

        $invalidShapeRoot = storage_path('app/private/content_releases/'.Str::uuid().'/nested/source_pack');
        $invalidFixture = $this->quarantineLegacySourcePackFixture('restore_blocked_invalid_shape', $invalidShapeRoot);
        $invalidShapePlan = $service->buildPlan($invalidFixture['item_root']);
        $this->assertSame('blocked', (string) ($invalidShapePlan['status'] ?? ''));
        $this->assertSame(
            'restore target must match legacy content_releases/{release_id}/source_pack shape.',
            (string) ($invalidShapePlan['blocked_reason'] ?? '')
        );

        $invalidPrimaryFixture = $this->quarantineV2PrimaryFixture(
            'restore_blocked_invalid_primary_shape',
            storage_path('app/private/packs_v2/BIG5_OCEAN/v1/nested/'.Str::uuid())
        );
        $invalidPrimaryPlan = $service->buildPlan($invalidPrimaryFixture['item_root']);
        $this->assertSame('blocked', (string) ($invalidPrimaryPlan['status'] ?? ''));
        $this->assertSame(
            'restore target must match V2 primary private/packs_v2/{pack_id}/{pack_version}/{release_id} shape.',
            (string) ($invalidPrimaryPlan['blocked_reason'] ?? '')
        );
    }

    public function test_service_rejects_tampered_plan_without_restoring_or_mutating_paths(): void
    {
        $fixture = $this->quarantineLegacySourcePackFixture('restore_tampered_plan');
        $service = app(QuarantinedRootRestoreService::class);
        $plan = $service->buildPlan($fixture['item_root']);
        $plan['target_root'] = storage_path('app/private/content_releases/'.Str::uuid().'/source_pack');

        $this->expectExceptionObject(new \RuntimeException('restore_plan_mismatch'));
        try {
            $service->executePlan($plan, $fixture['item_root']);
        } finally {
            $this->assertDirectoryExists($fixture['item_root']);
            $this->assertDirectoryDoesNotExist($fixture['source_root']);
        }
    }

    /**
     * @return array{
     *   item_root:string,
     *   source_root:string,
     *   release_id:string,
     *   version_id:string,
     *   exact_manifest_id:int,
     *   manifest_hash:string,
     *   files:array<string,string>
     * }
     */
    private function quarantineLegacySourcePackFixture(string $suffix, ?string $sourceRoot = null): array
    {
        $releaseId = (string) Str::uuid();
        $sourceRoot ??= storage_path('app/private/content_releases/'.Str::uuid().'/source_pack');
        $files = $this->createCompiledTree($sourceRoot, 'BIG5_OCEAN', 'v1', $suffix);
        $versionId = $this->insertRelease($releaseId, 'BIG5_OCEAN', 'v1', $sourceRoot);
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
                    'bucket' => 'restore-service-bucket',
                    'region' => 'ap-guangzhou',
                    'endpoint' => 'https://cos.restore-service.test',
                    'url' => 'https://cos.restore-service.test/'.$remotePath,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $quarantinePlan = app(ExactRootQuarantineService::class)->buildPlan('s3');
        $quarantineResult = app(ExactRootQuarantineService::class)->executePlan($quarantinePlan);
        $itemRoot = (string) data_get($quarantineResult, 'quarantined.0.target_root', '');
        $this->assertNotSame('', $itemRoot);

        return [
            'item_root' => $itemRoot,
            'source_root' => $sourceRoot,
            'release_id' => $releaseId,
            'version_id' => $versionId,
            'exact_manifest_id' => (int) $manifest->getKey(),
            'manifest_hash' => hash('sha256', $files['compiled/manifest.json']),
            'files' => $files,
        ];
    }

    /**
     * @return array{
     *   item_root:string,
     *   mirror_root:string,
     *   primary_root:string,
     *   release_id:string,
     *   exact_manifest_id:int,
     *   manifest_hash:string,
     *   files:array<string,string>
     * }
     */
    private function quarantineV2MirrorFixture(string $suffix, ?string $mirrorRoot = null): array
    {
        $releaseId = (string) Str::uuid();
        $storagePath = 'private/packs_v2/BIG5_OCEAN/v1/'.$releaseId;
        $primaryRoot = storage_path('app/'.$storagePath);
        $mirrorRoot ??= storage_path('app/content_packs_v2/BIG5_OCEAN/v1/'.$releaseId);
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
                    'bucket' => 'restore-service-bucket',
                    'region' => 'ap-guangzhou',
                    'endpoint' => 'https://cos.restore-service.test',
                    'url' => 'https://cos.restore-service.test/'.$remotePath,
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
            'files' => $files,
        ];
    }

    /**
     * @return array{
     *   item_root:string,
     *   primary_root:string,
     *   mirror_root:string,
     *   release_id:string,
     *   exact_manifest_id:int,
     *   manifest_hash:string,
     *   files:array<string,string>
     * }
     */
    private function quarantineV2PrimaryFixture(string $suffix, ?string $primaryRoot = null): array
    {
        $releaseId = (string) Str::uuid();
        $storagePath = 'private/packs_v2/BIG5_OCEAN/v1/'.$releaseId;
        $primaryRoot ??= storage_path('app/'.$storagePath);
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
                    'bucket' => 'restore-service-bucket',
                    'region' => 'ap-guangzhou',
                    'endpoint' => 'https://cos.restore-service.test',
                    'url' => 'https://cos.restore-service.test/'.$remotePath,
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
            'primary_root' => $primaryRoot,
            'mirror_root' => $mirrorRoot,
            'release_id' => $releaseId,
            'exact_manifest_id' => (int) $manifest->getKey(),
            'manifest_hash' => $manifestHash,
            'files' => $files,
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
            'schema' => 'storage.restore.test.v1',
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

    private function insertRelease(string $releaseId, string $packId, string $packVersion, string $storagePath): string
    {
        $versionId = (string) Str::uuid();

        DB::table('content_pack_releases')->updateOrInsert(
            ['id' => $releaseId],
            [
                'action' => 'publish',
                'region' => 'CN_MAINLAND',
                'locale' => 'zh-CN',
                'dir_alias' => $packVersion,
                'from_version_id' => null,
                'to_version_id' => $versionId,
                'from_pack_id' => null,
                'to_pack_id' => $packId,
                'status' => 'success',
                'message' => null,
                'created_by' => 'test',
                'manifest_hash' => null,
                'compiled_hash' => null,
                'content_hash' => null,
                'norms_version' => null,
                'git_sha' => 'test-sha',
                'pack_version' => $packVersion,
                'manifest_json' => null,
                'storage_path' => $storagePath,
                'source_commit' => 'test-sha',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        return $versionId;
    }

    private function insertV2Release(string $releaseId, string $packId, string $packVersion, string $storagePath, string $manifestHash): void
    {
        DB::table('content_pack_releases')->updateOrInsert(
            ['id' => $releaseId],
            [
                'action' => 'packs2_publish',
                'region' => 'GLOBAL',
                'locale' => 'global',
                'dir_alias' => $packVersion,
                'from_version_id' => null,
                'to_version_id' => null,
                'from_pack_id' => null,
                'to_pack_id' => $packId,
                'status' => 'success',
                'message' => null,
                'created_by' => 'test',
                'manifest_hash' => $manifestHash,
                'compiled_hash' => $manifestHash,
                'content_hash' => null,
                'norms_version' => null,
                'git_sha' => 'test-sha',
                'pack_version' => $packVersion,
                'manifest_json' => null,
                'storage_path' => $storagePath,
                'source_commit' => 'test-sha',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    /**
     * @param  array<string,string>  $files
     */
    private function assertRootMatchesExactAuthority(string $root, array $files, string $manifestHash): void
    {
        $actualPaths = collect(File::allFiles($root))
            ->map(fn (\SplFileInfo $file): string => str_replace('\\', '/', ltrim(substr($file->getPathname(), strlen($root)), '/\\')))
            ->sort()
            ->values()
            ->all();

        $expectedPaths = array_keys($files);
        sort($expectedPaths);
        $this->assertSame($expectedPaths, $actualPaths);

        foreach ($files as $logicalPath => $payload) {
            $absolutePath = $root.'/'.$logicalPath;
            $this->assertFileExists($absolutePath);
            $this->assertSame(hash('sha256', $payload), hash_file('sha256', $absolutePath));
            $this->assertSame(strlen($payload), filesize($absolutePath));
        }

        $this->assertSame($manifestHash, hash_file('sha256', $root.'/compiled/manifest.json'));
    }
}
