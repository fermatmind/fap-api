<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Filament\Tenant\Widgets\TeamDynamicsOverviewWidget;
use App\Models\Assessment;
use App\Models\TenantUser;
use App\Support\OrgContext;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\TestCase;

final class TenantDashboardTeamDynamicsTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_dashboard_widget_resolves_team_dynamics_for_single_org_member(): void
    {
        (new ScaleRegistrySeeder)->run();

        $user = TenantUser::query()->create([
            'name' => 'Tenant Member',
            'email' => 'tenant-member@test.local',
            'password' => bcrypt('secret'),
        ]);

        $orgId = (int) DB::table('organizations')->insertGetId([
            'name' => 'Tenant Org',
            'owner_user_id' => (int) $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('organization_members')->insert([
            'org_id' => $orgId,
            'user_id' => (int) $user->id,
            'role' => 'owner',
            'is_active' => 1,
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $assessment = Assessment::query()->create([
            'org_id' => $orgId,
            'scale_code' => 'MBTI',
            'title' => 'Tenant Team MBTI',
            'created_by' => (int) $user->id,
            'status' => 'open',
        ]);

        $inviteTokens = [];
        foreach ([0, 1] as $index) {
            $token = (string) Str::uuid();
            $inviteTokens[] = $token;
            DB::table('assessment_assignments')->insert([
                'org_id' => $orgId,
                'assessment_id' => (int) $assessment->id,
                'subject_type' => 'email',
                'subject_value' => "member{$index}@tenant.test",
                'invite_token' => $token,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->attachMbtiResult($orgId, (int) $user->id, $inviteTokens[0], 'INTJ-A', ['EI' => 33, 'SN' => 71, 'TF' => 79, 'JP' => 68, 'AT' => 25]);
        $this->attachMbtiResult($orgId, (int) $user->id, $inviteTokens[1], 'ENFP-T', ['EI' => 81, 'SN' => 63, 'TF' => 29, 'JP' => 21, 'AT' => 82]);

        $this->actingAs($user, (string) config('tenant.guard', 'tenant'));
        app(OrgContext::class)->set($orgId, (int) $user->id, 'owner', null, OrgContext::KIND_TENANT);
        request()->attributes->set('org_id', $orgId);
        request()->attributes->set('fm_org_id', $orgId);
        request()->attributes->set('org_context_resolved', true);
        request()->attributes->set('org_context_kind', OrgContext::KIND_TENANT);

        $widget = app(TeamDynamicsOverviewWidget::class);
        $reflection = new ReflectionClass($widget);
        $method = $reflection->getMethod('getStats');
        $method->setAccessible(true);
        $stats = $method->invoke($widget);

        $this->assertIsArray($stats);
        $this->assertCount(4, $stats);
        $this->assertSame('Team focus', $stats[0]->getLabel());
        $this->assertStringContainsString('Assessment #', (string) $stats[0]->getDescription());
        $this->assertSame('Next action', $stats[3]->getLabel());
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
