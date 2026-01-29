<?php

namespace App\Services\Scale;

use App\Models\ScaleRegistry as ScaleRegistryModel;
use App\Models\ScaleSlug;
use App\Support\CacheKeys;
use Illuminate\Support\Facades\Cache;

class ScaleRegistry
{
    public const CACHE_TTL_SECONDS = 300;

    public function listActivePublic(int $orgId = 0): array
    {
        $cacheKey = CacheKeys::scaleRegistryActive($orgId);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $rows = ScaleRegistryModel::query()
            ->where('org_id', $orgId)
            ->where('is_active', true)
            ->where('is_public', true)
            ->orderBy('code')
            ->get()
            ->toArray();

        Cache::put($cacheKey, $rows, self::CACHE_TTL_SECONDS);

        return $rows;
    }

    public function getByCode(string $code, int $orgId = 0): ?array
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        $cacheKey = CacheKeys::scaleRegistryByCode($orgId, $code);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $row = ScaleRegistryModel::query()
            ->where('org_id', $orgId)
            ->where('code', $code)
            ->first();

        if (!$row) {
            return null;
        }

        $payload = $row->toArray();
        Cache::put($cacheKey, $payload, self::CACHE_TTL_SECONDS);

        return $payload;
    }

    public function lookupBySlug(string $slug, int $orgId = 0): ?array
    {
        $slug = trim(strtolower($slug));
        if ($slug === '') {
            return null;
        }
        if (!preg_match('/^[a-z0-9-]{0,127}$/', $slug)) {
            return null;
        }

        $cacheKey = CacheKeys::scaleRegistryBySlug($orgId, $slug);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $slugRow = ScaleSlug::query()
            ->where('org_id', $orgId)
            ->where('slug', $slug)
            ->first();

        if (!$slugRow) {
            return null;
        }

        $registry = ScaleRegistryModel::query()
            ->where('org_id', $orgId)
            ->where('code', $slugRow->scale_code)
            ->first();

        if (!$registry) {
            return null;
        }

        $payload = $registry->toArray();
        Cache::put($cacheKey, $payload, self::CACHE_TTL_SECONDS);

        return $payload;
    }
}
