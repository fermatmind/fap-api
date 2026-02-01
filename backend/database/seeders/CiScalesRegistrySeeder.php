<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class CiScalesRegistrySeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        // CI：放宽 MBTI 报告可见范围，保证 overrides 校验能看到插入的 test card
        $mbtiViewPolicy = [
            "free_sections" => ["intro", "score", "traits"],
            "blur_others" => true,
            "teaser_percent" => 0.3,
            "upgrade_sku" => null,
        ];

        $rows = [
            // ========== MBTI ==========
            [
                "org_id" => 0,
                "code" => "MBTI",
                "is_public" => 1,
                "is_active" => 1,
                "driver_type" => "mbti",
                "default_pack_id" => "MBTI.cn-mainland.zh-CN.v0.2.1-TEST",
                "default_dir_version" => "MBTI-CN-v0.2.1-TEST",
                "default_region" => "CN_MAINLAND",
                "default_locale" => "zh-CN",
                "primary_slug" => "mbti",
                "slugs_json" => json_encode(["mbti"], JSON_UNESCAPED_UNICODE),
                "capabilities_json" => json_encode(["report" => true, "share" => true], JSON_UNESCAPED_UNICODE),
                "view_policy_json" => json_encode($mbtiViewPolicy, JSON_UNESCAPED_UNICODE),
                "commercial_json" => json_encode(["upgrade_sku" => null], JSON_UNESCAPED_UNICODE),
                "seo_schema_json" => json_encode(["type" => "mbti"], JSON_UNESCAPED_UNICODE),
                "created_at" => $now,
                "updated_at" => $now,
            ],

            // ========== DEMO_ANSWERS ==========
            [
                "org_id" => 0,
                "code" => "DEMO_ANSWERS",
                "is_public" => 1,
                "is_active" => 1,
                "driver_type" => "demo",
                "default_pack_id" => "default",
                "default_dir_version" => "DEMO-ANSWERS-CN-v0.3.0-DEMO",
                "default_region" => "CN_MAINLAND",
                "default_locale" => "zh-CN",
                "primary_slug" => "demo-answers",
                "slugs_json" => json_encode(["demo-answers"], JSON_UNESCAPED_UNICODE),
                "capabilities_json" => json_encode(["report" => false, "share" => false], JSON_UNESCAPED_UNICODE),
                "view_policy_json" => json_encode(["free_sections" => ["all"], "blur_others" => false], JSON_UNESCAPED_UNICODE),
                "commercial_json" => json_encode([], JSON_UNESCAPED_UNICODE),
                "seo_schema_json" => json_encode(["type" => "demo"], JSON_UNESCAPED_UNICODE),
                "created_at" => $now,
                "updated_at" => $now,
            ],

            // ========== IQ_RAVEN ==========
            [
                "org_id" => 0,
                "code" => "IQ_RAVEN",
                "is_public" => 1,
                "is_active" => 1,
                "driver_type" => "iq_raven",
                "default_pack_id" => "default",
                "default_dir_version" => "IQ-RAVEN-CN-v0.3.0-DEMO",
                "default_region" => "CN_MAINLAND",
                "default_locale" => "zh-CN",
                "primary_slug" => "iq-raven",
                "slugs_json" => json_encode(["iq-raven"], JSON_UNESCAPED_UNICODE),
                "capabilities_json" => json_encode(["report" => true, "share" => false], JSON_UNESCAPED_UNICODE),
                "view_policy_json" => json_encode(["free_sections" => ["all"], "blur_others" => false], JSON_UNESCAPED_UNICODE),
                "commercial_json" => json_encode([], JSON_UNESCAPED_UNICODE),
                "seo_schema_json" => json_encode(["type" => "iq"], JSON_UNESCAPED_UNICODE),
                "created_at" => $now,
                "updated_at" => $now,
            ],

            // ========== SIMPLE_SCORE_DEMO ==========
            [
                "org_id" => 0,
                "code" => "SIMPLE_SCORE_DEMO",
                "is_public" => 1,
                "is_active" => 1,
                "driver_type" => "simple_score",
                "default_pack_id" => "default",
                "default_dir_version" => "SIMPLE-SCORE-CN-v0.3.0-DEMO",
                "default_region" => "CN_MAINLAND",
                "default_locale" => "zh-CN",
                "primary_slug" => "simple-score",
                "slugs_json" => json_encode(["simple-score"], JSON_UNESCAPED_UNICODE),
                "capabilities_json" => json_encode(["report" => true, "share" => false], JSON_UNESCAPED_UNICODE),
                "view_policy_json" => json_encode(["free_sections" => ["all"], "blur_others" => false], JSON_UNESCAPED_UNICODE),
                "commercial_json" => json_encode([], JSON_UNESCAPED_UNICODE),
                "seo_schema_json" => json_encode(["type" => "simple"], JSON_UNESCAPED_UNICODE),
                "created_at" => $now,
                "updated_at" => $now,
            ],
        ];

        foreach ($rows as $row) {
            DB::table("scales_registry")->updateOrInsert(
                ["org_id" => $row["org_id"], "code" => $row["code"]],
                $row
            );
        }
    }
}
