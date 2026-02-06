<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Content\ContentLoaderService;
use App\Services\ContentPackResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class ContentPackResolverCacheTest extends TestCase
{
    public function test_load_json_is_cached_until_forget(): void
    {
        $packId = 'MBTI.cn-mainland.zh-CN.v-cache';
        $dirVersion = 'MBTI-CN-v-cache';

        $root = sys_get_temp_dir() . '/pr34_content_pack_' . uniqid('', true);
        $packDir = $root . DIRECTORY_SEPARATOR . $dirVersion;
        File::ensureDirectoryExists($packDir);

        file_put_contents($packDir . DIRECTORY_SEPARATOR . 'manifest.json', json_encode([
            'pack_id' => $packId,
            'scale_code' => 'MBTI',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'content_package_version' => 'v-cache',
        ], JSON_UNESCAPED_UNICODE));
        file_put_contents($packDir . DIRECTORY_SEPARATOR . 'cache-target.json', json_encode([
            'value' => 'v1',
        ], JSON_UNESCAPED_UNICODE));

        config()->set('content_packs.root', $root);
        config()->set('content_packs.default_pack_id', $packId);
        config()->set('content_packs.default_region', 'CN_MAINLAND');
        config()->set('content_packs.default_locale', 'zh-CN');
        config()->set('content_packs.loader_cache_ttl_seconds', 600);
        config()->set('content_packs.loader_cache_store', 'array');

        $resolver = new ContentPackResolver();
        $resolved = $resolver->resolve('MBTI', 'CN_MAINLAND', 'zh-CN', 'v-cache');
        $readJson = $resolved->loaders['readJson'];

        $first = $readJson('cache-target.json');
        $this->assertSame('v1', $first['value'] ?? null);

        file_put_contents($packDir . DIRECTORY_SEPARATOR . 'cache-target.json', json_encode([
            'value' => 'v2',
        ], JSON_UNESCAPED_UNICODE));

        $second = $readJson('cache-target.json');
        $this->assertSame('v1', $second['value'] ?? null);

        $loader = app(ContentLoaderService::class);
        Cache::forget($loader->makeCacheKey($packId, $dirVersion, 'cache-target.json'));

        $third = $readJson('cache-target.json');
        $this->assertSame('v2', $third['value'] ?? null);

        File::deleteDirectory($root);
    }
}
