<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\BigFiveNormObservation;
use App\Services\BigFive\Norms\BigFiveNormObservationCaptureWriter;
use App\Services\Iq\IqResultPayloadRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class Security103Api02ScoringPayloadBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_iq_public_redactor_removes_answer_key_and_test_bank_aliases_recursively(): void
    {
        $redacted = IqResultPayloadRedactor::redactAnswerKeys([
            'safe_item_state' => [
                'question_id' => 'IQ001',
                'selected_code' => 'B',
                'is_correct' => false,
            ],
            'result' => [
                'answer_key_status' => 'backend_private_answer_key_available',
                'correct_answers' => ['IQ001' => 'A'],
                'correctOption' => 'A',
                'solution_steps' => ['private solution trace'],
                'scoring_spec_json' => ['private' => true],
                'questionBank' => [['id' => 'IQ001', 'correctAnswer' => 'A']],
                'privatePayload' => ['answerKey' => ['IQ001' => 'A']],
            ],
        ]);

        $this->assertSame('B', data_get($redacted, 'safe_item_state.selected_code'));
        $this->assertFalse((bool) data_get($redacted, 'safe_item_state.is_correct'));
        $this->assertForbiddenKeysAbsent($redacted);
    }

    public function test_big_five_norm_capture_requires_explicit_norming_consent(): void
    {
        $writer = new BigFiveNormObservationCaptureWriter;

        $blocked = $writer->capture($this->bigFiveScoreResult(), $this->bigFiveContext([
            'observation_idempotency_key' => 'security-103-api-02-no-consent',
            'norming_consent_accepted' => false,
        ]));

        $this->assertFalse($blocked->captured);
        $this->assertSame('rejected', $blocked->status);
        $this->assertSame('explicit_norming_consent_required', $blocked->reason);
        $this->assertSame(0, BigFiveNormObservation::query()->count());

        $captured = $writer->capture($this->bigFiveScoreResult(), $this->bigFiveContext([
            'observation_idempotency_key' => 'security-103-api-02-with-consent',
            'norming_consent_accepted' => true,
        ]));

        $this->assertTrue($captured->captured);
        $this->assertSame('captured', $captured->status);
        $this->assertSame(1, BigFiveNormObservation::query()->count());
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function assertForbiddenKeysAbsent(array $payload, string $path = '$'): void
    {
        $forbidden = [
            'answer_key',
            'answerKey',
            'answer_key_version',
            'answerKeyVersion',
            'answer_key_status',
            'answerKeyStatus',
            'correct_answer',
            'correctAnswer',
            'correct_answers',
            'correctAnswers',
            'correct_option',
            'correctOption',
            'correct_options',
            'correctOptions',
            'solution_rule',
            'solutionRule',
            'solution_rules',
            'solutionRules',
            'solution_steps',
            'solutionSteps',
            'distractor_logic',
            'distractorLogic',
            'asset_hashes',
            'assetHashes',
            'generator_metadata',
            'generatorMetadata',
            'scoring_spec',
            'scoringSpec',
            'scoring_spec_json',
            'scoringSpecJson',
            'item_bank',
            'itemBank',
            'question_bank',
            'questionBank',
            'test_bank',
            'testBank',
            'private_payload',
            'privatePayload',
        ];

        foreach ($payload as $key => $value) {
            $this->assertNotContains((string) $key, $forbidden, 'Forbidden scoring field leaked at '.$path.'.'.$key);

            if (is_array($value)) {
                $this->assertForbiddenKeysAbsent($value, $path.'.'.$key);
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function bigFiveScoreResult(): array
    {
        return [
            'raw_domain_scores' => ['O' => 71, 'C' => 64, 'E' => 58, 'A' => 69, 'N' => 31],
            'raw_facet_scores' => [
                'N1' => 7, 'E1' => 12, 'O1' => 15, 'A1' => 14, 'C1' => 13,
                'N2' => 8, 'E2' => 11, 'O2' => 16, 'A2' => 13, 'C2' => 14,
                'N3' => 9, 'E3' => 10, 'O3' => 17, 'A3' => 12, 'C3' => 15,
                'N4' => 10, 'E4' => 9, 'O4' => 18, 'A4' => 11, 'C4' => 16,
                'N5' => 11, 'E5' => 8, 'O5' => 19, 'A5' => 10, 'C5' => 17,
                'N6' => 12, 'E6' => 7, 'O6' => 20, 'A6' => 9, 'C6' => 18,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function bigFiveContext(array $overrides = []): array
    {
        return array_replace([
            'capture_enabled' => true,
            'operation_scope' => 'internal_only',
            'observation_schema_version' => BigFiveNormObservationCaptureWriter::SCHEMA_VERSION,
            'observation_idempotency_key' => 'security-103-api-02',
            'observation_source' => 'security_test',
            'environment' => 'testing',
            'scale_code' => 'BIG5_OCEAN',
            'form_code' => 'big5_120',
            'content_version' => 'big5.result_page_v2.content.v0.1',
            'score_version' => 'big5.scoring.v1',
            'norm_version_at_scoring' => 'norms.disabled',
            'norm_eligibility_status' => 'eligible',
            'norm_excluded' => false,
            'norming_consent_accepted' => true,
            'quality_level' => 'A',
            'quality_flags' => [],
            'locale' => 'zh-CN',
            'region' => 'CN',
            'age_band' => '25_34',
            'gender_bucket' => 'unspecified',
            'occupation_bucket' => 'not_collected',
            'attempt_source' => 'real',
            'attempt_submitted_at' => now(),
            'observed_at' => now(),
        ], $overrides);
    }
}
