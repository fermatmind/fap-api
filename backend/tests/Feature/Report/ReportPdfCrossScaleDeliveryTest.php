<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Assessment\Scorers\ClinicalCombo68ScorerV1;
use App\Services\Assessment\Scorers\Sds20ScorerV2FactorLogic;
use App\Services\Content\ClinicalComboPackLoader;
use App\Services\Content\Sds20PackLoader;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReportPdfCrossScaleDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_pdf_artifacts_are_partitioned_by_scale_code_and_reused(): void
    {
        $this->artisan('content:compile --pack=CLINICAL_COMBO_68 --pack-version=v1')->assertExitCode(0);
        $this->artisan('content:compile --pack=SDS_20 --pack-version=v1')->assertExitCode(0);
        (new ScaleRegistrySeeder)->run();
        Storage::fake('local');

        $clinicalAnon = 'anon_pdf_clinical_delivery';
        $sdsAnon = 'anon_pdf_sds_delivery';
        $clinicalAttemptId = $this->createClinicalAttemptWithResult($clinicalAnon);
        $sdsAttemptId = $this->createSdsAttemptWithResult($sdsAnon);
        $clinicalToken = $this->issueAnonToken($clinicalAnon);
        $sdsToken = $this->issueAnonToken($sdsAnon);

        $clinicalPdf = $this->withHeaders([
            'Authorization' => 'Bearer '.$clinicalToken,
            'X-Anon-Id' => $clinicalAnon,
        ])->get('/api/v0.3/attempts/'.$clinicalAttemptId.'/report.pdf');
        $clinicalPdf->assertStatus(200);
        $clinicalPdf->assertHeader('Content-Type', 'application/pdf');
        $clinicalPdf->assertHeader('X-Report-Scale', 'CLINICAL_COMBO_68');

        $sdsPdf = $this->withHeaders([
            'Authorization' => 'Bearer '.$sdsToken,
            'X-Anon-Id' => $sdsAnon,
        ])->get('/api/v0.3/attempts/'.$sdsAttemptId.'/report.pdf');
        $sdsPdf->assertStatus(200);
        $sdsPdf->assertHeader('Content-Type', 'application/pdf');
        $sdsPdf->assertHeader('X-Report-Scale', 'SDS_20');

        $clinicalFilesFirst = Storage::disk('local')->allFiles('artifacts/pdf/CLINICAL_COMBO_68/'.$clinicalAttemptId);
        $sdsFilesFirst = Storage::disk('local')->allFiles('artifacts/pdf/SDS_20/'.$sdsAttemptId);
        $this->assertCount(1, $clinicalFilesFirst);
        $this->assertCount(1, $sdsFilesFirst);
        $this->assertStringContainsString('/report_free.pdf', $clinicalFilesFirst[0]);
        $this->assertStringContainsString('/report_free.pdf', $sdsFilesFirst[0]);

        $clinicalPdfAgain = $this->withHeaders([
            'Authorization' => 'Bearer '.$clinicalToken,
            'X-Anon-Id' => $clinicalAnon,
        ])->get('/api/v0.3/attempts/'.$clinicalAttemptId.'/report.pdf');
        $clinicalPdfAgain->assertStatus(200);

        $clinicalFilesSecond = Storage::disk('local')->allFiles('artifacts/pdf/CLINICAL_COMBO_68/'.$clinicalAttemptId);
        $this->assertSame($clinicalFilesFirst, $clinicalFilesSecond);
    }

    private function createClinicalAttemptWithResult(string $anonId): string
    {
        $attemptId = (string) Str::uuid();
        $score = $this->scoreClinicalPayload([
            9 => 'A',
            68 => 'A',
        ]);

        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'CLINICAL_COMBO_68',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 68,
            'client_platform' => 'test',
            'answers_summary_json' => [
                'stage' => 'seed',
                'meta' => [
                    'pack_release_manifest_hash' => 'clinical_pdf_hash_v1',
                ],
            ],
            'started_at' => now()->subMinutes(3),
            'submitted_at' => now(),
            'pack_id' => 'CLINICAL_COMBO_68',
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => 'v1.0_2026',
        ]);

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'CLINICAL_COMBO_68',
            'scale_version' => 'v0.3',
            'type_code' => '',
            'scores_json' => (array) ($score['scores'] ?? []),
            'scores_pct' => [],
            'axis_states' => [],
            'content_package_version' => 'v1',
            'result_json' => [
                'normed_json' => $score,
                'breakdown_json' => ['score_result' => $score],
                'axis_scores_json' => ['score_result' => $score],
            ],
            'pack_id' => 'CLINICAL_COMBO_68',
            'dir_version' => 'v1',
            'scoring_spec_version' => 'v1.0_2026',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        return $attemptId;
    }

    private function createSdsAttemptWithResult(string $anonId): string
    {
        $attemptId = (string) Str::uuid();
        $score = $this->scoreSdsPayload();

        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'SDS_20',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 20,
            'client_platform' => 'test',
            'answers_summary_json' => [
                'stage' => 'seed',
                'meta' => [
                    'pack_release_manifest_hash' => 'sds_pdf_hash_v1',
                ],
            ],
            'started_at' => now()->subMinutes(3),
            'submitted_at' => now(),
            'pack_id' => 'SDS_20',
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => 'v2.0_Factor_Logic',
        ]);

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'SDS_20',
            'scale_version' => 'v0.3',
            'type_code' => '',
            'scores_json' => (array) ($score['scores'] ?? []),
            'scores_pct' => [],
            'axis_states' => [],
            'content_package_version' => 'v1',
            'result_json' => [
                'normed_json' => $score,
                'breakdown_json' => ['score_result' => $score],
                'axis_scores_json' => ['score_result' => $score],
            ],
            'pack_id' => 'SDS_20',
            'dir_version' => 'v1',
            'scoring_spec_version' => 'v2.0_Factor_Logic',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        return $attemptId;
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

    /**
     * @param  array<int,string>  $overrides
     * @return array<string,mixed>
     */
    private function scoreClinicalPayload(array $overrides = []): array
    {
        /** @var ClinicalComboPackLoader $loader */
        $loader = app(ClinicalComboPackLoader::class);
        /** @var ClinicalCombo68ScorerV1 $scorer */
        $scorer = app(ClinicalCombo68ScorerV1::class);

        $answers = [];
        for ($i = 1; $i <= 68; $i++) {
            $answers[$i] = 'A';
        }
        foreach ($overrides as $qid => $code) {
            $qid = (int) $qid;
            if ($qid >= 1 && $qid <= 68) {
                $answers[$qid] = strtoupper(trim((string) $code));
            }
        }

        return $scorer->score(
            $answers,
            $loader->loadQuestionIndex('v1'),
            $loader->loadOptionSets('v1'),
            $loader->loadPolicy('v1'),
            [
                'pack_id' => 'CLINICAL_COMBO_68',
                'dir_version' => 'v1',
                'started_at' => now()->subSeconds(315)->toISOString(),
                'submitted_at' => now()->toISOString(),
                'duration_ms' => 120000,
                'content_manifest_hash' => 'clinical_pdf_hash_v1',
            ]
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function scoreSdsPayload(): array
    {
        /** @var Sds20PackLoader $loader */
        $loader = app(Sds20PackLoader::class);
        /** @var Sds20ScorerV2FactorLogic $scorer */
        $scorer = app(Sds20ScorerV2FactorLogic::class);

        $answers = [];
        for ($i = 1; $i <= 20; $i++) {
            $answers[$i] = 'A';
        }

        return $scorer->score(
            $answers,
            $loader->loadQuestionIndex('v1'),
            $loader->loadPolicy('v1'),
            [
                'pack_id' => 'SDS_20',
                'dir_version' => 'v1',
                'duration_ms' => 98000,
                'started_at' => now()->subSeconds(98)->toISOString(),
                'submitted_at' => now()->toISOString(),
                'locale' => 'zh-CN',
                'region' => 'CN_MAINLAND',
                'content_manifest_hash' => 'sds_pdf_hash_v1',
            ]
        );
    }
}
