<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\Attempt;
use App\Models\Result;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AttemptPublicReportPdfParityTest extends TestCase
{
    use RefreshDatabase;

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder)->run();
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

    private function createAttempt(string $attemptId, string $scaleCode, string $anonId): void
    {
        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => $scaleCode,
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now(),
            'submitted_at' => now(),
            'pack_id' => (string) config('content_packs.default_pack_id'),
            'dir_version' => 'MBTI-CN-v0.3',
            'content_package_version' => 'attempt-v1',
            'scoring_spec_version' => 'attempt-score-v1',
        ]);
    }

    private function createResult(string $attemptId): void
    {
        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'type_code' => 'INTJ-A',
            'scores_json' => [
                'EI' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'SN' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'TF' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'JP' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'AT' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
            ],
            'scores_pct' => [
                'EI' => 50,
                'SN' => 50,
                'TF' => 50,
                'JP' => 50,
                'AT' => 50,
            ],
            'axis_states' => [
                'EI' => 'clear',
                'SN' => 'clear',
                'TF' => 'clear',
                'JP' => 'clear',
                'AT' => 'clear',
            ],
            'content_package_version' => 'result-v1',
            'result_json' => [
                'raw_score' => 0,
                'final_score' => 0,
                'breakdown_json' => [],
                'type_code' => 'INTJ-A',
                'axis_scores_json' => [
                    'scores_pct' => [
                        'EI' => 50,
                        'SN' => 50,
                        'TF' => 50,
                        'JP' => 50,
                        'AT' => 50,
                    ],
                    'axis_states' => [
                        'EI' => 'clear',
                        'SN' => 'clear',
                        'TF' => 'clear',
                        'JP' => 'clear',
                        'AT' => 'clear',
                    ],
                ],
            ],
            'pack_id' => (string) config('content_packs.default_pack_id'),
            'dir_version' => 'MBTI-CN-v0.3',
            'scoring_spec_version' => 'result-score-v1',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);
    }

    public function test_public_mbti_report_pdf_uses_same_attempt_resolution_as_json_report(): void
    {
        $this->seedScales();
        config()->set('fap.features.report_snapshot_strict_v2', false);
        Storage::fake('local');

        $attemptId = (string) Str::uuid();
        $anonId = 'anon_mbti_pdf_parity';
        $token = $this->issueAnonToken($anonId);
        $this->createAttempt($attemptId, 'MBTI', $anonId);
        $this->createResult($attemptId);

        $headers = [
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ];

        $json = $this->withHeaders($headers)->getJson("/api/v0.3/attempts/{$attemptId}/report");
        $json->assertStatus(200);
        $json->assertJsonPath('ok', true);
        $json->assertJsonPath('locked', true);

        $pdf = $this->withHeaders($headers)->get("/api/v0.3/attempts/{$attemptId}/report.pdf");
        $pdf->assertStatus(200);
        $pdf->assertHeader('Content-Type', 'application/pdf');
        $pdf->assertHeader('X-Report-Scale', 'MBTI');
        $pdf->assertHeader('X-Report-Locked', 'true');
    }

    public function test_public_mbti_report_pdf_still_requires_matching_attempt_subject(): void
    {
        $this->seedScales();
        config()->set('fap.features.report_snapshot_strict_v2', false);
        Storage::fake('local');

        $attemptId = (string) Str::uuid();
        $ownerAnonId = 'anon_mbti_pdf_owner';
        $viewerAnonId = 'anon_mbti_pdf_viewer';
        $token = $this->issueAnonToken($viewerAnonId);
        $this->createAttempt($attemptId, 'MBTI', $ownerAnonId);
        $this->createResult($attemptId);

        $headers = [
            'X-Anon-Id' => $viewerAnonId,
            'Authorization' => 'Bearer '.$token,
        ];

        $json = $this->withHeaders($headers)->getJson("/api/v0.3/attempts/{$attemptId}/report");
        $json->assertStatus(200);
        $json->assertJsonPath('ok', true);
        $json->assertJsonPath('locked', true);

        $pdf = $this->withHeaders($headers)->get("/api/v0.3/attempts/{$attemptId}/report.pdf");
        $pdf->assertStatus(404);
    }
}
