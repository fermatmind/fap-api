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

    public function test_it_caches_only_public_mbti_read_models(): void
    {
        $cache = app(PersonalityPublicReadModelCache::class);
        $payload = ['ok' => true, 'profile' => ['runtime_type_code' => 'INTJ-A']];

        $cache->put('detail', 'intj-a', 'en', 0, 'MBTI', 'profile:1', $payload);
        $cache->put('detail', 'intj', 'en', 0, 'MBTI', 'profile:base', ['type' => 'INTJ']);

        self::assertSame($payload, $cache->get('detail', 'INTJ-A', 'en', 0, 'MBTI', 'profile:1'));
        self::assertSame(['type' => 'INTJ'], $cache->get('detail', 'INTJ', 'en', 0, 'MBTI', 'profile:base'));
        self::assertTrue(Cache::has($cache->key('detail', 'INTJ-A', 'en', 'profile:1')));
    }

    public function test_it_bypasses_tenant_non_mbti_and_unknown_surfaces(): void
    {
        $cache = app(PersonalityPublicReadModelCache::class);
        $payload = ['ok' => true];

        $cache->put('detail', 'INTJ-A', 'en', 7, 'MBTI', 'v1', $payload);
        $cache->put('detail', 'INTJ-A', 'en', 0, 'BIG_FIVE', 'v1', $payload);
        $cache->put('comparison', 'INTJ-A', 'en', 0, 'MBTI', 'v1', $payload);

        self::assertSame('bypass', $cache->read('detail', 'INTJ-A', 'en', 7, 'MBTI', 'v1')['state']);
        self::assertSame('bypass', $cache->read('detail', 'INTJ-A', 'en', 0, 'BIG_FIVE', 'v1')['state']);
        self::assertSame('bypass', $cache->read('comparison', 'INTJ-A', 'en', 0, 'MBTI', 'v1')['state']);
    }

    public function test_detail_and_seo_payloads_use_distinct_keys(): void
    {
        $cache = app(PersonalityPublicReadModelCache::class);
        $cache->put('detail', 'ENFP-T', 'zh-CN', 0, 'MBTI', 'v1', ['surface' => 'detail']);
        $cache->put('seo', 'ENFP-T', 'zh-CN', 0, 'MBTI', 'v1', ['surface' => 'seo']);

        self::assertSame(['surface' => 'detail'], $cache->get('detail', 'ENFP-T', 'zh-CN', 0, 'MBTI', 'v1'));
        self::assertSame(['surface' => 'seo'], $cache->get('seo', 'ENFP-T', 'zh-CN', 0, 'MBTI', 'v1'));
    }

    public function test_versioned_publish_switches_active_and_keeps_the_previous_lkg(): void
    {
        $cache = app(PersonalityPublicReadModelCache::class);
        $old = ['revision' => 1];
        $new = ['revision' => 2];

        $cache->put('detail', 'INTJ-A', 'en', 0, 'MBTI', 'profile:1', $old);
        $cache->put('detail', 'INTJ-A', 'en', 0, 'MBTI', 'profile:2', $new);

        self::assertSame('profile:2', Cache::get($cache->activeKey('detail', 'INTJ-A', 'en')));
        self::assertSame('profile:1', Cache::get($cache->lkgKey('detail', 'INTJ-A', 'en')));
        self::assertSame([
            'state' => 'fresh',
            'payload' => $new,
        ], $cache->read('detail', 'INTJ-A', 'en', 0, 'MBTI', 'profile:2'));

        Cache::forget($cache->key('detail', 'INTJ-A', 'en', 'profile:2'));

        self::assertSame([
            'state' => 'stale',
            'payload' => $old,
        ], $cache->stale('detail', 'INTJ-A', 'en', 0, 'MBTI'));
    }

    public function test_authoritative_absence_removes_detail_and_seo_stale_pointers(): void
    {
        $cache = app(PersonalityPublicReadModelCache::class);
        $cache->put('detail', 'INTJ-A', 'en', 0, 'MBTI', 'v1', ['surface' => 'detail']);
        $cache->put('seo', 'INTJ-A', 'en', 0, 'MBTI', 'v1', ['surface' => 'seo']);

        $cache->forgetType('INTJ-A', 'en', 0, 'MBTI');

        self::assertSame('miss', $cache->stale('detail', 'INTJ-A', 'en', 0, 'MBTI')['state']);
        self::assertSame('miss', $cache->stale('seo', 'INTJ-A', 'en', 0, 'MBTI')['state']);
        self::assertFalse(Cache::has($cache->activeKey('detail', 'INTJ-A', 'en')));
        self::assertFalse(Cache::has($cache->lkgKey('seo', 'INTJ-A', 'en')));
    }

    public function test_forget_type_rotates_the_content_generation_token(): void
    {
        $cache = app(PersonalityPublicReadModelCache::class);

        self::assertSame('0', $cache->versionToken('INTJ-A', 'zh-CN', 0, 'MBTI'));
        self::assertTrue($cache->forgetType('INTJ-A', 'zh-CN', 0, 'MBTI'));

        $first = $cache->versionToken('INTJ-A', 'zh-CN', 0, 'MBTI');
        self::assertNotSame('0', $first);
        self::assertTrue($cache->forgetType('INTJ-A', 'zh-CN', 0, 'MBTI'));
        self::assertNotSame($first, $cache->versionToken('INTJ-A', 'zh-CN', 0, 'MBTI'));
        self::assertTrue(Cache::has($cache->generationKey('INTJ-A', 'zh-CN')));
    }

    public function test_uncached_version_reports_miss_without_promoting_stale_content(): void
    {
        $cache = app(PersonalityPublicReadModelCache::class);
        $cache->put('detail', 'INTJ-A', 'en', 0, 'MBTI', 'profile:1', ['revision' => 1]);

        self::assertSame([
            'state' => 'miss',
            'payload' => null,
        ], $cache->read('detail', 'INTJ-A', 'en', 0, 'MBTI', 'profile:2'));
    }
}
