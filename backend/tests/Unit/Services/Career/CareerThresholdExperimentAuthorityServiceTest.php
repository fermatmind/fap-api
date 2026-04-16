<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Services\Career\Config\CareerThresholdExperimentAuthorityService;
use Tests\TestCase;

final class CareerThresholdExperimentAuthorityServiceTest extends TestCase
{
    public function test_it_builds_default_threshold_and_experiment_authority_snapshot(): void
    {
        $authority = app(CareerThresholdExperimentAuthorityService::class)->buildAuthority()->toArray();

        $this->assertSame('career_threshold_experiment_authority', $authority['authority_kind'] ?? null);
        $this->assertSame('career.threshold_experiment.v1', $authority['authority_version'] ?? null);
        $this->assertSame('career_default_v1', $authority['snapshot_key'] ?? null);
        $this->assertSame(60, data_get($authority, 'thresholds.confidence.publish_min'));
        $this->assertSame(70, data_get($authority, 'thresholds.confidence.promotion_candidate_min'));
        $this->assertSame(75, data_get($authority, 'thresholds.confidence.stable_min'));
        $this->assertSame(72, data_get($authority, 'thresholds.warnings.low_confidence_threshold'));
        $this->assertSame(70, data_get($authority, 'thresholds.warnings.high_strain_threshold'));
        $this->assertSame(65, data_get($authority, 'thresholds.warnings.ai_risk_threshold'));
        $this->assertSame(2, data_get($authority, 'thresholds.promotion.next_step_links_min'));
        $this->assertSame(true, data_get($authority, 'thresholds.promotion.strong_claim_required'));
        $this->assertSame(true, data_get($authority, 'experiments.career_warning_copy_v1.enabled'));
        $this->assertSame('control', data_get($authority, 'experiments.career_warning_copy_v1.variant'));
        $this->assertSame('jobs_first', data_get($authority, 'experiments.career_explorer_primary_path_v1.variant'));
        $this->assertSame('balanced', data_get($authority, 'experiments.career_transition_emphasis_v1.variant'));
    }
}
