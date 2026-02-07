<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Content\ContentLoaderService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class ContentLoaderServiceMtimeTest extends TestCase
{
    public function test_read_json_refreshes_when_file_mtime_changes(): void
    {
        $packId = 'MBTI.cn-mainland.zh-CN.v-mtime-39';
        $dirVersion = 'MBTI-CN-v-mtime-39';
        $tempRoot = sys_get_temp_dir().'/pr39_content_loader_'.uniqid('', true);
        $jsonPath = $tempRoot.DIRECTORY_SEPARATOR.'cache-target.json';

        File::ensureDirectoryExists($tempRoot);

        try {
            file_put_contents($jsonPath, json_encode(['v' => 1], JSON_UNESCAPED_UNICODE));
            clearstatcache(true, $jsonPath);

            config()->set('content_packs.loader_cache_ttl_seconds', 600);
            config()->set('content_packs.loader_cache_store', 'array');

            $loader = app(ContentLoaderService::class);
            $resolveAbsPath = fn (): ?string => is_file($jsonPath) ? $jsonPath : null;

            $first = $loader->readJson($packId, $dirVersion, 'cache-target.json', $resolveAbsPath);
            $this->assertSame(1, $first['v'] ?? null);

            file_put_contents($jsonPath, json_encode(['v' => 2], JSON_UNESCAPED_UNICODE));
            touch($jsonPath, time() + 2);
            clearstatcache(true, $jsonPath);

            $second = $loader->readJson($packId, $dirVersion, 'cache-target.json', $resolveAbsPath);
            $this->assertSame(2, $second['v'] ?? null);
        } finally {
            File::deleteDirectory($tempRoot);
        }
    }

    public function test_read_json_falls_back_when_redis_store_unavailable(): void
    {
        $packId = 'MBTI.cn-mainland.zh-CN.v-redis-fallback-39';
        $dirVersion = 'MBTI-CN-v-redis-fallback-39';
        $tempRoot = sys_get_temp_dir().'/pr39_content_loader_fallback_'.uniqid('', true);
        $jsonPath = $tempRoot.DIRECTORY_SEPARATOR.'fallback-target.json';

        File::ensureDirectoryExists($tempRoot);

        try {
            file_put_contents($jsonPath, json_encode(['v' => 7], JSON_UNESCAPED_UNICODE));
            clearstatcache(true, $jsonPath);

            config()->set('app.env', 'ci');
            config()->set('content_packs.loader_cache_ttl_seconds', 600);
            config()->set('content_packs.loader_cache_store', 'hot_redis');

            $loader = app(ContentLoaderService::class);
            $resolveAbsPath = fn (): ?string => is_file($jsonPath) ? $jsonPath : null;

            $result = $loader->readJson($packId, $dirVersion, 'fallback-target.json', $resolveAbsPath);
            $this->assertSame(7, $result['v'] ?? null);
        } finally {
            File::deleteDirectory($tempRoot);
        }
    }
}
