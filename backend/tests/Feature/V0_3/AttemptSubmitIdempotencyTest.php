<?php

namespace Tests\Feature\V0_3;

use Database\Seeders\Pr21AnswerDemoSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttemptSubmitIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder())->run();
        (new Pr21AnswerDemoSeeder())->run();
    }

    private function seedOrgWithToken(): array
    {
        $userId = 9002;
        DB::table('users')->insert([
            'id' => $userId,
            'name' => 'Test User 2',
            'email' => 'test_user2@example.com',
            'password' => 'secret',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orgId = 102;
        DB::table('organizations')->insert([
            'id' => $orgId,
            'name' => 'Test Org 2',
            'owner_user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('organization_members')->insert([
            'org_id' => $orgId,
            'user_id' => $userId,
            'role' => 'owner',
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = 'fm_' . (string) Str::uuid();
        $tokenRow = [
            'token' => $token,
            'anon_id' => 'anon_' . $userId,
            'user_id' => $userId,
            'expires_at' => now()->addDays(1),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (DB::getSchemaBuilder()->hasColumn('fm_tokens', 'org_id')) {
            $tokenRow['org_id'] = $orgId;
        }
        DB::table('fm_tokens')->insert($tokenRow);

        DB::table('benefit_wallets')->insert([
            'org_id' => $orgId,
            'benefit_code' => 'MBTI_CREDIT',
            'balance' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$orgId, $token];
    }

    public function test_submit_idempotent_no_duplicate_consume(): void
    {
        $this->seedScales();
        [$orgId, $token] = $this->seedOrgWithToken();

        $start = $this->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'DEMO_ANSWERS',
        ], [
            'X-Org-Id' => (string) $orgId,
            'Authorization' => 'Bearer ' . $token,
        ]);
        $start->assertStatus(200);
        $attemptId = (string) $start->json('attempt_id');

        $answers = [
            [
                'question_id' => 'DEMO-SLIDER-1',
                'question_type' => 'slider',
                'question_index' => 0,
                'code' => '5',
                'answer' => ['value' => 5],
            ],
            [
                'question_id' => 'DEMO-RANK-1',
                'question_type' => 'rank_order',
                'question_index' => 1,
                'code' => 'A>B>C',
                'answer' => ['order' => ['A', 'B', 'C']],
            ],
            [
                'question_id' => 'DEMO-TEXT-1',
                'question_type' => 'open_text',
                'question_index' => 2,
                'code' => 'TEXT',
                'answer' => ['text' => 'demo answer'],
            ],
        ];

        $submit = $this->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $answers,
            'duration_ms' => 30000,
        ], [
            'X-Org-Id' => (string) $orgId,
            'Authorization' => 'Bearer ' . $token,
        ]);
        $submit->assertStatus(200);
        $submit->assertJson(['ok' => true]);

        $this->assertSame(1, DB::table('results')->where('attempt_id', $attemptId)->count());
        $this->assertSame(1, DB::table('attempt_answer_sets')->where('attempt_id', $attemptId)->count());
        $this->assertSame(1, DB::table('benefit_consumptions')->where('attempt_id', $attemptId)->count());

        $dup = $this->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $answers,
            'duration_ms' => 30000,
        ], [
            'X-Org-Id' => (string) $orgId,
            'Authorization' => 'Bearer ' . $token,
        ]);
        $dup->assertStatus(200);
        $dup->assertJson(['ok' => true, 'idempotent' => true]);

        $this->assertSame(1, DB::table('results')->where('attempt_id', $attemptId)->count());
        $this->assertSame(1, DB::table('attempt_answer_sets')->where('attempt_id', $attemptId)->count());
        $this->assertSame(1, DB::table('benefit_consumptions')->where('attempt_id', $attemptId)->count());
    }
}
