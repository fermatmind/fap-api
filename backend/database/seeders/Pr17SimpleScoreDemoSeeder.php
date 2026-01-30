<?php

namespace Database\Seeders;

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
        $scale = $writer->upsertScale([
            'code' => 'SIMPLE_SCORE_DEMO',
            'org_id' => 0,
            'primary_slug' => 'simple-score-demo',
            'slugs_json' => [
                'simple-score-demo',
            ],
            'driver_type' => 'simple_score',
            'default_pack_id' => 'default',
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
                'upgrade_sku' => 'MBTI_REPORT_FULL',
            ],
            'commercial_json' => [
                'report_benefit_code' => 'MBTI_REPORT_FULL',
                'credit_benefit_code' => 'MBTI_CREDIT',
                'report_unlock_sku' => 'MBTI_REPORT_FULL',
            ],
            'is_public' => true,
            'is_active' => true,
        ]);

        $writer->syncSlugsForScale($scale);
        $this->command?->info('Pr17SimpleScoreDemoSeeder: SIMPLE_SCORE_DEMO scale upserted.');
    }
}
