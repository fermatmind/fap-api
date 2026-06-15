<?php

declare(strict_types=1);

namespace App\Services\Career\SalaryAssets;

use App\Models\CareerJobSalaryAsset;

final class CareerSalaryAssetPreviewService
{
    public function previewEnabled(): bool
    {
        return (bool) config('career_salary_assets.staging_preview_enabled', false);
    }

    /**
     * @return list<string>
     */
    public function previewSlugs(): array
    {
        $slugs = config('career_salary_assets.preview_slugs', []);
        if (! is_array($slugs)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(fn (mixed $slug): string => $this->normalizeSlug((string) $slug), $slugs),
            static fn (string $slug): bool => $slug !== ''
        )));
    }

    public function previewAsset(string $slug, string $locale): ?CareerJobSalaryAsset
    {
        $normalizedSlug = $this->normalizeSlug($slug);
        $normalizedLocale = $this->normalizeLocale($locale);

        if (! $this->previewEnabled() || ! in_array($normalizedSlug, $this->previewSlugs(), true)) {
            return null;
        }

        return CareerJobSalaryAsset::query()
            ->where('career_job_slug', $normalizedSlug)
            ->where('locale', $normalizedLocale)
            ->where('asset_version', CareerJobSalaryAsset::ASSET_VERSION_V3_6)
            ->where('status', CareerJobSalaryAsset::STATUS_STAGING_PREVIEW)
            ->where('preview_allowlisted', true)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function publicPayload(CareerJobSalaryAsset $asset): array
    {
        $payload = is_array($asset->asset_payload_json) ? $asset->asset_payload_json : [];

        return [
            'ok' => true,
            'preview' => true,
            'salary_asset_v1' => $payload,
            'lineage' => [
                'career_job_slug' => (string) $asset->career_job_slug,
                'locale' => (string) $asset->locale,
                'asset_version' => (string) $asset->asset_version,
                'status' => (string) $asset->status,
                'asset_row_hash' => (string) $asset->asset_row_hash,
                'source_artifact_sha256' => $asset->source_artifact_sha256,
                'evidence_artifact_sha256' => $asset->evidence_artifact_sha256,
                'estimate_artifact_sha256' => $asset->estimate_artifact_sha256,
                'import_run_id' => $asset->import_run_id,
            ],
        ];
    }

    public function normalizeSlug(string $slug): string
    {
        return strtolower(trim($slug));
    }

    public function normalizeLocale(string $locale): string
    {
        return match (strtolower(trim($locale))) {
            'en', 'en-us', 'en_us' => 'en',
            default => 'zh-CN',
        };
    }
}
