<?php

declare(strict_types=1);

namespace App\Services\Cms;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ArticlePublicListReadCache
{
    public const CACHE_KEY_PREFIX = 'public:articles:list:v1';

    public const FRESH_TTL_SECONDS = 120;

    public const LKG_TTL_SECONDS = 86400;

    public const BUILD_LOCK_SECONDS = 45;

    /**
     * @param  array{org_id:int,locale:?string,related_test_slug:?string,voice:?string,page:int,per_page:int}  $filters
     * @param  callable():array<string,mixed>  $builder
     * @return array{state:'hit'|'miss'|'stale'|'bypass',payload:array<string,mixed>}
     */
    public function resolve(array $filters, callable $builder): array
    {
        if (! $this->isCacheable($filters)) {
            return $this->buildBypass($filters, $builder);
        }

        try {
            $generation = $this->currentGeneration();
            $fingerprint = $this->fingerprint($filters);
            $fresh = $this->readFresh($generation, $fingerprint);
            if ($fresh !== null) {
                $this->recordState($filters, 'hit');

                return ['state' => 'hit', 'payload' => $fresh];
            }

            $stale = $this->readStale($generation, $fingerprint);
            $lock = Cache::lock($this->lockKey($generation, $fingerprint), self::BUILD_LOCK_SECONDS);
            $acquired = $lock->get();
        } catch (Throwable $cacheFailure) {
            $this->recordState($filters, 'bypass', $cacheFailure);

            return ['state' => 'bypass', 'payload' => $builder()];
        }

        if (! $acquired) {
            if ($stale !== null) {
                $this->recordState($filters, 'stale');

                return ['state' => 'stale', 'payload' => $stale];
            }

            try {
                $waited = $this->waitForFresh($generation, $fingerprint);
            } catch (Throwable $cacheFailure) {
                $this->recordState($filters, 'bypass', $cacheFailure);

                return ['state' => 'bypass', 'payload' => $builder()];
            }

            if ($waited !== null) {
                $this->recordState($filters, 'hit');

                return ['state' => 'hit', 'payload' => $waited];
            }

            return $this->buildAndRecord($filters, $builder, null, 'miss');
        }

        return $this->buildUnderLock($filters, $builder, $generation, $fingerprint, $stale, $lock);
    }

    public function invalidate(bool $preserveLkg = true): void
    {
        try {
            Cache::lock(self::CACHE_KEY_PREFIX.':invalidation-lock', 10)
                ->block(10, function () use ($preserveLkg): void {
                    $current = $this->currentGeneration();
                    if ($preserveLkg) {
                        Cache::put($this->previousGenerationKey(), $current, self::LKG_TTL_SECONDS);
                    } else {
                        Cache::forget($this->previousGenerationKey());
                    }

                    Cache::forever($this->generationKey(), $this->newGeneration());
                });
        } catch (Throwable $throwable) {
            $this->recordInvalidationFailure($throwable);
            if (! $preserveLkg) {
                throw $throwable;
            }
        }
    }

    /**
     * @param  array{org_id:int,locale:?string,related_test_slug:?string,voice:?string,page:int,per_page:int}  $filters
     * @param  callable():array<string,mixed>  $builder
     * @return array{state:'bypass',payload:array<string,mixed>}
     */
    private function buildBypass(array $filters, callable $builder): array
    {
        $startedAt = microtime(true);
        $payload = $builder();
        $this->recordState($filters, 'bypass', null, $startedAt);

        return ['state' => 'bypass', 'payload' => $payload];
    }

    /**
     * @param  array{org_id:int,locale:?string,related_test_slug:?string,voice:?string,page:int,per_page:int}  $filters
     * @param  callable():array<string,mixed>  $builder
     * @param  array<string,mixed>|null  $stale
     * @return array{state:'hit'|'miss'|'stale',payload:array<string,mixed>}
     */
    private function buildUnderLock(
        array $filters,
        callable $builder,
        string $generation,
        string $fingerprint,
        ?array $stale,
        Lock $lock,
    ): array {
        try {
            try {
                $fresh = $this->readFresh($generation, $fingerprint);
            } catch (Throwable $cacheFailure) {
                $fresh = null;
                $this->recordState($filters, 'bypass', $cacheFailure);
            }
            if ($fresh !== null) {
                $this->recordState($filters, 'hit');

                return ['state' => 'hit', 'payload' => $fresh];
            }

            $startedAt = microtime(true);
            try {
                $payload = $builder();
            } catch (Throwable $throwable) {
                if ($stale !== null) {
                    $this->recordState($filters, 'stale', $throwable, $startedAt);

                    return ['state' => 'stale', 'payload' => $stale];
                }

                throw $throwable;
            }

            try {
                $this->putIfCurrent($generation, $fingerprint, $payload);
            } catch (Throwable $cacheFailure) {
                $this->recordState($filters, 'bypass', $cacheFailure);
            }
            $this->recordState($filters, 'miss', null, $startedAt);

            return ['state' => 'miss', 'payload' => $payload];
        } finally {
            try {
                $lock->release();
            } catch (Throwable $cacheFailure) {
                $this->recordState($filters, 'bypass', $cacheFailure);
            }
        }
    }

    /**
     * @param  array{org_id:int,locale:?string,related_test_slug:?string,voice:?string,page:int,per_page:int}  $filters
     * @param  callable():array<string,mixed>  $builder
     * @param  array<string,mixed>|null  $stale
     * @return array{state:'miss'|'stale',payload:array<string,mixed>}
     */
    private function buildAndRecord(
        array $filters,
        callable $builder,
        ?array $stale,
        string $state,
    ): array {
        $startedAt = microtime(true);
        try {
            $payload = $builder();
        } catch (Throwable $throwable) {
            if ($stale !== null) {
                $this->recordState($filters, 'stale', $throwable, $startedAt);

                return ['state' => 'stale', 'payload' => $stale];
            }

            throw $throwable;
        }

        $this->recordState($filters, $state, null, $startedAt);

        return ['state' => 'miss', 'payload' => $payload];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readFresh(string $generation, string $fingerprint): ?array
    {
        $envelope = $this->readEnvelope($generation, $fingerprint);
        if ($envelope === null || (float) $envelope['fresh_until'] < microtime(true)) {
            return null;
        }

        return $envelope['payload'];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readStale(string $generation, string $fingerprint): ?array
    {
        $current = $this->readEnvelope($generation, $fingerprint);
        if ($current !== null) {
            return $current['payload'];
        }

        $previous = Cache::get($this->previousGenerationKey());
        if (! is_string($previous) || $previous === '') {
            return null;
        }

        return $this->readEnvelope($previous, $fingerprint)['payload'] ?? null;
    }

    /**
     * @return array{fresh_until:float,payload:array<string,mixed>}|null
     */
    private function readEnvelope(string $generation, string $fingerprint): ?array
    {
        $value = Cache::get($this->payloadKey($generation, $fingerprint));
        if (! is_array($value)
            || ! is_numeric($value['fresh_until'] ?? null)
            || ! is_array($value['payload'] ?? null)) {
            return null;
        }

        return [
            'fresh_until' => (float) $value['fresh_until'],
            'payload' => $value['payload'],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function waitForFresh(string $generation, string $fingerprint): ?array
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            usleep(100000);
            $payload = $this->readFresh($generation, $fingerprint);
            if ($payload !== null) {
                return $payload;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function putIfCurrent(string $generation, string $fingerprint, array $payload): void
    {
        if (! hash_equals($generation, $this->currentGeneration())) {
            return;
        }

        Cache::put($this->payloadKey($generation, $fingerprint), [
            'fresh_until' => microtime(true) + self::FRESH_TTL_SECONDS,
            'payload' => $payload,
        ], self::LKG_TTL_SECONDS);
    }

    private function currentGeneration(): string
    {
        $key = $this->generationKey();
        $generation = Cache::get($key);
        if (is_string($generation) && $generation !== '') {
            return $generation;
        }

        $candidate = $this->newGeneration();
        Cache::add($key, $candidate, null);
        $generation = Cache::get($key);

        return is_string($generation) && $generation !== '' ? $generation : $candidate;
    }

    /**
     * @param  array{org_id:int,locale:?string,related_test_slug:?string,voice:?string,page:int,per_page:int}  $filters
     */
    private function isCacheable(array $filters): bool
    {
        return $filters['org_id'] === 0
            && in_array($filters['locale'], ['en', 'zh-CN'], true)
            && $filters['related_test_slug'] === null
            && $filters['voice'] === null
            && $filters['page'] <= 50;
    }

    /**
     * @param  array{org_id:int,locale:?string,related_test_slug:?string,voice:?string,page:int,per_page:int}  $filters
     */
    private function fingerprint(array $filters): string
    {
        return hash('xxh3', json_encode([
            'locale' => $filters['locale'],
            'page' => $filters['page'],
            'per_page' => $filters['per_page'],
        ], JSON_THROW_ON_ERROR));
    }

    private function generationKey(): string
    {
        return self::CACHE_KEY_PREFIX.':generation';
    }

    private function previousGenerationKey(): string
    {
        return self::CACHE_KEY_PREFIX.':previous-generation';
    }

    private function payloadKey(string $generation, string $fingerprint): string
    {
        return implode(':', [
            self::CACHE_KEY_PREFIX,
            'payload',
            hash('xxh3', $generation),
            $fingerprint,
        ]);
    }

    private function lockKey(string $generation, string $fingerprint): string
    {
        return $this->payloadKey($generation, $fingerprint).':build-lock';
    }

    private function newGeneration(): string
    {
        return hash('xxh3', microtime(true).':'.random_int(PHP_INT_MIN, PHP_INT_MAX));
    }

    /**
     * @param  array{org_id:int,locale:?string,related_test_slug:?string,voice:?string,page:int,per_page:int}  $filters
     */
    private function recordState(
        array $filters,
        string $state,
        ?Throwable $throwable = null,
        ?float $startedAt = null,
    ): void {
        try {
            Log::info('article_public_list_cache', array_filter([
                'cache_state' => $state,
                'locale' => $filters['locale'],
                'page' => $filters['page'],
                'per_page' => $filters['per_page'],
                'build_ms' => $startedAt !== null
                    ? round((microtime(true) - $startedAt) * 1000, 2)
                    : null,
                'error_class' => $throwable !== null ? $throwable::class : null,
            ], static fn (mixed $value): bool => $value !== null));
        } catch (Throwable) {
            return;
        }
    }

    private function recordInvalidationFailure(Throwable $throwable): void
    {
        try {
            Log::warning('article_public_list_cache_invalidation_failed', [
                'error_class' => $throwable::class,
            ]);
        } catch (Throwable) {
            return;
        }
    }
}
