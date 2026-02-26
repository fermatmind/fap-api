<?php

namespace Tests\Feature\V0_3;

use Database\Seeders\Pr17SimpleScoreDemoSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ScoringModelRoutingExperimentTest extends TestCase
{
    use RefreshDatabase;

    private function issueAnonToken(string $anonId): string
    {
        $token = 'fm_'.(string) Str::uuid();
        DB::table('auth_tokens')->insert([
            'token_hash' => hash('sha256', $token),
            'user_id' => null,
            'anon_id' => $anonId,
            'org_id' => 0,
            'role' => 'public',
            'meta_json' => null,
            'expires_at' => now()->addDay(),
            'revoked_at' => null,
            'last_used_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }

    private function seedScale(): void
    {
        (new ScaleRegistrySeeder())->run();
        (new Pr17SimpleScoreDemoSeeder())->run();
    }

    public function test_submit_uses_experiment_routed_scoring_model_when_rollout_matches(): void
    {
        config()->set('fap.features.submit_async_v2', false);
        $this->seedScale();

        $anonId = 'anon_model_router_match';
        $token = $this->issueAnonToken($anonId);

        $boot = $this->withHeaders([
            'X-Anon-Id' => $anonId,
        ])->getJson('/api/v0.3/boot');
        $boot->assertStatus(200);

        $variant = trim((string) $boot->json('experiments.PR23_STICKY_BUCKET'));
        $this->assertNotSame('', $variant);

        DB::table('scoring_models')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'scale_code' => 'SIMPLE_SCORE_DEMO',
            'model_key' => 'simple_exp_model',
            'driver_type' => 'simple_score_demo',
            'scoring_spec_version' => 'spec_exp_v1',
            'priority' => 10,
            'is_active' => true,
            'config_json' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('scoring_model_rollouts')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'scale_code' => 'SIMPLE_SCORE_DEMO',
            'model_key' => 'simple_exp_model',
            'experiment_key' => 'PR23_STICKY_BUCKET',
            'experiment_variant' => $variant,
            'rollout_percent' => 100,
            'priority' => 10,
            'is_active' => true,
            'starts_at' => null,
            'ends_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $start = $this->withHeaders([
            'X-Anon-Id' => $anonId,
        ])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'SIMPLE_SCORE_DEMO',
            'anon_id' => $anonId,
        ]);
        $start->assertStatus(200);
        $attemptId = (string) $start->json('attempt_id');

        $submit = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
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

        $submit->assertStatus(200);
        $submit->assertJsonPath('result.model_selection.source', 'rollout');
        $submit->assertJsonPath('result.model_selection.model_key', 'simple_exp_model');
        $submit->assertJsonPath('result.model_selection.experiment_key', 'PR23_STICKY_BUCKET');
        $submit->assertJsonPath('result.model_selection.experiment_variant', $variant);
        $submit->assertJsonPath('result.model_selection.experiments_json.PR23_STICKY_BUCKET', $variant);
        $submit->assertJsonPath('meta.scoring_spec_version', 'spec_exp_v1');
    }

    public function test_submit_falls_back_to_default_model_selection_when_no_rollout_matches(): void
    {
        config()->set('fap.features.submit_async_v2', false);
        $this->seedScale();

        $anonId = 'anon_model_router_default';
        $token = $this->issueAnonToken($anonId);

        $start = $this->withHeaders([
            'X-Anon-Id' => $anonId,
        ])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'SIMPLE_SCORE_DEMO',
            'anon_id' => $anonId,
        ]);
        $start->assertStatus(200);
        $attemptId = (string) $start->json('attempt_id');

        $submit = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
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

        $submit->assertStatus(200);
        $submit->assertJsonPath('result.model_selection.source', 'default');
        $submit->assertJsonPath('result.model_selection.model_key', 'default');
        $submit->assertJsonPath('result.model_selection.experiment_key', null);
        $submit->assertJsonPath('result.model_selection.experiment_variant', null);
        $this->assertIsArray($submit->json('result.model_selection.experiments_json'));
    }

    public function test_submit_uses_disabled_model_selection_when_model_router_feature_is_off(): void
    {
        config()->set('fap.features.submit_async_v2', false);
        config()->set('fap.features.model_router_v2', false);
        $this->seedScale();

        $anonId = 'anon_model_router_disabled';
        $token = $this->issueAnonToken($anonId);

        $boot = $this->withHeaders([
            'X-Anon-Id' => $anonId,
        ])->getJson('/api/v0.3/boot');
        $boot->assertStatus(200);
        $variant = trim((string) $boot->json('experiments.PR23_STICKY_BUCKET'));
        $this->assertNotSame('', $variant);

        DB::table('scoring_models')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'scale_code' => 'SIMPLE_SCORE_DEMO',
            'model_key' => 'simple_exp_model_disabled_case',
            'driver_type' => 'simple_score_demo',
            'scoring_spec_version' => 'spec_disabled_case',
            'priority' => 10,
            'is_active' => true,
            'config_json' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('scoring_model_rollouts')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'scale_code' => 'SIMPLE_SCORE_DEMO',
            'model_key' => 'simple_exp_model_disabled_case',
            'experiment_key' => 'PR23_STICKY_BUCKET',
            'experiment_variant' => $variant,
            'rollout_percent' => 100,
            'priority' => 10,
            'is_active' => true,
            'starts_at' => null,
            'ends_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $start = $this->withHeaders([
            'X-Anon-Id' => $anonId,
        ])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'SIMPLE_SCORE_DEMO',
            'anon_id' => $anonId,
        ]);
        $start->assertStatus(200);
        $attemptId = (string) $start->json('attempt_id');

        $submit = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
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

        $submit->assertStatus(200);
        $submit->assertJsonPath('result.model_selection.source', 'disabled');
        $submit->assertJsonPath('result.model_selection.model_key', 'default');
        $submit->assertJsonPath('result.model_selection.experiment_key', null);
        $submit->assertJsonPath('result.model_selection.experiment_variant', null);
        $submit->assertJsonPath('result.model_selection.experiments_json.PR23_STICKY_BUCKET', $variant);
    }
}
