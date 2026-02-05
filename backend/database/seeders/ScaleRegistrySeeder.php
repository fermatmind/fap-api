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

        // 关键：默认包/目录完全跟随 config，保证 CI 中 pr22/pr25 的一致性校验通过
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
                'upgrade_sku' => SkuContract::SKU_REPORT_FULL_199,
            ],

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
                'description' => 'MBTI personality test (demo).',
            ],

            'is_public' => true,
            'is_active' => true,
        ]);

        $writer->syncSlugsForScale($scale);
        $this->command?->info('ScaleRegistrySeeder: MBTI scale upserted.');

        $big5PackId = 'BIG5.cn-mainland.zh-CN.v0.1.0-TEST';
        $big5DirVersion = 'BIG5-CN-v0.1.0-TEST';

        $big5 = $writer->upsertScale([
            'code' => 'BIG5',
            'org_id' => 0,
            'primary_slug' => 'big5',
            'slugs_json' => [
                'big5',
                'big5-personality-test',
            ],
            'driver_type' => 'big5',
            'assessment_driver' => 'generic_scoring',

            'default_pack_id' => $big5PackId,
            'default_region' => $defaultRegion,
            'default_locale' => $defaultLocale,
            'default_dir_version' => $big5DirVersion,

            'capabilities_json' => [
                'assets' => false,
            ],
            'view_policy_json' => [
                'free_sections' => ['intro', 'score'],
                'blur_others' => false,
                'teaser_percent' => 0.0,
            ],
            'commercial_json' => [
                'price_tier' => 'FREE',
            ],
            'seo_schema_json' => [
                '@context' => 'https://schema.org',
                '@type' => 'Quiz',
                'name' => 'BIG5 Personality Test',
                'description' => 'BIG5 personality test (demo).',
            ],

            'is_public' => true,
            'is_active' => false,
        ]);

        $writer->syncSlugsForScale($big5);
        $this->command?->info('ScaleRegistrySeeder: BIG5 scale upserted.');
    }
}
