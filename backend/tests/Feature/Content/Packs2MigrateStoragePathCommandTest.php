<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\Content\ContentPackV2Resolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

final class Packs2MigrateStoragePathCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $releaseId;

    private string $manifestHash;

    private string $oldStoragePath;

    private string $newStoragePath;

    private string $oldCompiledDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->releaseId = (string) Str::uuid();
        $this->manifestHash = 'hash_'.substr(str_replace('-', '', (string) Str::uuid()), 0, 16);
        $this->oldStoragePath = 'content_packs_v2/BIG5_OCEAN/v1/'.$this->releaseId;
        $this->newStoragePath = 'private/packs_v2/BIG5_OCEAN/v1/'.$this->manifestHash;
        $this->oldCompiledDir = storage_path('app/'.$this->oldStoragePath.'/compiled');

        File::ensureDirectoryExists($this->oldCompiledDir);
        File::put(
            $this->oldCompiledDir.'/manifest.json',
            json_encode([
                'pack_id' => 'BIG5_OCEAN',
                'version' => 'v1',
                'compiled_hash' => $this->manifestHash,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        File::put(
            $this->oldCompiledDir.'/questions.compiled.json',
            json_encode([
                'question_index' => [],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        DB::table('content_pack_releases')->insert([
            'id' => $this->releaseId,
            'action' => 'packs2_publish',
            'region' => 'GLOBAL',
            'locale' => 'global',
            'dir_alias' => 'v1',
            'from_version_id' => null,
            'to_version_id' => null,
            'from_pack_id' => null,
            'to_pack_id' => 'BIG5_OCEAN',
            'status' => 'success',
            'message' => 'seed release',
            'created_by' => 'test',
            'manifest_hash' => $this->manifestHash,
            'compiled_hash' => $this->manifestHash,
            'content_hash' => $this->manifestHash,
            'norms_version' => 'v1',
            'git_sha' => null,
            'pack_version' => 'v1',
            'manifest_json' => json_encode(['compiled_hash' => $this->manifestHash], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'storage_path' => $this->oldStoragePath,
            'source_commit' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        $dirs = [
            storage_path('app/'.$this->oldStoragePath),
            storage_path('app/'.$this->newStoragePath),
        ];

        foreach ($dirs as $dir) {
            if (is_dir($dir)) {
                File::deleteDirectory($dir);
            }
        }

        parent::tearDown();
    }

    public function test_migrate_storage_path_updates_release_and_resolver_reads_with_legacy_fallback(): void
    {
        $this->artisan('packs2:migrate-storage-path --dry-run')
            ->assertExitCode(0);

        $this->assertSame(
            $this->oldStoragePath,
            (string) DB::table('content_pack_releases')->where('id', $this->releaseId)->value('storage_path')
        );

        $this->artisan('packs2:migrate-storage-path --execute')
            ->assertExitCode(0);

        $this->assertSame(
            $this->newStoragePath,
            (string) DB::table('content_pack_releases')->where('id', $this->releaseId)->value('storage_path')
        );
        $this->assertFileExists(storage_path('app/'.$this->newStoragePath.'/compiled/manifest.json'));

        $auditCount = DB::table('audit_logs')
            ->where('action', 'packs2_storage_path_migrate')
            ->where('target_id', $this->releaseId)
            ->count();
        $this->assertGreaterThanOrEqual(1, $auditCount);

        DB::table('content_pack_activations')->insert([
            'pack_id' => 'BIG5_OCEAN',
            'pack_version' => 'v1',
            'release_id' => $this->releaseId,
            'activated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var ContentPackV2Resolver $resolver */
        $resolver = app(ContentPackV2Resolver::class);
        $compiledPath = $resolver->resolveActiveCompiledPath('BIG5_OCEAN', 'v1');
        $this->assertSame(storage_path('app/'.$this->newStoragePath.'/compiled'), $compiledPath);
        $this->assertFileExists((string) $compiledPath.'/manifest.json');

        File::deleteDirectory(storage_path('app/'.$this->newStoragePath));

        $fallbackPath = $resolver->resolveActiveCompiledPath('BIG5_OCEAN', 'v1');
        $this->assertSame($this->oldCompiledDir, $fallbackPath);
        $this->assertFileExists((string) $fallbackPath.'/manifest.json');
    }
}
