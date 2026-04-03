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

final class BigFiveFormVersionFlowTest extends TestCase
{
    use RefreshDatabase;

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

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder())->run();
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
            if ($questionId === '') {
                continue;
            }

            $options = is_array($item['options'] ?? null) ? $item['options'] : [];
            if ($options === []) {
                continue;
            }

            $selected = $options[$index % count($options)] ?? $options[0];
            $code = trim((string) ($selected['code'] ?? ''));
            if ($code === '') {
                $code = trim((string) (($options[0]['code'] ?? '3')));
            }
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

    public function test_big5_questions_support_form_version_delivery(): void
    {
        $this->seedScales();

        $default = $this->getJson('/api/v0.3/scales/BIG5_OCEAN/questions');
        $default->assertStatus(200);
        $default->assertJsonPath('form_code', 'big5_120');
        $default->assertJsonPath('dir_version', 'v1');
        $default->assertJsonPath('content_package_version', 'v1');
        $this->assertCount(120, (array) $default->json('questions.items'));

        $short = $this->getJson('/api/v0.3/scales/BIG5_OCEAN/questions?form=standard_90');
        $short->assertStatus(200);
        $short->assertJsonPath('form_code', 'big5_90');
        $short->assertJsonPath('dir_version', 'v1-form-90');
        $short->assertJsonPath('content_package_version', 'v1-form-90');
        $this->assertCount(90, (array) $short->json('questions.items'));

        $invalid = $this->getJson('/api/v0.3/scales/BIG5_OCEAN/questions?form_code=big5_unknown');
        $invalid->assertStatus(422);
        $invalid->assertJsonPath('error_code', 'INVALID_FORM_CODE');
    }

    public function test_big5_start_submit_scoring_supports_90_and_keeps_120_default(): void
    {
        $this->seedScales();

        $anon90 = 'big5-form-90-anon';
        $token90 = $this->issueAnonToken($anon90);

        $start90 = $this->withHeaders([
            'X-Anon-Id' => $anon90,
        ])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'BIG5_OCEAN',
            'anon_id' => $anon90,
            'form_code' => 'big5_90',
        ]);
        $start90->assertStatus(200);
        $start90->assertJsonPath('form_code', 'big5_90');
        $start90->assertJsonPath('dir_version', 'v1-form-90');
        $start90->assertJsonPath('content_package_version', 'v1-form-90');
        $start90->assertJsonPath('question_count', 90);
        $start90->assertJsonPath('scoring_spec_version', 'big5_spec_2026Q2_form90_v1');
        $start90->assertJsonPath('norm_version', 'big5.norms.2026Q2.form90.v1');

        $attemptId90 = (string) $start90->json('attempt_id');
        $attempt90 = Attempt::query()->findOrFail($attemptId90);
        $this->assertSame('v1-form-90', (string) $attempt90->dir_version);
        $this->assertSame('v1-form-90', (string) $attempt90->content_package_version);
        $this->assertSame('big5_spec_2026Q2_form90_v1', (string) $attempt90->scoring_spec_version);
        $this->assertSame('big5.norms.2026Q2.form90.v1', (string) $attempt90->norm_version);
        $this->assertSame(90, (int) $attempt90->question_count);
        $this->assertSame('big5_90', data_get($attempt90->answers_summary_json, 'meta.form_code'));
        $this->assertSame('big5_quality_2026Q2_form90_v1', data_get($attempt90->answers_summary_json, 'meta.quality_version'));

        $questions90 = $this->getJson('/api/v0.3/scales/BIG5_OCEAN/questions?form=big5_90');
        $questions90->assertStatus(200);
        $answers90 = $this->buildAnswersFromItems((array) $questions90->json('questions.items'));
        $this->assertCount(90, $answers90);

        $submit90 = $this->withHeaders([
            'X-Anon-Id' => $anon90,
            'Authorization' => 'Bearer '.$token90,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId90,
            'answers' => $answers90,
            'duration_ms' => 180000,
        ]);
        $submit90->assertStatus(200);
        $submit90->assertJsonPath('ok', true);

        $result90 = Result::query()->where('attempt_id', $attemptId90)->first();
        $this->assertNotNull($result90);
        $this->assertSame('v1-form-90', (string) ($result90->dir_version ?? ''));
        $this->assertSame('v1-form-90', (string) ($result90->content_package_version ?? ''));
        $this->assertSame('big5_spec_2026Q2_form90_v1', (string) ($result90->scoring_spec_version ?? ''));
        $this->assertSame('big5.norms.2026Q2.form90.v1', (string) data_get($result90->result_json, 'norms.norms_version'));

        $anon120 = 'big5-form-120-anon';
        $token120 = $this->issueAnonToken($anon120);

        $start120 = $this->withHeaders([
            'X-Anon-Id' => $anon120,
        ])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'BIG5_OCEAN',
            'anon_id' => $anon120,
        ]);
        $start120->assertStatus(200);
        $start120->assertJsonPath('form_code', 'big5_120');
        $start120->assertJsonPath('dir_version', 'v1');
        $start120->assertJsonPath('question_count', 120);
        $start120->assertJsonPath('scoring_spec_version', 'big5_spec_2026Q1_v1');

        $attemptId120 = (string) $start120->json('attempt_id');
        $questions120 = $this->getJson('/api/v0.3/scales/BIG5_OCEAN/questions');
        $questions120->assertStatus(200);
        $answers120 = $this->buildAnswersFromItems((array) $questions120->json('questions.items'));
        $this->assertCount(120, $answers120);

        $submit120 = $this->withHeaders([
            'X-Anon-Id' => $anon120,
            'Authorization' => 'Bearer '.$token120,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId120,
            'answers' => $answers120,
            'duration_ms' => 180000,
        ]);
        $submit120->assertStatus(200);
        $submit120->assertJsonPath('ok', true);

        $result120 = Result::query()->where('attempt_id', $attemptId120)->first();
        $this->assertNotNull($result120);
        $this->assertSame('v1', (string) ($result120->dir_version ?? ''));
        $this->assertSame('v1', (string) ($result120->content_package_version ?? ''));
        $this->assertSame('big5_spec_2026Q1_v1', (string) ($result120->scoring_spec_version ?? ''));

        $attempt120 = Attempt::query()->findOrFail($attemptId120);
        $this->assertSame('big5_120', data_get($attempt120->answers_summary_json, 'meta.form_code'));
        $this->assertSame('big5_spec_2026Q1_v1', data_get($attempt120->answers_summary_json, 'meta.quality_version'));
    }
}
