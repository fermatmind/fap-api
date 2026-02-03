<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\Commerce\SkuContract;
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

        // 单一真源：全部从 content_packs.* 读取（CI/本地/生产统一，允许 env 覆盖）
        $defaultPackId = (string) config('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.2.2');
        $defaultDirVersion = (string) config('content_packs.default_dir_version', 'MBTI-CN-v0.2.2');
        $defaultRegion = (string) config('content_packs.default_region', 'CN_MAINLAND');
        $defaultLocale = (string) config('content_packs.default_locale', 'zh-CN');

        // Paywall sections：与 v0.3 paywall teaser 合约对齐
        $freeSections = ['overview', 'type_snapshot', 'dimension_summary', 'highlights_free'];
        $fullSections = [
            'overview',
            'type_snapshot',
            'dimension_summary',
            'highlights_full',
            'traits',
            'growth',
            'relationships',
            'stress_recovery',
            'career',
            'recommended_reads',
            'borderline_notes',
        ];

        $writer = app(ScaleRegistryWriter::class);

        $scale = $writer->upsertScale([
            'code' => 'MBTI',
            'org_id' => 0,
            'primary_slug' => 'mbti',
            'slugs_json' => [
                'mbti',
                'mbti-test',
                'mbti-personality-test',
            ],
            'driver_type' => 'mbti',

            'default_pack_id' => $defaultPackId,
            'default_region' => $defaultRegion,
            'default_locale' => $defaultLocale,
            'default_dir_version' => $defaultDirVersion,

            'capabilities_json' => [
                'share_templates' => true,
                'content_graph' => true,
            ],

            // view_policy_json：用于 report/paywall 组装
            'view_policy_json' => [
                'free_sections' => $freeSections,
                'full_sections' => $fullSections,
                'blur_others' => true,
                'teaser_percent' => 0.3,
                // view_policy 内保持新 SKU（有效 SKU）
                'upgrade_sku' => SkuContract::SKU_REPORT_FULL_199,
            ],

            // commercial_json：长期运营合约（锚点 SKU + 有效 SKU + offers）
            'commercial_json' => [
                'price_tier' => 'FREE',
                'report_benefit_code' => 'MBTI_REPORT_FULL',
                'credit_benefit_code' => 'MBTI_CREDIT',
                'report_unlock_sku' => SkuContract::SKU_REPORT_FULL_199,
                'upgrade_sku_anchor' => SkuContract::UPGRADE_SKU_ANCHOR,
                'offers' => SkuContract::offers(),
            ],

            'seo_schema_json' => [
                '@context' => 'https://schema.org',
                '@type' => 'Quiz',
                'name' => 'MBTI Personality Test',
                'description' => 'MBTI personality test.',
            ],

            'is_public' => true,
            'is_active' => true,
        ]);

        $writer->syncSlugsForScale($scale);
        $this->command?->info('ScaleRegistrySeeder: MBTI scale upserted.');
    }
}
