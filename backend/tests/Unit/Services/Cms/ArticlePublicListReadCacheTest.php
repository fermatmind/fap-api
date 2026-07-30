<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Cms;

use App\Services\Cms\ArticlePublicListReadCache;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

final class ArticlePublicListReadCacheTest extends TestCase
{
    /**
     * @var array{org_id:int,locale:?string,related_test_slug:?string,voice:?string,page:int,per_page:int}
     */
    private array $filters = [
        'org_id' => 0,
        'locale' => 'en',
        'related_test_slug' => null,
        'voice' => null,
        'page' => 1,
        'per_page' => 6,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_resolve_caches_payload_and_reuses_it_without_rebuilding(): void
    {
        $cache = app(ArticlePublicListReadCache::class);
        $builds = 0;
        $builder = static function () use (&$builds): array {
            $builds++;

            return ['ok' => true, 'items' => [['slug' => 'cached']]];
        };

        $first = $cache->resolve($this->filters, $builder);
        $second = $cache->resolve($this->filters, $builder);

        $this->assertSame('miss', $first['state']);
        $this->assertSame('hit', $second['state']);
        $this->assertSame($first['payload'], $second['payload']);
        $this->assertSame(1, $builds);
    }

    public function test_stale_payload_is_returned_when_rebuild_fails(): void
    {
        $cache = app(ArticlePublicListReadCache::class);
        $generation = 'stale-generation';
        Cache::forever($this->generationKey(), $generation);
        Cache::put($this->payloadKey($generation), [
            'fresh_until' => microtime(true) - 1,
            'payload' => ['ok' => true, 'items' => [['slug' => 'stale']]],
        ], ArticlePublicListReadCache::LKG_TTL_SECONDS);

        $resolved = $cache->resolve(
            $this->filters,
            static fn (): array => throw new RuntimeException('database unavailable'),
        );

        $this->assertSame('stale', $resolved['state']);
        $this->assertSame('stale', $resolved['payload']['items'][0]['slug']);
    }

    public function test_hard_invalidation_makes_old_payload_unreachable(): void
    {
        $cache = app(ArticlePublicListReadCache::class);
        $cache->resolve($this->filters, static fn (): array => [
            'ok' => true,
            'items' => [['slug' => 'withdrawn']],
        ]);

        $cache->invalidate(false);

        $this->expectException(RuntimeException::class);
        $cache->resolve(
            $this->filters,
            static fn (): array => throw new RuntimeException('database unavailable'),
        );
    }

    public function test_preserving_invalidation_keeps_previous_generation_as_lkg(): void
    {
        $cache = app(ArticlePublicListReadCache::class);
        $cache->resolve($this->filters, static fn (): array => [
            'ok' => true,
            'items' => [['slug' => 'previous-generation']],
        ]);

        $cache->invalidate();
        $resolved = $cache->resolve(
            $this->filters,
            static fn (): array => throw new RuntimeException('database unavailable'),
        );

        $this->assertSame('stale', $resolved['state']);
        $this->assertSame('previous-generation', $resolved['payload']['items'][0]['slug']);
    }

    public function test_generation_fence_rejects_payload_written_after_invalidation(): void
    {
        $cache = app(ArticlePublicListReadCache::class);
        $builds = 0;

        $first = $cache->resolve($this->filters, function () use ($cache, &$builds): array {
            $builds++;
            $cache->invalidate();

            return ['ok' => true, 'items' => [['slug' => 'old-generation']]];
        });
        $second = $cache->resolve($this->filters, static function () use (&$builds): array {
            $builds++;

            return ['ok' => true, 'items' => [['slug' => 'new-generation']]];
        });

        $this->assertSame('miss', $first['state']);
        $this->assertSame('miss', $second['state']);
        $this->assertSame('new-generation', $second['payload']['items'][0]['slug']);
        $this->assertSame(2, $builds);
    }

    public function test_contender_uses_stale_payload_without_running_second_builder(): void
    {
        $cache = app(ArticlePublicListReadCache::class);
        $generation = 'locked-generation';
        Cache::forever($this->generationKey(), $generation);
        Cache::put($this->payloadKey($generation), [
            'fresh_until' => microtime(true) - 1,
            'payload' => ['ok' => true, 'items' => [['slug' => 'locked-stale']]],
        ], ArticlePublicListReadCache::LKG_TTL_SECONDS);
        $lock = Cache::lock(
            $this->payloadKey($generation).':build-lock',
            ArticlePublicListReadCache::BUILD_LOCK_SECONDS,
        );
        $this->assertTrue($lock->get());
        $builds = 0;

        try {
            $resolved = $cache->resolve($this->filters, static function () use (&$builds): array {
                $builds++;

                return ['ok' => true];
            });
        } finally {
            $lock->release();
        }

        $this->assertSame('stale', $resolved['state']);
        $this->assertSame(0, $builds);
    }

    public function test_cache_failure_fails_open_to_builder(): void
    {
        Cache::shouldReceive('get')
            ->once()
            ->andThrow(new RuntimeException('cache unavailable'));

        $resolved = app(ArticlePublicListReadCache::class)->resolve(
            $this->filters,
            static fn (): array => ['ok' => true, 'items' => [['slug' => 'database']]],
        );

        $this->assertSame('bypass', $resolved['state']);
        $this->assertSame('database', $resolved['payload']['items'][0]['slug']);
    }

    private function generationKey(): string
    {
        return ArticlePublicListReadCache::CACHE_KEY_PREFIX.':generation';
    }

    private function payloadKey(string $generation): string
    {
        $fingerprint = hash('xxh3', json_encode([
            'locale' => $this->filters['locale'],
            'page' => $this->filters['page'],
            'per_page' => $this->filters['per_page'],
        ], JSON_THROW_ON_ERROR));

        return implode(':', [
            ArticlePublicListReadCache::CACHE_KEY_PREFIX,
            'payload',
            hash('xxh3', $generation),
            $fingerprint,
        ]);
    }
}
