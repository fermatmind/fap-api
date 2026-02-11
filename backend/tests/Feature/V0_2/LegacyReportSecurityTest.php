<?php

declare(strict_types=1);

namespace Tests\Feature\V0_2;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LegacyReportSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_anon_id_can_read_own_attempt_result(): void
    {
        config(['features.enable_v0_2_report' => true]);

        $attemptId = (string) Str::uuid();
        $ownerAnonId = 'anon_owner_result';

        $this->seedAttemptAndResult($attemptId, 0, $ownerAnonId, null, 'INTJ-A');

        $this->withHeader('X-Anon-Id', $ownerAnonId)
            ->getJson("/api/v0.2/attempts/{$attemptId}/result")
            ->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('attempt_id', $attemptId);
    }

    public function test_different_anon_id_gets_404_for_result(): void
    {
        config(['features.enable_v0_2_report' => true]);

        $attemptId = (string) Str::uuid();
        $ownerAnonId = 'anon_owner_result_2';

        $this->seedAttemptAndResult($attemptId, 0, $ownerAnonId, null, 'INTJ-A');

        $this->withHeader('X-Anon-Id', 'anon_other_result')
            ->getJson("/api/v0.2/attempts/{$attemptId}/result")
            ->assertStatus(404)
            ->assertJsonPath('error_code', 'NOT_FOUND');
    }

    public function test_owner_user_can_read_own_report(): void
    {
        config(['features.enable_v0_2_report' => true]);

        $orgId = 0;
        $ownerUserId = $this->seedUser('legacy-report-owner@example.com');
        $attemptId = (string) Str::uuid();
        $ownerAnonId = 'anon_owner_report';

        $this->seedAttemptAndResult($attemptId, $orgId, $ownerAnonId, (string) $ownerUserId, 'INTJ-A');
        $this->seedReportJob($attemptId, $orgId, 'INTJ-A');
        $this->seedScaleRegistry($orgId, 'MBTI');
        $this->seedBenefitGrant($orgId, $attemptId, (string) $ownerUserId, $ownerAnonId);

        $owner = User::query()->findOrFail($ownerUserId);

        $this->actingAs($owner)
            ->getJson("/api/v0.2/attempts/{$attemptId}/report")
            ->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('attempt_id', $attemptId);
    }

    public function test_other_user_gets_404_for_report(): void
    {
        config(['features.enable_v0_2_report' => true]);

        $orgId = 0;
        $ownerUserId = $this->seedUser('legacy-report-owner-b@example.com');
        $otherUserId = $this->seedUser('legacy-report-other-b@example.com');
        $attemptId = (string) Str::uuid();
        $ownerAnonId = 'anon_owner_report_b';

        $this->seedAttemptAndResult($attemptId, $orgId, $ownerAnonId, (string) $ownerUserId, 'INTJ-A');
        $this->seedReportJob($attemptId, $orgId, 'INTJ-A');
        $this->seedScaleRegistry($orgId, 'MBTI');
        $this->seedBenefitGrant($orgId, $attemptId, (string) $ownerUserId, $ownerAnonId);

        $other = User::query()->findOrFail($otherUserId);

        $this->actingAs($other)
            ->getJson("/api/v0.2/attempts/{$attemptId}/report")
            ->assertStatus(404)
            ->assertJsonPath('error_code', 'NOT_FOUND');
    }

    private function seedUser(string $email): int
    {
        return (int) DB::table('users')->insertGetId([
            'name' => 'User ' . $email,
            'email' => $email,
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedAttemptAndResult(
        string $attemptId,
        int $orgId,
        string $anonId,
        ?string $userId,
        string $typeCode
    ): void {
        $now = now();
        $packId = (string) config('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.2.2');
        $dirVersion = (string) config('content_packs.default_dir_version', 'MBTI-CN-v0.2.2');

        DB::table('attempts')->insert([
            'id' => $attemptId,
            'org_id' => $orgId,
            'anon_id' => $anonId,
            'user_id' => $userId,
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
            'started_at' => $now->copy()->subMinutes(3),
            'submitted_at' => $now->copy()->subMinute(),
            'created_at' => $now,
            'updated_at' => $now,
            'result_json' => json_encode([
                'scale_code' => 'MBTI',
                'scale_version' => 'v0.2',
                'type_code' => $typeCode,
                'scores' => [
                    'EI' => ['a' => 12, 'b' => 8, 'neutral' => 0, 'sum' => 4, 'total' => 20],
                    'SN' => ['a' => 11, 'b' => 9, 'neutral' => 0, 'sum' => 2, 'total' => 20],
                    'TF' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                    'JP' => ['a' => 9, 'b' => 11, 'neutral' => 0, 'sum' => -2, 'total' => 20],
                    'AT' => ['a' => 13, 'b' => 7, 'neutral' => 0, 'sum' => 6, 'total' => 20],
                ],
                'scores_pct' => ['EI' => 55, 'SN' => 53, 'TF' => 50, 'JP' => 47, 'AT' => 58],
                'axis_states' => ['EI' => 'clear', 'SN' => 'moderate', 'TF' => 'moderate', 'JP' => 'moderate', 'AT' => 'clear'],
                'profile_version' => 'mbti32-v2.5',
                'content_package_version' => 'v0.2.2',
                'engine_version' => 'v1.2',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'type_code' => $typeCode,
        ]);

        DB::table('results')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'attempt_id' => $attemptId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.2',
            'content_package_version' => 'v0.2.2',
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'type_code' => $typeCode,
            'scores_json' => json_encode([
                'EI' => ['a' => 12, 'b' => 8, 'neutral' => 0, 'sum' => 4, 'total' => 20],
                'SN' => ['a' => 11, 'b' => 9, 'neutral' => 0, 'sum' => 2, 'total' => 20],
                'TF' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'JP' => ['a' => 9, 'b' => 11, 'neutral' => 0, 'sum' => -2, 'total' => 20],
                'AT' => ['a' => 13, 'b' => 7, 'neutral' => 0, 'sum' => 6, 'total' => 20],
            ], JSON_UNESCAPED_UNICODE),
            'scores_pct' => json_encode(['EI' => 55, 'SN' => 53, 'TF' => 50, 'JP' => 47, 'AT' => 58], JSON_UNESCAPED_UNICODE),
            'axis_states' => json_encode(['EI' => 'clear', 'SN' => 'moderate', 'TF' => 'moderate', 'JP' => 'moderate', 'AT' => 'clear'], JSON_UNESCAPED_UNICODE),
            'result_json' => json_encode(['type_code' => $typeCode, 'type_name' => 'Seeded'], JSON_UNESCAPED_UNICODE),
            'profile_version' => 'mbti32-v2.5',
            'report_engine_version' => 'v1.2',
            'computed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedReportJob(string $attemptId, int $orgId, string $typeCode): void
    {
        DB::table('report_jobs')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'attempt_id' => $attemptId,
            'status' => 'success',
            'tries' => 1,
            'available_at' => now(),
            'started_at' => now(),
            'finished_at' => now(),
            'report_json' => json_encode([
                'profile' => ['type_code' => $typeCode],
                'sections' => [
                    'traits' => [
                        'cards' => [
                            ['id' => 'trait_1', 'title' => 'Trait 1'],
                        ],
                    ],
                ],
                'highlights' => [],
                'tags' => [],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'meta' => json_encode(['seed' => 'legacy-security'], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedScaleRegistry(int $orgId, string $scaleCode): void
    {
        DB::table('scales_registry')->insert([
            'code' => $scaleCode,
            'org_id' => $orgId,
            'primary_slug' => 'mbti-test',
            'slugs_json' => json_encode(['mbti-test'], JSON_UNESCAPED_UNICODE),
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
