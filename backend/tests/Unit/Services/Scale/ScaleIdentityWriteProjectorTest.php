<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Scale;

use App\Models\Attempt;
use App\Services\Scale\ScaleIdentityWriteProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ScaleIdentityWriteProjectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_projector_keeps_existing_identity_values_when_complete(): void
    {
        $attempt = new Attempt([
            'scale_code' => 'MBTI',
            'scale_code_v2' => 'mbti_personality_test_16_types',
            'scale_uid' => '11111111-1111-4111-8111-111111111111',
        ]);

        $projected = app(ScaleIdentityWriteProjector::class)->projectFromAttempt($attempt);

        $this->assertSame('MBTI_PERSONALITY_TEST_16_TYPES', $projected['scale_code_v2']);
        $this->assertSame('11111111-1111-4111-8111-111111111111', $projected['scale_uid']);
    }

    public function test_projector_resolves_identity_from_database_defaults(): void
    {
        $attempt = new Attempt([
            'scale_code' => 'MBTI',
            'scale_code_v2' => null,
            'scale_uid' => null,
        ]);

        $projected = app(ScaleIdentityWriteProjector::class)->projectFromAttempt($attempt);

        $this->assertSame('MBTI_PERSONALITY_TEST_16_TYPES', $projected['scale_code_v2']);
        $this->assertSame('11111111-1111-4111-8111-111111111111', $projected['scale_uid']);
    }

    public function test_projector_prefers_database_alias_resolution_even_when_config_maps_are_empty(): void
    {
        DB::table('scale_code_aliases')->updateOrInsert(
            ['alias_code' => 'MBTI_CANARY_ALIAS'],
            [
                'scale_uid' => '11111111-1111-4111-8111-111111111111',
                'alias_type' => 'custom',
                'is_primary' => false,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        config()->set('scale_identity.code_map_v1_to_v2', []);
        config()->set('scale_identity.code_map_v2_to_v1', []);
        config()->set('scale_identity.scale_uid_map', []);

        $attempt = new Attempt([
            'scale_code' => 'MBTI_CANARY_ALIAS',
            'scale_code_v2' => null,
            'scale_uid' => null,
        ]);

        $projected = app(ScaleIdentityWriteProjector::class)->projectFromAttempt($attempt);

        $this->assertSame('MBTI_PERSONALITY_TEST_16_TYPES', $projected['scale_code_v2']);
        $this->assertSame('11111111-1111-4111-8111-111111111111', $projected['scale_uid']);
    }

    public function test_projector_falls_back_to_config_mapping_for_unknown_code(): void
    {
        config()->set('scale_identity.code_map_v1_to_v2', [
            'TEST_SCALE' => 'TEST_SCALE_V2',
        ]);
        config()->set('scale_identity.scale_uid_map', [
            'TEST_SCALE' => '77777777-7777-4777-8777-777777777777',
        ]);

        $attempt = new Attempt([
            'scale_code' => 'TEST_SCALE',
            'scale_code_v2' => null,
            'scale_uid' => null,
        ]);

        $projected = app(ScaleIdentityWriteProjector::class)->projectFromAttempt($attempt);

        $this->assertSame('TEST_SCALE_V2', $projected['scale_code_v2']);
        $this->assertSame('77777777-7777-4777-8777-777777777777', $projected['scale_uid']);
    }

    public function test_projector_can_fill_uid_from_v2_to_v1_mapping_when_legacy_code_missing(): void
    {
        config()->set('scale_identity.code_map_v1_to_v2', [
            'TEST_SCALE' => 'TEST_SCALE_V2',
        ]);
        config()->set('scale_identity.code_map_v2_to_v1', [
            'TEST_SCALE_V2' => 'TEST_SCALE',
        ]);
        config()->set('scale_identity.scale_uid_map', [
            'TEST_SCALE' => '88888888-8888-4888-8888-888888888888',
        ]);

        $attempt = new Attempt([
            'scale_code' => '',
            'scale_code_v2' => 'test_scale_v2',
            'scale_uid' => null,
        ]);

        $projected = app(ScaleIdentityWriteProjector::class)->projectFromAttempt($attempt);

        $this->assertSame('TEST_SCALE_V2', $projected['scale_code_v2']);
        $this->assertSame('88888888-8888-4888-8888-888888888888', $projected['scale_uid']);
    }
}
