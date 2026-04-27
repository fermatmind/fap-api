<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Content;

use App\Services\Content\EnneagramRegistryReleaseResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EnneagramRegistryReleaseResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolver_defaults_to_repo_registry_path_when_no_active_release_exists(): void
    {
        $resolver = app(EnneagramRegistryReleaseResolver::class);
        $context = $resolver->runtimeRegistryContext();

        $this->assertSame(base_path('content_packs/ENNEAGRAM/v2/registry'), $resolver->repoFallbackRegistryRoot());
        $this->assertSame(base_path('content_packs/ENNEAGRAM/v2/registry'), $resolver->runtimeRegistryRoot());
        $this->assertSame('repo_fallback', $context['source']);
        $this->assertNull($context['active_release_id']);
        $this->assertNull($context['active_storage_path']);
    }

    public function test_inactive_release_metadata_does_not_affect_runtime_root(): void
    {
        $resolver = app(EnneagramRegistryReleaseResolver::class);
        $fixtureRoot = $this->makeRegistryFixture('inactive_release_only');

        DB::table('content_pack_releases')->insert($this->releaseRow((string) Str::uuid(), $fixtureRoot));

        $context = $resolver->runtimeRegistryContext();

        $this->assertSame(base_path('content_packs/ENNEAGRAM/v2/registry'), $resolver->runtimeRegistryRoot());
        $this->assertSame('repo_fallback', $context['source']);
        $this->assertNull($context['active_release_id']);
    }

    public function test_resolver_uses_explicit_active_release_storage_path_when_manifest_exists(): void
    {
        $resolver = app(EnneagramRegistryReleaseResolver::class);
        $releaseId = (string) Str::uuid();
        $fixtureRoot = $this->makeRegistryFixture('active_release_override');

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

        $context = $resolver->runtimeRegistryContext();

        $this->assertSame($fixtureRoot, $resolver->activeRegistryRoot());
        $this->assertSame($fixtureRoot, $resolver->runtimeRegistryRoot());
        $this->assertSame('active_release', $context['source']);
        $this->assertSame($releaseId, $context['active_release_id']);
        $this->assertSame($fixtureRoot, $context['active_storage_path']);
    }

    private function makeRegistryFixture(string $releaseId): string
    {
        $root = storage_path('framework/testing/enneagram_registry_release/'.$releaseId);
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
