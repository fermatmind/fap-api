<?php

declare(strict_types=1);

namespace Tests\Feature\V0_4;

use App\Services\Auth\FmTokenService;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AssessmentTeamDynamicsSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_mbti_assessment_summary_exposes_team_dynamics_v1(): void
    {
        (new ScaleRegistrySeeder())->run();

        $adminId = $this->createUser('team-dynamics-admin@test.local');
        $orgId = $this->createOrg($adminId, 'Team Dynamics Org');
        $token = $this->issueToken($adminId);

        $assessmentId = $this->createAssessment($orgId, $token, 'MBTI', 'Team MBTI Dynamics');
        $invites = $this->inviteSubjects($orgId, $assessmentId, $token, 3);

        $this->attachMbtiResult($orgId, $adminId, (string) ($invites[0]['invite_token'] ?? ''), 'INTJ-A', ['EI' => 38, 'SN' => 77, 'TF' => 81, 'JP' => 66, 'AT' => 24]);
        $this->attachMbtiResult($orgId, $adminId, (string) ($invites[1]['invite_token'] ?? ''), 'ENFP-T', ['EI' => 79, 'SN' => 69, 'TF' => 31, 'JP' => 18, 'AT' => 84]);
        $this->attachMbtiResult($orgId, $adminId, (string) ($invites[2]['invite_token'] ?? ''), 'ISTJ-A', ['EI' => 24, 'SN' => 35, 'TF' => 74, 'JP' => 82, 'AT' => 28]);

        $summary = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.4/orgs/{$orgId}/assessments/{$assessmentId}/summary");

        $summary->assertStatus(200);
        $summary->assertJsonPath('summary.team_dynamics_v1.version', 'team_dynamics.v1');
        $summary->assertJsonPath('summary.team_dynamics_v1.team_member_count', 3);
        $summary->assertJsonPath('summary.team_dynamics_v1.analyzed_member_count', 3);
        $summary->assertJsonPath('summary.team_dynamics_v1.supporting_scales.0', 'MBTI');
        $this->assertNotEmpty((array) $summary->json('summary.team_dynamics_v1.communication_fit_keys'));
        $this->assertNotEmpty((array) $summary->json('summary.team_dynamics_v1.team_action_prompt_keys'));
        $summary->assertJsonPath('summary.workspace_surface_v1.version', 'workspace.surface.v1');
        $summary->assertJsonPath('summary.workspace_surface_v1.workspace_focus_key', 'team.communication.energy_translation');
        $summary->assertJsonPath('summary.workspace_surface_v1.manager_action_keys.0', 'team.action.sync_communication_cadence');
        $summary->assertJsonPath('summary.workspace_surface_v1.member_drill_in_keys.0', 'completed_assignments');
        $this->assertNotEmpty((string) $summary->json('summary.workspace_surface_v1.workspace_surface_fingerprint'));
        $summary->assertJsonPath('summary.insight_graph_v1.graph_contract_version', 'insight.graph.v1');
        $summary->assertJsonPath('summary.insight_graph_v1.graph_scope', 'tenant_protected');
        $summary->assertJsonPath('summary.partner_read_v1.version', 'partner.read.v1');
        $summary->assertJsonPath('summary.partner_read_v1.graph_scope', 'tenant_protected');
        $summary->assertJsonPath('summary.partner_read_v1.read_scope', 'partner_tenant_read');
        $summary->assertJsonPath('summary.partner_read_v1.subject_scope', 'tenant_aggregate_only');
        $summary->assertJsonPath('summary.partner_read_v1.attribution_scope', 'workspace_partner_surface');
        $this->assertContains('team_dynamics', (array) $summary->json('summary.partner_read_v1.allowed_node_ids'));
        $this->assertContains('continues_to', (array) $summary->json('summary.partner_read_v1.allowed_edge_types'));
    }

    public function test_summary_team_dynamics_respects_org_boundary(): void
    {
        (new ScaleRegistrySeeder())->run();

        $ownerId = $this->createUser('owner@test.local');
        $orgId = $this->createOrg($ownerId, 'Primary Org');
        $token = $this->issueToken($ownerId);
        $assessmentId = $this->createAssessment($orgId, $token, 'MBTI', 'Protected Team MBTI');

        $otherUserId = $this->createUser('other@test.local');
        $otherOrgId = $this->createOrg($otherUserId, 'Other Org');
        $otherToken = $this->issueToken($otherUserId);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$otherToken,
        ])->getJson("/api/v0.4/orgs/{$orgId}/assessments/{$assessmentId}/summary");

        $response->assertStatus(404)
            ->assertJsonPath('error_code', 'ORG_NOT_FOUND');
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

    private function createOrg(int $ownerUserId, string $name): int
    {
        $orgId = (int) DB::table('organizations')->insertGetId([
            'name' => $name,
            'owner_user_id' => $ownerUserId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('organization_members')->insert([
            'org_id' => $orgId,
            'user_id' => $ownerUserId,
            'role' => 'admin',
            'is_active' => 1,
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

    private function createAssessment(int $orgId, string $token, string $scaleCode, string $title): int
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->postJson("/api/v0.4/orgs/{$orgId}/assessments", [
            'scale_code' => $scaleCode,
            'title' => $title,
            'due_at' => now()->addDays(7)->toISOString(),
        ]);

        $response->assertStatus(200);

        return (int) $response->json('assessment.id');
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function inviteSubjects(int $orgId, int $assessmentId, string $token, int $count): array
    {
        $subjects = [];
        for ($index = 0; $index < $count; $index++) {
            $subjects[] = [
                'subject_type' => 'email',
                'subject_value' => "member{$index}@team.test",
            ];
        }

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->postJson("/api/v0.4/orgs/{$orgId}/assessments/{$assessmentId}/invite", [
            'subjects' => $subjects,
        ]);

        $response->assertStatus(200);

        return (array) $response->json('invites');
    }

    /**
     * @param  array<string,int>  $scoresPct
     */
    private function attachMbtiResult(int $orgId, int $userId, string $inviteToken, string $typeCode, array $scoresPct): void
    {
        $attemptId = (string) Str::uuid();
        $now = now();

        DB::table('attempts')->insert([
            'id' => $attemptId,
            'anon_id' => 'anon_'.$attemptId,
            'user_id' => (string) $userId,
            'org_id' => $orgId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'question_count' => 144,
            'answers_summary_json' => json_encode(['stage' => 'submit']),
            'client_platform' => 'test',
            'started_at' => $now,
            'submitted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('results')->insert([
            'id' => (string) Str::uuid(),
            'attempt_id' => $attemptId,
            'org_id' => $orgId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'type_code' => $typeCode,
            'scores_json' => json_encode($scoresPct),
            'scores_pct' => json_encode($scoresPct),
            'axis_states' => json_encode(['EI' => 'clear', 'SN' => 'clear', 'TF' => 'clear', 'JP' => 'clear', 'AT' => 'clear']),
            'content_package_version' => 'v0.3',
            'result_json' => json_encode([
                'type_code' => $typeCode,
                'axis_scores_json' => ['scores_pct' => $scoresPct],
            ]),
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'dir_version' => 'MBTI-CN-v0.3',
            'scoring_spec_version' => '2026.01',
            'report_engine_version' => 'v1.2',
            'is_valid' => 1,
            'computed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('assessment_assignments')
            ->where('invite_token', $inviteToken)
            ->update([
                'attempt_id' => $attemptId,
                'started_at' => $now,
                'completed_at' => $now,
                'updated_at' => $now,
            ]);
    }
}
