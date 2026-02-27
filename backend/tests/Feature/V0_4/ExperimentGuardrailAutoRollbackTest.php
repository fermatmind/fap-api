<?php

declare(strict_types=1);

namespace Tests\Feature\V0_4;

use App\Services\Auth\FmTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExperimentGuardrailAutoRollbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_guardrail_breach_triggers_auto_rollback(): void
    {
        $adminId = $this->createUser('exp-admin@fm.test');
        $orgId = $this->createOrgWithAdmin($adminId, 'Exp Governance Org');
        $token = $this->issueToken($adminId);
        $rolloutId = $this->seedRollout($orgId);

        $guardrail = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->putJson("/api/v0.4/orgs/{$orgId}/experiments/rollouts/{$rolloutId}/guardrails", [
            'metric_key' => 'submission_failed_rate',
            'operator' => 'gte',
            'threshold' => 0.2,
            'min_sample_size' => 20,
            'window_minutes' => 60,
            'auto_rollback' => true,
            'is_active' => true,
            'reason' => 'configure guardrail for rollout',
        ]);

        $guardrail->assertStatus(200);
        $guardrail->assertJsonPath('guardrail.metric_key', 'submission_failed_rate');
        $guardrail->assertJsonPath('guardrail.operator', 'gte');
        $guardrail->assertJsonPath('guardrail.auto_rollback', true);

        $evaluate = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->postJson("/api/v0.4/orgs/{$orgId}/experiments/rollouts/{$rolloutId}/guardrails/evaluate", [
            'metrics' => [
                'submission_failed_rate' => [
                    'value' => 0.35,
                    'sample_size' => 42,
                ],
            ],
            'reason' => 'error ratio breached',
        ]);

        $evaluate->assertStatus(200);
        $evaluate->assertJsonPath('rolled_back', true);
        $evaluate->assertJsonPath('triggered_count', 1);
        $evaluate->assertJsonPath('rollout.id', $rolloutId);
        $evaluate->assertJsonPath('rollout.is_active', false);

        $this->assertDatabaseHas('scoring_model_rollouts', [
            'id' => $rolloutId,
            'org_id' => $orgId,
            'is_active' => 0,
        ]);

        $rollout = DB::table('scoring_model_rollouts')->where('id', $rolloutId)->first();
        $this->assertNotNull($rollout);
        $this->assertNotNull($rollout->ends_at);

        $this->assertDatabaseHas('experiment_rollout_audits', [
            'org_id' => $orgId,
            'rollout_id' => $rolloutId,
            'action' => 'auto_rollback',
            'status' => 'triggered',
        ]);
    }

    public function test_guardrail_without_breach_keeps_rollout_active(): void
    {
        $adminId = $this->createUser('exp-admin-2@fm.test');
        $orgId = $this->createOrgWithAdmin($adminId, 'Exp Governance Org B');
        $token = $this->issueToken($adminId);
        $rolloutId = $this->seedRollout($orgId);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->putJson("/api/v0.4/orgs/{$orgId}/experiments/rollouts/{$rolloutId}/guardrails", [
            'metric_key' => 'submission_failed_rate',
            'operator' => 'gte',
            'threshold' => 0.8,
            'min_sample_size' => 20,
            'window_minutes' => 60,
            'auto_rollback' => true,
            'is_active' => true,
        ])->assertStatus(200);

        $evaluate = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->postJson("/api/v0.4/orgs/{$orgId}/experiments/rollouts/{$rolloutId}/guardrails/evaluate", [
            'metrics' => [
                'submission_failed_rate' => [
                    'value' => 0.35,
                    'sample_size' => 42,
                ],
            ],
        ]);

        $evaluate->assertStatus(200);
        $evaluate->assertJsonPath('rolled_back', false);
        $evaluate->assertJsonPath('triggered_count', 0);
        $evaluate->assertJsonPath('rollout.is_active', true);

        $this->assertDatabaseHas('scoring_model_rollouts', [
            'id' => $rolloutId,
            'org_id' => $orgId,
            'is_active' => 1,
        ]);
    }

    private function createUser(string $email): int
    {
        return (int) DB::table('users')->insertGetId([
            'name' => $email,
            'email' => $email,
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createOrgWithAdmin(int $adminId, string $name): int
    {
        $orgId = (int) DB::table('organizations')->insertGetId([
            'name' => $name,
            'owner_user_id' => $adminId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('organization_members')->insert([
            'org_id' => $orgId,
            'user_id' => $adminId,
            'role' => 'admin',
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $orgId;
    }

    private function issueToken(int $userId): string
    {
        $issued = app(FmTokenService::class)->issueForUser((string) $userId);

        return (string) ($issued['token'] ?? '');
    }

    private function seedRollout(int $orgId): string
    {
        $modelKey = 'mbti_exp_model_'.Str::lower(Str::random(6));
        DB::table('scoring_models')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'scale_code' => 'MBTI',
            'model_key' => $modelKey,
            'driver_type' => 'mbti',
            'scoring_spec_version' => 'mbti_spec_gov_v1',
            'priority' => 10,
            'is_active' => true,
            'config_json' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rolloutId = (string) Str::uuid();
        DB::table('scoring_model_rollouts')->insert([
            'id' => $rolloutId,
            'org_id' => $orgId,
            'scale_code' => 'MBTI',
            'model_key' => $modelKey,
            'experiment_key' => 'PR23_STICKY_BUCKET',
            'experiment_variant' => 'A',
            'rollout_percent' => 100,
            'priority' => 10,
            'is_active' => true,
            'starts_at' => now()->subMinute(),
            'ends_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $rolloutId;
    }
}
