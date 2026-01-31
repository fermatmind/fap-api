<?php

namespace Tests\Feature\V0_4;

use App\Services\Auth\FmTokenService;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AssessmentsProgressSummaryTest extends TestCase
{
    use RefreshDatabase;

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder())->run();
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

    private function issueToken(int $userId): string
    {
        $issued = app(FmTokenService::class)->issueForUser((string) $userId);
        return (string) ($issued['token'] ?? '');
    }

    public function test_progress_and_summary_fields(): void
    {
        $this->seedScales();

        $adminId = $this->createUser('admin@summary.test');
        $orgId = DB::table('organizations')->insertGetId([
            'name' => 'Org Summary',
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

        $token = $this->issueToken($adminId);

        $create = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson("/api/v0.4/orgs/{$orgId}/assessments", [
            'scale_code' => 'MBTI',
            'title' => 'Team MBTI',
            'due_at' => now()->addDays(7)->toISOString(),
        ]);
        $create->assertStatus(200);
        $assessmentId = (int) $create->json('assessment.id');
        $this->assertGreaterThan(0, $assessmentId);

        $subjects = [];
        for ($i = 0; $i < 10; $i++) {
            $subjects[] = [
                'subject_type' => 'email',
                'subject_value' => "member{$i}@example.com",
            ];
        }

        $invite = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson("/api/v0.4/orgs/{$orgId}/assessments/{$assessmentId}/invite", [
            'subjects' => $subjects,
        ]);
        $invite->assertStatus(200);
        $invite->assertJson([
            'ok' => true,
            'total' => 10,
        ]);
        $invites = $invite->json('invites');
        $this->assertIsArray($invites);
        $this->assertCount(10, $invites);

        $now = now();
        for ($i = 0; $i < 3; $i++) {
            $attemptId = (string) Str::uuid();
            DB::table('attempts')->insert([
                'id' => $attemptId,
                'anon_id' => 'anon_' . $attemptId,
                'user_id' => (string) $adminId,
                'org_id' => $orgId,
                'scale_code' => 'MBTI',
                'scale_version' => 'v0.3',
                'question_count' => 144,
                'answers_summary_json' => json_encode(['stage' => 'submit']),
                'client_platform' => 'test',
                'client_version' => null,
                'channel' => null,
                'referrer' => null,
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
                'type_code' => 'INTJ-A',
                'scores_json' => json_encode(['EI' => 10, 'SN' => 12, 'TF' => 14, 'JP' => 8, 'AT' => 6]),
                'scores_pct' => json_encode(['EI' => 55, 'SN' => 62, 'TF' => 71, 'JP' => 48, 'AT' => 60]),
                'axis_states' => json_encode(['EI' => 'weak', 'SN' => 'clear', 'TF' => 'strong', 'JP' => 'weak', 'AT' => 'clear']),
                'profile_version' => null,
                'content_package_version' => 'v0.2.1-TEST',
                'result_json' => json_encode([
                    'final_score' => 144,
                    'axis_scores_json' => [
                        'scores_pct' => ['EI' => 55, 'SN' => 62, 'TF' => 71, 'JP' => 48, 'AT' => 60],
                    ],
                ]),
                'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.2.1-TEST',
                'dir_version' => 'MBTI-CN-v0.2.1-TEST',
                'scoring_spec_version' => '2026.01',
                'report_engine_version' => 'v1.2',
                'is_valid' => 1,
                'computed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $tokenRow = (string) ($invites[$i]['invite_token'] ?? '');
            DB::table('assessment_assignments')
                ->where('invite_token', $tokenRow)
                ->update([
                    'attempt_id' => $attemptId,
                    'started_at' => $now,
                    'completed_at' => $now,
                    'updated_at' => $now,
                ]);
        }

        $progress = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson("/api/v0.4/orgs/{$orgId}/assessments/{$assessmentId}/progress");
        $progress->assertStatus(200);
        $progress->assertJson([
            'ok' => true,
            'total' => 10,
            'completed' => 3,
            'pending' => 7,
        ]);

        $summary = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson("/api/v0.4/orgs/{$orgId}/assessments/{$assessmentId}/summary");
        $summary->assertStatus(200);
        $summary->assertJson([
            'ok' => true,
            'summary' => [
                'completion_rate' => [
                    'completed' => 3,
                    'total' => 10,
                ],
            ],
        ]);
        $summary->assertJsonStructure([
            'summary' => [
                'completion_rate',
                'due_at',
                'window' => ['start_at', 'end_at'],
                'score_distribution',
                'dimension_means',
            ],
        ]);
    }
}
