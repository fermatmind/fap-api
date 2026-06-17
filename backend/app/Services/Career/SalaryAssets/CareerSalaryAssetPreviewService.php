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
            'salary_asset_v1' => $this->readerSafePayload($payload),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function readerSafePayload(array $payload): array
    {
        foreach ([
            'research_notes',
            'audit_fields',
            'evidence_used',
            'derived_from_estimate',
            'forbidden_claims',
        ] as $internalKey) {
            unset($payload[$internalKey]);
        }

        $payload['sources'] = $this->readerSafeSources(
            is_array($payload['sources'] ?? null) ? $payload['sources'] : [],
            $this->normalizeLocale((string) ($payload['locale'] ?? 'zh-CN')),
        );

        return $this->withoutInternalReferenceIds($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withoutInternalReferenceIds(array $payload): array
    {
        if (is_array($payload['china_recruitment_reference']['facts'] ?? null)) {
            unset($payload['china_recruitment_reference']['facts']['range_source_evidence_ids']);
        }

        if (is_array($payload['us_official_reference'] ?? null)) {
            unset($payload['us_official_reference']['source_ids']);
        }

        if (is_array($payload['uk_reference'] ?? null)) {
            unset($payload['uk_reference']['source_id']);
        }

        if (is_array($payload['eu_context_boundary'] ?? null)) {
            unset($payload['eu_context_boundary']['source_id']);
        }

        return $payload;
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     * @return list<array<string, string>>
     */
    private function readerSafeSources(array $sources, string $locale): array
    {
        $safeSources = [];

        foreach ($sources as $source) {
            if (! is_array($source)) {
                continue;
            }

            $market = strtoupper(trim((string) ($source['market'] ?? '')));
            $url = trim((string) ($source['url'] ?? ''));
            if ($market === '' || $url === '') {
                continue;
            }

            $safeSources[] = [
                'market' => $market,
                'name' => $this->readerSafeSourceName((string) ($source['name'] ?? ''), $url, $market, $locale),
                'url' => $url,
                'used_for' => $this->readerSafeSourceUse($market, $locale),
            ];
        }

        return $safeSources;
    }

    private function readerSafeSourceName(string $name, string $url, string $market, string $locale): string
    {
        $normalized = trim($name, " \t\n\r\0\x0B/");
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($normalized !== '' && ! ($locale === 'en' && $market === 'CN' && $this->containsCjk($normalized))) {
            return $normalized;
        }

        if (str_contains($host, 'jobui.com')) {
            return $locale === 'zh-CN' ? '职友集/JobUI' : 'JobUI';
        }

        if (str_contains($host, 'liepin.com')) {
            return $locale === 'zh-CN' ? '猎聘' : 'Liepin';
        }

        if (str_contains($host, 'zhaopin.com')) {
            return $locale === 'zh-CN' ? '智联招聘' : 'Zhaopin';
        }

        if (str_contains($host, 'kanzhun.com')) {
            return $locale === 'zh-CN' ? '看准' : 'Kanzhun';
        }

        if (str_contains($host, 'zhipin.com')) {
            return 'BOSS Zhipin';
        }

        return match ($market) {
            'CN' => $locale === 'zh-CN' ? '中国招聘市场来源' : 'China recruitment source',
            'US' => 'US official source',
            'UK' => 'UK career source',
            'EU' => 'EU macro context source',
            default => 'Source',
        };
    }

    private function containsCjk(string $value): bool
    {
        return preg_match('/\p{Han}/u', $value) === 1;
    }

    private function readerSafeSourceUse(string $market, string $locale): string
    {
        if ($locale === 'zh-CN') {
            return match ($market) {
                'CN' => '中国招聘市场参考',
                'US' => '美国官方薪资或就业参考',
                'UK' => '英国职业参考',
                'EU' => '欧盟宏观语境边界',
                default => '薪资参考来源',
            };
        }

        return match ($market) {
            'CN' => 'China recruitment-market reference',
            'US' => 'US official salary or outlook reference',
            'UK' => 'UK career reference',
            'EU' => 'EU macro context boundary',
            default => 'Salary reference source',
        };
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
