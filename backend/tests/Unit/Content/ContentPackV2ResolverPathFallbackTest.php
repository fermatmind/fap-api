<?php

declare(strict_types=1);

namespace Tests\Unit\Content;

use App\Services\Content\ContentPackV2Resolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ContentPackV2ResolverPathFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolver_falls_back_to_mirror_path_when_primary_missing(): void
    {
        $releaseId = (string) Str::uuid();
        $manifestHash = 'resolver_fallback_hash';
        $primaryStoragePath = 'private/packs_v2/BIG5_OCEAN/v1/'.$releaseId;
        $mirrorStoragePath = 'content_packs_v2/BIG5_OCEAN/v1/'.$releaseId;

        DB::table('content_pack_releases')->insert([
            'id' => $releaseId,
            'action' => 'packs2_publish',
            'region' => 'GLOBAL',
            'locale' => 'global',
            'dir_alias' => 'v1',
            'from_version_id' => null,
            'to_version_id' => null,
            'from_pack_id' => null,
            'to_pack_id' => 'BIG5_OCEAN',
            'status' => 'success',
            'message' => 'test',
            'created_by' => 'test',
            'manifest_hash' => $manifestHash,
            'compiled_hash' => $manifestHash,
            'content_hash' => null,
            'norms_version' => null,
            'git_sha' => null,
            'pack_version' => 'v1',
            'manifest_json' => json_encode(['compiled_hash' => $manifestHash], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'storage_path' => $primaryStoragePath,
            'source_commit' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $mirrorCompiledDir = storage_path('app/'.$mirrorStoragePath.'/compiled');
        File::ensureDirectoryExists($mirrorCompiledDir);
        File::put($mirrorCompiledDir.'/manifest.json', json_encode([
            'compiled_hash' => $manifestHash,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        /** @var ContentPackV2Resolver $resolver */
        $resolver = app(ContentPackV2Resolver::class);
        $resolved = $resolver->resolveCompiledPathByManifestHash('BIG5_OCEAN', 'v1', $manifestHash);

        $this->assertSame($mirrorCompiledDir, $resolved);

        File::deleteDirectory(storage_path('app/private/packs_v2/BIG5_OCEAN/v1/'.$releaseId));
        File::deleteDirectory(storage_path('app/content_packs_v2/BIG5_OCEAN/v1/'.$releaseId));
    }
}
