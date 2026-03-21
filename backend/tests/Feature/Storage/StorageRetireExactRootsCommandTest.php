<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Console\Commands\StorageRetireExactRoots;
use App\Services\Storage\ExactReleaseFileSetCatalogService;
use App\Services\Storage\ExactRootQuarantineService;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StorageRetireExactRootsCommandTest extends TestCase
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
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-retirement-command-'.Str::uuid();
        $this->isolatedPacksRoot = sys_get_temp_dir().'/fap-retirement-command-packs-'.Str::uuid();
        File::ensureDirectoryExists($this->isolatedStoragePath.'/app/private');
        File::ensureDirectoryExists($this->isolatedPacksRoot);

        $this->app->useStoragePath($this->isolatedStoragePath);
        config()->set('filesystems.disks.local.root', $this->isolatedStoragePath.'/app/private');
        config()->set('content_packs.root', $this->isolatedPacksRoot);
        config()->set('storage_rollout.blob_offload_disk', 's3');
        config()->set('filesystems.disks.s3.bucket', 'retirement-command-bucket');
        config()->set('filesystems.disks.s3.region', 'ap-guangzhou');
        config()->set('filesystems.disks.s3.endpoint', 'https://cos.retirement-command.test');
        Storage::forgetDisk('local');
        Storage::fake('s3');

        $this->app->make(ConsoleKernel::class)->registerCommand(
            $this->app->make(StorageRetireExactRoots::class)
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

    public function test_command_plans_quarantine_batch_and_records_audit_without_mutating_roots(): void
    {
        $fixture = $this->seedLegacyExactRootFixture('command_retire_quarantine');

        $this->assertSame(0, Artisan::call('storage:retire-exact-roots', [
            '--dry-run' => true,
            '--action' => 'quarantine',
            '--disk' => 's3',
        ]));

        $output = Artisan::output();
        $this->assertStringContainsString('status=planned', $output);
        $this->assertStringContainsString('action=quarantine', $output);
        $this->assertStringContainsString('candidate_count=1', $output);

        $planPath = $this->extractOutputValue($output, 'plan');
        $this->assertFileExists($planPath);
        $this->assertDirectoryExists($fixture['source_root']);

        $audit = DB::table('audit_logs')
            ->where('action', 'storage_retire_exact_roots')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($audit);
        $auditMeta = json_decode((string) ($audit->meta_json ?? '{}'), true);
        $this->assertIsArray($auditMeta);
        $this->assertSame('planned', (string) ($auditMeta['mode'] ?? ''));
        $this->assertSame($planPath, (string) ($auditMeta['plan_path'] ?? ''));
        $this->assertDirectoryDoesNotExist(storage_path('app/private/blobs'));
    }

    public function test_command_executes_purge_batch_from_generated_plan_and_writes_run_metadata(): void
    {
        $fixture = $this->quarantineLegacyFixture('command_retire_purge');

        $this->assertSame(0, Artisan::call('storage:retire-exact-roots', [
            '--dry-run' => true,
            '--action' => 'purge',
            '--disk' => 's3',
        ]));

        $dryRunOutput = Artisan::output();
        $this->assertStringContainsString('status=planned', $dryRunOutput);
        $this->assertStringContainsString('action=purge', $dryRunOutput);
        $planPath = $this->extractOutputValue($dryRunOutput, 'plan');
        $this->assertFileExists($planPath);

        $releaseStoragePathBefore = DB::table('content_pack_releases')
            ->where('id', $fixture['release_id'])
            ->value('storage_path');

        $this->assertSame(0, Artisan::call('storage:retire-exact-roots', [
            '--execute' => true,
            '--action' => 'purge',
            '--disk' => 's3',
            '--plan' => $planPath,
        ]));

        $executeOutput = Artisan::output();
        $this->assertStringContainsString('action=purge', $executeOutput);
        $this->assertStringContainsString('success_count=1', $executeOutput);
        $runDir = $this->extractOutputValue($executeOutput, 'run_dir');
        $this->assertFileExists($runDir.'/run.json');
        $this->assertDirectoryDoesNotExist($fixture['item_root']);

        $run = json_decode((string) File::get($runDir.'/run.json'), true);
        $this->assertIsArray($run);
        $resultEntry = collect((array) ($run['results'] ?? []))->firstWhere('item_root', $fixture['item_root']);
        $this->assertIsArray($resultEntry);
        $this->assertFileExists((string) ($resultEntry['receipt_path'] ?? ''));
        $this->assertSame($releaseStoragePathBefore, DB::table('content_pack_releases')
            ->where('id', $fixture['release_id'])
            ->value('storage_path'));

        $audit = DB::table('audit_logs')
            ->where('action', 'storage_retire_exact_roots')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($audit);
        $auditMeta = json_decode((string) ($audit->meta_json ?? '{}'), true);
        $this->assertIsArray($auditMeta);
        $this->assertSame('executed', (string) ($auditMeta['mode'] ?? ''));
        $this->assertSame($runDir, (string) ($auditMeta['result']['run_dir'] ?? ''));
        $this->assertDirectoryDoesNotExist(storage_path('app/private/blobs'));
    }

    public function test_command_rejects_mixed_action_plan_during_execute(): void
    {
        $this->seedLegacyExactRootFixture('command_retire_mixed_action');

        $this->assertSame(0, Artisan::call('storage:retire-exact-roots', [
            '--dry-run' => true,
            '--action' => 'quarantine',
            '--disk' => 's3',
        ]));
        $planPath = $this->extractOutputValue(Artisan::output(), 'plan');

        $plan = json_decode((string) File::get($planPath), true);
        $this->assertIsArray($plan);
        $plan['candidates'][0]['action'] = 'purge';
        File::put($planPath, json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT).PHP_EOL);

        $this->assertSame(1, Artisan::call('storage:retire-exact-roots', [
            '--execute' => true,
            '--action' => 'quarantine',
            '--disk' => 's3',
            '--plan' => $planPath,
        ]));

        $this->assertStringContainsString('mixed_action_plan_not_executable', Artisan::output());
    }

    /**
     * @return array{release_id:string,source_root:string}
     */
    private function seedLegacyExactRootFixture(string $suffix): array
    {
        $releaseId = (string) Str::uuid();
        $sourceRoot = storage_path('app/private/content_releases/'.Str::uuid().'/source_pack');
        $files = $this->createCompiledTree($sourceRoot, 'BIG5_OCEAN', 'v1', $suffix);
        $this->insertRelease($releaseId, 'BIG5_OCEAN', 'v1', $sourceRoot);

        app(ExactReleaseFileSetCatalogService::class)->upsertExactManifest([
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
        $itemRoot = (string) data_get($result, 'quarantined.0.target_root', '');

        return [
            'item_root' => $itemRoot,
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
                    'bucket' => 'retirement-command-bucket',
                    'region' => 'ap-guangzhou',
                    'endpoint' => 'https://cos.retirement-command.test',
                    'url' => 'https://cos.retirement-command.test/'.$remotePath,
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

    private function extractOutputValue(string $output, string $key): string
    {
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            if (str_starts_with($line, $key.'=')) {
                return trim(substr($line, strlen($key) + 1));
            }
        }

        $this->fail('output key not found: '.$key."\n".$output);
    }
}
