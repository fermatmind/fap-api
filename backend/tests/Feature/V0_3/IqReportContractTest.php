<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Attempts\AttemptSubmitService;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class IqReportContractTest extends TestCase
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

    private function createAttempt(string $attemptId, string $anonId): void
    {
        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'IQ_RAVEN',
            'scale_code_v2' => 'IQ_INTELLIGENCE_QUOTIENT',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 30,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now()->subMinutes(12),
            'submitted_at' => now()->subMinute(),
            'pack_id' => 'IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO',
            'dir_version' => 'IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO',
            'content_package_version' => 'v0.3.0-demo',
            'scoring_spec_version' => '2026.03',
        ]);
    }

    private function createScoredResult(string $attemptId): Result
    {
        $score = [
            'scale_code' => 'IQ_INTELLIGENCE_QUOTIENT',
            'status' => 'scored',
            'scoring_mode' => 'scored',
            'bank_id' => 'IQ_SHOWCASE_12_BETA',
            'answer_key_version' => 'showcase12.v1',
            'norm_table_version' => 'unavailable',
            'scoring_engine_version' => 'iq_scoring_v2',
            'raw_score' => 21.0,
            'quality' => [
                'level' => 'A',
                'flags' => [],
            ],
            'result_stability' => [
                'status' => 'stable',
                'reason' => 'quality_clear',
            ],
            'norms' => [
                'status' => 'unavailable_without_norm_table',
                'iq_estimate' => null,
                'percentile' => null,
                'confidence_interval' => null,
            ],
            'dimension_scores' => [
                'VSI' => [
                    'dimension_name' => '视觉空间洞察',
                    'item_count' => 4,
                    'answered_count' => 4,
                    'correct_count' => 3,
                    'raw_score' => 3.0,
                    'percent_correct' => 75.0,
                ],
                'VSPR' => [
                    'dimension_name' => '视觉空间模式推理',
                    'item_count' => 4,
                    'answered_count' => 4,
                    'correct_count' => 4,
                    'raw_score' => 4.0,
                    'percent_correct' => 100.0,
                ],
                'NPR' => [
                    'dimension_name' => '数字规律推理',
                    'item_count' => 4,
                    'answered_count' => 4,
                    'correct_count' => 2,
                    'raw_score' => 2.0,
                    'percent_correct' => 50.0,
                ],
            ],
            'answer_key' => [
                'IQ001' => 'A',
            ],
            'items' => [
                [
                    'item_id' => 'FM-IQ-VSI-HS-L2-0001',
                    'question_id' => 'IQ001',
                    'dimension' => 'VSI',
                    'selected_code' => 'B',
                    'correct_answer' => 'A',
                    'is_correct' => false,
                    'raw_points' => 1.0,
                    'awarded_points' => 0.0,
                ],
            ],
        ];

        return Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'IQ_RAVEN',
            'scale_code_v2' => 'IQ_INTELLIGENCE_QUOTIENT',
            'scale_version' => 'v0.3',
            'type_code' => '',
            'scores_json' => [
                'items' => [
                    [
                        'question_id' => 'IQ001',
                        'selected_code' => 'B',
                        'correct_answer' => 'A',
                    ],
                ],
            ],
            'scores_pct' => [],
            'axis_states' => [],
            'content_package_version' => 'v0.3.0-demo',
            'result_json' => [
                'normed_json' => $score,
                'breakdown_json' => ['score_result' => $score],
                'axis_scores_json' => ['score_result' => $score],
            ],
            'pack_id' => 'IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO',
            'dir_version' => 'IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO',
            'scoring_spec_version' => '2026.03',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);
    }

    public function test_iq_report_endpoint_uses_iq_specific_three_dimension_payload_without_payment_unlock_changes(): void
    {
        $this->seedScales();

        $attemptId = (string) Str::uuid();
        $anonId = 'anon_iq_report_contract';
        $token = $this->issueAnonToken($anonId);
        $this->createAttempt($attemptId, $anonId);
        $this->createScoredResult($attemptId);

        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report");

        $response->assertStatus(200);
        $response->assertJson([
            'ok' => true,
            'locked' => true,
            'access_level' => 'free',
            'variant' => 'free',
        ]);
        $response->assertJsonPath('report.schema_version', 'iq.report.v1');
        $response->assertJsonPath('report.scale_code', 'IQ_INTELLIGENCE_QUOTIENT');
        $response->assertJsonPath('report.scale_code_legacy', 'IQ_RAVEN');
        $response->assertJsonPath('report.attempt_id', $attemptId);
        $response->assertJsonPath('report.summary.raw_score', null);
        $response->assertJsonPath('report.sections', []);
        $response->assertJsonPath('report._meta.redacted', true);
        $response->assertJsonPath('report.access.report_access_level', 'free');
        $response->assertJsonMissingPath('report.dimensions');
        $response->assertJsonMissingPath('report.quality');
        $response->assertJsonMissingPath('report.stability');
        $response->assertJsonMissingPath('report.scoring');
        $response->assertJsonMissingPath('report.iq_pro');
        $response->assertJsonMissingPath('report.profile');
        $response->assertJsonPath('upgrade_sku', null);
    }

    public function test_iq_result_endpoint_redacts_legacy_answer_keys_from_public_payload(): void
    {
        $this->seedScales();

        $attemptId = (string) Str::uuid();
        $anonId = 'anon_iq_result_redaction';
        $token = $this->issueAnonToken($anonId);
        $this->createAttempt($attemptId, $anonId);
        $this->createScoredResult($attemptId);

        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/result");

        $response->assertStatus(200);
        $this->assertPayloadHasNoAnswerKeyFields($response->json());
        $this->assertSame('B', data_get($response->json(), 'result.normed_json.items.0.selected_code'));
        $this->assertFalse((bool) data_get($response->json(), 'result.normed_json.items.0.is_correct'));
    }

    public function test_iq_submit_payload_redacts_legacy_answer_keys_before_response_serialization(): void
    {
        $this->seedScales();

        $attemptId = (string) Str::uuid();
        $anonId = 'anon_iq_submit_redaction';
        $this->createAttempt($attemptId, $anonId);
        $result = $this->createScoredResult($attemptId);
        $attempt = Attempt::query()->where('id', $attemptId)->firstOrFail();

        $payload = app(AttemptSubmitService::class)->buildSubmitPayload($attempt, $result, true);

        $this->assertPayloadHasNoAnswerKeyFields($payload);
        $this->assertSame('B', data_get($payload, 'result.normed_json.items.0.selected_code'));
        $this->assertFalse((bool) data_get($payload, 'result.normed_json.items.0.is_correct'));
    }

    public function test_iq_submission_read_redacts_stored_submit_response_answer_keys(): void
    {
        $this->seedScales();

        $attemptId = (string) Str::uuid();
        $anonId = 'anon_iq_submission_read_redaction';
        $token = $this->issueAnonToken($anonId);
        $this->createAttempt($attemptId, $anonId);

        DB::table('attempt_submissions')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'actor_user_id' => null,
            'actor_anon_id' => $anonId,
            'dedupe_key' => hash('sha256', $attemptId.':iq-submission-redaction'),
            'mode' => 'async',
            'state' => 'succeeded',
            'error_code' => null,
            'error_message' => null,
            'request_payload_json' => json_encode(['attempt_id' => $attemptId], JSON_THROW_ON_ERROR),
            'response_payload_json' => json_encode([
                'ok' => true,
                'attempt_id' => $attemptId,
                'result' => [
                    'normed_json' => [
                        'items' => [
                            [
                                'question_id' => 'IQ001',
                                'selected_code' => 'B',
                                'correct_answer' => 'A',
                            ],
                        ],
                    ],
                    'answer_key' => ['IQ001' => 'A'],
                ],
            ], JSON_THROW_ON_ERROR),
            'started_at' => now()->subMinute(),
            'finished_at' => now()->subSeconds(10),
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subSeconds(5),
        ]);

        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/submission");

        $response->assertStatus(200);
        $this->assertPayloadHasNoAnswerKeyFields($response->json());
        $this->assertSame('B', data_get($response->json(), 'result.result.normed_json.items.0.selected_code'));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function assertPayloadHasNoAnswerKeyFields(array $payload): void
    {
        foreach ($payload as $key => $value) {
            $this->assertNotContains($key, ['answer_key', 'answerKey', 'correct_answer', 'correctAnswer']);

            if (is_array($value)) {
                $this->assertPayloadHasNoAnswerKeyFields($value);
            }
        }
    }
}
