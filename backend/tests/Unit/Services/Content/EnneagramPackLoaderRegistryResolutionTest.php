<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Content;

use App\Services\Content\EnneagramPackLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EnneagramPackLoaderRegistryResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_loader_registry_root_matches_existing_repo_path_without_active_release(): void
    {
        $loader = app(EnneagramPackLoader::class);

        $this->assertSame(base_path('content_packs/ENNEAGRAM/v2/registry'), $loader->registryRoot());
        $this->assertSame(
            'enneagram_registry_pack_v1_p0_ready_2026_04',
            data_get($loader->loadRegistryManifest(), 'release_id')
        );
    }

    public function test_loader_reads_alternate_registry_only_when_release_is_explicitly_active(): void
    {
        $loader = app(EnneagramPackLoader::class);
        $releaseId = (string) Str::uuid();
        $fixtureRoot = $this->makeRegistryFixture('test_active_registry_release');

        DB::table('content_pack_releases')->insert($this->releaseRow($releaseId, $fixtureRoot));
        DB::table('content_pack_activations')->updateOrInsert(
            ['pack_id' => 'ENNEAGRAM', 'pack_version' => 'v2'],
            [
                'release_id' => $releaseId,
                'activated_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $pack = $loader->loadRegistryPack();

        $this->assertSame($fixtureRoot, $loader->registryRoot());
        $this->assertSame($fixtureRoot, $pack['root']);
        $this->assertSame('test_active_registry_release', data_get($pack, 'manifest.release_id'));
        $this->assertStringStartsWith('sha256:', (string) $pack['release_hash']);
    }

    private function makeRegistryFixture(string $releaseId): string
    {
        $root = storage_path('framework/testing/enneagram_pack_loader_registry/'.$releaseId);
        File::deleteDirectory($root);
        File::ensureDirectoryExists(dirname($root));
        File::copyDirectory(base_path('content_packs/ENNEAGRAM/v2/registry'), $root);

        $manifestPath = $root.'/manifest.json';
        $manifest = json_decode((string) File::get($manifestPath), true);
        $manifest['release_id'] = $releaseId;
        File::put($manifestPath, json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        return $root;
    }

    /**
     * @return array<string,mixed>
     */
    private function releaseRow(string $releaseId, string $storagePath): array
    {
        $manifestHash = 'sha256:'.hash('sha256', $releaseId);

        return [
            'id' => $releaseId,
            'action' => 'enneagram_registry_publish',
            'region' => 'GLOBAL',
            'locale' => 'global',
            'dir_alias' => 'v2',
            'from_version_id' => null,
            'to_version_id' => null,
            'from_pack_id' => null,
            'to_pack_id' => 'ENNEAGRAM',
            'status' => 'success',
            'message' => 'test',
            'created_by' => 'test',
            'manifest_hash' => $manifestHash,
            'compiled_hash' => $manifestHash,
            'content_hash' => $manifestHash,
            'norms_version' => null,
            'git_sha' => null,
            'pack_version' => 'v2',
            'manifest_json' => json_encode(['release_id' => $releaseId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'storage_path' => $storagePath,
            'source_commit' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
