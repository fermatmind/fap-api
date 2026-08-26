<?php

namespace App\Services\Scale;

use App\Models\ScaleRegistry as ScaleRegistryModel;
use App\Models\ScaleSlug;
use App\Support\CacheKeys;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ScaleRegistry
{
    public const CACHE_TTL_SECONDS = 300;

    private const REGISTRY_V2_TABLE = 'scales_registry_v2';

    private const REGISTRY_LEGACY_TABLE = 'scales_registry';

    /**
     * Every field consumed by ScalesLookupController::projectCatalogItem() or
     * one of its direct projectors. Keep this projection explicit so a catalog
     * miss never hydrates the complete registry row.
     *
     * @var list<string>
     */
    private const PUBLIC_CATALOG_COLUMNS = [
        'code',
        'primary_slug',
        'default_pack_id',
        'default_dir_version',
        'default_locale',
        'capabilities_json',
        'view_policy_json',
        'seo_schema_json',
        'seo_i18n_json',
        'content_i18n_json',
        'is_public',
        'is_active',
        'is_indexable',
    ];

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

        if ($this->canUseV2ForReads()) {
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
            Cache::put($cacheKey, $tenantRows, self::CACHE_TTL_SECONDS);

            return $tenantRows;
        }

        $query = $this->registryQueryForOrg($orgId)
            ->where('is_active', true)
            ->where('org_id', $orgId);

        $rows = $query
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

        $rows = $this->listActivePublicFromV2();
        if ($rows === []) {
            $rows = $this->listActivePublicFromLegacy();
        }

        Cache::put($cacheKey, $rows, self::CACHE_TTL_SECONDS);

        return $rows;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listActivePublicForCatalog(int $orgId = 0): array
    {
        if ($orgId !== 0) {
            return [];
        }

        $rows = $this->listActivePublicCatalogFromV2();
        if ($rows === []) {
            $rows = $this->listActivePublicCatalogFromLegacy();
        }

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
            return $this->canExposeRegistryRow($cached, $orgId) ? $cached : null;
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
            return $this->canExposeRegistryRow($cached, $orgId) ? $cached : null;
        }

        if ($this->canUseV2ForReads()) {
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
                    ->where('is_active', true)
                    ->first();
            } else {
                $registry = $this->registryQueryForOrg($orgId)
                    ->where('org_id', $orgId)
                    ->where('primary_slug', $slug)
                    ->first();
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
        }

        if (! $slugRow) {
            return null;
        }

        $registryOrgId = (int) ($slugRow->org_id ?? $orgId);
        if ($orgId > 0 && $registryOrgId !== $orgId) {
            return null;
        }
        $registry = $this->registryQueryForOrg($registryOrgId)
            ->where('org_id', $registryOrgId)
            ->where('code', $slugRow->scale_code)
            ->when($registryOrgId === 0, function ($q) {
                $q->where('is_public', true)
                    ->where('is_active', true);
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
        if ($orgId <= 0) {
            $v2Row = $this->findPublicByCodeFromV2($code);
            if ($v2Row !== null) {
                return $v2Row;
            }

            return $this->findPublicByCodeFromLegacy($code);
        }

        if ($this->canUseV2ForReads()) {
            $tenantRow = $this->v2RegistryQuery()
                ->where('org_id', $orgId)
                ->where('code', $code)
                ->first();
            if ($tenantRow) {
                return $this->normalizeV2RegistryRow((array) $tenantRow);
            }
        }

        $row = $this->registryQueryForOrg($orgId)
            ->where('org_id', $orgId)
            ->where('code', $code)
            ->first();
        if ($row) {
            return $row->toArray();
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function canExposeRegistryRow(array $row, int $orgId): bool
    {
        if ($orgId > 0) {
            return true;
        }

        return (bool) ($row['is_public'] ?? false) && (bool) ($row['is_active'] ?? false);
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

    private function canUseV2ForReads(): bool
    {
        if (! (bool) config('fap.scales_registry.use_v2', true)) {
            return false;
        }

        return Schema::hasTable(self::REGISTRY_V2_TABLE);
    }

    private function v2RegistryQuery()
    {
        return DB::table(self::REGISTRY_V2_TABLE);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function listActivePublicFromLegacy(): array
    {
        if (! Schema::hasTable(self::REGISTRY_LEGACY_TABLE)) {
            return [];
        }

        try {
            return $this->registryQueryForOrg(0)
                ->where('org_id', 0)
                ->where('is_active', true)
                ->where('is_public', true)
                ->orderBy('code')
                ->get()
                ->toArray();
        } catch (\Throwable $e) {
            Log::warning('[scale_registry] legacy_public_read_failed', [
                'source' => 'scale_registry.list_active_public',
                'exception' => $e::class,
            ]);

            return [];
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function listActivePublicCatalogFromLegacy(): array
    {
        if (! Schema::hasTable(self::REGISTRY_LEGACY_TABLE)) {
            return [];
        }

        try {
            return $this->registryQueryForOrg(0)
                ->select(self::PUBLIC_CATALOG_COLUMNS)
                ->where('org_id', 0)
                ->where('is_active', true)
                ->where('is_public', true)
                ->orderBy('code')
                ->get()
                ->map(static fn (ScaleRegistryModel $row): array => $row->toArray())
                ->all();
        } catch (\Throwable $e) {
            Log::warning('[scale_registry] legacy_public_catalog_read_failed', [
                'source' => 'scale_registry.list_active_public_for_catalog',
                'exception' => $e::class,
            ]);

            return [];
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function listActivePublicFromV2(): array
    {
        if (! $this->canReadPublicFromV2()) {
            return [];
        }

        try {
            return $this->v2RegistryQuery()
                ->where('org_id', 0)
                ->where('is_active', true)
                ->where('is_public', true)
                ->orderBy('code')
                ->get()
                ->map(fn (object $row): array => $this->normalizeV2RegistryRow((array) $row))
                ->all();
        } catch (\Throwable $e) {
            Log::warning('[scale_registry] v2_public_read_failed', [
                'source' => 'scale_registry.list_active_public',
                'exception' => $e::class,
            ]);

            return [];
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function listActivePublicCatalogFromV2(): array
    {
        if (! $this->canReadPublicFromV2()) {
            return [];
        }

        try {
            return $this->v2RegistryQuery()
                ->select(self::PUBLIC_CATALOG_COLUMNS)
                ->where('org_id', 0)
                ->where('is_active', true)
                ->where('is_public', true)
                ->orderBy('code')
                ->get()
                ->map(fn (object $row): array => $this->normalizeV2RegistryRow((array) $row))
                ->all();
        } catch (\Throwable $e) {
            Log::warning('[scale_registry] v2_public_catalog_read_failed', [
                'source' => 'scale_registry.list_active_public_for_catalog',
                'exception' => $e::class,
            ]);

            return [];
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findPublicByCodeFromLegacy(string $code): ?array
    {
        if (! Schema::hasTable(self::REGISTRY_LEGACY_TABLE)) {
            return null;
        }

        try {
            $row = $this->registryQueryForOrg(0)
                ->where('org_id', 0)
                ->where('code', $code)
                ->where('is_public', true)
                ->where('is_active', true)
                ->first();

            return $row ? $row->toArray() : null;
        } catch (\Throwable $e) {
            Log::warning('[scale_registry] legacy_public_lookup_failed', [
                'source' => 'scale_registry.find_by_code',
                'exception' => $e::class,
            ]);

            return null;
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findPublicByCodeFromV2(string $code): ?array
    {
        if (! $this->canReadPublicFromV2()) {
            return null;
        }

        try {
            $row = $this->v2RegistryQuery()
                ->where('org_id', 0)
                ->where('code', $code)
                ->where('is_public', true)
                ->where('is_active', true)
                ->first();
            if (! $row) {
                return null;
            }

            return $this->normalizeV2RegistryRow((array) $row);
        } catch (\Throwable $e) {
            Log::warning('[scale_registry] v2_public_lookup_failed', [
                'source' => 'scale_registry.find_by_code',
                'exception' => $e::class,
            ]);

            return null;
        }
    }

    private function canReadPublicFromV2(): bool
    {
        if (! (bool) config('fap.scales_registry.use_v2', true)) {
            return false;
        }

        return Schema::hasTable(self::REGISTRY_V2_TABLE);
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function normalizeV2RegistryRow(array $row): array
    {
        foreach ([
            'slugs_json',
            'capabilities_json',
            'view_policy_json',
            'commercial_json',
            'seo_schema_json',
            'seo_i18n_json',
            'content_i18n_json',
            'report_summary_i18n_json',
        ] as $jsonColumn) {
            $value = $row[$jsonColumn] ?? null;
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $row[$jsonColumn] = $decoded;
            }
        }

        return $row;
    }

    private function lookupBySlugFromV2(string $slug, int $orgId, bool $allowAlias): ?array
    {
        if (! $allowAlias) {
            $registry = $this->v2RegistryQuery()
                ->where('org_id', $orgId)
                ->where('primary_slug', $slug)
                ->when($orgId === 0, function ($query): void {
                    $query->where('is_public', true)
                        ->where('is_active', true);
                })
                ->first();
            if ($registry) {
                return $this->normalizeV2RegistryRow((array) $registry);
            }

            return null;
        }

        $slugRow = $this->slugQueryForOrg($orgId)
            ->where('org_id', $orgId)
            ->where('slug', $slug)
            ->first();
        if (! $slugRow) {
            return null;
        }

        $slugOrgId = (int) ($slugRow->org_id ?? 0);
        $scaleCode = strtoupper(trim((string) ($slugRow->scale_code ?? '')));
        if ($scaleCode === '') {
            return null;
        }

        $registry = $this->v2RegistryQuery()
            ->where('org_id', $slugOrgId)
            ->where('code', $scaleCode)
            ->when($slugOrgId === 0, function ($query): void {
                $query->where('is_public', true)
                    ->where('is_active', true);
            })
            ->first();
        if ($registry) {
            return $this->normalizeV2RegistryRow((array) $registry);
        }

        return null;
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
