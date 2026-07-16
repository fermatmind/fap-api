<?php

declare(strict_types=1);

namespace App\Services\Analytics\ProviderFreshness;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

final class ProviderSnapshotStore
{
    public const SCHEMA_VERSION = 'analytics.provider_freshness.v1';

    /** @return array<string,mixed>|null */
    public function read(): ?array
    {
        $value = $this->cache()->get($this->key());

        return is_array($value) && ($value['schema_version'] ?? null) === self::SCHEMA_VERSION
            ? $value
            : null;
    }

    /** @param array<string,mixed> $snapshot */
    public function write(array $snapshot): void
    {
        $this->cache()->forever($this->key(), $snapshot);
    }

    private function cache(): Repository
    {
        $store = config('analytics.provider_freshness.cache_store');

        return Cache::store(is_string($store) && trim($store) !== '' ? trim($store) : null);
    }

    private function key(): string
    {
        return (string) config('analytics.provider_freshness.cache_key', 'analytics:provider-freshness:v1');
    }
}
