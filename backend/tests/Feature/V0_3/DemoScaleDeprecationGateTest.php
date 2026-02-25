<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use Database\Seeders\Pr17SimpleScoreDemoSeeder;
use Database\Seeders\Pr21AnswerDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DemoScaleDeprecationGateTest extends TestCase
{
    use RefreshDatabase;

    private function issueAnonToken(string $anonId): string
    {
        $token = 'fm_' . (string) Str::uuid();
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

    private function seedDemoScales(): void
    {
        (new Pr21AnswerDemoSeeder())->run();
        (new Pr17SimpleScoreDemoSeeder())->run();
    }

    public function test_start_returns_410_when_demo_answers_scale_is_disabled(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->seedDemoScales();

        Config::set('scale_identity.allow_demo_scales', false);

        $response = $this->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'DEMO_ANSWERS',
            'anon_id' => 'demo_scale_off_start',
        ]);

        $response->assertStatus(410);
        $response->assertJsonPath('error_code', 'SCALE_DEPRECATED');
        $response->assertJsonPath('details.scale_code_legacy', 'DEMO_ANSWERS');
        $response->assertJsonPath('details.replacement_scale_code', 'IQ_RAVEN');
        $response->assertJsonPath('details.replacement_scale_code_v2', 'IQ_INTELLIGENCE_QUOTIENT');
    }

    public function test_submit_returns_410_when_simple_score_demo_scale_is_disabled(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->seedDemoScales();

        $anonId = 'demo_scale_off_submit';
        $token = $this->issueAnonToken($anonId);

        $start = $this->withHeaders([
            'X-Anon-Id' => $anonId,
        ])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'SIMPLE_SCORE_DEMO',
            'anon_id' => $anonId,
        ]);
        $start->assertStatus(200);
        $attemptId = (string) $start->json('attempt_id');
        $this->assertNotSame('', $attemptId);

        Config::set('scale_identity.allow_demo_scales', false);

        $submit = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => [
                ['question_id' => 'SS-001', 'code' => '5'],
                ['question_id' => 'SS-002', 'code' => '4'],
                ['question_id' => 'SS-003', 'code' => '3'],
                ['question_id' => 'SS-004', 'code' => '2'],
                ['question_id' => 'SS-005', 'code' => '1'],
            ],
            'duration_ms' => 120000,
        ]);

        $submit->assertStatus(410);
        $submit->assertJsonPath('error_code', 'SCALE_DEPRECATED');
        $submit->assertJsonPath('details.attempt_id', $attemptId);
        $submit->assertJsonPath('details.scale_code_legacy', 'SIMPLE_SCORE_DEMO');
        $submit->assertJsonPath('details.replacement_scale_code', 'SDS_20');
        $submit->assertJsonPath('details.replacement_scale_code_v2', 'DEPRESSION_SCREENING_STANDARD');
    }

    public function test_lookup_returns_410_when_demo_answers_scale_is_disabled(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->seedDemoScales();
        $this->artisan('fap:scales:sync-slugs');

        Config::set('scale_identity.allow_demo_scales', false);

        $response = $this->getJson('/api/v0.3/scales/lookup?slug=demo-answers');
        $response->assertStatus(410);
        $response->assertJsonPath('error_code', 'SCALE_DEPRECATED');
        $response->assertJsonPath('details.requested_slug', 'demo-answers');
        $response->assertJsonPath('details.scale_code_legacy', 'DEMO_ANSWERS');
        $response->assertJsonPath('details.replacement_scale_code', 'IQ_RAVEN');
        $response->assertJsonPath('details.replacement_scale_code_v2', 'IQ_INTELLIGENCE_QUOTIENT');
    }
}

