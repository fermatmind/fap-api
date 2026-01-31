<?php

namespace Tests\Feature\V0_3;

use App\Services\Auth\FmTokenService;
use Database\Seeders\Pr17SimpleScoreDemoSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreditsConsumeOnAttemptSubmitTest extends TestCase
{
    use RefreshDatabase;

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder())->run();
        (new Pr17SimpleScoreDemoSeeder())->run();

        $row = DB::table('scales_registry')
            ->where('org_id', 0)
            ->where('code', 'SIMPLE_SCORE_DEMO')
            ->first();

        if ($row) {
            $commercial = $row->commercial_json ?? null;
            if (is_string($commercial)) {
                $decoded = json_decode($commercial, true);
                $commercial = is_array($decoded) ? $decoded : null;
            }
            if (!is_array($commercial)) {
                $commercial = [];
            }

            unset($commercial['credit_benefit_code']);
            $payload = json_encode($commercial, JSON_UNESCAPED_UNICODE);

            DB::table('scales_registry')
                ->where('org_id', 0)
                ->where('code', 'SIMPLE_SCORE_DEMO')
                ->update([
                    'commercial_json' => $payload,
                    'updated_at' => now(),
                ]);
        }

        Cache::flush();
    }

    private function seedOrgWithToken(): array
    {
        $userId = (int) DB::table('users')->insertGetId([
            'name' => 'B2B User',
            'email' => 'b2b-user@example.com',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orgId = (int) DB::table('organizations')->insertGetId([
            'name' => 'B2B Org',
            'owner_user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('organization_members')->insert([
            'org_id' => $orgId,
            'user_id' => $userId,
            'role' => 'member',
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $issued = app(FmTokenService::class)->issueForUser((string) $userId);

        return [$orgId, $userId, (string) ($issued['token'] ?? '')];
    }

    private function seedWallet(int $orgId, int $balance): void
    {
        DB::table('benefit_wallets')->insert([
            'org_id' => $orgId,
            'benefit_code' => 'B2B_ASSESSMENT_ATTEMPT_SUBMIT',
            'balance' => $balance,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createAssessmentWithAssignments(int $orgId, int $userId, int $count): array
    {
        $assessmentId = (int) DB::table('assessments')->insertGetId([
            'org_id' => $orgId,
            'scale_code' => 'SIMPLE_SCORE_DEMO',
            'title' => 'Credits Demo',
            'created_by' => $userId,
            'due_at' => now()->addDays(3),
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tokens = [];
        for ($i = 0; $i < $count; $i++) {
            $token = Str::random(40);
            $tokens[] = $token;

            DB::table('assessment_assignments')->insert([
                'org_id' => $orgId,
                'assessment_id' => $assessmentId,
                'subject_type' => 'email',
                'subject_value' => "member{$i}@example.com",
                'invite_token' => $token,
                'started_at' => null,
                'completed_at' => null,
                'attempt_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $tokens;
    }

    private function fetchAnswers(int $orgId, string $token): array
    {
        $questions = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'X-Org-Id' => (string) $orgId,
        ])->getJson('/api/v0.3/scales/SIMPLE_SCORE_DEMO/questions');

        $questions->assertStatus(200);
        $items = $questions->json('questions.items');
        $this->assertIsArray($items);

        $answers = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $qid = (string) ($item['question_id'] ?? '');
            $options = $item['options'] ?? [];
            if ($qid === '' || !is_array($options) || $options === []) {
                continue;
            }
            $code = (string) ($options[0]['code'] ?? '');
            if ($code === '') {
                continue;
            }
            $answers[] = [
                'question_id' => $qid,
                'code' => $code,
            ];
        }

        return $answers;
    }

    public function test_consume_b2b_credits_and_insufficient_response(): void
    {
        $this->seedScales();
        [$orgId, $userId, $token] = $this->seedOrgWithToken();

        $this->seedWallet($orgId, 3);
        $inviteTokens = $this->createAssessmentWithAssignments($orgId, $userId, 4);

        $answers = $this->fetchAnswers($orgId, $token);
        $this->assertNotEmpty($answers);

        for ($i = 0; $i < 3; $i++) {
            $start = $this->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'X-Org-Id' => (string) $orgId,
            ])->postJson('/api/v0.3/attempts/start', [
                'scale_code' => 'SIMPLE_SCORE_DEMO',
            ]);
            $start->assertStatus(200);
            $attemptId = (string) $start->json('attempt_id');

            $submit = $this->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'X-Org-Id' => (string) $orgId,
            ])->postJson('/api/v0.3/attempts/submit', [
                'attempt_id' => $attemptId,
                'answers' => $answers,
                'duration_ms' => 120000,
                'invite_token' => $inviteTokens[$i],
            ]);
            $submit->assertStatus(200);
            $submit->assertJson(['ok' => true]);

            $wallet = DB::table('benefit_wallets')
                ->where('org_id', $orgId)
                ->where('benefit_code', 'B2B_ASSESSMENT_ATTEMPT_SUBMIT')
                ->first();
            $this->assertSame(2 - $i, (int) ($wallet->balance ?? -1));
        }

        DB::table('benefit_wallets')
            ->where('org_id', $orgId)
            ->where('benefit_code', 'B2B_ASSESSMENT_ATTEMPT_SUBMIT')
            ->update([
                'balance' => 0,
                'updated_at' => now(),
            ]);

        $start = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'X-Org-Id' => (string) $orgId,
        ])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'SIMPLE_SCORE_DEMO',
        ]);
        $start->assertStatus(200);
        $attemptId = (string) $start->json('attempt_id');

        $submit = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'X-Org-Id' => (string) $orgId,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $answers,
            'duration_ms' => 120000,
            'invite_token' => $inviteTokens[3],
        ]);

        $submit->assertStatus(402);
        $submit->assertJson([
            'ok' => false,
            'error' => [
                'code' => 'CREDITS_INSUFFICIENT',
                'benefit_code' => 'B2B_ASSESSMENT_ATTEMPT_SUBMIT',
                'required' => 1,
            ],
        ]);
    }
}
