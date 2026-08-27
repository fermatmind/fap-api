<?php

namespace Tests\Unit\SeoIntel;

use App\Services\SeoIntel\Decision\SeoNullablePriorityEvaluator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SeoNullablePriorityEvaluatorTest extends TestCase
{
    #[Test]
    public function every_contract_required_missing_input_returns_null_score_and_hold(): void
    {
        $evaluator = new SeoNullablePriorityEvaluator;

        foreach (SeoNullablePriorityEvaluator::REQUIRED_INPUTS as $key) {
            $input = $this->completeInput();
            unset($input[$key]);
            $result = $evaluator->evaluate($input);

            $this->assertNull($result['priority_score'], $key);
            $this->assertFalse($result['ranking_eligible'], $key);
            $this->assertSame('MEASUREMENT_HOLD', $result['state'], $key);
        }
    }

    #[Test]
    public function missing_risk_detail_and_failed_measurement_are_never_defaulted(): void
    {
        $evaluator = new SeoNullablePriorityEvaluator;
        $riskMissing = $this->completeInput();
        unset($riskMissing['risk']['blast_radius']);
        $measurementFailed = $this->completeInput();
        $measurementFailed['measurement_state']['quality_passed'] = false;
        $malformedEvidence = $this->completeInput();
        $malformedEvidence['evidence_strength'] = [];

        $this->assertSame('invalid_risk', $evaluator->evaluate($riskMissing)['hold_reasons'][0]);
        $this->assertSame('measurement_gate_not_passed', $evaluator->evaluate($measurementFailed)['hold_reasons'][0]);
        $this->assertNull($evaluator->evaluate($riskMissing)['priority_score']);
        $this->assertNull($evaluator->evaluate($measurementFailed)['priority_score']);
        $this->assertSame('invalid_evidence_strength', $evaluator->evaluate($malformedEvidence)['hold_reasons'][0]);
        $this->assertNull($evaluator->evaluate($malformedEvidence)['priority_score']);
    }

    #[Test]
    public function stale_or_ambiguous_freshness_holds_instead_of_becoming_zero(): void
    {
        $evaluator = new SeoNullablePriorityEvaluator;
        $stale = $this->completeInput();
        $stale['evidence_freshness']['evaluated_at'] = '2026-08-28T00:00:01Z';
        $missingAge = $this->completeInput();
        unset($missingAge['evidence_freshness']['max_age_seconds']);

        $this->assertSame('stale_evidence', $evaluator->evaluate($stale)['hold_reasons'][0]);
        $this->assertSame('invalid_evidence_freshness', $evaluator->evaluate($missingAge)['hold_reasons'][0]);
        $this->assertNull($evaluator->evaluate($stale)['priority_score']);
    }

    #[Test]
    public function an_observed_zero_is_valid_only_after_all_gates_pass(): void
    {
        $input = $this->completeInput();
        $input['impact_scope']['affected_unique_public_urls'] = 0;
        $result = (new SeoNullablePriorityEvaluator)->evaluate($input);

        $this->assertTrue($result['ranking_eligible']);
        $this->assertSame(0.0, $result['components']['impact_scope']);
        $this->assertIsFloat($result['priority_score']);
    }

    #[Test]
    public function equal_inputs_and_sorting_are_deterministic_without_runtime_clock_or_randomness(): void
    {
        $evaluator = new SeoNullablePriorityEvaluator;
        $input = $this->completeInput();
        $first = $evaluator->evaluate($input);
        $second = $evaluator->evaluate($input);
        $lower = $this->completeInput('a');
        $lower['business_value'] = 'conditional';
        $held = $this->completeInput('f');
        unset($held['measurement_state']);

        $this->assertSame($first, $second);
        $this->assertSame(
            [$input['cluster_uid'], $lower['cluster_uid'], $held['cluster_uid']],
            array_column($evaluator->sort([$evaluator->evaluate($held), $evaluator->evaluate($lower), $first]), 'cluster_uid'),
        );
    }

    /** @return array<string, mixed> */
    private function completeInput(string $clusterCharacter = 'b'): array
    {
        return [
            'cluster_uid' => 'seo_cluster_'.str_repeat($clusterCharacter, 48),
            'impact_scope' => [
                'affected_unique_public_urls' => 12,
                'family_scope' => 'personality_hub',
            ],
            'evidence_strength' => 'verified',
            'business_value' => 'L1',
            'risk' => [
                'severity' => 'P1',
                'blast_radius' => 'medium',
                'direct_evidence' => true,
            ],
            'estimated_fix_cost' => 'bounded',
            'evidence_freshness' => [
                'observed_at' => '2026-08-27T00:00:00Z',
                'evaluated_at' => '2026-08-27T12:00:00Z',
                'max_age_seconds' => 86400,
            ],
            'measurement_state' => [
                'complete' => true,
                'quality_passed' => true,
                'comparable' => true,
                'lag_seconds' => 300,
                'max_lag_seconds' => 3600,
            ],
        ];
    }
}
