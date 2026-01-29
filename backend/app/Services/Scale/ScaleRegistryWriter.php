<?php

namespace App\Services\Scale;

use App\Models\ScaleRegistry as ScaleRegistryModel;
use App\Models\ScaleSlug;
use App\Support\CacheKeys;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ScaleRegistryWriter
{
    public function upsertScale(array $payload): ScaleRegistryModel
    {
        $code = trim((string) ($payload['code'] ?? ''));
        $orgId = (int) ($payload['org_id'] ?? 0);
        if ($code === '') {
            throw new \InvalidArgumentException('code is required');
        }

        $data = $payload;
        $data['code'] = $code;
        $data['org_id'] = $orgId;

        ScaleRegistryModel::query()->updateOrCreate([
            'code' => $code,
            'org_id' => $orgId,
        ], $data);

        $scale = ScaleRegistryModel::query()
            ->where('code', $code)
            ->where('org_id', $orgId)
            ->firstOrFail();

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
            ScaleSlug::query()
                ->where('org_id', $orgId)
                ->where('scale_code', $code)
                ->delete();

            foreach (array_keys($normalized) as $slug) {
                ScaleSlug::query()->create([
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
}
