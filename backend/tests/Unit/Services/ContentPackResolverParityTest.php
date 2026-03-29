<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Content\ContentPackResolver as NewContentPackResolver;
use App\Services\ContentPackResolver as LegacyContentPackResolver;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class ContentPackResolverParityTest extends TestCase
{
    public function test_container_can_resolve_legacy_and_new_resolvers(): void
    {
        $ctx = $this->bootstrapDefaultPackConfig();

        $this->app->forgetInstance(LegacyContentPackResolver::class);
        $this->app->forgetInstance(NewContentPackResolver::class);

        $legacy = app()->make(LegacyContentPackResolver::class);
        $new = app()->make(NewContentPackResolver::class);

        $this->assertInstanceOf(LegacyContentPackResolver::class, $legacy);
        $this->assertInstanceOf(NewContentPackResolver::class, $new);
        $this->assertNotSame('', $ctx['pack_id']);
    }

    public function test_legacy_and_new_resolver_match_default_pack_identity(): void
    {
        $ctx = $this->bootstrapDefaultPackConfig();

        $this->app->forgetInstance(LegacyContentPackResolver::class);
        $this->app->forgetInstance(NewContentPackResolver::class);

        /** @var LegacyContentPackResolver $legacy */
        $legacy = app()->make(LegacyContentPackResolver::class);
        /** @var NewContentPackResolver $new */
        $new = app()->make(NewContentPackResolver::class);

        $legacyResolved = $legacy->resolve(
            'default',
            $ctx['region'],
            $ctx['locale'],
            $ctx['dir_version']
        );
        $newResolved = $new->resolve(
            'default',
            $ctx['region'],
            $ctx['locale'],
            $ctx['dir_version']
        );

        $this->assertSame((string) $legacyResolved->packId, $newResolved->packId());
        $this->assertSame(
            basename((string) $legacyResolved->baseDir),
            basename($newResolved->basePath())
        );
        $this->assertSame(
            (string) ($legacyResolved->manifest['content_package_version'] ?? ''),
            $newResolved->version()
        );
    }

    /**
     * @return array{root:string,region:string,locale:string,dir_version:string,pack_id:string}
     */
    private function bootstrapDefaultPackConfig(): array
    {
        $root = (string) config('content_packs.root', base_path('../content_packages'));
        if (! is_dir($root)) {
            $root = base_path('../content_packages');
        }

        $region = (string) config('content_packs.default_region', 'CN_MAINLAND');
        $locale = (string) config('content_packs.default_locale', 'zh-CN');
        $dirVersion = (string) config('content_packs.default_dir_version', 'MBTI-CN-v0.3');

        $manifestPath = $root
            .DIRECTORY_SEPARATOR.'default'
            .DIRECTORY_SEPARATOR.$region
            .DIRECTORY_SEPARATOR.$locale
            .DIRECTORY_SEPARATOR.$dirVersion
            .DIRECTORY_SEPARATOR.'manifest.json';

        $this->assertTrue(File::exists($manifestPath), "default pack manifest missing: {$manifestPath}");
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $this->assertIsArray($manifest);

        $packId = trim((string) ($manifest['pack_id'] ?? ''));
        $this->assertNotSame('', $packId, "manifest missing pack_id: {$manifestPath}");

        config()->set('content_packs.root', $root);
        config()->set('content.packs_root', $root); // compat key for legacy tests
        config()->set('content_packs.driver', 'local');
        config()->set('content_packs.default_region', $region);
        config()->set('content_packs.default_locale', $locale);
        config()->set('content_packs.default_dir_version', $dirVersion);
        config()->set('content_packs.default_pack_id', $packId);

        return [
            'root' => $root,
            'region' => $region,
            'locale' => $locale,
            'dir_version' => $dirVersion,
            'pack_id' => $packId,
        ];
    }
}
