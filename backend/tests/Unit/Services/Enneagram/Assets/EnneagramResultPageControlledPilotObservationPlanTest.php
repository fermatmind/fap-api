<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Enneagram\Assets;

use Tests\TestCase;

final class EnneagramResultPageControlledPilotObservationPlanTest extends TestCase
{
    public function test_controlled_pilot_plan_locks_release_windows_metrics_and_negative_guarantees(): void
    {
        $root = base_path('content_assets/enneagram/result_page/controlled_pilot_observation/v0_1');
        $plan = $this->readJson($root.'/controlled_pilot_observation_plan_v0_1.json');

        $this->assertSame('fap.enneagram.result_page.controlled_pilot_observation.v0.1', $plan['schema_version'] ?? null);
        $this->assertSame('observation_plan_only', $plan['status'] ?? null);
        $this->assertSame('not_runtime', $plan['runtime_use'] ?? null);
        $this->assertFalse((bool) ($plan['production_use_allowed'] ?? true));
        $this->assertTrue((bool) ($plan['requires_completed_manual_production_gate'] ?? false));
        $this->assertTrue((bool) ($plan['requires_post_activation_smoke_pass'] ?? false));

        $this->assertSame(
            'enneagram_1r_a_to_1r_h_phase8b_candidate_20260427_a9fd3eb4',
            data_get($plan, 'release_contract.release_id')
        );
        $this->assertSame(
            'a9fd3eb474ea2ca0130d06ad2b1640305d9160ee1a74e559ad4f60bfc4db56c0',
            data_get($plan, 'release_contract.candidate_manifest_sha256')
        );
        $this->assertSame(
            'ac5bdaab3c761b0d01a56f92679aa58341110d64de0f47a1fa0062b64f76f97f',
            data_get($plan, 'release_contract.runtime_registry_sha256')
        );
        $this->assertSame('60 minutes after activation', data_get($plan, 'release_contract.rollback_window'));

        $windows = array_map(
            static fn (array $window): string => (string) ($window['window_id'] ?? ''),
            (array) ($plan['observation_windows'] ?? [])
        );
        $this->assertSame(['D1', 'D7', 'D14', 'D28'], $windows);

        foreach ((array) ($plan['observation_windows'] ?? []) as $window) {
            $this->assertTrue((bool) ($window['required_review'] ?? false));
        }

        $allowedMetrics = (array) data_get($plan, 'metric_contract.allowed_metrics', []);
        foreach ([
            'share_clicks',
            'retest_starts',
            'retest_completions',
            'big_five_cross_test_clicks',
            'mbti_cross_test_clicks',
            'pdf_generation_errors',
            'share_generation_errors',
            'claim_gate_hits',
        ] as $metric) {
            $this->assertContains($metric, $allowedMetrics);
        }

        $this->assertSame('aggregate_only', data_get($plan, 'metric_contract.aggregation_minimum'));
        $this->assertSame('public_safe_summary_only', data_get($plan, 'metric_contract.public_surface_data_policy'));

        foreach ((array) data_get($plan, 'metric_contract.forbidden_metric_fields', []) as $field) {
            $this->assertContains($field, [
                'attempt_id',
                'raw_score',
                'raw_scores',
                'score_vector',
                'raw_score_vector',
                'private_report_payload',
                'private_report_url',
                'internal_hash',
                'release_hash',
                'source_trace',
                'selector_metadata',
                'interpretation_context_id',
            ]);
        }

        foreach ((array) ($plan['pilot_holds'] ?? []) as $enabled) {
            $this->assertTrue((bool) $enabled);
        }

        foreach ([
            'private_payload_leak_detected',
            'attempt_id_or_raw_score_leak_detected',
            'forbidden_claim_detected',
            'fc144_boundary_violation_detected',
            'pdf_or_share_private_boundary_violation_detected',
            'post_activation_smoke_failure',
            'rollback_target_snapshot_missing',
        ] as $trigger) {
            $this->assertContains($trigger, (array) ($plan['stop_triggers'] ?? []));
        }

        foreach ((array) ($plan['negative_guarantees'] ?? []) as $guarantee) {
            $this->assertFalse((bool) $guarantee);
        }

        $encoded = mb_strtolower(json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
        foreach ([
            'you are this type',
            'fixed type',
            'diagnosis',
            'treatment',
            'hiring',
            'salary prediction',
            'performance prediction',
            'fc144 is more accurate',
            'fc144 replaces',
            '你就是这个类型',
            '诊断',
            '治疗',
            '招聘',
            '薪资预测',
            '成功预测',
        ] as $blockedCopy) {
            $this->assertStringNotContainsString($blockedCopy, $encoded);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function readJson(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded, $path.' must decode to an array');

        return $decoded;
    }
}
