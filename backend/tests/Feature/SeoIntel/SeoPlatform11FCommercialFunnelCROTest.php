<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoCouncil\Measurement\CommercialFunnelCROMode;
use Tests\TestCase;

final class SeoPlatform11FCommercialFunnelCROTest extends TestCase
{
    public function test_valid_aggregate_evidence_emits_candidate_without_starting_experiment(): void
    {
        $output = app(CommercialFunnelCROMode::class)->review($this->request(), [
            'quality_gate_passed' => true, 'current_window_readable' => true,
            'expected_evidence_present' => true, 'window_complete' => true,
            'valid_measurement_present' => true, 'mapping_revision' => 'taxonomy:v1',
            'aggregate_metrics' => [
                'landing_pv_count' => 1500, 'start_test_count' => 100,
                'complete_test_count' => 75, 'view_result_count' => 74,
                'return_public_content_count' => 12, 'cta_exposure_count' => 500,
                'cta_click_count' => 80, 'order_count' => 9,
            ],
            'experiment_hypothesis' => 'promise parity increases qualified starts',
            'primary_metric' => 'start_test_count',
            'guardrail_metrics' => ['complete_test_count', 'view_result_count'],
            'minimum_sample_requirement' => 1200,
        ]);

        $this->assertSame('READY', $output['status']);
        $this->assertArrayNotHasKey('order_count', $output['findings'][0]['aggregate_metrics']);
        $this->assertCount(1, $output['experiment_candidates']);
        $candidate = $output['experiment_candidates'][0];
        $this->assertSame(1200, $candidate['minimum_sample_requirement']);
        $this->assertFalse($candidate['execution_allowed']);
        $this->assertSame(0, $output['write_count']);
    }

    public function test_insufficient_sample_and_private_url_value_fail_closed(): void
    {
        $insufficient = app(CommercialFunnelCROMode::class)->review($this->request(), [
            'quality_gate_passed' => true, 'current_window_readable' => true,
            'expected_evidence_present' => true, 'window_complete' => true,
            'valid_measurement_present' => true,
            'aggregate_metrics' => ['landing_pv_count' => 99],
            'minimum_sample_requirement' => 100,
        ]);
        $this->assertSame('HOLD', $insufficient['status']);
        $this->assertSame([], $insufficient['experiment_candidates']);

        $url = app(CommercialFunnelCROMode::class)->review($this->request(), [
            'quality_gate_passed' => true, 'current_window_readable' => true,
            'expected_evidence_present' => true, 'window_complete' => true,
            'valid_measurement_present' => true,
            'aggregate_metrics' => ['landing_pv_count' => 200],
            'evidence_refs' => ['/en/results/private-id'],
        ]);
        $this->assertSame('HOLD', $url['status']);
        $this->assertSame([], $url['experiment_candidates']);
    }

    public function test_private_identity_session_content_and_url_fail_closed(): void
    {
        foreach ([
            ['user_id' => 7], ['raw_session' => 'session'], ['result_content' => 'private'],
            ['private_url' => '/en/results/secret'], ['payment_id' => 9],
        ] as $private) {
            $output = app(CommercialFunnelCROMode::class)->review($this->request(), [
                'quality_gate_passed' => true, 'current_window_readable' => true,
                'expected_evidence_present' => true, 'window_complete' => true,
                'valid_measurement_present' => true,
                ...$private,
            ]);
            $this->assertSame('HOLD', $output['status']);
            $this->assertSame('hold', $output['findings'][0]['measurement_state']['state']);
            $this->assertTrue($output['findings'][0]['privacy_violation']);
            $this->assertSame([], $output['experiment_candidates']);
        }
    }

    /** @return array<string, mixed> */
    private function request(): array
    {
        return [
            'version' => 'seo.commercial_funnel_cro_request.v1',
            'role_id' => 'seo.expert.commercial_funnel_cro',
            'page_family' => 'tests', 'locale' => 'en', 'window' => ['days' => 28],
            'execution_allowed' => false,
        ];
    }
}
