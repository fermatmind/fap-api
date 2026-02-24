<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class CiScalesRegistrySeeder extends Seeder
{
    public function run(): void
    {
        if (!Schema::hasTable('scales_registry')) {
            $this->command?->warn('CiScalesRegistrySeeder skipped: missing scales_registry table.');
            return;
        }

        $now = now();

        // Keep CI seeding aligned with content_packs config (no hardcoded default pack ids).
        $defaultPackId = trim((string) config('content_packs.default_pack_id', ''));
        $defaultDirVersion = trim((string) config('content_packs.default_dir_version', ''));
        $defaultRegion = trim((string) config('content_packs.default_region', ''));
        $defaultLocale = trim((string) config('content_packs.default_locale', ''));
        if ($defaultPackId === '' || $defaultDirVersion === '' || $defaultRegion === '' || $defaultLocale === '') {
            throw new \RuntimeException(
                'CiScalesRegistrySeeder requires non-empty content_packs defaults: '
                . 'default_pack_id/default_dir_version/default_region/default_locale'
            );
        }

        $demoPackId = trim((string) config('content_packs.demo_pack_id', ''));
        if ($demoPackId === '') {
            $demoPackId = $defaultPackId;
        }

        $rows = [
            [
                'org_id' => 0,
                'code' => 'MBTI',
                'primary_slug' => 'mbti',
                'slugs_json' => json_encode(['mbti'], JSON_UNESCAPED_UNICODE),
                'driver_type' => 'mbti',
                'assessment_driver' => 'generic_scoring',
                'default_pack_id' => $defaultPackId,
                'default_region' => $defaultRegion,
                'default_locale' => $defaultLocale,
                'default_dir_version' => $defaultDirVersion,
                'capabilities_json' => null,
                'commercial_json' => json_encode([
                    'report_benefit_code' => 'MBTI_REPORT_FULL',
                    'credit_benefit_code' => 'MBTI_CREDIT',
                ], JSON_UNESCAPED_UNICODE),
                'is_public' => 1,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'org_id' => 0,
                'code' => 'DEMO_ANSWERS',
                'primary_slug' => 'demo_answers',
                'slugs_json' => json_encode(['demo_answers'], JSON_UNESCAPED_UNICODE),
                'driver_type' => 'demo_answers',
                'assessment_driver' => 'demo_answers',
                'default_pack_id' => $demoPackId,
                'default_region' => $defaultRegion,
                'default_locale' => $defaultLocale,
                'default_dir_version' => 'DEMO-ANSWERS-CN-v0.3.0-DEMO',
                'capabilities_json' => null,
                'commercial_json' => null,
                'is_public' => 1,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'org_id' => 0,
                'code' => 'SIMPLE_SCORE_DEMO',
                'primary_slug' => 'simple_score_demo',
                'slugs_json' => json_encode(['simple_score_demo'], JSON_UNESCAPED_UNICODE),
                'driver_type' => 'simple_score_demo',
                'assessment_driver' => 'simple_score_demo',
                'default_pack_id' => $demoPackId,
                'default_region' => $defaultRegion,
                'default_locale' => $defaultLocale,
                'default_dir_version' => 'SIMPLE-SCORE-CN-v0.3.0-DEMO',
                'capabilities_json' => null,
                'commercial_json' => null,
                'is_public' => 1,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'org_id' => 0,
                'code' => 'IQ_RAVEN',
                'primary_slug' => 'iq_raven',
                'slugs_json' => json_encode(['iq_raven'], JSON_UNESCAPED_UNICODE),
                'driver_type' => 'iq_raven',
                'assessment_driver' => 'iq_raven',
                'default_pack_id' => $demoPackId,
                'default_region' => $defaultRegion,
                'default_locale' => $defaultLocale,
                'default_dir_version' => 'IQ-RAVEN-CN-v0.3.0-DEMO',
                'capabilities_json' => null,
                'commercial_json' => null,
                'is_public' => 1,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'org_id' => 0,
                'code' => 'BIG5_OCEAN',
                'primary_slug' => 'big5-ocean',
                'slugs_json' => json_encode(['big5-ocean', 'big5'], JSON_UNESCAPED_UNICODE),
                'driver_type' => 'big5_ocean',
                'assessment_driver' => 'big5_ocean',
                'default_pack_id' => 'BIG5_OCEAN',
                'default_region' => $defaultRegion,
                'default_locale' => $defaultLocale,
                'default_dir_version' => 'v1',
                'capabilities_json' => json_encode([
                    'assets' => false,
                    'questions' => true,
                    'enabled_in_prod' => true,
                    'enabled_regions' => ['CN_MAINLAND', 'GLOBAL'],
                    'rollout_ratio' => 1.0,
                ], JSON_UNESCAPED_UNICODE),
                'commercial_json' => json_encode([
                    'report_benefit_code' => 'BIG5_FULL_REPORT',
                    'credit_benefit_code' => 'BIG5_FULL_REPORT',
                    'report_unlock_sku' => 'SKU_BIG5_FULL_REPORT_299',
                ], JSON_UNESCAPED_UNICODE),
                'is_public' => 1,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'org_id' => 0,
                'code' => 'CLINICAL_COMBO_68',
                'primary_slug' => 'clinical-combo-68',
                'slugs_json' => json_encode(['clinical-combo-68', 'depression-anxiety-combo'], JSON_UNESCAPED_UNICODE),
                'driver_type' => 'clinical_combo_68',
                'assessment_driver' => 'clinical_combo_68',
                'default_pack_id' => 'CLINICAL_COMBO_68',
                'default_region' => $defaultRegion,
                'default_locale' => $defaultLocale,
                'default_dir_version' => 'v1',
                'capabilities_json' => json_encode([
                    'assets' => false,
                    'questions' => true,
                    'enabled_in_prod' => true,
                    'enabled_regions' => ['CN_MAINLAND', 'GLOBAL'],
                    'rollout_ratio' => 1.0,
                    'paywall_mode' => 'full',
                ], JSON_UNESCAPED_UNICODE),
                'commercial_json' => json_encode([
                    'report_benefit_code' => 'CLINICAL_COMBO_68_PRO',
                    'credit_benefit_code' => 'CLINICAL_COMBO_68_PRO',
                    'report_unlock_sku' => 'SKU_CLINICAL_COMBO_68_PRO_299',
                ], JSON_UNESCAPED_UNICODE),
                'is_public' => 1,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'org_id' => 0,
                'code' => 'SDS_20',
                'primary_slug' => 'sds-20',
                'slugs_json' => json_encode(['sds-20', 'zung-self-rating-depression-scale'], JSON_UNESCAPED_UNICODE),
                'driver_type' => 'sds_20',
                'assessment_driver' => 'sds_20',
                'default_pack_id' => 'SDS_20',
                'default_region' => $defaultRegion,
                'default_locale' => $defaultLocale,
                'default_dir_version' => 'v1',
                'capabilities_json' => json_encode([
                    'assets' => false,
                    'questions' => true,
                    'enabled_in_prod' => true,
                    'enabled_regions' => ['CN_MAINLAND', 'GLOBAL'],
                    'rollout_ratio' => 1.0,
                    'paywall_mode' => 'full',
                ], JSON_UNESCAPED_UNICODE),
                'commercial_json' => json_encode([
                    'report_benefit_code' => 'SDS_20_FULL',
                    'credit_benefit_code' => 'SDS_20_FULL',
                    'report_unlock_sku' => 'SKU_SDS_20_FULL_299',
                ], JSON_UNESCAPED_UNICODE),
                'is_public' => 1,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'org_id' => 0,
                'code' => 'EQ_60',
                'primary_slug' => 'eq-test',
                'slugs_json' => json_encode(['eq-test', 'emotional-intelligence-test'], JSON_UNESCAPED_UNICODE),
                'driver_type' => 'eq_60',
                'assessment_driver' => 'eq_60',
                'default_pack_id' => 'EQ_60',
                'default_region' => $defaultRegion,
                'default_locale' => $defaultLocale,
                'default_dir_version' => 'v1',
                'capabilities_json' => json_encode([
                    'questions' => true,
                    'enabled_in_prod' => true,
                    'enabled_regions' => ['CN_MAINLAND', 'GLOBAL'],
                    'rollout_ratio' => 1.0,
                ], JSON_UNESCAPED_UNICODE),
                'commercial_json' => json_encode([
                    'report_benefit_code' => 'EQ_60_FULL',
                    'credit_benefit_code' => 'EQ_60_FULL',
                    'report_unlock_sku' => 'SKU_EQ_60_FULL_299',
                ], JSON_UNESCAPED_UNICODE),
                'is_public' => 1,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        DB::table('scales_registry')->upsert(
            $rows,
            ['code'],
            [
                'primary_slug',
                'slugs_json',
                'driver_type',
                'assessment_driver',
                'default_pack_id',
                'default_region',
                'default_locale',
                'default_dir_version',
                'capabilities_json',
                'commercial_json',
                'is_public',
                'is_active',
                'updated_at',
            ]
        );

        $this->command?->info('CiScalesRegistrySeeder: upserted 8 scales.');
    }
}
