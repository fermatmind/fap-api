<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Scale;

use App\Services\Scale\PublicScaleCatalogCache;
use App\Services\Scale\PublicScaleCatalogUnavailable;
use App\Support\CacheKeys;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class PublicScaleCatalogCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('content_packs.public_scale_cache_store', 'array');
        Config::set('content_packs.public_scale_catalog_schema_version', 'v1');
        Config::set('content_packs.public_scale_catalog_fresh_ttl_seconds', 60);
        Config::set('content_packs.public_scale_catalog_stale_ttl_seconds', 120);
        Config::set('content_packs.public_scale_catalog_ttl_jitter_seconds', 0);
        Config::set('content_packs.public_scale_catalog_lock_ttl_seconds', 10);
        Config::set('content_packs.public_scale_catalog_wait_budget_ms', 50);
        Config::set('content_packs.public_scale_catalog_wait_interval_ms', 5);
        Cache::store('array')->flush();
        Carbon::setTestNow('2026-07-30 00:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_fresh_hit_does_not_rebuild_and_locales_are_isolated(): void
    {
        $cache = app(PublicScaleCatalogCache::class);
        $builds = 0;

        $zh = $cache->read(0, 'zh-CN', function () use (&$builds): array {
            $builds++;

            return $this->payload('zh-CN', 'zh-title');
        });
        $zhHit = $cache->read(0, 'zh-CN', function () use (&$builds): array {
            $builds++;

            return $this->payload('zh-CN', 'wrong');
        });
        $en = $cache->read(0, 'en', function () use (&$builds): array {
            $builds++;

            return $this->payload('en', 'en-title');
        });

        $this->assertSame('miss', $zh['state']);
        $this->assertSame('hit', $zhHit['state']);
        $this->assertSame('miss', $en['state']);
        $this->assertSame('zh-title', data_get($zhHit, 'payload.items.0.title'));
        $this->assertSame('en-title', data_get($en, 'payload.items.0.title'));
        $this->assertSame(2, $builds);
    }

    public function test_stale_hit_returns_lkg_then_one_deferred_refresh_replaces_it(): void
    {
        $cache = app(PublicScaleCatalogCache::class);
        $cache->read(0, 'zh-CN', fn (): array => $this->payload('zh-CN', 'old'));
        Carbon::setTestNow('2026-07-30 00:01:01');
        $builds = 0;

        $first = $cache->read(0, 'zh-CN', function () use (&$builds): array {
            $builds++;

            return $this->payload('zh-CN', 'new');
        });
        $second = $cache->read(0, 'zh-CN', function () use (&$builds): array {
            $builds++;

            return $this->payload('zh-CN', 'duplicate');
        });

        $this->assertSame('stale', $first['state']);
        $this->assertSame('stale', $second['state']);
        $this->assertSame(0, $builds);
        defer()->invoke();
        $this->assertSame(1, $builds);

        $refreshed = $cache->read(0, 'zh-CN', fn (): array => $this->payload('zh-CN', 'wrong'));
        $this->assertSame('hit', $refreshed['state']);
        $this->assertSame('duplicate', data_get($refreshed, 'payload.items.0.title'));
    }

    public function test_refresh_failure_preserves_stale_lkg_and_expired_lkg_fails_closed(): void
    {
        $cache = app(PublicScaleCatalogCache::class);
        $cache->read(0, 'zh-CN', fn (): array => $this->payload('zh-CN', 'last-good'));
        Carbon::setTestNow('2026-07-30 00:01:01');

        $stale = $cache->read(0, 'zh-CN', function (): array {
            throw new \RuntimeException('registry unavailable');
        });
        defer()->invoke();

        $this->assertSame('stale', $stale['state']);
        $this->assertSame('last-good', data_get($stale, 'payload.items.0.title'));

        Carbon::setTestNow('2026-07-30 00:03:01');
        $this->expectException(PublicScaleCatalogUnavailable::class);
        $cache->read(0, 'zh-CN', function (): array {
            throw new \RuntimeException('registry unavailable');
        });
    }

    public function test_generation_bump_makes_previous_fresh_payload_unreachable(): void
    {
        $cache = app(PublicScaleCatalogCache::class);
        $cache->read(0, 'zh-CN', fn (): array => $this->payload('zh-CN', 'old-generation'));
        $cache->bumpGeneration(0);

        $result = $cache->read(0, 'zh-CN', fn (): array => $this->payload('zh-CN', 'new-generation'));

        $this->assertSame('miss', $result['state']);
        $this->assertSame('new-generation', data_get($result, 'payload.items.0.title'));
        $this->assertSame(2, $cache->generation(0));
    }

    public function test_fresh_ttl_jitter_is_bounded_and_deterministic_for_the_same_key(): void
    {
        Config::set('content_packs.public_scale_catalog_ttl_jitter_seconds', 30);
        $cache = app(PublicScaleCatalogCache::class);
        $cache->storeValidated(0, 'zh-CN', $this->payload('zh-CN', 'jittered'));
        $key = CacheKeys::publicScaleCatalog(0, 'zh-CN', $cache->generation(0));
        $first = Cache::store('array')->get($key);
        $firstTtl = (int) $first['fresh_until'] - (int) $first['created_at'];

        Cache::store('array')->forget($key);
        $cache->storeValidated(0, 'zh-CN', $this->payload('zh-CN', 'jittered'));
        $second = Cache::store('array')->get($key);
        $secondTtl = (int) $second['fresh_until'] - (int) $second['created_at'];

        $this->assertGreaterThanOrEqual(30, $firstTtl);
        $this->assertLessThanOrEqual(60, $firstTtl);
        $this->assertSame($firstTtl, $secondTtl);
    }

    public function test_cold_lock_competitor_waits_for_single_flight_payload_without_building(): void
    {
        $cache = new class extends PublicScaleCatalogCache
        {
            public ?\Closure $onSleep = null;

            protected function sleepMilliseconds(int $milliseconds): void
            {
                if ($this->onSleep !== null) {
                    ($this->onSleep)();
                    $this->onSleep = null;
                }
            }
        };
        $generation = $cache->generation(0);
        $payloadKey = CacheKeys::publicScaleCatalog(0, 'zh-CN', $generation);
        $lock = Cache::store('array')->lock(CacheKeys::publicScaleCatalogLock($payloadKey), 10);
        $this->assertTrue($lock->get());
        $builds = 0;
        $cache->onSleep = function () use ($cache, $lock): void {
            $lock->release();
            $cache->storeValidated(0, 'zh-CN', $this->payload('zh-CN', 'single-flight'));
        };

        $result = $cache->read(0, 'zh-CN', function () use (&$builds): array {
            $builds++;

            return $this->payload('zh-CN', 'duplicate-build');
        });

        $this->assertSame('wait-hit', $result['state']);
        $this->assertSame(0, $builds);
        $this->assertSame('single-flight', data_get($result, 'payload.items.0.title'));
    }

    /**
     * @return array<string,mixed>
     */
    private function payload(string $locale, string $title): array
    {
        return [
            'ok' => true,
            'locale' => $locale,
            'items' => [[
                'slug' => 'example-scale',
                'title' => $title,
                'is_public' => true,
                'is_active' => true,
            ]],
        ];
    }
}
