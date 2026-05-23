<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Riasec;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Riasec\RiasecCompareGuardService;
use App\Services\Riasec\RiasecMeasurementContract;
use Tests\TestCase;

final class RiasecCompareGuardContractTest extends TestCase
{
    public function test_compare_guard_allows_same_form_attempts(): void
    {
        $service = new RiasecCompareGuardService(new RiasecMeasurementContract);

        $guard = $service->evaluate(
            $this->attempt('attempt_a', 'riasec_60', 60),
            $this->makeResult('attempt_a', 'riasec_60', 'riasec_60_likert5_activity_sum_space.v1'),
            $this->attempt('attempt_b', 'riasec_60', 60),
            $this->makeResult('attempt_b', 'riasec_60', 'riasec_60_likert5_activity_sum_space.v1')
        );

        $this->assertSame('RIASEC', $guard['scale_code']);
        $this->assertTrue($guard['can_compare']);
        $this->assertSame('same_compare_compatibility_group', $guard['reason']);
        $this->assertSame('riasec.compare.allowed_same_form', $guard['copy_key']);
        $this->assertFalse($guard['raw_score_delta_allowed']);
    }

    public function test_compare_guard_blocks_60q_and_140q_cross_form_delta(): void
    {
        $service = new RiasecCompareGuardService(new RiasecMeasurementContract);

        $guard = $service->evaluate(
            $this->attempt('attempt_a', 'riasec_60', 60),
            $this->makeResult('attempt_a', 'riasec_60', 'riasec_60_likert5_activity_sum_space.v1'),
            $this->attempt('attempt_b', 'riasec_140', 140),
            $this->makeResult('attempt_b', 'riasec_140', 'riasec_140_likert5_activity_context_space.v1')
        );

        $this->assertFalse($guard['can_compare']);
        $this->assertSame('cross_form_score_space_mismatch', $guard['reason']);
        $this->assertSame('riasec.compare.blocked_cross_form', $guard['copy_key']);
        $this->assertFalse($guard['raw_score_delta_allowed']);
        $this->assertArrayNotHasKey('raw_scores_delta', $guard);
        $this->assertArrayNotHasKey('domains_delta', $guard);
    }

    public function test_compare_guard_blocks_same_form_when_score_space_versions_differ(): void
    {
        $service = new RiasecCompareGuardService(new RiasecMeasurementContract);

        $guard = $service->evaluate(
            $this->attempt('attempt_a', 'riasec_60', 60),
            $this->makeResult('attempt_a', 'riasec_60', 'riasec_60_likert5_activity_sum_space.v1'),
            $this->attempt('attempt_b', 'riasec_60', 60),
            $this->makeResult(
                'attempt_b',
                'riasec_60',
                'riasec_60_legacy_or_untrusted_space.v0',
                'RIASEC:riasec_60:riasec_60_likert5_activity_sum_space.v1'
            )
        );

        $this->assertFalse($guard['can_compare']);
        $this->assertSame('cross_form_score_space_mismatch', $guard['reason']);
        $this->assertSame('riasec.compare.blocked_cross_form', $guard['copy_key']);
        $this->assertFalse($guard['raw_score_delta_allowed']);
    }

    public function test_measurement_contract_keeps_claim_boundary_and_140q_contextual_label(): void
    {
        $contract = (new RiasecMeasurementContract)->forFormCode('riasec_140', 140);

        $this->assertSame('riasec.measurement_contract.v1', $contract['schema_version']);
        $this->assertSame('career_interest', $contract['measured_signal_kind']);
        $this->assertSame('riasec_140_likert5_activity_context_space.v1', data_get($contract, 'form.score_space_version'));
        $this->assertSame('140Q contextual daily-work interest signal', data_get($contract, 'form.score_space_label'));
        $this->assertContains('ability', data_get($contract, 'claim_boundary.does_not_measure'));
        $this->assertContains('career_success_probability', data_get($contract, 'claim_boundary.does_not_measure'));
        $this->assertSame('content_example_not_registry_match_without_reviewed_registry_source', data_get($contract, 'claim_boundary.occupation_examples_policy'));
        $this->assertFalse((bool) data_get($contract, 'scoring.raw_score_delta_allowed'));
        $this->assertFalse((bool) data_get($contract, 'compare_policy.cross_form_comparable'));
    }

    private function attempt(string $id, string $formCode, int $questionCount): Attempt
    {
        $attempt = new Attempt;
        $attempt->id = $id;
        $attempt->org_id = 0;
        $attempt->scale_code = 'RIASEC';
        $attempt->question_count = $questionCount;
        $attempt->answers_summary_json = [
            'meta' => [
                'form_code' => $formCode,
            ],
        ];

        return $attempt;
    }

    private function makeResult(string $attemptId, string $formCode, string $scoreSpaceVersion, ?string $compareGroup = null): Result
    {
        $result = new Result;
        $result->attempt_id = $attemptId;
        $result->scale_code = 'RIASEC';
        $contract = (new RiasecMeasurementContract)->forFormCode($formCode);
        data_set($contract, 'form.score_space_version', $scoreSpaceVersion);
        data_set(
            $contract,
            'compare_policy.compare_compatibility_group',
            $compareGroup ?? (new RiasecMeasurementContract)->compareCompatibilityGroup($formCode, $scoreSpaceVersion)
        );

        $result->result_json = [
            'form_code' => $formCode,
            'top_code' => 'RIA',
            'measurement_contract_v1' => $contract,
            'score_space_version' => $scoreSpaceVersion,
        ];

        return $result;
    }
}
