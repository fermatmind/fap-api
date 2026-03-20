<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Console\Commands\StorageQuarantineExactRoots;
use App\Services\Storage\ExactReleaseFileSetCatalogService;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StorageQuarantineExactRootsCommandTest extends TestCase
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
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-storage-quarantine-command-'.Str::uuid();
        $this->isolatedPacksRoot = sys_get_temp_dir().'/fap-packs-quarantine-command-'.Str::uuid();
        File::ensureDirectoryExists($this->isolatedStoragePath.'/app/private');
        File::ensureDirectoryExists($this->isolatedPacksRoot);

        $this->app->useStoragePath($this->isolatedStoragePath);
        config()->set('filesystems.disks.local.root', $this->isolatedStoragePath.'/app/private');
        config()->set('content_packs.root', $this->isolatedPacksRoot);
        config()->set('storage_rollout.blob_offload_disk', 's3');
        config()->set('filesystems.disks.s3.bucket', 'quarantine-command-bucket');
        config()->set('filesystems.disks.s3.region', 'ap-guangzhou');
        config()->set('filesystems.disks.s3.endpoint', 'https://cos.quarantine-command.test');
        Storage::forgetDisk('local');
        Storage::fake('s3');
        $this->app->make(ConsoleKernel::class)->registerCommand(
            $this->app->make(StorageQuarantineExactRoots::class)
        );
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

    public function test_command_plans_and_executes_quarantine_from_generated_plan(): void
    {
        $releaseId = (string) Str::uuid();
        $root = storage_path('app/private/content_releases/'.Str::uuid().'/source_pack');
        $this->seedExactRootFixture($releaseId, 'legacy.source_pack', $root, 'command_quarantine_success', 'BIG5_OCEAN', 'v1');

        $blockedRoot = storage_path('app/private/content_releases/backups/'.Str::uuid().'/previous_pack');
        $this->seedExactRootFixture((string) Str::uuid(), 'legacy.previous_pack', $blockedRoot, 'command_blocked_previous', 'BIG5_OCEAN', 'v1');

        $this->assertSame(0, Artisan::call('storage:quarantine-exact-roots', [
            '--dry-run' => true,
            '--disk' => 's3',
        ]));

        $dryRunOutput = Artisan::output();
        $this->assertStringContainsString('status=planned', $dryRunOutput);
        $this->assertStringContainsString('disk=s3', $dryRunOutput);
        $this->assertStringContainsString('candidate_count=1', $dryRunOutput);
        $this->assertStringContainsString('blocked_count=1', $dryRunOutput);

        $planPath = $this->extractOutputValue($dryRunOutput, 'plan');
        $this->assertFileExists($planPath);
        $this->assertDirectoryExists($root);

        $releaseStoragePathBefore = DB::table('content_pack_releases')
            ->where('id', $releaseId)
            ->value('storage_path');

        $this->assertSame(0, Artisan::call('storage:quarantine-exact-roots', [
            '--execute' => true,
            '--disk' => 's3',
            '--plan' => $planPath,
        ]));

        $executeOutput = Artisan::output();
        $this->assertStringContainsString('status=executed', $executeOutput);
        $this->assertStringContainsString('quarantined_root_count=1', $executeOutput);
        $this->assertStringContainsString('failed_root_count=0', $executeOutput);

        $runDir = $this->extractOutputValue($executeOutput, 'run_dir');
        $this->assertDirectoryExists($runDir);
        $this->assertFileExists($runDir.'/run.json');
        $this->assertDirectoryDoesNotExist($root);
        $this->assertDirectoryExists($blockedRoot);

        $quarantineSentinels = glob($runDir.'/items/*/root/.quarantine.json') ?: [];
        $this->assertCount(1, $quarantineSentinels);
        $this->assertFileExists($quarantineSentinels[0]);

        $audit = DB::table('audit_logs')
            ->where('action', 'storage_quarantine_exact_roots')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($audit);
        $auditMeta = json_decode((string) ($audit->meta_json ?? '{}'), true);
        $this->assertIsArray($auditMeta);
        $this->assertSame('executed', (string) ($auditMeta['mode'] ?? ''));
        $this->assertSame($runDir, (string) ($auditMeta['result']['run_dir'] ?? ''));

        $this->assertSame($releaseStoragePathBefore, DB::table('content_pack_releases')
            ->where('id', $releaseId)
            ->value('storage_path'));
        $this->assertDirectoryDoesNotExist(storage_path('app/private/blobs'));
    }

    public function test_command_fails_when_execute_plan_becomes_stale(): void
    {
        $releaseId = (string) Str::uuid();
        $root = storage_path('app/private/content_releases/'.Str::uuid().'/source_pack');
        $fixture = $this->seedExactRootFixture($releaseId, 'legacy.source_pack', $root, 'command_quarantine_stale', 'BIG5_OCEAN', 'v1');

        $this->assertSame(0, Artisan::call('storage:quarantine-exact-roots', [
            '--dry-run' => true,
            '--disk' => 's3',
        ]));
        $planPath = $this->extractOutputValue(Artisan::output(), 'plan');
        $this->assertFileExists($planPath);

        DB::table('storage_blob_locations')
            ->where('blob_hash', $fixture['manifest_blob_hash'])
            ->where('disk', 's3')
            ->delete();

        $this->assertSame(1, Artisan::call('storage:quarantine-exact-roots', [
            '--execute' => true,
            '--disk' => 's3',
            '--plan' => $planPath,
        ]));

        $output = Artisan::output();
        $this->assertStringContainsString('status=failure', $output);
        $this->assertStringContainsString('failed_root_count=1', $output);
        $this->assertDirectoryExists($root);
    }

    /**
     * @return array{manifest_blob_hash:string}
     */
    private function seedExactRootFixture(
        string $releaseId,
        string $sourceKind,
        string $root,
        string $suffix,
        string $packId,
        string $packVersion,
    ): array {
        $files = $this->createCompiledTree($root, $packId, $packVersion, $suffix);
        $this->insertRelease($releaseId, $packId, $packVersion, $root);

        app(ExactReleaseFileSetCatalogService::class)->upsertExactManifest([
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
                    'bucket' => 'quarantine-command-bucket',
                    'region' => 'ap-guangzhou',
                    'endpoint' => 'https://cos.quarantine-command.test',
                    'url' => 'https://cos.quarantine-command.test/'.$remotePath,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [
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
            'schema' => 'storage.quarantine.command.test.v1',
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

    private function extractOutputValue(string $output, string $key): string
    {
        preg_match('/^'.preg_quote($key, '/').'=(.+)$/m', $output, $matches);

        return trim((string) ($matches[1] ?? ''));
    }
}
