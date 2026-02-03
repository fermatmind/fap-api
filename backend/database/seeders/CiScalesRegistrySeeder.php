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

        // ✅ CI 单一真源：跟随 config/content_packs.php（保持和 PR 脚本的“config==db”断言一致）
        $defaultPackId = (string) config('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.2.1-TEST');
        $defaultDirVersion = (string) config('content_packs.default_dir_version', 'MBTI-CN-v0.2.1-TEST');
        $defaultRegion = (string) config('content_packs.default_region', 'CN_MAINLAND');
        $defaultLocale = (string) config('content_packs.default_locale', 'zh-CN');

        // demo pack id：沿用仓库既有口径（空则回退 default）
        $demoPackId = (string) config('content_packs.demo_pack_id', 'default');

        $rows = [
            [
                'org_id' => 0,
                'code' => 'MBTI',
                'primary_slug' => 'mbti',
                'slugs_json' => json_encode(['mbti'], JSON_UNESCAPED_UNICODE),
                'driver_type' => 'mbti',
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
                'default_pack_id' => $demoPackId,
                'default_region' => $defaultRegion,
                'default_locale' => $defaultLocale,
                'default_dir_version' => 'IQ-RAVEN-CN-v0.3.0-DEMO',
                'is_public' => 1,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        // ✅ 以 (org_id, code) 为键，避免 CI/多 org 场景产生脏行
        DB::table('scales_registry')->upsert(
            $rows,
            ['org_id', 'code'],
            [
                'primary_slug',
                'slugs_json',
                'driver_type',
                'default_pack_id',
                'default_region',
                'default_locale',
                'default_dir_version',
                'is_public',
                'is_active',
                'updated_at',
            ]
        );

        $this->command?->info('CiScalesRegistrySeeder: upserted 4 scales.');
    }
}
