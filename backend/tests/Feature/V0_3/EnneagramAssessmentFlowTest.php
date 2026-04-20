<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\Attempt;
use App\Models\Result;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EnneagramAssessmentFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_enneagram_questions_support_likert_105_and_forced_choice_144_forms(): void
    {
        $this->seedScales();

        $likert = $this->getJson('/api/v0.3/scales/ENNEAGRAM/questions?form_code=enneagram_likert_105&locale=zh-CN');
        $likert->assertStatus(200);
        $likert->assertJsonPath('ok', true);
        $likert->assertJsonPath('scale_code', 'ENNEAGRAM');
        $likert->assertJsonPath('form_code', 'enneagram_likert_105');
        $likert->assertJsonPath('dir_version', 'v1-likert-105');
        $likert->assertJsonPath('questions.schema', 'fap.questions.v1');
        $this->assertCount(105, (array) $likert->json('questions.items'));
        $likert->assertJsonPath('questions.items.0.options.0.code', '-2');
        $likert->assertJsonPath('questions.items.0.options.4.code', '2');

        $forced = $this->getJson('/api/v0.3/scales/ENNEAGRAM/questions?form_code=enneagram_forced_choice_144&locale=zh-CN');
        $forced->assertStatus(200);
        $forced->assertJsonPath('ok', true);
        $forced->assertJsonPath('scale_code', 'ENNEAGRAM');
        $forced->assertJsonPath('form_code', 'enneagram_forced_choice_144');
        $forced->assertJsonPath('dir_version', 'v1-forced-choice-144');
        $forced->assertJsonPath('questions.schema', 'fap.questions.v1');
        $this->assertCount(144, (array) $forced->json('questions.items'));
        $forced->assertJsonPath('questions.items.0.options.0.code', 'A');
        $forced->assertJsonPath('questions.items.0.options.1.code', 'B');
    }

    public function test_enneagram_likert_105_start_submit_and_result_readback_use_backend_result(): void
    {
        $this->seedScales();

        $anonId = 'anon_enneagram_likert';
        $token = $this->issueAnonToken($anonId);

        $start = $this->withHeaders(['X-Anon-Id' => $anonId])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'ENNEAGRAM',
            'anon_id' => $anonId,
            'locale' => 'zh-CN',
            'region' => 'CN_MAINLAND',
            'form_code' => '105',
        ]);
        $start->assertStatus(200);
        $start->assertJsonPath('ok', true);
        $start->assertJsonPath('scale_code', 'ENNEAGRAM');
        $start->assertJsonPath('form_code', 'enneagram_likert_105');
        $start->assertJsonPath('dir_version', 'v1-likert-105');
        $start->assertJsonPath('question_count', 105);
        $start->assertJsonPath('scoring_spec_version', 'enneagram_likert_105_spec_v1');

        $attemptId = (string) $start->json('attempt_id');
        $attempt = Attempt::query()->findOrFail($attemptId);
        $this->assertSame('enneagram_likert_105', data_get($attempt->answers_summary_json, 'meta.form_code'));

        $questions = $this->getJson('/api/v0.3/scales/ENNEAGRAM/questions?form_code=enneagram_likert_105');
        $answers = $this->buildAnswersFromItems((array) $questions->json('questions.items'));
        $this->assertCount(105, $answers);

        $submit = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $answers,
            'duration_ms' => 180000,
        ]);
        $submit->assertStatus(200);
        $submit->assertJsonPath('ok', true);
        $submit->assertJsonPath('attempt_id', $attemptId);
        $submit->assertJsonPath('result.form_code', 'enneagram_likert_105');
        $submit->assertJsonPath('result.score_method', 'enneagram_likert_105_weighted_v1');

        $stored = Result::query()->where('attempt_id', $attemptId)->first();
        $this->assertNotNull($stored);
        $this->assertSame('ENNEAGRAM', (string) $stored->scale_code);
        $this->assertSame('v1-likert-105', (string) $stored->dir_version);
        $this->assertSame('enneagram_likert_105', (string) data_get($stored->result_json, 'form_code'));
        $this->assertSame('enneagram_likert_105_weighted_v1', (string) data_get($stored->result_json, 'score_method'));
        $this->assertIsArray(data_get($stored->result_json, 'ranking'));

        $readback = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/result");
        $readback->assertStatus(200);
        $readback->assertJsonPath('ok', true);
        $readback->assertJsonPath('result.form_code', 'enneagram_likert_105');
        $readback->assertJsonPath('result.computed_at', (string) data_get($stored->result_json, 'computed_at'));
    }

    public function test_enneagram_forced_choice_144_start_submit_and_result_readback_use_backend_result(): void
    {
        $this->seedScales();

        $anonId = 'anon_enneagram_forced';
        $token = $this->issueAnonToken($anonId);

        $start = $this->withHeaders(['X-Anon-Id' => $anonId])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'ENNEAGRAM',
            'anon_id' => $anonId,
            'locale' => 'zh-CN',
            'region' => 'CN_MAINLAND',
            'form_code' => 'forced_choice_144',
        ]);
        $start->assertStatus(200);
        $start->assertJsonPath('ok', true);
        $start->assertJsonPath('scale_code', 'ENNEAGRAM');
        $start->assertJsonPath('form_code', 'enneagram_forced_choice_144');
        $start->assertJsonPath('dir_version', 'v1-forced-choice-144');
        $start->assertJsonPath('question_count', 144);
        $start->assertJsonPath('scoring_spec_version', 'enneagram_forced_choice_144_spec_v1');

        $attemptId = (string) $start->json('attempt_id');
        $attempt = Attempt::query()->findOrFail($attemptId);
        $this->assertSame('enneagram_forced_choice_144', data_get($attempt->answers_summary_json, 'meta.form_code'));

        $questions = $this->getJson('/api/v0.3/scales/ENNEAGRAM/questions?form_code=enneagram_forced_choice_144');
        $answers = $this->buildAnswersFromItems((array) $questions->json('questions.items'));
        $this->assertCount(144, $answers);

        $submit = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $answers,
            'duration_ms' => 240000,
        ]);
        $submit->assertStatus(200);
        $submit->assertJsonPath('ok', true);
        $submit->assertJsonPath('attempt_id', $attemptId);
        $submit->assertJsonPath('result.form_code', 'enneagram_forced_choice_144');
        $submit->assertJsonPath('result.score_method', 'enneagram_forced_choice_144_pair_v1');

        $stored = Result::query()->where('attempt_id', $attemptId)->first();
        $this->assertNotNull($stored);
        $this->assertSame('ENNEAGRAM', (string) $stored->scale_code);
        $this->assertSame('v1-forced-choice-144', (string) $stored->dir_version);
        $this->assertSame('enneagram_forced_choice_144', (string) data_get($stored->result_json, 'form_code'));
        $this->assertSame('enneagram_forced_choice_144_pair_v1', (string) data_get($stored->result_json, 'score_method'));
        $this->assertIsArray(data_get($stored->result_json, 'raw_scores.type_counts'));

        $readback = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/result");
        $readback->assertStatus(200);
        $readback->assertJsonPath('ok', true);
        $readback->assertJsonPath('result.form_code', 'enneagram_forced_choice_144');
        $readback->assertJsonPath('result.computed_at', (string) data_get($stored->result_json, 'computed_at'));
    }

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder)->run();
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return list<array{question_id:string,code:string}>
     */
    private function buildAnswersFromItems(array $items): array
    {
        $answers = [];
        foreach ($items as $index => $item) {
            $questionId = trim((string) ($item['question_id'] ?? ''));
            $options = is_array($item['options'] ?? null) ? $item['options'] : [];
            if ($questionId === '' || $options === []) {
                continue;
            }

            $selected = $options[$index % count($options)] ?? $options[0];
            $code = trim((string) ($selected['code'] ?? ''));
            if ($code === '') {
                continue;
            }

            $answers[] = [
                'question_id' => $questionId,
                'code' => $code,
            ];
        }

        return $answers;
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
