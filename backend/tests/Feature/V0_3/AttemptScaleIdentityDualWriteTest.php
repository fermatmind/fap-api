<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AttemptScaleIdentityDualWriteTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_dual_write_persists_scale_identity_columns_for_v2_input(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');

        Config::set('scale_identity.write_mode', 'dual');

        $response = $this->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'MBTI_PERSONALITY_TEST_16_TYPES',
            'anon_id' => 'dual_write_mbti_v2',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'ok' => true,
            'scale_code' => 'MBTI',
            'scale_code_legacy' => 'MBTI',
            'scale_code_v2' => 'MBTI_PERSONALITY_TEST_16_TYPES',
            'scale_uid' => '11111111-1111-4111-8111-111111111111',
            'requested_scale_code' => 'MBTI_PERSONALITY_TEST_16_TYPES',
            'resolved_from_alias' => true,
        ]);

        $attemptId = (string) $response->json('attempt_id');
        $this->assertNotSame('', $attemptId);

        $row = DB::table('attempts')->where('id', $attemptId)->first();
        $this->assertNotNull($row);
        $this->assertSame('MBTI', (string) ($row->scale_code ?? ''));
        $this->assertSame('MBTI_PERSONALITY_TEST_16_TYPES', (string) ($row->scale_code_v2 ?? ''));
        $this->assertSame('11111111-1111-4111-8111-111111111111', (string) ($row->scale_uid ?? ''));
    }

    public function test_start_legacy_mode_keeps_identity_columns_nullable(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');

        Config::set('scale_identity.write_mode', 'legacy');

        $response = $this->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'MBTI',
            'anon_id' => 'legacy_write_mbti',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'ok' => true,
            'scale_code' => 'MBTI',
            'scale_code_legacy' => 'MBTI',
            'scale_code_v2' => 'MBTI_PERSONALITY_TEST_16_TYPES',
            'scale_uid' => '11111111-1111-4111-8111-111111111111',
            'requested_scale_code' => 'MBTI',
            'resolved_from_alias' => false,
        ]);

        $attemptId = (string) $response->json('attempt_id');
        $this->assertNotSame('', $attemptId);

        $row = DB::table('attempts')->where('id', $attemptId)->first();
        $this->assertNotNull($row);
        $this->assertSame('MBTI', (string) ($row->scale_code ?? ''));
        $this->assertNull($row->scale_code_v2);
        $this->assertNull($row->scale_uid);
    }

    public function test_start_uses_v2_primary_scale_code_when_response_mode_is_v2(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');

        Config::set('scale_identity.write_mode', 'dual');
        Config::set('scale_identity.api_response_scale_code_mode', 'v2');

        $response = $this->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'MBTI',
            'anon_id' => 'response_mode_v2_mbti',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'ok' => true,
            'scale_code' => 'MBTI_PERSONALITY_TEST_16_TYPES',
            'scale_code_legacy' => 'MBTI',
            'scale_code_v2' => 'MBTI_PERSONALITY_TEST_16_TYPES',
            'scale_uid' => '11111111-1111-4111-8111-111111111111',
            'requested_scale_code' => 'MBTI',
            'resolved_from_alias' => false,
        ]);
    }
}
