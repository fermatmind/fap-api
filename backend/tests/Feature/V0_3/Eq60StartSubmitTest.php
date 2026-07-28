<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\Attempt;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class Eq60StartSubmitTest extends TestCase
{
    use RefreshDatabase;

    public function test_eq60_start_submit_returns_dimension_scores(): void
    {
        $this->artisan('content:compile --pack=EQ_60 --pack-version=v1')->assertExitCode(0);
        (new ScaleRegistrySeeder)->run();

        $anonId = 'anon_eq60_owner';
        $token = $this->issueAnonToken($anonId);

        $payload = [
            'scale_code' => 'EQ_60',
            'anon_id' => $anonId,
            'locale' => 'zh-CN',
            'region' => 'CN_MAINLAND',
        ];
        $this->assertArrayNotHasKey('form_code', $payload);

        $start = $this->withHeaders([
            'X-Anon-Id' => $anonId,
        ])->postJson('/api/v0.3/attempts/start', $payload);

        $start->assertStatus(200);
        $start->assertJsonPath('scale_code', 'EQ_60');
        $start->assertJsonPath('form_code', 'eq_60');
        $start->assertJsonPath('dir_version', 'v1');
        $start->assertJsonPath('question_count', 60);

        $attemptId = (string) $start->json('attempt_id');
        $this->assertNotSame('', $attemptId);
        $attempt = Attempt::query()->findOrFail($attemptId);
        $this->assertSame('eq_60', data_get($attempt->answers_summary_json, 'meta.form_code'));
        $this->assertSame($start->json('form_code'), data_get($attempt->answers_summary_json, 'meta.form_code'));

        $answers = [];
        for ($i = 1; $i <= 60; $i++) {
            $answers[] = [
                'question_id' => (string) $i,
                'code' => 'C',
            ];
        }

        $submit = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $answers,
            'duration_ms' => 160000,
        ]);

        $submit->assertStatus(200);
        $submit->assertJsonPath('ok', true);
        $submit->assertJsonPath('attempt_id', $attemptId);

        $dimScores = (array) data_get($submit->json(), 'result.breakdown_json.dim_scores', []);
        $this->assertSame(45, (int) ($dimScores['SA'] ?? 0));
        $this->assertSame(45, (int) ($dimScores['ER'] ?? 0));
        $this->assertSame(45, (int) ($dimScores['EM'] ?? 0));
        $this->assertSame(45, (int) ($dimScores['RM'] ?? 0));
        $this->assertSame(180, (int) data_get($submit->json(), 'result.final_score', 0));
        $this->assertSame(45, (int) data_get($submit->json(), 'result.scores.EM.raw_sum', 0));
        $this->assertSame('PROVISIONAL', (string) data_get($submit->json(), 'result.norms.status', ''));
    }

    public function test_eq60_start_accepts_the_explicit_canonical_form(): void
    {
        $this->artisan('content:compile --pack=EQ_60 --pack-version=v1')->assertExitCode(0);
        (new ScaleRegistrySeeder)->run();

        $response = $this->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'EQ_60',
            'anon_id' => 'anon_eq60_explicit_form',
            'form_code' => 'eq_60',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('form_code', 'eq_60');
        $attempt = Attempt::query()->findOrFail((string) $response->json('attempt_id'));
        $this->assertSame('eq_60', data_get($attempt->answers_summary_json, 'meta.form_code'));
    }

    public function test_eq60_start_rejects_missing_default_form_without_creating_attempt(): void
    {
        $this->artisan('content:compile --pack=EQ_60 --pack-version=v1')->assertExitCode(0);
        (new ScaleRegistrySeeder)->run();
        $defaultFormCode = config('content_packs.eq60_forms.default_form_code');
        config()->set('content_packs.eq60_forms.default_form_code', '');
        try {
            $response = $this->postJson('/api/v0.3/attempts/start', [
                'scale_code' => 'EQ_60',
                'anon_id' => 'anon_eq60_no_default',
            ]);

            $response->assertStatus(422);
            $response->assertJsonPath('error_code', 'FORM_UNAVAILABLE');
            $this->assertDatabaseMissing('attempts', [
                'anon_id' => 'anon_eq60_no_default',
                'scale_code' => 'EQ_60',
            ]);
        } finally {
            config()->set('content_packs.eq60_forms.default_form_code', $defaultFormCode);
        }
    }

    private function issueAnonToken(string $anonId): string
    {
        $token = 'fm_'.(string) Str::uuid();
        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'user_id' => null,
            'anon_id' => $anonId,
            'org_id' => 0,
            'role' => 'public',
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }
}
