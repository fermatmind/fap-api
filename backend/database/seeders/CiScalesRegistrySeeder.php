<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class CiScalesRegistrySeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        DB::table('scales_registry')->updateOrInsert(
            [
                'org_id' => 0,
                'code'   => 'MBTI',
            ],
            [
                'primary_slug'        => 'mbti',
                'slugs_json'          => json_encode(['mbti'], JSON_UNESCAPED_UNICODE),
                'driver_type'         => 'mbti',

                'default_pack_id'     => env('FAP_DEFAULT_PACK_ID', 'MBTI.cn-mainland.zh-CN.v0.2.1-TEST'),
                'default_dir_version' => env('FAP_DEFAULT_DIR_VERSION', 'MBTI-CN-v0.2.1-TEST'),
                'default_region'      => env('FAP_DEFAULT_REGION', 'CN_MAINLAND'),
                'default_locale'      => env('FAP_DEFAULT_LOCALE', 'zh-CN'),

                // 这些字段在表里存在，统一给一个可用默认值（SQLite/JSON 字段兼容）
                'capabilities_json'   => json_encode([], JSON_UNESCAPED_UNICODE),
                'view_policy_json'    => json_encode([], JSON_UNESCAPED_UNICODE),
                'commercial_json'     => json_encode([], JSON_UNESCAPED_UNICODE),
                'seo_schema_json'     => json_encode([], JSON_UNESCAPED_UNICODE),

                'is_public'           => 1,
                'is_active'           => 1,

                'created_at'          => $now,
                'updated_at'          => $now,
            ]
        );
    }
}