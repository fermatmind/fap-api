<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\Attempt;
use App\Models\Result;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MbtiFormVersionFlowTest extends TestCase
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

    public function test_mbti_questions_default_to_144_form(): void
    {
        $this->seedScales();

        $response = $this->getJson('/api/v0.3/scales/MBTI/questions');

        $response->assertStatus(200);
        $response->assertJsonPath('form_code', 'mbti_144');
        $response->assertJsonPath('dir_version', 'MBTI-CN-v0.3');
        $response->assertJsonPath('content_package_version', 'v0.3');
        $this->assertCount(144, $response->json('questions.items'));
    }

    public function test_mbti_questions_can_load_93_form_by_alias(): void
    {
        $this->seedScales();

        $response = $this->getJson('/api/v0.3/scales/MBTI/questions?form=standard_93');

        $response->assertStatus(200);
        $response->assertJsonPath('form_code', 'mbti_93');
        $response->assertJsonPath('dir_version', 'MBTI-CN-v0.3-form-93');
        $response->assertJsonPath('content_package_version', 'v0.3-form-93');
        $this->assertCount(93, $response->json('questions.items'));
    }

    public function test_mbti_questions_reject_unknown_form_code(): void
    {
        $this->seedScales();

        $response = $this->getJson('/api/v0.3/scales/MBTI/questions?form_code=mbti_unknown');

        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'INVALID_FORM_CODE');
    }

    public function test_mbti_start_persists_93_form_truth_without_schema_changes(): void
    {
        $this->seedScales();

        $anonId = 'mbti-form-start-anon';
        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
        ])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'MBTI',
            'anon_id' => $anonId,
            'form_code' => 'mbti_93',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('form_code', 'mbti_93');
        $response->assertJsonPath('dir_version', 'MBTI-CN-v0.3-form-93');
        $response->assertJsonPath('content_package_version', 'v0.3-form-93');
        $response->assertJsonPath('scoring_spec_version', '2026.01.mbti_93');
        $response->assertJsonPath('norm_version', 'mbti.cn-mainland.zh-CN.2026.form93.provisional');
        $response->assertJsonPath('question_count', 93);

        $attemptId = (string) $response->json('attempt_id');
        $attempt = Attempt::query()->findOrFail($attemptId);

        $this->assertSame('MBTI-CN-v0.3-form-93', (string) $attempt->dir_version);
        $this->assertSame('v0.3-form-93', (string) $attempt->content_package_version);
        $this->assertSame('2026.01.mbti_93', (string) $attempt->scoring_spec_version);
        $this->assertSame('mbti.cn-mainland.zh-CN.2026.form93.provisional', (string) $attempt->norm_version);
        $this->assertSame(93, (int) $attempt->question_count);
        $this->assertSame('mbti_93', data_get($attempt->answers_summary_json, 'meta.form_code'));
    }

    public function test_mbti_submit_scores_against_93_source(): void
    {
        $this->seedScales();

        $anonId = 'mbti-form-submit-anon';
        $anonToken = $this->issueAnonToken($anonId);

        $start = $this->withHeaders([
            'X-Anon-Id' => $anonId,
        ])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'MBTI',
            'anon_id' => $anonId,
            'form_code' => 'mbti_93',
        ]);
        $start->assertStatus(200);

        $attemptId = (string) $start->json('attempt_id');
        $this->assertNotSame('', $attemptId);

        $doc = json_decode((string) File::get(base_path('../content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.3-form-93/questions.json')), true);
        $items = is_array($doc['items'] ?? null) ? $doc['items'] : [];

        $answers = [];
        $preferredCodes = ['A', 'B', 'C', 'D', 'E'];
        foreach ($items as $index => $item) {
            $options = array_map(
                static fn (array $opt): string => (string) ($opt['code'] ?? ''),
                is_array($item['options'] ?? null) ? $item['options'] : []
            );
            $selectedCode = $preferredCodes[$index % count($preferredCodes)];
            if (! in_array($selectedCode, $options, true)) {
                $selectedCode = (string) ($options[0] ?? 'A');
            }

            $answers[] = [
                'question_id' => (string) ($item['question_id'] ?? ''),
                'code' => $selectedCode,
            ];
        }

        $submit = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$anonToken,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $answers,
            'duration_ms' => 180000,
        ]);

        $submit->assertStatus(200);
        $submit->assertJsonPath('ok', true);
        $submit->assertJsonPath('attempt_id', $attemptId);
        $this->assertIsString((string) $submit->json('result.type_code'));

        $attempt = Attempt::query()->findOrFail($attemptId);
        $this->assertSame('MBTI-CN-v0.3-form-93', (string) $attempt->dir_version);
        $this->assertSame('mbti_93', data_get($attempt->answers_summary_json, 'meta.form_code'));

        $result = Result::query()->where('attempt_id', $attemptId)->first();
        $this->assertNotNull($result);
        $this->assertSame('MBTI-CN-v0.3-form-93', (string) ($result->dir_version ?? ''));
        $this->assertSame('v0.3-form-93', (string) ($result->content_package_version ?? ''));
        $this->assertSame('2026.01.mbti_93', (string) ($result->scoring_spec_version ?? ''));
    }
}
