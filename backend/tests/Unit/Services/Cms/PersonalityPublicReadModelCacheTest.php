<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Cms;

use App\Services\Cms\PersonalityPublicReadModelCache;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class PersonalityPublicReadModelCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    public function test_it_caches_only_public_mbti_variant_read_models(): void
    {
        $cache = app(PersonalityPublicReadModelCache::class);
        $payload = ['ok' => true, 'profile' => ['runtime_type_code' => 'INTJ-A']];

        $cache->put('detail', 'intj-a', 'en', 0, 'MBTI', 'profile:1', $payload);

        self::assertSame($payload, $cache->get('detail', 'INTJ-A', 'en', 0, 'MBTI', 'profile:1'));
        self::assertTrue(Cache::has($cache->key('detail', 'INTJ-A', 'en', 'profile:1')));
    }

    public function test_it_bypasses_tenant_base_type_and_unknown_surfaces(): void
    {
        $cache = app(PersonalityPublicReadModelCache::class);
        $payload = ['ok' => true];

        $cache->put('detail', 'INTJ-A', 'en', 7, 'MBTI', 'v1', $payload);
        $cache->put('detail', 'INTJ', 'en', 0, 'MBTI', 'v1', $payload);
        $cache->put('comparison', 'INTJ-A', 'en', 0, 'MBTI', 'v1', $payload);

        self::assertNull($cache->get('detail', 'INTJ-A', 'en', 7, 'MBTI', 'v1'));
        self::assertNull($cache->get('detail', 'INTJ', 'en', 0, 'MBTI', 'v1'));
        self::assertNull($cache->get('comparison', 'INTJ-A', 'en', 0, 'MBTI', 'v1'));
    }

    public function test_detail_and_seo_payloads_use_distinct_keys(): void
    {
        $cache = app(PersonalityPublicReadModelCache::class);
        $cache->put('detail', 'ENFP-T', 'zh-CN', 0, 'MBTI', 'v1', ['surface' => 'detail']);
        $cache->put('seo', 'ENFP-T', 'zh-CN', 0, 'MBTI', 'v1', ['surface' => 'seo']);

        self::assertSame(['surface' => 'detail'], $cache->get('detail', 'ENFP-T', 'zh-CN', 0, 'MBTI', 'v1'));
        self::assertSame(['surface' => 'seo'], $cache->get('seo', 'ENFP-T', 'zh-CN', 0, 'MBTI', 'v1'));
    }
}
