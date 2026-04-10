<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class MbtiResponseCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('content_packs.mbti_response_cache_store', 'array');
        Config::set('content_packs.mbti_lookup_cache_ttl_seconds', 600);
        Config::set('content_packs.mbti_questions_cache_ttl_seconds', 600);
        Config::set('content_packs.loader_cache_store', 'array');
        Config::set('content_packs.loader_cache_ttl_seconds', 300);

        Cache::store('array')->flush();

        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');
        $this->artisan('fap:scales:sync-slugs');
    }

    public function test_mbti_lookup_returns_miss_then_hit(): void
    {
        $first = $this->getJson('/api/v0.3/scales/lookup?slug=mbti-personality-test-16-personality-types&locale=zh');
        $first->assertStatus(200);
        $first->assertHeader('X-FAP-Cache', 'miss');

        $second = $this->getJson('/api/v0.3/scales/lookup?slug=mbti-personality-test-16-personality-types&locale=zh');
        $second->assertStatus(200);
        $second->assertHeader('X-FAP-Cache', 'hit');
        $this->assertSame($first->json(), $second->json());
    }

    public function test_mbti_questions_93_returns_miss_then_hit(): void
    {
        $first = $this->getJson('/api/v0.3/scales/MBTI/questions?locale=zh-CN&form_code=mbti_93');
        $first->assertStatus(200);
        $first->assertHeader('X-FAP-Cache', 'miss');
        $first->assertJsonPath('form_code', 'mbti_93');

        $second = $this->getJson('/api/v0.3/scales/MBTI/questions?locale=zh-CN&form_code=mbti_93');
        $second->assertStatus(200);
        $second->assertHeader('X-FAP-Cache', 'hit');
        $this->assertSame($first->json(), $second->json());
    }

    public function test_mbti_questions_144_en_returns_miss_then_hit(): void
    {
        $first = $this->getJson('/api/v0.3/scales/MBTI/questions?locale=en&form_code=mbti_144');
        $first->assertStatus(200);
        $first->assertHeader('X-FAP-Cache', 'miss');
        $first->assertJsonPath('form_code', 'mbti_144');

        $second = $this->getJson('/api/v0.3/scales/MBTI/questions?locale=en&form_code=mbti_144');
        $second->assertStatus(200);
        $second->assertHeader('X-FAP-Cache', 'hit');
        $this->assertSame($first->json(), $second->json());
    }
}
