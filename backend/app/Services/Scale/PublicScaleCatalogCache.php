<?php

declare(strict_types=1);

namespace App\Services\Scale;

use App\Support\CacheKeys;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PublicScaleCatalogCache
{
    /**
     * @param  callable():array<string,mixed>  $builder
     * @return array{payload:array<string,mixed>,state:string}
     */
    public function read(int $orgId, string $locale, callable $builder): array
    {
        $generation = $this->generation($orgId);
        $payloadKey = CacheKeys::publicScaleCatalog($orgId, $locale, $generation);
        $cached = $this->validatedEnvelope($this->store()->get($payloadKey), $orgId, $locale, $generation);
        $now = now()->getTimestamp();

        if ($cached !== null && $now <= $cached['fresh_until']) {
            return ['payload' => $cached['payload'], 'state' => 'hit'];
        }

        if ($cached !== null && $now <= $cached['stale_until']) {
            $this->scheduleRefresh($payloadKey, $orgId, $locale, $generation, $builder);

            return ['payload' => $cached['payload'], 'state' => 'stale'];
        }

        return $this->buildCold($payloadKey, $orgId, $locale, $generation, $builder);
    }

    public function generation(int $orgId): int
    {
        $value = $this->store()->get(CacheKeys::publicScaleRegistryGeneration($orgId));

        return is_numeric($value) && (int) $value > 0 ? (int) $value : 1;
    }

    public function bumpGeneration(int $orgId): int
    {
        $key = CacheKeys::publicScaleRegistryGeneration($orgId);
        $store = $this->store();
        $store->add($key, 1);
        $next = $store->increment($key);

        return is_numeric($next) && (int) $next > 1 ? (int) $next : 2;
    }

    /**
     * Store a validated payload for an explicit warm/single-flight publication.
     *
     * @param  array<string,mixed>  $payload
     */
    public function storeValidated(int $orgId, string $locale, array $payload): void
    {
        $generation = $this->generation($orgId);
        $this->storePayload(
            CacheKeys::publicScaleCatalog($orgId, $locale, $generation),
            $orgId,
            $locale,
            $generation,
            $payload
        );
    }

    /**
     * @param  callable():array<string,mixed>  $builder
     * @return array{payload:array<string,mixed>,state:string}
     */
    private function buildCold(
        string $payloadKey,
        int $orgId,
        string $locale,
        int $generation,
        callable $builder,
    ): array {
        $store = $this->store();
        $lock = $store->lock(
            CacheKeys::publicScaleCatalogLock($payloadKey),
            max(1, (int) config('content_packs.public_scale_catalog_lock_ttl_seconds', 30))
        );

        if ($lock->get()) {
            try {
                $existing = $this->validatedEnvelope($store->get($payloadKey), $orgId, $locale, $generation);
                if ($existing !== null && now()->getTimestamp() <= $existing['stale_until']) {
                    return ['payload' => $existing['payload'], 'state' => 'wait-hit'];
                }

                $payload = $this->buildValidated($builder, $locale);
                $this->storePayload($payloadKey, $orgId, $locale, $generation, $payload);

                return ['payload' => $payload, 'state' => 'miss'];
            } catch (\Throwable $e) {
                $this->logFailure('cold_build_failed', $orgId, $locale, $e);
                throw new PublicScaleCatalogUnavailable('Public scale catalog is temporarily unavailable.', 0, $e);
            } finally {
                $lock->release();
            }
        }

        $deadline = microtime(true) + (max(0, (int) config(
            'content_packs.public_scale_catalog_wait_budget_ms',
            250
        )) / 1000);
        $interval = max(1, (int) config('content_packs.public_scale_catalog_wait_interval_ms', 25));

        do {
            $this->sleepMilliseconds($interval);
            $cached = $this->validatedEnvelope($store->get($payloadKey), $orgId, $locale, $generation);
            if ($cached !== null && now()->getTimestamp() <= $cached['stale_until']) {
                return ['payload' => $cached['payload'], 'state' => 'wait-hit'];
            }
        } while (microtime(true) < $deadline);

        $this->logFailure('cold_wait_exhausted', $orgId, $locale);
        throw new PublicScaleCatalogUnavailable('Public scale catalog is temporarily unavailable.');
    }

    /**
     * @param  callable():array<string,mixed>  $builder
     */
    private function scheduleRefresh(
        string $payloadKey,
        int $orgId,
        string $locale,
        int $generation,
        callable $builder,
    ): void {
        defer(function () use ($payloadKey, $orgId, $locale, $generation, $builder): void {
            $store = $this->store();
            $lock = $store->lock(
                CacheKeys::publicScaleCatalogLock($payloadKey),
                max(1, (int) config('content_packs.public_scale_catalog_lock_ttl_seconds', 30))
            );
            if (! $lock->get()) {
                return;
            }

            try {
                $current = $this->validatedEnvelope($store->get($payloadKey), $orgId, $locale, $generation);
                if ($current !== null && now()->getTimestamp() <= $current['fresh_until']) {
                    return;
                }

                $payload = $this->buildValidated($builder, $locale);
                $this->storePayload($payloadKey, $orgId, $locale, $generation, $payload);
            } catch (\Throwable $e) {
                $this->logFailure('stale_refresh_failed', $orgId, $locale, $e);
            } finally {
                $lock->release();
            }
        }, 'public-scale-catalog-refresh:'.hash('sha256', $payloadKey), true);
    }

    /**
     * @param  callable():array<string,mixed>  $builder
     * @return array<string,mixed>
     */
    private function buildValidated(callable $builder, string $locale): array
    {
        $payload = $builder();
        if (! $this->validPayload($payload, $locale)) {
            throw new \UnexpectedValueException('Public scale catalog projection failed validation.');
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function storePayload(
        string $payloadKey,
        int $orgId,
        string $locale,
        int $generation,
        array $payload,
    ): void {
        if (! $this->validPayload($payload, $locale)) {
            throw new \UnexpectedValueException('Public scale catalog projection failed validation.');
        }

        $freshTtl = $this->freshTtl($payloadKey);
        $staleTtl = max(1, (int) config('content_packs.public_scale_catalog_stale_ttl_seconds', 3600));
        $createdAt = now()->getTimestamp();
        $envelope = [
            'schema' => (string) config('content_packs.public_scale_catalog_schema_version', 'v1'),
            'org_id' => $orgId,
            'locale' => $locale,
            'generation' => $generation,
            'created_at' => $createdAt,
            'fresh_until' => $createdAt + $freshTtl,
            'stale_until' => $createdAt + $freshTtl + $staleTtl,
            'payload' => $payload,
        ];
        $this->store()->put($payloadKey, $envelope, $freshTtl + $staleTtl);
    }

    /**
     * @return array{fresh_until:int,stale_until:int,payload:array<string,mixed>}|null
     */
    private function validatedEnvelope(mixed $value, int $orgId, string $locale, int $generation): ?array
    {
        if (! is_array($value)
            || ($value['schema'] ?? null) !== (string) config('content_packs.public_scale_catalog_schema_version', 'v1')
            || (int) ($value['org_id'] ?? -1) !== $orgId
            || ($value['locale'] ?? null) !== $locale
            || (int) ($value['generation'] ?? 0) !== $generation
            || ! is_numeric($value['fresh_until'] ?? null)
            || ! is_numeric($value['stale_until'] ?? null)
            || ! is_array($value['payload'] ?? null)
            || ! $this->validPayload($value['payload'], $locale)
        ) {
            return null;
        }

        return [
            'fresh_until' => (int) $value['fresh_until'],
            'stale_until' => (int) $value['stale_until'],
            'payload' => $value['payload'],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function validPayload(array $payload, string $locale): bool
    {
        if (($payload['ok'] ?? null) !== true
            || ($payload['locale'] ?? null) !== $locale
            || ! is_array($payload['items'] ?? null)
            || $payload['items'] === []
        ) {
            return false;
        }

        foreach ($payload['items'] as $item) {
            if (! is_array($item)
                || trim((string) ($item['slug'] ?? '')) === ''
                || ($item['is_public'] ?? null) !== true
                || ($item['is_active'] ?? null) !== true
            ) {
                return false;
            }
        }

        return true;
    }

    private function freshTtl(string $payloadKey): int
    {
        $base = max(1, (int) config('content_packs.public_scale_catalog_fresh_ttl_seconds', 300));
        $jitter = max(0, min(
            $base - 1,
            (int) config('content_packs.public_scale_catalog_ttl_jitter_seconds', 30)
        ));
        if ($jitter === 0) {
            return $base;
        }

        $offset = hexdec(substr(hash('sha256', $payloadKey), 0, 8)) % ($jitter + 1);

        return max(1, $base - $offset);
    }

    protected function sleepMilliseconds(int $milliseconds): void
    {
        usleep($milliseconds * 1000);
    }

    private function store(): Repository
    {
        $configured = trim((string) config('content_packs.public_scale_cache_store', ''));
        $store = $configured !== ''
            ? $configured
            : (string) config('content_packs.mbti_response_cache_store', 'hot_redis');

        return Cache::store($store);
    }

    private function logFailure(
        string $event,
        int $orgId,
        string $locale,
        ?\Throwable $exception = null,
    ): void {
        try {
            Log::warning('[public_scale_catalog_cache] '.$event, array_filter([
                'org_id' => $orgId,
                'locale' => $locale,
                'exception' => $exception === null ? null : $exception::class,
            ]));
        } catch (\Throwable) {
            // Telemetry must never change the public read result.
        }
    }
}
