<?php

namespace App\Services\Scale;

use App\Models\ScaleRegistry as ScaleRegistryModel;
use App\Models\ScaleSlug;
use App\Support\CacheKeys;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ScaleRegistryWriter
{
    private const LEGACY_TABLE = 'scales_registry';

    private const V2_TABLE = 'scales_registry_v2';

    public function __construct(
        private PublicScaleCatalogCache $publicScaleCatalogCache,
    ) {}

    public function upsertScale(array $payload): ScaleRegistryModel
    {
        $code = strtoupper(trim((string) ($payload['code'] ?? '')));
        $orgId = (int) ($payload['org_id'] ?? 0);
        if ($code === '') {
            throw new \InvalidArgumentException('code is required');
        }

        $data = $payload;
        $data['code'] = $code;
        $data['org_id'] = $orgId;
        [$primarySlug, $authoritativeSlugs, $projectedSlugs] = $this->normalizeAuthoritativeSlugs(
            $data['primary_slug'] ?? null,
            $data['slugs_json'] ?? null,
        );
        $data['primary_slug'] = $primarySlug;
        $data['slugs_json'] = $authoritativeSlugs;

        [$scale, $affectedSlugs] = DB::transaction(function () use ($data, $orgId, $code, $primarySlug, $authoritativeSlugs, $projectedSlugs): array {
            $affectedSlugs = array_values(array_unique([
                ...$this->existingSlugs($orgId, $code),
                ...$this->existingProjectedSlugs($orgId, $code),
                ...$authoritativeSlugs,
            ]));
            $this->assertSlugOwnershipAvailable($orgId, $code, $projectedSlugs);

            if ($this->useV2Table()) {
                DB::table(self::V2_TABLE)->upsert(
                    [$this->buildV2Row($data)],
                    ['org_id', 'code'],
                    [
                        'primary_slug',
                        'slugs_json',
                        'driver_type',
                        'assessment_driver',
                        'default_pack_id',
                        'default_region',
                        'default_locale',
                        'default_dir_version',
                        'capabilities_json',
                        'view_policy_json',
                        'commercial_json',
                        'seo_schema_json',
                        'seo_i18n_json',
                        'content_i18n_json',
                        'report_summary_i18n_json',
                        'is_public',
                        'is_active',
                        'is_indexable',
                        'updated_at',
                    ]
                );
            }

            $scale = $this->upsertLegacyIfEligible($data);
            if (! $scale) {
                $scale = new ScaleRegistryModel;
                $scale->forceFill($data);
            }

            $this->syncSlugProjection($orgId, $code, $primarySlug, $projectedSlugs);

            return [$scale, $affectedSlugs];
        });

        $this->invalidatePublicProjection($orgId, $code, $affectedSlugs);

        return $scale;
    }

    public function syncSlugsForScale(ScaleRegistryModel $scale): void
    {
        $orgId = (int) $scale->org_id;
        $code = (string) $scale->code;
        [$primarySlug, $authoritativeSlugs, $projectedSlugs] = $this->normalizeAuthoritativeSlugs(
            $scale->primary_slug,
            $scale->slugs_json,
        );
        $affectedSlugs = array_values(array_unique([
            ...$this->existingProjectedSlugs($orgId, $code),
            ...$authoritativeSlugs,
        ]));

        DB::transaction(function () use ($orgId, $code, $primarySlug, $projectedSlugs): void {
            $this->assertSlugOwnershipAvailable($orgId, $code, $projectedSlugs);
            $this->syncSlugProjection($orgId, $code, $primarySlug, $projectedSlugs);
        });

        $this->invalidatePublicProjection($orgId, $code, $affectedSlugs);
    }

    /** @param iterable<ScaleRegistryModel> $scales */
    public function rebuildSlugProjections(iterable $scales): void
    {
        $prepared = [];
        foreach ($scales as $scale) {
            $orgId = (int) $scale->org_id;
            $code = strtoupper(trim((string) $scale->code));
            [$primarySlug, $authoritativeSlugs, $projectedSlugs] = $this->normalizeAuthoritativeSlugs(
                $scale->primary_slug,
                $scale->slugs_json,
            );
            $prepared[] = compact('orgId', 'code', 'primarySlug', 'authoritativeSlugs', 'projectedSlugs');
        }

        DB::transaction(function () use ($prepared): void {
            ScaleSlug::query()->withoutGlobalScopes()->delete();
            foreach ($prepared as $scale) {
                $this->assertSlugOwnershipAvailable($scale['orgId'], $scale['code'], $scale['projectedSlugs']);
                $this->syncSlugProjection(
                    $scale['orgId'],
                    $scale['code'],
                    $scale['primarySlug'],
                    $scale['projectedSlugs'],
                );
            }
        });

        foreach ($prepared as $scale) {
            $this->invalidatePublicProjection(
                $scale['orgId'],
                $scale['code'],
                $scale['authoritativeSlugs'],
            );
        }
    }

    public function invalidateCache(int $orgId = 0, ?string $code = null, ?string $slug = null): void
    {
        Cache::forget(CacheKeys::scaleRegistryActive($orgId));

        if ($code !== null) {
            Cache::forget(CacheKeys::scaleRegistryByCode($orgId, $code));
        }

        if ($slug !== null) {
            Cache::forget(CacheKeys::scaleRegistryBySlug($orgId, $slug));
            Cache::forget(CacheKeys::scaleRegistryBySlug($orgId, 'compat:'.$slug));
            Cache::forget(CacheKeys::scaleRegistryBySlug($orgId, 'canonical:'.$slug));
        }
    }

    /**
     * @param  list<mixed>  $slugs
     */
    private function invalidatePublicProjection(int $orgId, string $code, array $slugs): void
    {
        $this->invalidateCache($orgId, $code);
        foreach ($slugs as $slug) {
            $normalized = $this->normalizeSlug((string) $slug);
            if ($normalized !== '') {
                $this->invalidateCache($orgId, null, $normalized);
            }
        }
        $this->publicScaleCatalogCache->bumpGeneration($orgId);
    }

    /**
     * `primary_slug` and `slugs_json` are the write authority. `scale_slugs`
     * is rebuilt from them inside the same transaction.
     *
     * @return array{0:string,1:list<string>,2:list<string>}
     */
    private function normalizeAuthoritativeSlugs(mixed $primarySlug, mixed $slugs): array
    {
        $rawPrimarySlug = strtolower(trim((string) $primarySlug));
        $normalizedPrimarySlug = $this->normalizeSlug($rawPrimarySlug);
        if ($normalizedPrimarySlug === '') {
            if (! preg_match('/^[a-z0-9_-]{1,127}$/', $rawPrimarySlug)) {
                throw new \InvalidArgumentException('primary_slug must be a valid lowercase URL slug');
            }
            $normalizedPrimarySlug = $rawPrimarySlug;
        }

        $authoritative = [$normalizedPrimarySlug => true];
        $projected = [];
        if ($this->normalizeSlug($normalizedPrimarySlug) !== '') {
            $projected[$normalizedPrimarySlug] = true;
        }
        foreach ($this->decodeJsonArray($slugs) as $slug) {
            $rawSlug = strtolower(trim((string) $slug));
            if ($rawSlug === '') {
                continue;
            }

            if (! preg_match('/^[a-z0-9_-]{1,127}$/', $rawSlug)) {
                throw new \InvalidArgumentException('slugs_json contains an invalid URL slug');
            }
            $authoritative[$rawSlug] = true;

            $normalizedSlug = $this->normalizeSlug($rawSlug);
            if ($normalizedSlug !== '') {
                $projected[$normalizedSlug] = true;
            }
        }

        return [$normalizedPrimarySlug, array_keys($authoritative), array_keys($projected)];
    }

    /** @param list<string> $slugs */
    private function assertSlugOwnershipAvailable(int $orgId, string $code, array $slugs): void
    {
        if ($slugs === [] || ! Schema::hasTable('scale_slugs')) {
            return;
        }

        $conflict = ScaleSlug::queryByOrgWhitelist([$orgId])
            ->where('org_id', $orgId)
            ->whereIn('slug', $slugs)
            ->where('scale_code', '!=', $code)
            ->orderBy('slug')
            ->first(['slug', 'scale_code']);
        if ($conflict) {
            throw new \DomainException(sprintf(
                'Scale slug "%s" is already owned by %s in organization %d.',
                (string) $conflict->slug,
                (string) $conflict->scale_code,
                $orgId,
            ));
        }
    }

    /** @param list<string> $slugs */
    private function syncSlugProjection(int $orgId, string $code, string $primarySlug, array $slugs): void
    {
        if (! Schema::hasTable('scale_slugs')) {
            return;
        }

        ScaleSlug::queryByOrgWhitelist([$orgId])
            ->where('org_id', $orgId)
            ->where('scale_code', $code)
            ->delete();

        foreach ($slugs as $slug) {
            ScaleSlug::queryByOrgWhitelist([$orgId])->create([
                'org_id' => $orgId,
                'slug' => $slug,
                'scale_code' => $code,
                'is_primary' => $slug === $primarySlug,
            ]);
        }
    }

    /** @return list<string> */
    private function existingProjectedSlugs(int $orgId, string $code): array
    {
        if (! Schema::hasTable('scale_slugs')) {
            return [];
        }

        return ScaleSlug::queryByOrgWhitelist([$orgId])
            ->where('org_id', $orgId)
            ->where('scale_code', $code)
            ->pluck('slug')
            ->map(static fn (mixed $slug): string => (string) $slug)
            ->all();
    }

    /**
     * @return list<string>
     */
    private function existingSlugs(int $orgId, string $code): array
    {
        $table = $this->useV2Table() ? self::V2_TABLE : self::LEGACY_TABLE;
        if (! Schema::hasTable($table)) {
            return [];
        }

        $row = DB::table($table)
            ->where('org_id', $orgId)
            ->where('code', $code)
            ->first(['primary_slug', 'slugs_json']);
        if (! $row) {
            return [];
        }

        return array_values(array_filter([
            ...$this->decodeJsonArray($row->slugs_json ?? null),
            (string) ($row->primary_slug ?? ''),
        ], static fn (mixed $slug): bool => trim((string) $slug) !== ''));
    }

    private function normalizeSlug(string $slug): string
    {
        $slug = trim(strtolower($slug));
        if ($slug === '') {
            return '';
        }
        if (! preg_match('/^[a-z0-9-]{0,127}$/', $slug)) {
            return '';
        }

        return $slug;
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function buildV2Row(array $data): array
    {
        $now = now();

        return [
            'org_id' => (int) ($data['org_id'] ?? 0),
            'code' => strtoupper(trim((string) ($data['code'] ?? ''))),
            'primary_slug' => trim((string) ($data['primary_slug'] ?? '')),
            'slugs_json' => $this->encodeJson($data['slugs_json'] ?? []),
            'driver_type' => trim((string) ($data['driver_type'] ?? 'mbti')),
            'assessment_driver' => $this->nullableString($data['assessment_driver'] ?? null),
            'default_pack_id' => $this->nullableString($data['default_pack_id'] ?? null),
            'default_region' => $this->nullableString($data['default_region'] ?? null),
            'default_locale' => $this->nullableString($data['default_locale'] ?? null),
            'default_dir_version' => $this->nullableString($data['default_dir_version'] ?? null),
            'capabilities_json' => $this->encodeJson($data['capabilities_json'] ?? null),
            'view_policy_json' => $this->encodeJson($data['view_policy_json'] ?? null),
            'commercial_json' => $this->encodeJson($data['commercial_json'] ?? null),
            'seo_schema_json' => $this->encodeJson($data['seo_schema_json'] ?? null),
            'seo_i18n_json' => $this->encodeJson($data['seo_i18n_json'] ?? null),
            'content_i18n_json' => $this->encodeJson($data['content_i18n_json'] ?? null),
            'report_summary_i18n_json' => $this->encodeJson($data['report_summary_i18n_json'] ?? null),
            'is_public' => (bool) ($data['is_public'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'is_indexable' => (bool) ($data['is_indexable'] ?? true),
            'created_at' => $data['created_at'] ?? $now,
            'updated_at' => $data['updated_at'] ?? $now,
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function upsertLegacyIfEligible(array $data): ?ScaleRegistryModel
    {
        if (! Schema::hasTable(self::LEGACY_TABLE)) {
            return null;
        }

        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        $orgId = (int) ($data['org_id'] ?? 0);
        if ($code === '') {
            return null;
        }

        if ($orgId > 0) {
            $conflict = DB::table(self::LEGACY_TABLE)
                ->where('code', $code)
                ->where('org_id', '!=', $orgId)
                ->exists();
            if ($conflict) {
                return null;
            }
        }

        ScaleRegistryModel::queryByOrgWhitelist([$orgId])->updateOrCreate([
            'code' => $code,
            'org_id' => $orgId,
        ], $data);

        return ScaleRegistryModel::queryByOrgWhitelist([$orgId])
            ->where('code', $code)
            ->where('org_id', $orgId)
            ->first();
    }

    private function useV2Table(): bool
    {
        if (! (bool) config('fap.scales_registry.use_v2', true)) {
            return false;
        }

        return Schema::hasTable(self::V2_TABLE);
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function encodeJson(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }

            return $trimmed;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<int,mixed>
     */
    private function decodeJsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}
