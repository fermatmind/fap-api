<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoCouncil\Measurement\CommercialFunnelCROMode;
use Tests\Feature\SeoIntel\Concerns\BuildsMeasurementV2Context;
use Tests\TestCase;

final class SeoPlatform11FCommercialFunnelCROTest extends TestCase
{
    use BuildsMeasurementV2Context;

    public function test_public_chain_emits_only_falsifiable_non_executing_candidate(): void
    {
        $context = $this->measurementContext('commercial_funnel_cro');
        $output = app(CommercialFunnelCROMode::class)->review($context);

        $this->assertSame('READY', $output['status']);
        $this->assertCount(1, $output['candidates']);
        $candidate = $output['candidates'][0];
        foreach (['falsification_rule', 'uncertainty', 'primary_metric', 'guardrail_metrics', 'stop_conditions'] as $field) {
            $this->assertArrayHasKey($field, $candidate);
        }
        $this->assertFalse($candidate['execution_allowed']);
        $this->assertSame([], $output['findings'][0]['verified_facts']);
        $this->assertSame(0, $output['write_count']);
    }

    public function test_incomplete_chain_insufficient_sample_and_causal_claims_hold(): void
    {
        $incomplete = $this->measurementContext('commercial_funnel_cro', [
            'stage_coverage' => [
                'landing' => true, 'start' => true, 'completion' => true,
                'aggregate_outcome_view' => true, 'return_public_content' => true, 'cta' => false,
            ],
        ]);
        $this->assertSame('HOLD', app(CommercialFunnelCROMode::class)->review($incomplete)['status']);

        $small = $this->croPayload();
        foreach ($small['windows'] as &$window) {
            $window['metrics'] = array_map(static fn (int $value): int => min($value, 20), $window['metrics']);
        }
        unset($window);
        $this->assertSame('HOLD', app(CommercialFunnelCROMode::class)->review($this->measurementContext('commercial_funnel_cro', $small))['status']);

        $causal = $this->measurementContext('commercial_funnel_cro', ['associations' => ['The CTA caused completion growth.']]);
        $output = app(CommercialFunnelCROMode::class)->review($causal);
        $this->assertSame('HOLD', $output['status']);
        $this->assertSame([], $output['candidates']);
        $this->assertSame([], $output['findings'][0]['associations']);
    }

    public function test_exact_zero_proof_is_ready_without_inventing_a_candidate(): void
    {
        $zero = $this->croPayload();
        foreach ($zero['windows'] as &$window) {
            $window['metrics'] = array_map(static fn (): int => 0, $window['metrics']);
        }
        unset($window);
        $zero['valid_measurement_present'] = false;
        $zero['explicit_zero_proof'] = true;
        $zero['all_relevant_values_zero'] = true;

        $output = app(CommercialFunnelCROMode::class)->review(
            $this->measurementContext('commercial_funnel_cro', $zero)
        );

        $this->assertSame('READY', $output['status']);
        $this->assertNull($output['hold_reason']);
        $this->assertSame([], $output['candidates']);
        $this->assertSame([], $output['findings'][0]['hypotheses']);
        $this->assertSame('valid_zero', $output['findings'][0]['measurement_state']['state']);
        $this->assertSame(0, $output['write_count']);
    }
}
