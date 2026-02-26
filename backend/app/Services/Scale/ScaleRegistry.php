<?php

namespace App\Services\Scale;

use App\Models\ScaleRegistry as ScaleRegistryModel;
use App\Models\ScaleSlug;
use App\Support\CacheKeys;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ScaleRegistry
{
    public const CACHE_TTL_SECONDS = 300;
    private const REGISTRY_V2_TABLE = 'scales_registry_v2';
    private const REGISTRY_LEGACY_TABLE = 'scales_registry';

    public function __construct(
        private ScaleIdentityResolver $identityResolver,
    ) {}

    public function listVisible(int $orgId = 0): array
    {
        if ($orgId <= 0) {
            return $this->listActivePublic(0);
        }

        $cacheKey = CacheKeys::scaleRegistryActive($orgId);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        if ($this->useV2ForTenantReads($orgId)) {
            $tenantRows = $this->v2RegistryQuery()
                ->where('org_id', $orgId)
                ->where('is_active', true)
                ->orderBy('code')
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all();

            $legacyTenantRows = $this->registryQueryForOrg($orgId)
                ->where('org_id', $orgId)
                ->where('is_active', true)
                ->orderBy('code')
                ->get()
                ->toArray();
            $tenantRows = $this->mergeRowsByCode($tenantRows, $legacyTenantRows);

            $globalRows = $this->registryQueryForOrg(0)
                ->where('org_id', 0)
                ->where('is_active', true)
                ->where('is_public', true)
                ->orderBy('code')
                ->get()
                ->toArray();

            $rows = $this->mergeRowsByCode($tenantRows, $globalRows);
            Cache::put($cacheKey, $rows, self::CACHE_TTL_SECONDS);

            return $rows;
        }

        $rows = $this->registryQueryForOrg($orgId, true)
            ->where('is_active', true)
            ->where(function ($q) use ($orgId) {
                $q->where('org_id', $orgId)
                    ->orWhere(function ($q) {
                        $q->where('org_id', 0)->where('is_public', true);
                    });
            })
            ->orderBy('code')
            ->get()
            ->toArray();

        Cache::put($cacheKey, $rows, self::CACHE_TTL_SECONDS);

        return $rows;
    }

    public function listActivePublic(int $orgId = 0): array
    {
        $cacheKey = CacheKeys::scaleRegistryActive(0);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $rows = $this->registryQueryForOrg(0)
            ->where('org_id', 0)
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
        $requestedCode = strtoupper(trim($code));
        if ($requestedCode === '') {
            return null;
        }

        $cacheKey = CacheKeys::scaleRegistryByCode($orgId, $requestedCode);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $row = $this->findByCode($requestedCode, $orgId);
        if (! $row) {
            $resolvedCode = $this->normalizeLookupCode($requestedCode);
            if ($resolvedCode !== '' && $resolvedCode !== $requestedCode) {
                $row = $this->findByCode($resolvedCode, $orgId);
            }
        }

        if (! $row) {
            return null;
        }

        Cache::put($cacheKey, $row, self::CACHE_TTL_SECONDS);

        return $row;
    }

    public function lookupBySlug(string $slug, int $orgId = 0, bool $allowAlias = true): ?array
    {
        $slug = trim(strtolower($slug));
        if ($slug === '') {
            return null;
        }
        if (! preg_match('/^[a-z0-9-]{0,127}$/', $slug)) {
            return null;
        }

        $cacheSuffix = $allowAlias ? "compat:{$slug}" : "canonical:{$slug}";
        $cacheKey = CacheKeys::scaleRegistryBySlug($orgId, $cacheSuffix);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        if ($this->useV2ForTenantReads($orgId)) {
            $row = $this->lookupBySlugFromV2($slug, $orgId, $allowAlias);
            if ($row) {
                Cache::put($cacheKey, $row, self::CACHE_TTL_SECONDS);

                return $row;
            }
        }

        if (! $allowAlias) {
            $registry = null;
            if ($orgId <= 0) {
                $registry = $this->registryQueryForOrg(0)
                    ->where('org_id', 0)
                    ->where('primary_slug', $slug)
                    ->where('is_public', true)
                    ->first();
            } else {
                $registry = $this->registryQueryForOrg($orgId)
                    ->where('org_id', $orgId)
                    ->where('primary_slug', $slug)
                    ->first();
                if (! $registry) {
                    $registry = $this->registryQueryForOrg(0)
                        ->where('org_id', 0)
                        ->where('primary_slug', $slug)
                        ->where('is_public', true)
                        ->first();
                }
            }

            if (! $registry) {
                return null;
            }

            $payload = $registry->toArray();
            Cache::put($cacheKey, $payload, self::CACHE_TTL_SECONDS);

            return $payload;
        }

        $slugRow = null;
        if ($orgId <= 0) {
            $slugRow = $this->slugQueryForOrg(0)
                ->where('org_id', 0)
                ->where('slug', $slug)
                ->first();
        } else {
            $slugRow = $this->slugQueryForOrg($orgId)
                ->where('org_id', $orgId)
                ->where('slug', $slug)
                ->first();
            if (! $slugRow) {
                $slugRow = $this->slugQueryForOrg(0)
                    ->where('org_id', 0)
                    ->where('slug', $slug)
                    ->first();
            }
        }

        if (! $slugRow) {
            return null;
        }

        $registryOrgId = (int) ($slugRow->org_id ?? $orgId);
        $registry = $this->registryQueryForOrg($registryOrgId)
            ->where('org_id', $registryOrgId)
            ->where('code', $slugRow->scale_code)
            ->when($registryOrgId === 0, function ($q) {
                $q->where('is_public', true);
            })
            ->first();

        if (! $registry) {
            return null;
        }

        $payload = $registry->toArray();
        Cache::put($cacheKey, $payload, self::CACHE_TTL_SECONDS);

        return $payload;
    }

    private function findByCode(string $code, int $orgId): ?array
    {
        if ($this->useV2ForTenantReads($orgId)) {
            $tenantRow = $this->v2RegistryQuery()
                ->where('org_id', $orgId)
                ->where('code', $code)
                ->first();
            if ($tenantRow) {
                return (array) $tenantRow;
            }
        }

        if ($orgId <= 0) {
            $row = $this->registryQueryForOrg(0)
                ->where('org_id', 0)
                ->where('code', $code)
                ->where('is_public', true)
                ->first();
            if (! $row) {
                return null;
            }

            return $row->toArray();
        }

        $row = $this->registryQueryForOrg($orgId)
            ->where('org_id', $orgId)
            ->where('code', $code)
            ->first();
        if ($row) {
            return $row->toArray();
        }

        $globalRow = $this->registryQueryForOrg(0)
            ->where('org_id', 0)
            ->where('code', $code)
            ->where('is_public', true)
            ->first();
        if (! $globalRow) {
            return null;
        }

        return $globalRow->toArray();
    }

    private function registryQueryForOrg(int $orgId, bool $includeGlobalFallback = false): Builder
    {
        $orgWhitelist = [$orgId > 0 ? $orgId : 0];
        if ($includeGlobalFallback && $orgId > 0) {
            $orgWhitelist[] = 0;
        }

        return ScaleRegistryModel::queryByOrgWhitelist($orgWhitelist);
    }

    private function slugQueryForOrg(int $orgId, bool $includeGlobalFallback = false): Builder
    {
        $orgWhitelist = [$orgId > 0 ? $orgId : 0];
        if ($includeGlobalFallback && $orgId > 0) {
            $orgWhitelist[] = 0;
        }

        return ScaleSlug::queryByOrgWhitelist($orgWhitelist);
    }

    private function useV2ForTenantReads(int $orgId): bool
    {
        if ($orgId <= 0) {
            return false;
        }

        if (! (bool) config('fap.scales_registry.use_v2', true)) {
            return false;
        }

        return Schema::hasTable(self::REGISTRY_V2_TABLE);
    }

    private function v2RegistryQuery()
    {
        return DB::table(self::REGISTRY_V2_TABLE);
    }

    private function lookupBySlugFromV2(string $slug, int $orgId, bool $allowAlias): ?array
    {
        if (! $allowAlias) {
            $tenantRegistry = $this->v2RegistryQuery()
                ->where('org_id', $orgId)
                ->where('primary_slug', $slug)
                ->first();
            if ($tenantRegistry) {
                return (array) $tenantRegistry;
            }

            $globalRegistry = $this->registryQueryForOrg(0)
                ->where('org_id', 0)
                ->where('primary_slug', $slug)
                ->where('is_public', true)
                ->first();

            return $globalRegistry ? $globalRegistry->toArray() : null;
        }

        $slugRow = $this->slugQueryForOrg($orgId)
            ->where('org_id', $orgId)
            ->where('slug', $slug)
            ->first();
        if (! $slugRow) {
            $slugRow = $this->slugQueryForOrg(0)
                ->where('org_id', 0)
                ->where('slug', $slug)
                ->first();
        }
        if (! $slugRow) {
            return null;
        }

        $slugOrgId = (int) ($slugRow->org_id ?? 0);
        $scaleCode = strtoupper(trim((string) ($slugRow->scale_code ?? '')));
        if ($scaleCode === '') {
            return null;
        }

        if ($slugOrgId > 0) {
            $tenantRegistry = $this->v2RegistryQuery()
                ->where('org_id', $slugOrgId)
                ->where('code', $scaleCode)
                ->first();
            if ($tenantRegistry) {
                return (array) $tenantRegistry;
            }
        }

        $globalRegistry = $this->registryQueryForOrg(0)
            ->where('org_id', 0)
            ->where('code', $scaleCode)
            ->where('is_public', true)
            ->first();

        return $globalRegistry ? $globalRegistry->toArray() : null;
    }

    /**
     * @param  list<array<string,mixed>>  $tenantRows
     * @param  list<array<string,mixed>>  $globalRows
     * @return list<array<string,mixed>>
     */
    private function mergeRowsByCode(array $tenantRows, array $globalRows): array
    {
        $byCode = [];

        foreach ($tenantRows as $row) {
            $code = strtoupper(trim((string) ($row['code'] ?? '')));
            if ($code === '') {
                continue;
            }
            $byCode[$code] = $row;
        }

        foreach ($globalRows as $row) {
            $code = strtoupper(trim((string) ($row['code'] ?? '')));
            if ($code === '' || isset($byCode[$code])) {
                continue;
            }
            $byCode[$code] = $row;
        }

        ksort($byCode);

        return array_values($byCode);
    }

    private function normalizeLookupCode(string $requestedCode): string
    {
        $identity = $this->identityResolver->resolveByAnyCode($requestedCode);
        if (! is_array($identity) || ! ((bool) ($identity['is_known'] ?? false))) {
            return $requestedCode;
        }

        $legacyCode = strtoupper(trim((string) ($identity['scale_code_v1'] ?? '')));
        if ($legacyCode === '') {
            return $requestedCode;
        }

        $isLegacyInput = $requestedCode === $legacyCode;
        if (
            $isLegacyInput
            && ! $this->runtimePolicy()->acceptsLegacyScaleCode()
        ) {
            return '';
        }

        // Current storage is still v1; known aliases are resolved to v1 for read compatibility.
        return $legacyCode;
    }

    private function runtimePolicy(): ScaleIdentityRuntimePolicy
    {
        return app(ScaleIdentityRuntimePolicy::class);
    }
}
