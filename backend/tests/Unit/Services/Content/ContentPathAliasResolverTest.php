<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Content;

use App\Services\Content\BigFivePackLoader;
use App\Services\Content\ContentPathAliasResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class ContentPathAliasResolverTest extends TestCase
{
    use RefreshDatabase;

    private string $legacyPackCode;

    private string $mappedPackCode;

    private string $legacyPath;

    private string $mappedPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->legacyPackCode = 'TEST_PATH_ALIAS_PACK';
        $this->mappedPackCode = 'TEST_PATH_ALIAS_PACK_V2';
        $this->legacyPath = base_path('content_packs/'.$this->legacyPackCode);
        $this->mappedPath = base_path('content_packs/'.$this->mappedPackCode);

        File::deleteDirectory($this->legacyPath);
        File::deleteDirectory($this->mappedPath);
        File::ensureDirectoryExists($this->legacyPath);

        DB::table('content_path_aliases')->updateOrInsert(
            [
                'scope' => 'backend_content_packs',
                'old_path' => 'content_packs/'.$this->legacyPackCode,
            ],
            [
                'new_path' => 'content_packs/'.$this->mappedPackCode,
                'scale_uid' => null,
                'is_active' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->legacyPath);
        File::deleteDirectory($this->mappedPath);
        parent::tearDown();
    }

    public function test_dual_prefer_new_falls_back_to_legacy_when_mapped_directory_missing(): void
    {
        config()->set('scale_identity.content_path_mode', 'dual_prefer_new');
        $resolver = app(ContentPathAliasResolver::class);

        $resolved = $resolver->resolveBackendPackRoot($this->legacyPackCode);

        $this->assertSame($this->legacyPath, $resolved);
    }

    public function test_dual_prefer_new_uses_mapped_directory_when_present(): void
    {
        File::ensureDirectoryExists($this->mappedPath);
        config()->set('scale_identity.content_path_mode', 'dual_prefer_new');
        $resolver = app(ContentPathAliasResolver::class);

        $resolved = $resolver->resolveBackendPackRoot($this->legacyPackCode);

        $this->assertSame($this->mappedPath, $resolved);
    }

    public function test_bigfive_loader_ignores_physical_alias_and_uses_canonical_assessment_pack(): void
    {
        $canonicalRoot = base_path('content_packs/BIG5_OCEAN');
        $mappedBigFiveRoot = base_path('content_packs/BIG_FIVE_OCEAN_MODEL');
        $this->assertSame([], is_dir($mappedBigFiveRoot) ? File::allFiles($mappedBigFiveRoot) : []);

        config()->set('scale_identity.content_path_mode', 'dual_prefer_new');
        DB::table('content_path_aliases')->updateOrInsert(
            [
                'scope' => 'backend_content_packs',
                'old_path' => 'content_packs/BIG5_OCEAN',
            ],
            [
                'new_path' => 'content_packs/BIG_FIVE_OCEAN_MODEL',
                'scale_uid' => null,
                'is_active' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $loader = new BigFivePackLoader(null, app(ContentPathAliasResolver::class));
        $this->assertSame($canonicalRoot.DIRECTORY_SEPARATOR.'v1', $loader->packRoot('v1'));
        $this->assertSame(
            $canonicalRoot.DIRECTORY_SEPARATOR.'v1',
            app(ContentPathAliasResolver::class)->resolveBackendPublishSourceDir('BIG5_OCEAN', 'v1')
        );
    }

    public function test_publish_source_dir_legacy_mode_prefers_legacy_path(): void
    {
        $legacyVersionPath = $this->legacyPath.DIRECTORY_SEPARATOR.'v1';
        $mappedVersionPath = $this->mappedPath.DIRECTORY_SEPARATOR.'v1';
        File::ensureDirectoryExists($legacyVersionPath);
        File::ensureDirectoryExists($mappedVersionPath);

        config()->set('scale_identity.content_publish_mode', 'legacy');
        $resolver = app(ContentPathAliasResolver::class);
        $resolved = $resolver->resolveBackendPublishSourceDir($this->legacyPackCode, 'v1');
        $context = $resolver->resolveBackendPublishSourceContext($this->legacyPackCode, 'v1');

        $this->assertSame($legacyVersionPath, $resolved);
        $this->assertSame('legacy', (string) ($context['selected_source'] ?? ''));
        $this->assertFalse((bool) ($context['fallback_used'] ?? true));
    }

    public function test_publish_source_dir_v2_mode_prefers_mapped_path_with_fallback(): void
    {
        $legacyVersionPath = $this->legacyPath.DIRECTORY_SEPARATOR.'v1';
        $mappedVersionPath = $this->mappedPath.DIRECTORY_SEPARATOR.'v1';
        File::ensureDirectoryExists($legacyVersionPath);

        config()->set('scale_identity.content_publish_mode', 'v2');
        $resolver = app(ContentPathAliasResolver::class);

        $fallbackResolved = $resolver->resolveBackendPublishSourceDir($this->legacyPackCode, 'v1');
        $fallbackContext = $resolver->resolveBackendPublishSourceContext($this->legacyPackCode, 'v1');
        $this->assertSame($legacyVersionPath, $fallbackResolved);
        $this->assertSame('legacy', (string) ($fallbackContext['selected_source'] ?? ''));
        $this->assertTrue((bool) ($fallbackContext['fallback_used'] ?? false));

        File::ensureDirectoryExists($mappedVersionPath);
        $mappedResolved = $resolver->resolveBackendPublishSourceDir($this->legacyPackCode, 'v1');
        $mappedContext = $resolver->resolveBackendPublishSourceContext($this->legacyPackCode, 'v1');
        $this->assertSame($mappedVersionPath, $mappedResolved);
        $this->assertSame('mapped', (string) ($mappedContext['selected_source'] ?? ''));
        $this->assertFalse((bool) ($mappedContext['fallback_used'] ?? true));
    }
}
