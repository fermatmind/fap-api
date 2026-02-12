<?php

declare(strict_types=1);

namespace Tests\Feature\V0_2;

use App\Models\Attempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LegacyAttemptCrossOrgIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cross_org_attempt_update_returns_404_without_leaking_existence(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $attemptId = $this->seedAttempt(
            orgId: 2,
            anonId: 'anon_org2',
            userId: (string) $user->id
        );

        $token = $this->seedFmToken((int) $user->id, 'anon_org0', 0);
        $answers = $this->buildAnswers();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson("/api/v0.2/attempts/{$attemptId}/result", [
            'anon_id' => 'anon_org2',
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.2',
            'client_platform' => 'test',
            'client_version' => '1.0.0',
            'channel' => 'test',
            'answers' => $answers,
        ]);

        $response->assertStatus(404);
        $response->assertJsonPath('ok', false);
        $response->assertJsonPath('error_code', 'NOT_FOUND');
        $response->assertJsonMissingPath('error');
    }

    public function test_same_org_attempt_update_remains_successful_for_org0_compatibility(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $attemptId = $this->seedAttempt(
            orgId: 0,
            anonId: 'anon_org0',
            userId: (string) $user->id
        );

        $token = $this->seedFmToken((int) $user->id, 'anon_org0', 0);
        $answers = $this->buildAnswers();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson("/api/v0.2/attempts/{$attemptId}/result", [
            'anon_id' => 'anon_org0',
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.2',
            'client_platform' => 'test',
            'client_version' => '1.0.0',
            'channel' => 'test',
            'answers' => $answers,
        ]);

        $this->assertContains($response->status(), [200, 201]);
        $response->assertJsonPath('ok', true);
    }

    /**
     * @return array<int, array{question_id:string, code:string}>
     */
    private function buildAnswers(): array
    {
        $questions = $this->getJson('/api/v0.2/scales/MBTI/questions')
            ->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->json('items', []);

        $this->assertIsArray($questions);
        $this->assertNotEmpty($questions);

        return array_map(static function ($row): array {
            $qid = is_array($row) ? (string) ($row['question_id'] ?? '') : '';

            return [
                'question_id' => $qid,
                'code' => 'A',
            ];
        }, $questions);
    }

    private function seedAttempt(int $orgId, string $anonId, string $userId): string
    {
        $attemptId = (string) Str::uuid();

        Attempt::create([
            'id' => $attemptId,
            'org_id' => $orgId,
            'anon_id' => $anonId,
            'user_id' => $userId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.2',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'answers_summary_json' => ['seed' => true],
            'client_platform' => 'test',
            'client_version' => '1.0.0',
            'channel' => 'test',
            'started_at' => now()->subMinute(),
            'submitted_at' => now(),
            'pack_id' => (string) config('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.2.2'),
            'dir_version' => (string) config('content_packs.default_dir_version', 'MBTI-CN-v0.2.2'),
            'content_package_version' => 'v0.2.2',
            'scoring_spec_version' => '2026.01',
        ]);

        return $attemptId;
    }

    private function seedFmToken(int $userId, string $anonId, int $orgId): string
    {
        $token = 'fm_'.(string) Str::uuid();

        $row = [
            'token' => $token,
            'anon_id' => $anonId,
            'user_id' => $userId,
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('fm_tokens', 'org_id')) {
            $row['org_id'] = $orgId;
        } elseif (Schema::hasColumn('fm_tokens', 'meta_json')) {
            $row['meta_json'] = json_encode(['org_id' => $orgId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        DB::table('fm_tokens')->insert($row);

        return $token;
    }
}
