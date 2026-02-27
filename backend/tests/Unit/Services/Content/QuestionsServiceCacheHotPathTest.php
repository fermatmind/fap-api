<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Content;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class QuestionsServiceCacheHotPathTest extends TestCase
{
    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempRoot = sys_get_temp_dir().'/questions_service_cache_'.uniqid('', true);
        File::ensureDirectoryExists($this->tempRoot);

        config()->set('content_packs.driver', 'local');
        config()->set('content_packs.root', $this->tempRoot);
        config()->set('content_packs.default_pack_id', 'PACK_CACHE_TEST');
        config()->set('content_packs.default_dir_version', 'PACK-CACHE-v1');
        config()->set('content_packs.default_region', 'CN_MAINLAND');
        config()->set('content_packs.default_locale', 'zh-CN');
        config()->set('content_packs.loader_cache_store', 'array');
        config()->set('content_packs.loader_cache_ttl_seconds', 300);
        try {
            Cache::store('array')->flush();
        } catch (\Throwable $e) {
            // no-op
        }
    }

    protected function tearDown(): void
    {
        try {
            Cache::store('array')->flush();
        } catch (\Throwable $e) {
            // no-op
        }

        File::deleteDirectory($this->tempRoot);
        parent::tearDown();
    }

    public function test_hot_path_uses_cached_questions_payload_without_disk_read(): void
    {
        $packId = 'PACK_CACHE_TEST';
        $dirVersion = 'PACK-CACHE-v1';
        $packDir = $this->tempRoot
            .DIRECTORY_SEPARATOR.'default'
            .DIRECTORY_SEPARATOR.'CN_MAINLAND'
            .DIRECTORY_SEPARATOR.'zh-CN'
            .DIRECTORY_SEPARATOR.$dirVersion;
        File::ensureDirectoryExists($packDir);

        $questionsPath = $packDir.DIRECTORY_SEPARATOR.'questions.json';
        $manifestPath = $packDir.DIRECTORY_SEPARATOR.'manifest.json';
        $versionPath = $packDir.DIRECTORY_SEPARATOR.'version.json';

        file_put_contents($questionsPath, json_encode([
            ['question_id' => 'q1', 'text' => 'Question-1'],
        ], JSON_UNESCAPED_UNICODE));
        file_put_contents($manifestPath, json_encode([
            'schema_version' => 'pack-manifest@v1',
            'pack_id' => $packId,
            'scale_code' => 'MBTI',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'content_package_version' => 'v1',
        ], JSON_UNESCAPED_UNICODE));
        file_put_contents($versionPath, json_encode([
            'pack_id' => $packId,
            'content_package_version' => 'v1',
            'dir_version' => $dirVersion,
        ], JSON_UNESCAPED_UNICODE));

        clearstatcache(true, $questionsPath);
        clearstatcache(true, $manifestPath);
        clearstatcache(true, $versionPath);

        $service = app(\App\Services\Content\QuestionsService::class);

        $first = $service->loadByPack($packId, $dirVersion);
        $this->assertTrue((bool) ($first['ok'] ?? false));
        $this->assertSame('Question-1', (string) data_get($first, 'questions.0.text', ''));

        $originalMtime = (int) filemtime($questionsPath);
        $originalRaw = (string) file_get_contents($questionsPath);
        $brokenRaw = '{'.substr($originalRaw, 1);
        file_put_contents($questionsPath, $brokenRaw);
        touch($questionsPath, $originalMtime);
        clearstatcache(true, $questionsPath);

        $second = $service->loadByPack($packId, $dirVersion);
        $this->assertTrue((bool) ($second['ok'] ?? false));
        $this->assertSame('Question-1', (string) data_get($second, 'questions.0.text', ''));
    }
}
