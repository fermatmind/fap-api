<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Content\ContentLoaderService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class ContentLoaderMtimeCacheTest extends TestCase
{
    public function test_read_json_refreshes_when_file_mtime_changes(): void
    {
        $packId = 'MBTI.cn-mainland.zh-CN.v-mtime';
        $dirVersion = 'MBTI-CN-v-mtime';
        $tempRoot = sys_get_temp_dir().'/pr38_content_loader_'.uniqid('', true);
        $jsonPath = $tempRoot.DIRECTORY_SEPARATOR.'cache-target.json';

        File::ensureDirectoryExists($tempRoot);

        try {
            file_put_contents($jsonPath, json_encode(['value' => 'v1'], JSON_UNESCAPED_UNICODE));
            clearstatcache(true, $jsonPath);

            config()->set('content_packs.loader_cache_ttl_seconds', 600);
            config()->set('content_packs.loader_cache_store', 'array');

            $loader = app(ContentLoaderService::class);
            $resolveAbsPath = fn (): ?string => is_file($jsonPath) ? $jsonPath : null;

            $first = $loader->readJson($packId, $dirVersion, 'cache-target.json', $resolveAbsPath);
            $this->assertSame('v1', $first['value'] ?? null);

            file_put_contents($jsonPath, json_encode(['value' => 'v2'], JSON_UNESCAPED_UNICODE));
            touch($jsonPath, time() + 2);
            clearstatcache(true, $jsonPath);

            $second = $loader->readJson($packId, $dirVersion, 'cache-target.json', $resolveAbsPath);
            $this->assertSame('v2', $second['value'] ?? null);
        } finally {
            File::deleteDirectory($tempRoot);
        }
    }
}
