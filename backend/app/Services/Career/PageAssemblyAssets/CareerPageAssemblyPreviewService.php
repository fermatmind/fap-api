<?php

declare(strict_types=1);

namespace App\Services\Career\PageAssemblyAssets;

use App\Models\CareerJobPageAssemblyAsset;

final class CareerPageAssemblyPreviewService
{
    private const FORBIDDEN_READER_KEYS = [
        'audit_fields',
        'block_refs',
        'source_row_hash',
        'row_hash',
        'search_projection',
        'internal_lineage',
        'lineage',
    ];

    public function previewEnabled(): bool
    {
        return (bool) config('career_content_page_assembly_assets.staging_preview_enabled', false);
    }

    /**
     * @return list<string>
     */
    public function previewSlugs(): array
    {
        $slugs = config('career_content_page_assembly_assets.preview_slugs', []);
        if (! is_array($slugs)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(fn (mixed $slug): string => $this->normalizeSlug((string) $slug), $slugs),
            static fn (string $slug): bool => $slug !== ''
        )));
    }

    public function previewAsset(string $slug, string $locale): ?CareerJobPageAssemblyAsset
    {
        $normalizedLocale = $this->normalizePreviewLocale($locale);
        if ($normalizedLocale === null || ! $this->previewEnabled()) {
            return null;
        }

        return CareerJobPageAssemblyAsset::query()
            ->where('career_job_slug', $this->normalizeSlug($slug))
            ->where('locale', $normalizedLocale)
            ->where('asset_version', CareerJobPageAssemblyAsset::ASSET_VERSION_V1)
            ->where('status', CareerJobPageAssemblyAsset::STATUS_STAGING_PREVIEW)
            ->where('preview_allowlisted', true)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function publicPayload(CareerJobPageAssemblyAsset $asset): array
    {
        $payload = is_array($asset->asset_payload_json) ? $asset->asset_payload_json : [];

        return [
            'ok' => true,
            'preview' => true,
            'status' => $asset->status,
            'career_page_assembly_v1' => $this->readerSafePayload($payload),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function readerSafePayload(array $payload): array
    {
        $safePayload = [];
        foreach ([
            'slug',
            'locale',
            'asset_version',
            'occupation',
            'section_order',
            'page_sections',
            'reader_boundary',
        ] as $readerKey) {
            if (array_key_exists($readerKey, $payload)) {
                $safePayload[$readerKey] = $readerKey === 'occupation' && is_array($payload[$readerKey])
                    ? $this->readerSafeOccupation($payload[$readerKey], (string) ($payload['locale'] ?? ''))
                    : $this->sanitizeReaderValue($payload[$readerKey]);
            }
        }

        $safePayload['preview'] = true;

        return $safePayload;
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

    /**
     * @param  array<string, mixed>  $occupation
     * @return array<string, mixed>
     */
    private function readerSafeOccupation(array $occupation, string $locale): array
    {
        $safeOccupation = $this->sanitizeReaderValue($occupation);
        if (! is_array($safeOccupation)) {
            return [];
        }

        if ($this->normalizeLocale($locale) === 'en') {
            unset($safeOccupation['title_zh']);
        }

        return $safeOccupation;
    }

    private function sanitizeReaderValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        $sanitized = [];
        foreach ($value as $key => $nestedValue) {
            if (is_string($key) && in_array($key, self::FORBIDDEN_READER_KEYS, true)) {
                continue;
            }

            $sanitized[$key] = $this->sanitizeReaderValue($nestedValue);
        }

        return $sanitized;
    }
}
