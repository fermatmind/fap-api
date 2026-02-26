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

        $legacyScale = $this->upsertLegacyIfEligible($data);
        if ($legacyScale) {
            $this->invalidateCache($orgId, $code);

            return $legacyScale;
        }

        $scale = new ScaleRegistryModel();
        $scale->forceFill($data);
        if (! is_array($scale->slugs_json ?? null)) {
            $decoded = $this->decodeJsonArray($data['slugs_json'] ?? null);
            $scale->setAttribute('slugs_json', $decoded);
        }

        $this->invalidateCache($orgId, $code);

        return $scale;
    }

    public function syncSlugsForScale(ScaleRegistryModel $scale): void
    {
        $orgId = (int) $scale->org_id;
        $code = (string) $scale->code;
        $primarySlug = $this->normalizeSlug((string) $scale->primary_slug);
        $slugs = $scale->slugs_json;

        if (!is_array($slugs)) {
            $slugs = [];
        }

        $normalized = [];
        foreach ($slugs as $slug) {
            $s = $this->normalizeSlug((string) $slug);
            if ($s !== '') {
                $normalized[$s] = true;
            }
        }

        if ($primarySlug !== '') {
            $normalized[$primarySlug] = true;
        }

        DB::transaction(function () use ($orgId, $code, $primarySlug, $normalized) {
            ScaleSlug::queryByOrgWhitelist([$orgId])
                ->where('org_id', $orgId)
                ->where('scale_code', $code)
                ->delete();

            foreach (array_keys($normalized) as $slug) {
                ScaleSlug::queryByOrgWhitelist([$orgId])->create([
                    'org_id' => $orgId,
                    'slug' => $slug,
                    'scale_code' => $code,
                    'is_primary' => $primarySlug !== '' && $slug === $primarySlug,
                ]);
            }
        });

        $this->invalidateCache($orgId, $code);
        foreach (array_keys($normalized) as $slug) {
            $this->invalidateCache($orgId, null, $slug);
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
        }
    }

    private function normalizeSlug(string $slug): string
    {
        $slug = trim(strtolower($slug));
        if ($slug === '') {
            return '';
        }
        if (!preg_match('/^[a-z0-9-]{0,127}$/', $slug)) {
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
