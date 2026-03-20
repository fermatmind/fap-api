<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Console\Commands\StorageQuarantineExactRoots;
use App\Console\Commands\StorageRestoreQuarantinedRoot;
use App\Services\Storage\ExactReleaseFileSetCatalogService;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StorageRestoreQuarantinedRootCommandTest extends TestCase
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
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-restore-command-'.Str::uuid();
        $this->isolatedPacksRoot = sys_get_temp_dir().'/fap-restore-command-packs-'.Str::uuid();
        File::ensureDirectoryExists($this->isolatedStoragePath.'/app/private');
        File::ensureDirectoryExists($this->isolatedPacksRoot);

        $this->app->useStoragePath($this->isolatedStoragePath);
        config()->set('filesystems.disks.local.root', $this->isolatedStoragePath.'/app/private');
        config()->set('content_packs.root', $this->isolatedPacksRoot);
        config()->set('storage_rollout.blob_offload_disk', 's3');
        config()->set('filesystems.disks.s3.bucket', 'restore-command-bucket');
        config()->set('filesystems.disks.s3.region', 'ap-guangzhou');
        config()->set('filesystems.disks.s3.endpoint', 'https://cos.restore-command.test');
        Storage::forgetDisk('local');
        Storage::fake('s3');
        $kernel = $this->app->make(ConsoleKernel::class);
        $kernel->registerCommand($this->app->make(StorageQuarantineExactRoots::class));
        $kernel->registerCommand($this->app->make(StorageRestoreQuarantinedRoot::class));
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

    public function test_command_plans_and_executes_restore_for_valid_quarantined_root(): void
    {
        $fixture = $this->quarantineLegacySourcePackFixture('command_restore_success');

        $this->assertSame(0, Artisan::call('storage:restore-quarantined-root', [
            '--dry-run' => true,
            '--item-root' => $fixture['item_root'],
        ]));

        $dryRunOutput = Artisan::output();
        $this->assertStringContainsString('status=planned', $dryRunOutput);
        $this->assertStringContainsString('source_kind=legacy.source_pack', $dryRunOutput);
        $planPath = $this->extractOutputValue($dryRunOutput, 'plan');
        $this->assertFileExists($planPath);
        $this->assertDirectoryExists($fixture['item_root']);

        $releaseStoragePathBefore = DB::table('content_pack_releases')
            ->where('id', $fixture['release_id'])
            ->value('storage_path');

        $this->assertSame(0, Artisan::call('storage:restore-quarantined-root', [
            '--execute' => true,
            '--plan' => $planPath,
        ]));

        $executeOutput = Artisan::output();
        $this->assertStringContainsString('status=success', $executeOutput);
        $runDir = $this->extractOutputValue($executeOutput, 'run_dir');
        $this->assertDirectoryExists($runDir);
        $this->assertFileExists($runDir.'/run.json');
        $this->assertDirectoryExists($fixture['source_root']);
        $this->assertDirectoryDoesNotExist($fixture['item_root']);
        $this->assertFileDoesNotExist($fixture['source_root'].'/.quarantine.json');
        $this->assertSame($releaseStoragePathBefore, DB::table('content_pack_releases')
            ->where('id', $fixture['release_id'])
            ->value('storage_path'));

        $audit = DB::table('audit_logs')
            ->where('action', 'storage_restore_quarantined_root')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($audit);
        $auditMeta = json_decode((string) ($audit->meta_json ?? '{}'), true);
        $this->assertIsArray($auditMeta);
        $this->assertSame('executed', (string) ($auditMeta['mode'] ?? ''));
        $this->assertSame($runDir, (string) ($auditMeta['result']['run_dir'] ?? ''));
        $this->assertDirectoryDoesNotExist(storage_path('app/private/blobs'));
    }

    public function test_command_fails_when_plan_is_tampered_or_item_root_mismatches(): void
    {
        $fixture = $this->quarantineLegacySourcePackFixture('command_restore_tampered');

        $this->assertSame(0, Artisan::call('storage:restore-quarantined-root', [
            '--dry-run' => true,
            '--item-root' => $fixture['item_root'],
        ]));
        $planPath = $this->extractOutputValue(Artisan::output(), 'plan');

        $plan = json_decode((string) File::get($planPath), true);
        $this->assertIsArray($plan);
        $plan['target_root'] = storage_path('app/private/content_releases/'.Str::uuid().'/source_pack');
        File::put($planPath, json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT).PHP_EOL);

        $this->assertSame(1, Artisan::call('storage:restore-quarantined-root', [
            '--execute' => true,
            '--plan' => $planPath,
        ]));
        $this->assertStringContainsString('restore_plan_mismatch', Artisan::output());
        $this->assertDirectoryExists($fixture['item_root']);
        $this->assertDirectoryDoesNotExist($fixture['source_root']);

        $otherFixture = $this->quarantineLegacySourcePackFixture('command_restore_item_root_mismatch');
        $this->assertSame(1, Artisan::call('storage:restore-quarantined-root', [
            '--execute' => true,
            '--plan' => $planPath,
            '--item-root' => $otherFixture['item_root'],
        ]));
        $this->assertStringContainsString('restore plan item_root does not match requested item root.', Artisan::output());
    }

    public function test_command_dry_run_fails_when_restore_target_shape_is_not_standard_legacy_source_pack(): void
    {
        $fixture = $this->quarantineLegacySourcePackFixture(
            'command_restore_invalid_shape',
            storage_path('app/private/content_releases/'.Str::uuid().'/nested/source_pack')
        );

        $this->assertSame(1, Artisan::call('storage:restore-quarantined-root', [
            '--dry-run' => true,
            '--item-root' => $fixture['item_root'],
        ]));
        $this->assertStringContainsString(
            'restore target must match legacy content_releases/{release_id}/source_pack shape.',
            Artisan::output()
        );
        $this->assertDirectoryExists($fixture['item_root']);
        $this->assertDirectoryDoesNotExist($fixture['source_root']);
    }

    /**
     * @return array{item_root:string,source_root:string,release_id:string}
     */
    private function quarantineLegacySourcePackFixture(string $suffix, ?string $root = null): array
    {
        $releaseId = (string) Str::uuid();
        $root ??= storage_path('app/private/content_releases/'.Str::uuid().'/source_pack');
        $files = $this->createCompiledTree($root, 'BIG5_OCEAN', 'v1', $suffix);
        $this->insertRelease($releaseId, 'BIG5_OCEAN', 'v1', $root);

        app(ExactReleaseFileSetCatalogService::class)->upsertExactManifest([
            'content_pack_release_id' => $releaseId,
            'schema_version' => (string) config('storage_rollout.exact_manifest_schema_version', 'storage_exact_manifest.v1'),
            'source_kind' => 'legacy.source_pack',
            'source_disk' => 'local',
            'source_storage_path' => $root,
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
                    'bucket' => 'restore-command-bucket',
                    'region' => 'ap-guangzhou',
                    'endpoint' => 'https://cos.restore-command.test',
                    'url' => 'https://cos.restore-command.test/'.$remotePath,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->assertSame(0, Artisan::call('storage:quarantine-exact-roots', [
            '--dry-run' => true,
            '--disk' => 's3',
        ]));
        $planPath = $this->extractOutputValue(Artisan::output(), 'plan');
        $this->assertSame(0, Artisan::call('storage:quarantine-exact-roots', [
            '--execute' => true,
            '--disk' => 's3',
            '--plan' => $planPath,
        ]));

        $runDir = $this->extractOutputValue(Artisan::output(), 'run_dir');
        $itemRoots = glob($runDir.'/items/*/root') ?: [];
        $this->assertCount(1, $itemRoots);

        return [
            'item_root' => (string) $itemRoots[0],
            'source_root' => $root,
            'release_id' => $releaseId,
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
            'schema' => 'storage.restore.command.test.v1',
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
                'action' => 'publish',
                'region' => 'CN_MAINLAND',
                'locale' => 'zh-CN',
                'dir_alias' => $packVersion,
                'from_version_id' => null,
                'to_version_id' => (string) Str::uuid(),
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
    }

    private function extractOutputValue(string $output, string $key): string
    {
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            if (str_starts_with($line, $key.'=')) {
                return substr($line, strlen($key) + 1);
            }
        }

        $this->fail('missing output key: '.$key."\n".$output);
    }
}
