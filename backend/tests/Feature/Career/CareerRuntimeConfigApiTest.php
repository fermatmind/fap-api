<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use Tests\TestCase;

final class CareerRuntimeConfigApiTest extends TestCase
{
    public function test_it_returns_runtime_threshold_experiment_authority_payload(): void
    {
        $this->getJson('/api/v0.5/career/runtime-config')
            ->assertOk()
            ->assertJsonPath('authority_kind', 'career_threshold_experiment_authority')
            ->assertJsonPath('authority_version', 'career.threshold_experiment.v1')
            ->assertJsonPath('snapshot_key', 'career_default_v1')
            ->assertJsonPath('thresholds.confidence.publish_min', 60)
            ->assertJsonPath('thresholds.confidence.promotion_candidate_min', 70)
            ->assertJsonPath('thresholds.confidence.stable_min', 75)
            ->assertJsonPath('thresholds.warnings.low_confidence_threshold', 72)
            ->assertJsonPath('thresholds.warnings.high_strain_threshold', 70)
            ->assertJsonPath('thresholds.warnings.ai_risk_threshold', 65)
            ->assertJsonPath('thresholds.promotion.next_step_links_min', 2)
            ->assertJsonPath('thresholds.promotion.strong_claim_required', true)
            ->assertJsonPath('experiments.career_warning_copy_v1.enabled', true)
            ->assertJsonPath('experiments.career_warning_copy_v1.variant', 'control')
            ->assertJsonPath('experiments.career_explorer_primary_path_v1.variant', 'jobs_first')
            ->assertJsonPath('experiments.career_transition_emphasis_v1.variant', 'balanced');
    }
}
