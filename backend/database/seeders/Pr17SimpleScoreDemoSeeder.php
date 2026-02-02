<?php

namespace Database\Seeders;

use App\Support\Commerce\SkuContract;
use App\Services\Scale\ScaleRegistryWriter;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class Pr17SimpleScoreDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (!Schema::hasTable('scales_registry') || !Schema::hasTable('scale_slugs')) {
            $this->command?->warn('Pr17SimpleScoreDemoSeeder skipped: missing tables.');
            return;
        }

        $writer = app(ScaleRegistryWriter::class);
        $defaultPackId = (string) config('content_packs.demo_pack_id', '');
        $scale = $writer->upsertScale([
            'code' => 'SIMPLE_SCORE_DEMO',
            'org_id' => 0,
            'primary_slug' => 'simple-score-demo',
            'slugs_json' => [
                'simple-score-demo',
            ],
            'driver_type' => 'simple_score',
            'default_pack_id' => $defaultPackId,
            'default_region' => 'CN_MAINLAND',
            'default_locale' => 'zh-CN',
            'default_dir_version' => 'SIMPLE-SCORE-CN-v0.3.0-DEMO',
            'capabilities_json' => [
                'assets' => false,
            ],
            'view_policy_json' => [
                'free_sections' => ['intro', 'score'],
                'blur_others' => true,
                'teaser_percent' => 0.3,
                'upgrade_sku' => SkuContract::SKU_REPORT_FULL_199,
            ],
            'commercial_json' => [
                'report_benefit_code' => 'MBTI_REPORT_FULL',
                'credit_benefit_code' => 'MBTI_CREDIT',
                'report_unlock_sku' => SkuContract::SKU_REPORT_FULL_199,
                'upgrade_sku_anchor' => SkuContract::UPGRADE_SKU_ANCHOR,
                'offers' => SkuContract::offers(),
            ],
            'is_public' => true,
            'is_active' => true,
        ]);

        $writer->syncSlugsForScale($scale);
        $this->command?->info('Pr17SimpleScoreDemoSeeder: SIMPLE_SCORE_DEMO scale upserted.');
    }
}
