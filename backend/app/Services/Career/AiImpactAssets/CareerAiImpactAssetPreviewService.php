<?php

declare(strict_types=1);

namespace App\Services\Career\AiImpactAssets;

use App\Models\CareerJobAiImpactAsset;

final class CareerAiImpactAssetPreviewService
{
    public function previewEnabled(): bool
    {
        return (bool) config('career_ai_impact_assets.staging_preview_enabled', false);
    }

    /**
     * @return list<string>
     */
    public function previewSlugs(): array
    {
        $slugs = config('career_ai_impact_assets.preview_slugs', []);
        if (! is_array($slugs)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(fn (mixed $slug): string => $this->normalizeSlug((string) $slug), $slugs),
            static fn (string $slug): bool => $slug !== ''
        )));
    }

    public function previewAsset(string $slug, string $locale): ?CareerJobAiImpactAsset
    {
        $normalizedSlug = $this->normalizeSlug($slug);
        $normalizedLocale = $this->normalizeLocale($locale);

        if (! $this->previewEnabled() || ! in_array($normalizedSlug, $this->previewSlugs(), true)) {
            return null;
        }

        return CareerJobAiImpactAsset::query()
            ->where('career_job_slug', $normalizedSlug)
            ->where('locale', $normalizedLocale)
            ->where('asset_version', CareerJobAiImpactAsset::ASSET_VERSION_V5)
            ->where('status', CareerJobAiImpactAsset::STATUS_STAGING_PREVIEW)
            ->where('preview_allowlisted', true)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function publicPayload(CareerJobAiImpactAsset $asset): array
    {
        $payload = is_array($asset->asset_payload_json) ? $asset->asset_payload_json : [];

        return [
            'ok' => true,
            'preview' => true,
            'ai_impact_asset_v1' => $this->readerSafePayload($payload),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function readerSafePayload(array $payload): array
    {
        foreach ([
            'audit_fields',
            'evidence_used',
            'derived_from_synthesis',
            'search_projection',
        ] as $internalKey) {
            unset($payload[$internalKey]);
        }

        $payload['sources'] = $this->readerSafeSources(is_array($payload['sources'] ?? null) ? $payload['sources'] : []);

        return $this->withoutInternalReferences($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withoutInternalReferences(array $payload): array
    {
        if (is_array($payload['score_rationale'] ?? null)) {
            unset($payload['score_rationale']['source_ids']);
            unset($payload['score_rationale']['evidence_ids']);
        }

        return $payload;
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     * @return list<array<string, string>>
     */
    private function readerSafeSources(array $sources): array
    {
        $safeSources = [];

        foreach ($sources as $source) {
            if (! is_array($source)) {
                continue;
            }

            $name = trim((string) ($source['source_name'] ?? $source['name'] ?? ''));
            $url = trim((string) ($source['source_url'] ?? $source['url'] ?? ''));
            $type = trim((string) ($source['source_type'] ?? ''));
            $boundary = trim((string) ($source['boundary'] ?? ''));

            if ($name === '' || $url === '') {
                continue;
            }

            $safeSource = [
                'name' => $name,
                'url' => $url,
            ];

            if ($type !== '') {
                $safeSource['source_type'] = $type;
            }

            if ($boundary !== '') {
                $safeSource['boundary'] = $boundary;
            }

            $safeSources[] = $safeSource;
        }

        return $safeSources;
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
