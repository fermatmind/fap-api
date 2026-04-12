<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Services\Career\Explainability\StrainRadarBuilder;
use Tests\TestCase;

final class StrainRadarBuilderTest extends TestCase
{
    public function test_it_builds_a_deterministic_public_safe_strain_radar(): void
    {
        $radar = (new StrainRadarBuilder)->build([
            'integrity_state' => 'provisional',
            'confidence_cap' => 78,
            'formula_ref' => 'career.strain_v1.2',
            'degradation_factor' => 0.84,
            'component_breakdown' => [
                'inputs' => [
                    'environment_fit' => 0.25,
                    'people_friction' => 0.61,
                    'context_switch_load' => 0.52,
                    'political_load' => 0.47,
                    'uncertainty_load' => 0.58,
                    'repetition_mismatch' => 0.33,
                    'low_autonomy_trap' => 0.41,
                ],
                'weights' => [
                    'people_friction' => 0.16,
                ],
                'base_score' => 49.2,
            ],
        ]);

        $this->assertSame([
            'integrity_state',
            'confidence_cap',
            'degradation_factor',
            'formula_version',
            'axes',
        ], array_keys((array) $radar));
        $this->assertSame([
            'people_friction',
            'context_switch_load',
            'political_load',
            'uncertainty_load',
            'low_autonomy_trap',
            'repetition_mismatch',
        ], array_keys((array) data_get($radar, 'axes')));
        $this->assertSame('provisional', data_get($radar, 'integrity_state'));
        $this->assertSame(78, data_get($radar, 'confidence_cap'));
        $this->assertSame(0.84, data_get($radar, 'degradation_factor'));
        $this->assertSame('career.strain_v1.2', data_get($radar, 'formula_version'));
        $this->assertSame(0.61, data_get($radar, 'axes.people_friction.value'));
        $this->assertNull(data_get($radar, 'axes.environment_fit'));
        $this->assertNull(data_get($radar, 'axes.environment_mismatch'));
        $this->assertNull(data_get($radar, 'weights'));
        $this->assertNull(data_get($radar, 'base_score'));
        $this->assertNull(data_get($radar, 'description'));
    }

    public function test_it_omits_strain_radar_when_integrity_is_blocked(): void
    {
        $radar = (new StrainRadarBuilder)->build([
            'integrity_state' => 'blocked',
            'confidence_cap' => 0,
            'formula_ref' => 'career.strain_v1.2',
            'degradation_factor' => 0.0,
            'component_breakdown' => [
                'inputs' => [
                    'people_friction' => 0.9,
                ],
            ],
        ]);

        $this->assertNull($radar);
    }
}
