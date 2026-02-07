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
                'is_public' => 1,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'org_id' => 0,
                'code' => 'BIG5',
                'primary_slug' => 'big5',
                'slugs_json' => json_encode(['big5'], JSON_UNESCAPED_UNICODE),
                'driver_type' => 'big5',
                'assessment_driver' => 'generic_scoring',
                'default_pack_id' => 'BIG5.cn-mainland.zh-CN.v0.1.0-TEST',
                'default_region' => $defaultRegion,
                'default_locale' => $defaultLocale,
                'default_dir_version' => 'BIG5-CN-v0.1.0-TEST',
                'is_public' => 1,
                'is_active' => 0,
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
                'is_public',
                'is_active',
                'updated_at',
            ]
        );

        $this->command?->info('CiScalesRegistrySeeder: upserted 5 scales.');
    }
}
