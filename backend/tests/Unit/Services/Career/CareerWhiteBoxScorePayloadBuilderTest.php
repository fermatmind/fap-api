<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Services\Career\Scoring\CareerWhiteBoxScorePayloadBuilder;
use Tests\TestCase;

final class CareerWhiteBoxScorePayloadBuilderTest extends TestCase
{
    public function test_it_builds_machine_safe_white_box_payload_from_score_bundle_truth(): void
    {
        $builder = app(CareerWhiteBoxScorePayloadBuilder::class);

        $payload = $builder->build([
            'fit_score' => [
                'value' => 78,
                'integrity_state' => 'full',
                'degradation_factor' => 1.0,
                'component_breakdown' => [
                    'inputs' => ['cognitive_fit' => 0.86, 'task_fit' => 0.74],
                    'weights' => ['cognitive_fit' => 0.25, 'task_fit' => 0.2],
                    'base_score' => 81.42,
                    'penalty_factor' => 0.96,
                ],
                'penalties' => [
                    ['code' => 'review_pending', 'weight' => 0.04, 'value' => 1.0],
                ],
            ],
            'strain_score' => [
                'value' => 41,
                'integrity_state' => 'provisional',
                'degradation_factor' => 0.82,
                'component_breakdown' => [
                    'inputs' => [
                        'people_friction' => 0.43,
                        'context_switch_load' => 0.58,
                        'political_load' => 0.39,
                        'uncertainty_load' => 0.55,
                        'low_autonomy_trap' => 0.47,
                        'repetition_mismatch' => 0.31,
                    ],
                    'weights' => [
                        'people_friction' => 0.16,
                        'context_switch_load' => 0.2,
                    ],
                    'base_score' => 46.2,
                ],
                'penalties' => [],
            ],
            'unknown_score' => [
                'value' => 99,
            ],
        ], [
            'red_flags' => ['fit_score.blocked', 'missing_median_pay'],
            'amber_flags' => ['strain_score.provisional', 'review_pending'],
            'blocked_claims' => ['strong_claim'],
        ]);

        $this->assertSame(['fit_score', 'strain_score'], array_keys($payload));

        $this->assertSame(78, data_get($payload, 'fit_score.score'));
        $this->assertSame('full', data_get($payload, 'fit_score.integrity_state'));
        $this->assertSame(1.0, data_get($payload, 'fit_score.degradation_factor'));
        $this->assertSame(0.25, data_get($payload, 'fit_score.component_weights.cognitive_fit'));
        $this->assertSame('cognitive_fit', data_get($payload, 'fit_score.formula_breakdown.0.component'));
        $this->assertSame('review_pending', data_get($payload, 'fit_score.penalties.0.code'));
        $this->assertContains('fit_score.blocked', (array) data_get($payload, 'fit_score.warnings'));
        $this->assertContains('penalty:review_pending', (array) data_get($payload, 'fit_score.warnings'));
        $this->assertArrayNotHasKey('formula_ref', (array) data_get($payload, 'fit_score'));
        $this->assertArrayNotHasKey('critical_missing_fields', (array) data_get($payload, 'fit_score'));

        $this->assertSame('strain_score.provisional', data_get($payload, 'strain_score.warnings.0'));
        $this->assertSame(
            ['people_friction', 'context_switch_load', 'political_load', 'uncertainty_load', 'low_autonomy_trap', 'repetition_mismatch'],
            array_map(static fn (array $row): string => (string) ($row['dimension'] ?? ''), (array) data_get($payload, 'strain_score.radar_dimensions'))
        );
    }

    public function test_it_omits_unstable_entries_when_score_payload_is_missing(): void
    {
        $builder = app(CareerWhiteBoxScorePayloadBuilder::class);

        $payload = $builder->build([
            'fit_score' => 'invalid',
            'ai_survival_score' => [
                'value' => 63,
                'integrity_state' => 'restricted',
                'degradation_factor' => 0.74,
            ],
        ], []);

        $this->assertArrayNotHasKey('fit_score', $payload);
        $this->assertSame(63, data_get($payload, 'ai_survival_score.score'));
        $this->assertSame([], data_get($payload, 'ai_survival_score.formula_breakdown'));
        $this->assertSame([], data_get($payload, 'ai_survival_score.component_weights'));
        $this->assertSame([], data_get($payload, 'ai_survival_score.penalties'));
        $this->assertSame([], data_get($payload, 'ai_survival_score.warnings'));
        $this->assertArrayNotHasKey('radar_dimensions', (array) data_get($payload, 'ai_survival_score'));
    }
}
