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
            'primary_slug' => 'mbti-personality-test-16-personality-types',
            'slugs_json' => [
                'mbti-personality-test-16-personality-types',
                'personality-mbti-test',
                'mbti',
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
            'primary_slug' => 'big-five-personality-test-ocean-model',
            'slugs_json' => [
                'big-five-personality-test-ocean-model',
                'big-five-personality-test',
                'big5-ocean-test',
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
                'enabled_in_prod' => true,
                'enabled_regions' => ['CN_MAINLAND', 'GLOBAL'],
                'rollout_ratio' => 1.0,
                'paywall_mode' => 'full',
            ],
            'view_policy_json' => [
                'free_sections' => ['disclaimer_top', 'summary', 'domains_overview', 'disclaimer'],
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

        $clinical = $writer->upsertScale([
            'code' => 'CLINICAL_COMBO_68',
            'org_id' => 0,
            'primary_slug' => 'clinical-depression-anxiety-assessment-professional-edition',
            'slugs_json' => [
                'clinical-depression-anxiety-assessment-professional-edition',
                'clinical-combo-68',
                'depression-anxiety-combo',
            ],
            'driver_type' => 'clinical_combo_68',
            'assessment_driver' => 'clinical_combo_68',
            'default_pack_id' => 'CLINICAL_COMBO_68',
            'default_region' => $defaultRegion,
            'default_locale' => $defaultLocale,
            'default_dir_version' => 'v1',
            'capabilities_json' => [
                'assets' => false,
                'questions' => true,
                'enabled_in_prod' => true,
                'enabled_regions' => ['CN_MAINLAND', 'GLOBAL'],
                'rollout_ratio' => 1.0,
                'paywall_mode' => 'full',
            ],
            'view_policy_json' => [
                'free_sections' => ['disclaimer_top', 'free_core', 'free_blocks'],
                'blur_others' => false,
                'teaser_percent' => 0.0,
                'upgrade_sku' => 'SKU_CLINICAL_COMBO_68_PRO_299',
            ],
            'commercial_json' => [
                'price_tier' => 'PAID',
                'report_benefit_code' => 'CLINICAL_COMBO_68_PRO',
                'credit_benefit_code' => 'CLINICAL_COMBO_68_PRO',
                'report_unlock_sku' => 'SKU_CLINICAL_COMBO_68_PRO_299',
                'offers' => [],
            ],
            'seo_schema_json' => [
                '@context' => 'https://schema.org',
                '@type' => 'Quiz',
                'name' => 'Comprehensive Depression and Anxiety Inventory',
                'description' => 'Clinical combo assessment with 68 items.',
            ],
            'is_public' => true,
            'is_active' => true,
        ]);

        $writer->syncSlugsForScale($clinical);
        $this->command?->info('ScaleRegistrySeeder: CLINICAL_COMBO_68 scale upserted.');

        $sds20 = $writer->upsertScale([
            'code' => 'SDS_20',
            'org_id' => 0,
            'primary_slug' => 'depression-screening-test-standard-edition',
            'slugs_json' => [
                'depression-screening-test-standard-edition',
                'sds-20',
                'zung-self-rating-depression-scale',
            ],
            'driver_type' => 'sds_20',
            'assessment_driver' => 'sds_20',
            'default_pack_id' => 'SDS_20',
            'default_region' => $defaultRegion,
            'default_locale' => $defaultLocale,
            'default_dir_version' => 'v1',
            'capabilities_json' => [
                'assets' => false,
                'questions' => true,
                'enabled_in_prod' => true,
                'enabled_regions' => ['CN_MAINLAND', 'GLOBAL'],
                'rollout_ratio' => 1.0,
                'paywall_mode' => 'full',
            ],
            'view_policy_json' => [
                'free_sections' => ['disclaimer_top', 'result_summary_free'],
                'blur_others' => false,
                'teaser_percent' => 0.0,
                'upgrade_sku' => 'SKU_SDS_20_FULL_299',
            ],
            'commercial_json' => [
                'price_tier' => 'PAID',
                'report_benefit_code' => 'SDS_20_FULL',
                'credit_benefit_code' => 'SDS_20_FULL',
                'report_unlock_sku' => 'SKU_SDS_20_FULL_299',
            ],
            'seo_schema_json' => [
                '@context' => 'https://schema.org',
                '@type' => 'Quiz',
                'name' => 'SDS-20 Depression Screening',
                'description' => 'SDS-20 self-rating depression screening scale.',
            ],
            'is_public' => true,
            'is_active' => true,
        ]);

        $writer->syncSlugsForScale($sds20);
        $this->command?->info('ScaleRegistrySeeder: SDS_20 scale upserted.');

        $demoPackId = trim((string) config('content_packs.demo_pack_id', ''));
        if ($demoPackId === '') {
            $demoPackId = $defaultPackId;
        }
        $iqRaven = $writer->upsertScale([
            'code' => 'IQ_RAVEN',
            'org_id' => 0,
            'primary_slug' => 'iq-test-intelligence-quotient-assessment',
            'slugs_json' => [
                'iq-test-intelligence-quotient-assessment',
                'iq-test',
                'iq_raven',
                'raven-iq-test',
                'raven-matrices',
            ],
            'driver_type' => 'iq_raven',
            'assessment_driver' => 'iq_raven',
            'default_pack_id' => $demoPackId,
            'default_region' => $defaultRegion,
            'default_locale' => $defaultLocale,
            'default_dir_version' => 'IQ-RAVEN-CN-v0.3.0-DEMO',
            'capabilities_json' => [
                'questions' => true,
                'enabled_in_prod' => true,
                'enabled_regions' => ['CN_MAINLAND', 'GLOBAL'],
                'rollout_ratio' => 1.0,
            ],
            'view_policy_json' => [
                'free_sections' => ['intro', 'summary'],
                'blur_others' => true,
                'teaser_percent' => 0.35,
            ],
            'commercial_json' => [
                'price_tier' => 'FREE',
            ],
            'seo_schema_json' => [
                '@context' => 'https://schema.org',
                '@type' => 'Quiz',
                'name' => 'IQ Test (Intelligence Quotient Assessment)',
            ],
            'seo_i18n_json' => [
                'en' => [
                    'title' => 'IQ Test (Intelligence Quotient Assessment)',
                ],
                'zh' => [
                    'title' => '智商（IQ）测试',
                ],
            ],
            'is_public' => true,
            'is_active' => true,
        ]);

        $writer->syncSlugsForScale($iqRaven);
        $this->command?->info('ScaleRegistrySeeder: IQ_RAVEN scale upserted.');

        $eq60 = $writer->upsertScale([
            'code' => 'EQ_60',
            'org_id' => 0,
            'primary_slug' => 'eq-test-emotional-intelligence-assessment',
            'slugs_json' => [
                'eq-test-emotional-intelligence-assessment',
                'eq-test',
                'emotional-intelligence-test',
            ],
            'driver_type' => 'eq_60',
            'assessment_driver' => 'eq_60',
            'default_pack_id' => 'EQ_60',
            'default_region' => $defaultRegion,
            'default_locale' => $defaultLocale,
            'default_dir_version' => 'v1',
            'capabilities_json' => [
                'questions' => true,
                'enabled_in_prod' => true,
                'enabled_regions' => ['CN_MAINLAND', 'GLOBAL'],
                'rollout_ratio' => 1.0,
            ],
            'view_policy_json' => [
                'free_sections' => ['intro', 'summary'],
                'blur_others' => true,
                'teaser_percent' => 0.35,
                'upgrade_sku' => 'SKU_EQ_60_FULL_299',
            ],
            'commercial_json' => [
                'price_tier' => 'PAID',
                'report_benefit_code' => 'EQ_60_FULL',
                'credit_benefit_code' => 'EQ_60_FULL',
                'report_unlock_sku' => 'SKU_EQ_60_FULL_299',
            ],
            'seo_schema_json' => [
                '@context' => 'https://schema.org',
                '@type' => 'Quiz',
                'name' => 'EQ Test (Emotional Intelligence Assessment)',
            ],
            'seo_i18n_json' => [
                'en' => [
                    'title' => 'EQ Test (Emotional Intelligence Assessment)',
                ],
                'zh' => [
                    'title' => '情商（EQ）测试',
                ],
            ],
            'is_public' => true,
            'is_active' => true,
        ]);

        $writer->syncSlugsForScale($eq60);
        $this->command?->info('ScaleRegistrySeeder: EQ_60 scale upserted.');
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
