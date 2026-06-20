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
        $normalizedLocale = $this->normalizePreviewLocale($locale);

        if ($normalizedLocale === null) {
            return null;
        }

        $productionAsset = CareerJobAiImpactAsset::query()
            ->where('career_job_slug', $normalizedSlug)
            ->where('locale', $normalizedLocale)
            ->where('asset_version', CareerJobAiImpactAsset::ASSET_VERSION_V5)
            ->where('status', CareerJobAiImpactAsset::STATUS_PRODUCTION_IMPORTED)
            ->first();

        if ($productionAsset instanceof CareerJobAiImpactAsset) {
            return $productionAsset;
        }

        if (! $this->previewEnabled()) {
            return null;
        }

        return CareerJobAiImpactAsset::query()
            ->where('career_job_slug', $normalizedSlug)
            ->where('locale', $normalizedLocale)
            ->where('asset_version', CareerJobAiImpactAsset::ASSET_VERSION_V5)
            ->whereIn('status', [
                CareerJobAiImpactAsset::STATUS_STAGING_PREVIEW,
                CareerJobAiImpactAsset::STATUS_EDITORIAL_REVIEW,
                CareerJobAiImpactAsset::STATUS_APPROVED,
            ])
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
        $safePayload = [];
        foreach ([
            'slug',
            'locale',
            'occupation',
            'ai_exposure_score',
            'summary',
            'items',
        ] as $readerKey) {
            if (array_key_exists($readerKey, $payload)) {
                $safePayload[$readerKey] = $this->sanitizeReaderValue($payload[$readerKey]);
            }
        }

        $safePayload['sources'] = $this->readerSafeSources(is_array($payload['sources'] ?? null) ? $payload['sources'] : []);

        return $safePayload;
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     * @return list<array{name: string, url: string}>
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

            if ($name === '' || $url === '') {
                continue;
            }

            if (str_starts_with($url, 'fermatmind://internal')) {
                continue;
            }

            $safeSources[] = [
                'name' => $name,
                'url' => $url,
            ];
        }

        return $safeSources;
    }

    private function sanitizeReaderValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->sanitizeReaderText($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        $sanitized = [];
        foreach ($value as $key => $nestedValue) {
            if (is_string($key) && in_array($key, [
                'source_id',
                'source_ids',
                'evidence_id',
                'evidence_ids',
                'row_hash',
                'audit_fields',
                'search_projection',
                'derived_from_synthesis',
                'evidence_used',
            ], true)) {
                continue;
            }

            $sanitized[$key] = $this->sanitizeReaderValue($nestedValue);
        }

        return $sanitized;
    }

    private function sanitizeReaderText(string $text): string
    {
        return str_replace([
            'career disappearance',
            'job-loss risk',
            'job loss risk',
            'wage-loss risk',
            'wage loss risk',
            '岗位会消失',
            '职业消失',
            '失业风险',
            '降薪风险',
        ], [
            'individual career outcome',
            'individual career outcome forecast',
            'individual career outcome forecast',
            'individual wage outcome forecast',
            'individual wage outcome forecast',
            '个人职业结果预测',
            '个人职业结果预测',
            '个人职业结果预测',
            '个人收入结果预测',
        ], $text);
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

    private function normalizePreviewLocale(string $locale): ?string
    {
        return match (strtolower(trim($locale))) {
            'en', 'en-us', 'en_us' => 'en',
            'zh', 'zh-cn', 'zh_cn' => 'zh-CN',
            default => null,
        };
    }
}
