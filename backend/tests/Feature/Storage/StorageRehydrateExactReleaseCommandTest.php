<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Services\Storage\ExactReleaseFileSetCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StorageRehydrateExactReleaseCommandTest extends TestCase
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
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-storage-rehydrate-command-'.Str::uuid();
        File::ensureDirectoryExists($this->isolatedStoragePath.'/app/private');
        $this->app->useStoragePath($this->isolatedStoragePath);
        config()->set('filesystems.disks.local.root', $this->isolatedStoragePath.'/app/private');
        config()->set('storage_rollout.blob_offload_disk', 's3');
        config()->set('filesystems.disks.s3.bucket', 'rehydrate-command-bucket');
        config()->set('filesystems.disks.s3.region', 'ap-guangzhou');
        config()->set('filesystems.disks.s3.endpoint', 'https://cos.rehydrate-command.test');
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

    public function test_command_plans_and_executes_rehydrate_from_exact_manifest_id(): void
    {
        $releaseId = (string) Str::uuid();
        $fixture = $this->seedExactManifestFixture($releaseId, 'legacy.source_pack', 'command_success');

        $this->assertSame(0, Artisan::call('storage:rehydrate-exact-release', [
            '--dry-run' => true,
            '--exact-manifest-id' => (string) $fixture['exact_manifest_id'],
            '--disk' => 's3',
        ]));
        $dryRunOutput = Artisan::output();
        $this->assertStringContainsString('status=planned', $dryRunOutput);
        $this->assertStringContainsString('exact_manifest_id='.$fixture['exact_manifest_id'], $dryRunOutput);
        $this->assertStringContainsString('disk=s3', $dryRunOutput);
        $this->assertStringContainsString('file_count=3', $dryRunOutput);
        $this->assertStringContainsString('missing_locations=0', $dryRunOutput);

        $planPath = $this->extractOutputValue($dryRunOutput, 'plan');
        $this->assertFileExists($planPath);
        $plan = json_decode((string) file_get_contents($planPath), true);
        $this->assertIsArray($plan);
        $this->assertSame('storage_rehydrate_exact_release_plan.v1', (string) ($plan['schema'] ?? ''));

        $this->assertSame(0, Artisan::call('storage:rehydrate-exact-release', [
            '--execute' => true,
            '--exact-manifest-id' => (string) $fixture['exact_manifest_id'],
            '--disk' => 's3',
        ]));
        $executeOutput = Artisan::output();
        $this->assertStringContainsString('status=executed', $executeOutput);
        $this->assertStringContainsString('exact_manifest_id='.$fixture['exact_manifest_id'], $executeOutput);
        $this->assertStringContainsString('rehydrated_files=3', $executeOutput);
        $this->assertStringContainsString('verified_files=3', $executeOutput);

        $runDir = $this->extractOutputValue($executeOutput, 'run_dir');
        $this->assertDirectoryExists($runDir);
        $this->assertFileExists($runDir.'/.rehydrate.json');
        $this->assertFileExists($runDir.'/compiled/manifest.json');
        $this->assertFileExists($runDir.'/compiled/payload.compiled.json');
        $this->assertFileExists($runDir.'/compiled/nested/cards.compiled.json');
        $this->assertDirectoryDoesNotExist(storage_path('app/private/blobs'));

        $audit = DB::table('audit_logs')
            ->where('action', 'storage_rehydrate_exact_release')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($audit);
        $auditMeta = json_decode((string) ($audit->meta_json ?? '{}'), true);
        $this->assertIsArray($auditMeta);
        $this->assertSame('executed', (string) ($auditMeta['mode'] ?? ''));
        $this->assertSame($fixture['exact_manifest_id'], (int) ($auditMeta['plan']['exact_manifest']['id'] ?? 0));
        $this->assertSame($runDir, (string) ($auditMeta['result']['run_dir'] ?? ''));
    }

    public function test_command_fails_fast_when_release_id_maps_to_multiple_exact_manifests(): void
    {
        $releaseId = (string) Str::uuid();
        $this->seedExactManifestFixture($releaseId, 'legacy.source_pack', 'command_ambiguous_a');
        $this->seedExactManifestFixture($releaseId, 'legacy.current_pack', 'command_ambiguous_b', false);

        $this->assertSame(1, Artisan::call('storage:rehydrate-exact-release', [
            '--dry-run' => true,
            '--release-id' => $releaseId,
            '--disk' => 's3',
        ]));
        $output = Artisan::output();
        $this->assertStringContainsString('multiple exact manifests found for release', $output);

        $this->assertDirectoryDoesNotExist(storage_path('app/private/rehydrate_runs'));
    }

    public function test_command_can_execute_via_release_id_when_it_maps_to_one_exact_manifest(): void
    {
        $releaseId = (string) Str::uuid();
        $fixture = $this->seedExactManifestFixture($releaseId, 'v2.mirror', 'command_release_id_success');

        $this->assertSame(0, Artisan::call('storage:rehydrate-exact-release', [
            '--execute' => true,
            '--release-id' => $releaseId,
            '--disk' => 's3',
        ]));

        $output = Artisan::output();
        $this->assertStringContainsString('status=executed', $output);
        $this->assertStringContainsString('exact_manifest_id='.$fixture['exact_manifest_id'], $output);
        $this->assertStringContainsString('rehydrated_files=3', $output);

        $runDir = $this->extractOutputValue($output, 'run_dir');
        $this->assertDirectoryExists($runDir);
        $this->assertFileExists($runDir.'/.rehydrate.json');
    }

    public function test_command_rejects_dangerous_target_root(): void
    {
        $releaseId = (string) Str::uuid();
        $fixture = $this->seedExactManifestFixture($releaseId, 'legacy.source_pack', 'command_bad_target');

        $this->assertSame(1, Artisan::call('storage:rehydrate-exact-release', [
            '--dry-run' => true,
            '--exact-manifest-id' => (string) $fixture['exact_manifest_id'],
            '--disk' => 's3',
            '--target-root' => storage_path('app/private/content_releases'),
        ]));

        $output = Artisan::output();
        $this->assertStringContainsString('unsafe target root for rehydrate runs', $output);
        $this->assertDirectoryDoesNotExist(storage_path('app/private/rehydrate_plans'));
    }

    /**
     * @return array{exact_manifest_id:int}
     */
    private function seedExactManifestFixture(string $releaseId, string $sourceKind, string $suffix, bool $withLocations = true): array
    {
        if (! DB::table('content_pack_releases')->where('id', $releaseId)->exists()) {
            $this->insertRelease($releaseId, 'BIG5_OCEAN', 'v1');
        }

        $files = [
            'compiled/manifest.json' => $this->manifestBytes('BIG5_OCEAN', 'v1', $suffix),
            'compiled/payload.compiled.json' => json_encode(['suffix' => $suffix, 'kind' => 'payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            'compiled/nested/cards.compiled.json' => json_encode(['suffix' => $suffix, 'kind' => 'cards'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        ];

        $manifest = app(ExactReleaseFileSetCatalogService::class)->upsertExactManifest([
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

            if (! $withLocations) {
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
                    'bucket' => 'rehydrate-command-bucket',
                    'region' => 'ap-guangzhou',
                    'endpoint' => 'https://cos.rehydrate-command.test',
                    'url' => 'https://cos.rehydrate-command.test/'.$remotePath,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return ['exact_manifest_id' => (int) $manifest->getKey()];
    }

    private function insertRelease(string $releaseId, string $packId, string $packVersion): void
    {
        DB::table('content_pack_releases')->insert([
            'id' => $releaseId,
            'action' => 'publish',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'dir_alias' => 'EXACT-REHYDRATE-CMD',
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

    private function extractOutputValue(string $output, string $key): string
    {
        foreach (preg_split('/\r\n|\r|\n/', trim($output)) ?: [] as $line) {
            if (str_starts_with($line, $key.'=')) {
                return substr($line, strlen($key) + 1);
            }
        }

        $this->fail('unable to extract '.$key.' from output: '.$output);
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
