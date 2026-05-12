<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Riasec;

use App\Models\Result;
use App\Services\Riasec\RiasecExplorationFeedbackOverlayService;
use Tests\TestCase;

final class RiasecExplorationFeedbackOverlayServiceTest extends TestCase
{
    public function test_overlay_is_non_measuring_and_cannot_mutate_measured_result(): void
    {
        $result = new Result([
            'scale_code' => 'RIASEC',
            'type_code' => 'IAS',
            'result_json' => [
                'form_code' => 'riasec_60',
                'score_space_version' => 'riasec_60_likert5_activity_sum_space.v1',
            ],
        ]);
        $projection = [
            'holland_code' => [
                'code' => 'IAS',
            ],
            'form' => [
                'form_code' => 'riasec_60',
                'score_space_version' => 'riasec_60_likert5_activity_sum_space.v1',
            ],
        ];

        $overlay = (new RiasecExplorationFeedbackOverlayService)->build($result, $projection, true);

        $this->assertSame('riasec.exploration_feedback_overlay.v0.1', $overlay['schema_version']);
        $this->assertSame('overlay_contract_only', $overlay['status']);
        $this->assertSame('not_connected_v0_1', $overlay['feedback_stream_status']);
        $this->assertTrue((bool) data_get($overlay, 'snapshot_identity.snapshot_required'));
        $this->assertTrue((bool) data_get($overlay, 'snapshot_identity.snapshot_bound'));
        $this->assertSame('projection_snapshot', data_get($overlay, 'snapshot_identity.identity_scope'));
        $this->assertSame('IAS', data_get($overlay, 'snapshot_identity.measured_holland_code'));
        $this->assertFalse((bool) data_get($overlay, 'measured_result_guard.scores_mutation_allowed'));
        $this->assertFalse((bool) data_get($overlay, 'measured_result_guard.holland_code_mutation_allowed'));
        $this->assertFalse((bool) data_get($overlay, 'measured_result_guard.report_snapshot_mutation_allowed'));
        $this->assertFalse((bool) data_get($overlay, 'surface_policy.share_pdf_exposure_allowed'));
        $this->assertFalse((bool) data_get($overlay, 'surface_policy.raw_feedback_public_exposure_allowed'));
        $this->assertFalse((bool) data_get($overlay, 'read_model.raw_feedback_included'));
        $this->assertFalse((bool) data_get($overlay, 'claim_boundary.feedback_is_measurement'));
        $this->assertFalse((bool) data_get($overlay, 'claim_boundary.feedback_changes_scores'));
        $this->assertFalse((bool) data_get($overlay, 'claim_boundary.feedback_changes_measured_holland_code'));
        $this->assertContains('career_recommendation', data_get($overlay, 'feedback_scope.not_allowed'));
        $this->assertContains('job_fit_prediction', data_get($overlay, 'feedback_scope.not_allowed'));
        $this->assertArrayNotHasKey('attempt_id', $overlay);
    }
}
