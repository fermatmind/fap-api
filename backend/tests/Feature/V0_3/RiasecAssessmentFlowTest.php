<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\Result;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RiasecAssessmentFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_riasec_questions_support_standard_60_and_enhanced_140_forms(): void
    {
        $this->seedScales();

        $standard = $this->getJson('/api/v0.3/scales/RIASEC/questions?form_code=riasec_60&locale=zh-CN');
        $standard->assertStatus(200);
        $standard->assertJsonPath('ok', true);
        $standard->assertJsonPath('scale_code', 'RIASEC');
        $standard->assertJsonPath('form_code', 'riasec_60');
        $standard->assertJsonPath('dir_version', 'v1-standard-60');
        $standard->assertJsonPath('questions.schema', 'fap.questions.v1');
        $this->assertCount(60, (array) $standard->json('questions.items'));
        $standard->assertJsonPath('questions.items.0.options.0.code', '1');
        $standard->assertJsonPath('questions.items.0.options.4.code', '5');

        $enhanced = $this->getJson('/api/v0.3/scales/RIASEC/questions?form_code=riasec_140&locale=zh-CN');
        $enhanced->assertStatus(200);
        $enhanced->assertJsonPath('ok', true);
        $enhanced->assertJsonPath('scale_code', 'RIASEC');
        $enhanced->assertJsonPath('form_code', 'riasec_140');
        $enhanced->assertJsonPath('dir_version', 'v1-enhanced-140');
        $this->assertCount(140, (array) $enhanced->json('questions.items'));
        $enhanced->assertJsonPath('meta.form_kind', 'enhanced');
    }

    public function test_riasec_standard_60_start_submit_and_result_readback_use_backend_result(): void
    {
        $this->seedScales();

        $anonId = 'anon_riasec_standard';
        $token = $this->issueAnonToken($anonId);

        $start = $this->withHeaders(['X-Anon-Id' => $anonId])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'RIASEC',
            'anon_id' => $anonId,
            'locale' => 'zh-CN',
            'region' => 'CN_MAINLAND',
            'form_code' => '60',
        ]);
        $start->assertStatus(200);
        $start->assertJsonPath('ok', true);
        $start->assertJsonPath('scale_code', 'RIASEC');
        $start->assertJsonPath('form_code', 'riasec_60');
        $start->assertJsonPath('dir_version', 'v1-standard-60');
        $start->assertJsonPath('question_count', 60);
        $start->assertJsonPath('scoring_spec_version', 'riasec_standard_60_v1');

        $attemptId = (string) $start->json('attempt_id');
        $answers = $this->answers(60);

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
        $submit->assertJsonPath('result.top_code', 'RIA');
        $submit->assertJsonPath('result.score_R', 100);

        $stored = Result::query()->where('attempt_id', $attemptId)->first();
        $this->assertNotNull($stored);
        $this->assertSame('RIASEC', (string) $stored->scale_code);
        $this->assertSame('v1-standard-60', (string) $stored->dir_version);
        $this->assertSame('RIA', (string) data_get($stored->result_json, 'top_code'));

        $readback = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/result");
        $readback->assertStatus(200);
        $readback->assertJsonPath('ok', true);
        $readback->assertJsonPath('type_code', 'RIA');
        $readback->assertJsonPath('result.top_code', 'RIA');
        $readback->assertJsonPath('riasec_public_projection_v1.top_code', 'RIA');
        $readback->assertJsonPath('riasec_form_v1.form_code', 'riasec_60');

        $report = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report");
        $report->assertStatus(200);
        $report->assertJsonPath('ok', true);
        $report->assertJsonPath('locked', false);
        $report->assertJsonPath('report.schema_version', 'riasec.report.v1');
        $report->assertJsonPath('report.top_code', 'RIA');
        $report->assertJsonPath('riasec_public_projection_v1.top_code', 'RIA');
        $report->assertJsonPath('riasec_form_v1.form_code', 'riasec_60');

        $reportAccess = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report-access");
        $reportAccess->assertStatus(200);
        $reportAccess->assertJsonPath('ok', true);
        $reportAccess->assertJsonPath('access_state', 'ready');
        $reportAccess->assertJsonPath('report_state', 'ready');
        $reportAccess->assertJsonPath('payload.access_level', 'full');
        $reportAccess->assertJsonPath('payload.variant', 'full');
        $reportAccess->assertJsonPath('riasec_form_v1.form_code', 'riasec_60');

        $share = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/share");
        $share->assertStatus(200);
        $share->assertJsonPath('scale_code', 'RIASEC');
        $share->assertJsonPath('type_code', 'RIA');
        $share->assertJsonPath('riasec_public_projection_v1.top_code', 'RIA');
        $share->assertJsonPath('landing_surface_v1.entry_surface', 'riasec_share_entry');
        $share->assertJsonPath('seo_surface_v1.surface_type', 'riasec_share_public_safe');
        $share->assertJsonPath('answer_surface_v1.surface_type', 'riasec_share_public_safe');
        $share->assertJsonPath('public_surface_v1.entry_surface', 'riasec_share_landing');
        $this->assertStringContainsString('/zh/tests/holland-career-interest-test-riasec', (string) $share->json('primary_cta_path'));
        $this->assertIsArray($share->json('dimensions'));
        $this->assertNotEmpty($share->json('dimensions'));
        $this->assertNull($share->json('mbti_public_projection_v1'));

        $history = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v0.3/me/attempts?scale=RIASEC');
        $history->assertStatus(200);
        $history->assertJsonPath('ok', true);
        $history->assertJsonPath('scale_code', 'RIASEC');
        $history->assertJsonPath('items.0.attempt_id', $attemptId);
        $history->assertJsonPath('items.0.riasec_form_v1.form_code', 'riasec_60');
        $history->assertJsonPath('items.0.riasec_form_v1.question_count', 60);
    }

    public function test_riasec_enhanced_140_persists_quality_and_layer_scores(): void
    {
        $this->seedScales();

        $anonId = 'anon_riasec_enhanced';
        $token = $this->issueAnonToken($anonId);

        $start = $this->withHeaders(['X-Anon-Id' => $anonId])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'RIASEC',
            'anon_id' => $anonId,
            'locale' => 'zh-CN',
            'region' => 'CN_MAINLAND',
            'form_code' => '140',
        ]);
        $start->assertStatus(200);
        $start->assertJsonPath('form_code', 'riasec_140');
        $start->assertJsonPath('question_count', 140);

        $attemptId = (string) $start->json('attempt_id');
        $answers = $this->answers(140);

        $submit = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $answers,
            'duration_ms' => 360000,
        ]);
        $submit->assertStatus(200);
        $submit->assertJsonPath('ok', true);
        $submit->assertJsonPath('result.top_code', 'RIA');
        $submit->assertJsonPath('result.activity_R', 100);
        $submit->assertJsonPath('result.env_R', 100);
        $submit->assertJsonPath('result.role_R', 100);
        $submit->assertJsonPath('result.quality_grade', 'A');
    }

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder)->run();
    }

    /**
     * @return list<array{question_id:string,code:string}>
     */
    private function answers(int $count): array
    {
        $answers = [];
        for ($qid = 1; $qid <= $count; $qid++) {
            $code = '1';
            if ($qid <= 10 || ($qid >= 61 && $qid <= 72)) {
                $code = '5';
            }
            if ($qid === 133) {
                $code = '3';
            } elseif ($qid === 137) {
                $code = '2';
            } elseif (in_array($qid, [138, 139, 140], true)) {
                $code = '1';
            }

            $answers[] = [
                'question_id' => (string) $qid,
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
