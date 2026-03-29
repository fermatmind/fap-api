<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use Database\Seeders\Pr16IqRavenDemoSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ScaleCodeV2PrimaryResponseTest extends TestCase
{
    use RefreshDatabase;

    private function issueAnonToken(string $anonId): string
    {
        $token = 'fm_'.(string) Str::uuid();
        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'user_id' => null,
            'anon_id' => $anonId,
            'org_id' => 0,
            'role' => 'public',
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder)->run();
        (new Pr16IqRavenDemoSeeder)->run();
    }

    public function test_v03_scale_read_endpoints_return_v2_primary_scale_code_when_mode_is_v2(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->seedScales();
        $this->artisan('fap:scales:sync-slugs');

        Config::set('scale_identity.api_response_scale_code_mode', 'v2');

        $show = $this->getJson('/api/v0.3/scales/MBTI');
        $show->assertStatus(200);
        $show->assertJsonPath('scale_code', 'MBTI_PERSONALITY_TEST_16_TYPES');
        $show->assertJsonPath('scale_code_legacy', 'MBTI');
        $show->assertJsonPath('scale_code_v2', 'MBTI_PERSONALITY_TEST_16_TYPES');

        $questions = $this->getJson('/api/v0.3/scales/MBTI/questions');
        $questions->assertStatus(200);
        $questions->assertJsonPath('scale_code', 'MBTI_PERSONALITY_TEST_16_TYPES');
        $questions->assertJsonPath('scale_code_legacy', 'MBTI');
        $questions->assertJsonPath('scale_code_v2', 'MBTI_PERSONALITY_TEST_16_TYPES');

        $lookup = $this->getJson('/api/v0.3/scales/lookup?slug=mbti-test');
        $lookup->assertStatus(200);
        $lookup->assertJsonPath('scale_code', 'MBTI_PERSONALITY_TEST_16_TYPES');
        $lookup->assertJsonPath('scale_code_legacy', 'MBTI');
        $lookup->assertJsonPath('scale_code_v2', 'MBTI_PERSONALITY_TEST_16_TYPES');
    }

    public function test_v03_attempt_submit_and_result_return_v2_primary_scale_code_when_mode_is_v2(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->seedScales();

        Config::set('scale_identity.write_mode', 'dual');
        Config::set('scale_identity.api_response_scale_code_mode', 'v2');

        $anonId = 'v03_v2_primary_iq';
        $token = $this->issueAnonToken($anonId);

        $start = $this->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'IQ_INTELLIGENCE_QUOTIENT',
            'anon_id' => $anonId,
        ]);
        $start->assertStatus(200);
        $attemptId = (string) $start->json('attempt_id');
        $this->assertNotSame('', $attemptId);

        $submit = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => [
                ['question_id' => 'MATRIX_Q01', 'code' => 'A'],
                ['question_id' => 'ODD_Q01', 'code' => 'B'],
                ['question_id' => 'SERIES_Q01', 'code' => 'C'],
            ],
            'duration_ms' => 21000,
        ]);
        $submit->assertStatus(200);
        $submit->assertJsonPath('meta.scale_code', 'IQ_INTELLIGENCE_QUOTIENT');
        $submit->assertJsonPath('meta.scale_code_legacy', 'IQ_RAVEN');
        $submit->assertJsonPath('meta.scale_code_v2', 'IQ_INTELLIGENCE_QUOTIENT');
        $submit->assertJsonPath('result.scale_code', 'IQ_INTELLIGENCE_QUOTIENT');
        $submit->assertJsonPath('result.scale_code_legacy', 'IQ_RAVEN');
        $submit->assertJsonPath('result.scale_code_v2', 'IQ_INTELLIGENCE_QUOTIENT');

        $result = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/result");
        $result->assertStatus(200);
        $result->assertJsonPath('meta.scale_code', 'IQ_INTELLIGENCE_QUOTIENT');
        $result->assertJsonPath('meta.scale_code_legacy', 'IQ_RAVEN');
        $result->assertJsonPath('meta.scale_code_v2', 'IQ_INTELLIGENCE_QUOTIENT');
        $result->assertJsonPath('result.scale_code', 'IQ_INTELLIGENCE_QUOTIENT');
        $result->assertJsonPath('result.scale_code_legacy', 'IQ_RAVEN');
        $result->assertJsonPath('result.scale_code_v2', 'IQ_INTELLIGENCE_QUOTIENT');
    }
}
