<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScalesDualCodeReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_accepts_v2_scale_code_and_returns_legacy_registry_item(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');

        $response = $this->getJson('/api/v0.3/scales/MBTI_PERSONALITY_TEST_16_TYPES');
        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('item.code', 'MBTI');
        $response->assertJsonPath('requested_scale_code', 'MBTI_PERSONALITY_TEST_16_TYPES');
        $response->assertJsonPath('scale_code_legacy', 'MBTI');
        $response->assertJsonPath('scale_code_v2', 'MBTI_PERSONALITY_TEST_16_TYPES');
        $response->assertJsonPath('scale_uid', '11111111-1111-4111-8111-111111111111');
        $response->assertJsonPath('pack_id_v2', 'MBTI_PERSONALITY_TEST_16_TYPES.cn-mainland.zh-CN.v0.3');
        $response->assertJsonPath('dir_version_v2', 'MBTI_PERSONALITY_TEST_16_TYPES-CN-v0.3');
        $response->assertJsonPath('resolved_from_alias', true);
    }

    public function test_questions_accepts_v2_scale_code_and_serves_same_questions_contract(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');

        $response = $this->getJson('/api/v0.3/scales/MBTI_PERSONALITY_TEST_16_TYPES/questions');
        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('scale_code', 'MBTI_PERSONALITY_TEST_16_TYPES');
        $response->assertJsonPath('requested_scale_code', 'MBTI_PERSONALITY_TEST_16_TYPES');
        $response->assertJsonPath('scale_code_legacy', 'MBTI');
        $response->assertJsonPath('scale_code_v2', 'MBTI_PERSONALITY_TEST_16_TYPES');
        $response->assertJsonPath('scale_uid', '11111111-1111-4111-8111-111111111111');
        $response->assertJsonPath('pack_id_v2', 'MBTI_PERSONALITY_TEST_16_TYPES.cn-mainland.zh-CN.v0.3');
        $response->assertJsonPath('dir_version_v2', 'MBTI_PERSONALITY_TEST_16_TYPES-CN-v0.3');
        $response->assertJsonPath('resolved_from_alias', true);
        $response->assertJsonPath('questions.schema', 'fap.questions.v1');
        $this->assertIsArray($response->json('questions.items'));
    }

    public function test_show_uses_v2_primary_field_when_response_mode_is_v2(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');
        Config::set('scale_identity.api_response_scale_code_mode', 'v2');

        $response = $this->getJson('/api/v0.3/scales/MBTI');
        $response->assertStatus(200);
        $response->assertJsonPath('scale_code', 'MBTI_PERSONALITY_TEST_16_TYPES');
        $response->assertJsonPath('scale_code_legacy', 'MBTI');
        $response->assertJsonPath('scale_code_v2', 'MBTI_PERSONALITY_TEST_16_TYPES');
        $response->assertJsonPath('pack_id_v2', 'MBTI_PERSONALITY_TEST_16_TYPES.cn-mainland.zh-CN.v0.3');
        $response->assertJsonPath('dir_version_v2', 'MBTI_PERSONALITY_TEST_16_TYPES-CN-v0.3');
    }

    public function test_questions_uses_v2_primary_field_when_response_mode_is_v2(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');
        Config::set('scale_identity.api_response_scale_code_mode', 'v2');

        $response = $this->getJson('/api/v0.3/scales/MBTI/questions');
        $response->assertStatus(200);
        $response->assertJsonPath('scale_code', 'MBTI_PERSONALITY_TEST_16_TYPES');
        $response->assertJsonPath('scale_code_legacy', 'MBTI');
        $response->assertJsonPath('scale_code_v2', 'MBTI_PERSONALITY_TEST_16_TYPES');
        $response->assertJsonPath('pack_id_v2', 'MBTI_PERSONALITY_TEST_16_TYPES.cn-mainland.zh-CN.v0.3');
        $response->assertJsonPath('dir_version_v2', 'MBTI_PERSONALITY_TEST_16_TYPES-CN-v0.3');
    }

    public function test_show_resolves_runtime_db_alias_even_when_config_maps_are_empty(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');

        DB::table('scale_code_aliases')->updateOrInsert(
            ['alias_code' => 'MBTI_CANARY_ALIAS'],
            [
                'scale_uid' => '11111111-1111-4111-8111-111111111111',
                'alias_type' => 'custom',
                'is_primary' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        Config::set('scale_identity.code_map_v1_to_v2', []);
        Config::set('scale_identity.code_map_v2_to_v1', []);
        Config::set('scale_identity.scale_uid_map', []);

        $response = $this->getJson('/api/v0.3/scales/MBTI_CANARY_ALIAS');
        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('item.code', 'MBTI');
        $response->assertJsonPath('requested_scale_code', 'MBTI_CANARY_ALIAS');
        $response->assertJsonPath('scale_code_legacy', 'MBTI');
        $response->assertJsonPath('scale_code_v2', 'MBTI_PERSONALITY_TEST_16_TYPES');
        $response->assertJsonPath('scale_uid', '11111111-1111-4111-8111-111111111111');
        $response->assertJsonPath('pack_id_v2', 'MBTI_PERSONALITY_TEST_16_TYPES.cn-mainland.zh-CN.v0.3');
        $response->assertJsonPath('dir_version_v2', 'MBTI_PERSONALITY_TEST_16_TYPES-CN-v0.3');
        $response->assertJsonPath('resolved_from_alias', true);
    }
}
