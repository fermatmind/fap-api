<?php

namespace Database\Seeders;

use App\Services\Scale\ScaleRegistryWriter;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class Pr21AnswerDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (!Schema::hasTable('scales_registry') || !Schema::hasTable('scale_slugs')) {
            $this->command?->warn('Pr21AnswerDemoSeeder skipped: missing tables.');
            return;
        }

        $writer = app(ScaleRegistryWriter::class);
        $scale = $writer->upsertScale([
            'code' => 'DEMO_ANSWERS',
            'org_id' => 0,
            'primary_slug' => 'demo-answers',
            'slugs_json' => [
                'demo-answers',
            ],
            'driver_type' => 'simple_score',
            'default_pack_id' => 'default',
            'default_region' => 'CN_MAINLAND',
            'default_locale' => 'zh-CN',
            'default_dir_version' => 'DEMO-ANSWERS-CN-v0.3.0-DEMO',
            'capabilities_json' => [
                'assets' => true,
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
        $this->command?->info('Pr21AnswerDemoSeeder: DEMO_ANSWERS scale upserted.');
    }
}
