<?php

declare(strict_types=1);

namespace Tests\Feature\V0_2;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LegacyReportOrgIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_read_is_scoped_by_org_context_for_same_user(): void
    {
        config(['features.enable_v0_2_report' => true]);

        $userId = $this->seedUser('legacy-report-org-a@example.com');
        $attemptOrg1 = (string) Str::uuid();
        $attemptOrg2 = (string) Str::uuid();

        $this->seedAttemptBundle($attemptOrg1, 1, $userId, 'anon_org1');
        $this->seedAttemptBundle($attemptOrg2, 2, $userId, 'anon_org2');

        $this->seedScaleRegistry(2);
        $this->seedBenefitGrant(2, $attemptOrg2, (string) $userId, 'anon_org2');

        $org1Token = $this->seedFmToken($userId, 1, 'anon_org1');
        $this->withHeader('Authorization', "Bearer {$org1Token}")
            ->getJson("/api/v0.2/attempts/{$attemptOrg2}/report")
            ->assertStatus(404)
            ->assertJsonPath('error_code', 'NOT_FOUND');

        $org2Token = $this->seedFmToken($userId, 2, 'anon_org2');
        $this->withHeader('Authorization', "Bearer {$org2Token}")
            ->getJson("/api/v0.2/attempts/{$attemptOrg2}/report")
            ->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('attempt_id', $attemptOrg2);
    }

    private function seedUser(string $email): int
    {
        return (int) DB::table('users')->insertGetId([
            'name' => 'User '.Str::before($email, '@'),
            'email' => $email,
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedFmToken(int $userId, int $orgId, string $anonId): string
    {
        $token = 'fm_'.(string) Str::uuid();

        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'user_id' => $userId,
            'anon_id' => $anonId,
            'org_id' => $orgId,
            'role' => 'member',
            'meta_json' => json_encode([
                'org_id' => $orgId,
                'role' => 'member',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'expires_at' => now()->addHour(),
            'revoked_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }

    private function seedAttemptBundle(string $attemptId, int $orgId, int $userId, string $anonId): void
    {
        $packId = (string) config('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.2.2');
        $dirVersion = (string) config('content_packs.default_dir_version', 'MBTI-CN-v0.2.2');
        $now = now();

        DB::table('attempts')->insert([
            'id' => $attemptId,
            'org_id' => $orgId,
            'anon_id' => $anonId,
            'user_id' => (string) $userId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.2',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 10,
            'answers_summary_json' => json_encode(['answered' => 10], JSON_UNESCAPED_UNICODE),
            'client_platform' => 'web',
            'client_version' => '1.0.0',
            'channel' => 'test',
            'content_package_version' => 'v0.2.2',
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'started_at' => $now->copy()->subMinutes(2),
            'submitted_at' => $now->copy()->subMinute(),
            'created_at' => $now,
            'updated_at' => $now,
            'result_json' => json_encode([
                'scale_code' => 'MBTI',
                'scale_version' => 'v0.2',
                'type_code' => 'INTJ-A',
                'scores' => [],
                'scores_pct' => [],
                'axis_states' => [],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'type_code' => 'INTJ-A',
        ]);

        DB::table('results')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'attempt_id' => $attemptId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.2',
            'type_code' => 'INTJ-A',
            'scores_json' => json_encode([], JSON_UNESCAPED_UNICODE),
            'scores_pct' => json_encode([], JSON_UNESCAPED_UNICODE),
            'axis_states' => json_encode([], JSON_UNESCAPED_UNICODE),
            'result_json' => json_encode(['type_code' => 'INTJ-A'], JSON_UNESCAPED_UNICODE),
            'profile_version' => 'mbti32-v2.5',
            'content_package_version' => 'v0.2.2',
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'scoring_spec_version' => '2026.01',
            'report_engine_version' => 'v1.2',
            'computed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('report_jobs')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'attempt_id' => $attemptId,
            'status' => 'success',
            'tries' => 1,
            'available_at' => $now,
            'started_at' => $now,
            'finished_at' => $now,
            'report_json' => json_encode([
                'profile' => [
                    'type_code' => 'INTJ-A',
                ],
                'highlights' => [],
                'tags' => [],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'meta' => json_encode(['seed' => 'legacy-report-org'], JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedScaleRegistry(int $orgId): void
    {
        DB::table('scales_registry')->insert([
            'code' => 'MBTI',
            'org_id' => $orgId,
            'primary_slug' => "mbti-org-{$orgId}",
            'slugs_json' => json_encode(["mbti-org-{$orgId}"], JSON_UNESCAPED_UNICODE),
            'driver_type' => 'mbti',
            'commercial_json' => json_encode([
                'report_benefit_code' => 'MBTI_REPORT_FULL',
                'credit_benefit_code' => 'MBTI_CREDIT',
            ], JSON_UNESCAPED_UNICODE),
            'is_public' => 1,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedBenefitGrant(int $orgId, string $attemptId, string $userId, string $benefitRef): void
    {
        DB::table('benefit_grants')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'user_id' => $userId,
            'benefit_code' => 'MBTI_REPORT_FULL',
            'scope' => 'attempt',
            'attempt_id' => $attemptId,
            'status' => 'active',
            'benefit_type' => 'report_unlock',
            'benefit_ref' => $benefitRef,
            'source_order_id' => (string) Str::uuid(),
            'source_event_id' => null,
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
