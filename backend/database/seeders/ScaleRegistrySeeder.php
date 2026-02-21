<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\Scale\ScaleRegistryWriter;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

final class ScaleRegistrySeeder extends Seeder
{
    public function run(): void
    {
        if (!Schema::hasTable('scales_registry') || !Schema::hasTable('scale_slugs')) {
            $this->command?->warn('ScaleRegistrySeeder skipped: missing tables.');
            return;
        }

        // Defaults must come from content_packs config to keep seed/config/pack contract consistent.
        $defaultPackId = trim((string) config('content_packs.default_pack_id', ''));
        $defaultDirVersion = trim((string) config('content_packs.default_dir_version', ''));
        $defaultRegion = trim((string) config('content_packs.default_region', ''));
        $defaultLocale = trim((string) config('content_packs.default_locale', ''));
        if ($defaultPackId === '' || $defaultDirVersion === '' || $defaultRegion === '' || $defaultLocale === '') {
            throw new \RuntimeException(
                'ScaleRegistrySeeder requires non-empty content_packs defaults: '
                . 'default_pack_id/default_dir_version/default_region/default_locale'
            );
        }

        $skuDefaults = $this->resolveSkuDefaults();

        $writer = app(ScaleRegistryWriter::class);

        $scale = $writer->upsertScale([
            'code' => 'MBTI',
            'org_id' => 0,
            'primary_slug' => 'mbti-test',
            'slugs_json' => [
                'mbti-test',
                'mbti-personality-test',
            ],
            'driver_type' => 'mbti',
            'assessment_driver' => 'generic_scoring',

            // ✅ follow config
            'default_pack_id' => $defaultPackId,
            'default_region' => $defaultRegion,
            'default_locale' => $defaultLocale,
            'default_dir_version' => $defaultDirVersion,

            'capabilities_json' => [
                'share_templates' => true,
                'content_graph' => true,
            ],

            // 保持你现有的商业化口径：view_policy 内是 effective SKU，新老兼容由响应层做 anchor 映射
            'view_policy_json' => [
                'free_sections' => ['intro', 'score'],
                'blur_others' => true,
                'teaser_percent' => 0.3,
                'upgrade_sku' => $skuDefaults['effective_sku'] ?? null,
            ],

            'commercial_json' => [
                'price_tier' => 'FREE',
                'report_benefit_code' => 'MBTI_REPORT_FULL',
                'credit_benefit_code' => 'MBTI_CREDIT',
                'report_unlock_sku' => $skuDefaults['effective_sku'] ?? null,
                'upgrade_sku_anchor' => $skuDefaults['anchor_sku'] ?? null,
                'offers' => $skuDefaults['offers'] ?? [],
            ],

            'seo_schema_json' => [
                '@context' => 'https://schema.org',
                '@type' => 'Quiz',
                'name' => 'MBTI Personality Test',
                'description' => 'MBTI personality test (demo).',
            ],

            'is_public' => true,
            'is_active' => true,
        ]);

        $writer->syncSlugsForScale($scale);
        $this->command?->info('ScaleRegistrySeeder: MBTI scale upserted.');

        $big5PackId = 'BIG5_OCEAN';
        $big5DirVersion = 'v1';

        $big5 = $writer->upsertScale([
            'code' => 'BIG5_OCEAN',
            'org_id' => 0,
            'primary_slug' => 'big5-ocean',
            'slugs_json' => [
                'big5-ocean',
                'big5',
                'big5-personality-test',
            ],
            'driver_type' => 'big5_ocean',
            'assessment_driver' => 'big5_ocean',

            'default_pack_id' => $big5PackId,
            'default_region' => $defaultRegion,
            'default_locale' => $defaultLocale,
            'default_dir_version' => $big5DirVersion,

            'capabilities_json' => [
                'assets' => false,
                'questions' => true,
            ],
            'view_policy_json' => [
                'free_sections' => ['summary', 'domains_overview', 'disclaimer'],
                'blur_others' => false,
                'teaser_percent' => 0.0,
                'upgrade_sku' => 'SKU_BIG5_FULL_REPORT_299',
            ],
            'commercial_json' => [
                'price_tier' => 'PAID',
                'report_benefit_code' => 'BIG5_FULL_REPORT',
                'credit_benefit_code' => 'BIG5_FULL_REPORT',
                'report_unlock_sku' => 'SKU_BIG5_FULL_REPORT_299',
            ],
            'seo_schema_json' => [
                '@context' => 'https://schema.org',
                '@type' => 'Quiz',
                'name' => 'BIG5 OCEAN Personality Test',
                'description' => 'BIG5 OCEAN personality test (IPIP-NEO-120).',
            ],

            'is_public' => true,
            'is_active' => true,
        ]);

        $writer->syncSlugsForScale($big5);
        $this->command?->info('ScaleRegistrySeeder: BIG5_OCEAN scale upserted.');
    }

    private function resolveSkuDefaults(): array
    {
        $rows = $this->loadSkuSeedData();
        if (count($rows) === 0) {
            return [
                'effective_sku' => null,
                'anchor_sku' => null,
                'offers' => [],
            ];
        }

        $anchorSku = null;
        $effectiveSku = null;

        foreach ($rows as $item) {
            if (!is_array($item)) {
                continue;
            }

            $sku = strtoupper(trim((string) ($item['sku'] ?? '')));
            if ($sku === '') {
                continue;
            }

            $meta = $item['metadata_json'] ?? [];
            $meta = is_array($meta) ? $meta : [];

            if ($anchorSku === null && !empty($meta['anchor'])) {
                $anchorSku = $sku;
            }

            if ($effectiveSku === null && (!empty($meta['effective_default']) || !empty($meta['default']))) {
                $effectiveSku = $sku;
            }
        }

        $offers = $this->buildOffersFromSeed($rows);

        return [
            'effective_sku' => $effectiveSku,
            'anchor_sku' => $anchorSku,
            'offers' => $offers,
        ];
    }

    private function loadSkuSeedData(): array
    {
        $path = database_path('seed_data/skus_mbti.json');
        if (!is_file($path)) {
            return [];
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function buildOffersFromSeed(array $rows): array
    {
        $offers = [];
        foreach ($rows as $item) {
            if (!is_array($item)) {
                continue;
            }

            $sku = strtoupper(trim((string) ($item['sku'] ?? '')));
            if ($sku === '') {
                continue;
            }

            $meta = $item['metadata_json'] ?? [];
            $meta = is_array($meta) ? $meta : [];
            if (!empty($meta['anchor']) || !empty($meta['deprecated'])) {
                continue;
            }
            if (array_key_exists('offer', $meta) && $meta['offer'] === false) {
                continue;
            }

            $grantType = trim((string) ($meta['grant_type'] ?? ''));
            if ($grantType === '') {
                $grantType = strtolower(trim((string) ($item['benefit_type'] ?? '')));
            }

            $grantQty = isset($meta['grant_qty']) ? (int) $meta['grant_qty'] : 1;
            $periodDays = isset($meta['period_days']) ? (int) $meta['period_days'] : null;

            $entitlementId = trim((string) ($meta['entitlement_id'] ?? ''));
            $modulesIncluded = $this->normalizeModulesIncluded($meta['modules_included'] ?? null);

            $offers[] = [
                'sku' => $sku,
                'price_cents' => (int) ($item['price_cents'] ?? 0),
                'currency' => (string) ($item['currency'] ?? 'CNY'),
                'title' => (string) ($meta['title'] ?? $meta['label'] ?? ''),
                'entitlement_id' => $entitlementId !== '' ? $entitlementId : null,
                'modules_included' => $modulesIncluded,
                'grant' => [
                    'type' => $grantType !== '' ? $grantType : null,
                    'qty' => $grantQty,
                    'period_days' => $periodDays,
                ],
            ];
        }

        return $offers;
    }

    /**
     * @return list<string>
     */
    private function normalizeModulesIncluded(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $module) {
            $module = strtolower(trim((string) $module));
            if ($module === '') {
                continue;
            }
            $out[$module] = true;
        }

        return array_keys($out);
    }
}
