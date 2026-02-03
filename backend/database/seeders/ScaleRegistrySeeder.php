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

        // ✅ 单一真源：跟随 config/content_packs.php（CI/本机/线上保持一致）
        $defaultPackId = (string) config('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.2.1-TEST');
        $defaultDirVersion = (string) config('content_packs.default_dir_version', 'MBTI-CN-v0.2.1-TEST');
        $defaultRegion = (string) config('content_packs.default_region', 'CN_MAINLAND');
        $defaultLocale = (string) config('content_packs.default_locale', 'zh-CN');

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

            // ✅ pack/dir/region/locale：跟随 config
            'default_pack_id' => $defaultPackId,
            'default_region' => $defaultRegion,
            'default_locale' => $defaultLocale,
            'default_dir_version' => $defaultDirVersion,

            'capabilities_json' => [
                'share_templates' => true,
                'content_graph' => true,
            ],

            // ✅ view_policy：保持“新 SKU 在 view_policy 内”，由服务层输出时做锚点兼容（top-level）
            'view_policy_json' => [
                'free_sections' => [
                    'overview',
                    'type_snapshot',
                    'dimension_summary',
                    'highlights_free',
                ],
                'full_sections' => [
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
                ],
                'blur_others' => true,
                'teaser_percent' => 0.3,
                'upgrade_sku' => SkuContract::SKU_REPORT_FULL_199,
            ],

            // ✅ commercial 合约：锚点(旧) + effective(新) + offers（长期可运营）
            'commercial_json' => [
                'price_tier' => 'FREE',
                'report_benefit_code' => 'MBTI_REPORT_FULL',
                'credit_benefit_code' => 'MBTI_CREDIT',

                // 真实可购买 SKU（新）
                'report_unlock_sku' => SkuContract::SKU_REPORT_FULL_199,

                // 兼容锚点（旧端/旧测试断言）
                'upgrade_sku_anchor' => SkuContract::UPGRADE_SKU_ANCHOR,

                // 前端/运营用 offers
                'offers' => SkuContract::offers(),
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
    }
}
