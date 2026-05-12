<?php

declare(strict_types=1);

namespace App\Services\Riasec;

use App\Models\Result;

final class RiasecExplorationFeedbackOverlayService
{
    private const SCHEMA_VERSION = 'riasec.exploration_feedback_overlay.v0.1';

    /**
     * @return array<string,mixed>
     */
    public function build(Result $result, array $projectionV2, bool $snapshotBound): array
    {
        $payload = is_array($result->result_json ?? null) ? $result->result_json : [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'overlay_contract_only',
            'feedback_stream_status' => 'not_connected_v0_1',
            'scale_code' => 'RIASEC',
            'snapshot_bound' => $snapshotBound,
            'snapshot_identity' => [
                'snapshot_required' => true,
                'snapshot_bound' => $snapshotBound,
                'identity_scope' => 'projection_snapshot',
                'form_code' => (string) data_get($projectionV2, 'form.form_code', ''),
                'score_space_version' => (string) data_get($projectionV2, 'form.score_space_version', ''),
                'measured_holland_code' => (string) data_get($projectionV2, 'holland_code.code', ''),
            ],
            'measured_result_guard' => [
                'measured_holland_code' => (string) data_get($projectionV2, 'holland_code.code', ''),
                'scores_mutation_allowed' => false,
                'holland_code_mutation_allowed' => false,
                'report_snapshot_mutation_allowed' => false,
                'measurement_evidence_mutation_allowed' => false,
            ],
            'feedback_scope' => [
                'allowed' => [
                    'activity_resonance',
                    'task_interest_signal',
                    'occupation_example_helpfulness',
                    'exploration_next_step_interest',
                ],
                'not_allowed' => [
                    'score_override',
                    'holland_code_override',
                    'career_recommendation',
                    'job_fit_prediction',
                    'career_success_prediction',
                    'qualification_judgment',
                ],
            ],
            'surface_policy' => [
                'public_projection_allowed' => true,
                'share_pdf_exposure_allowed' => false,
                'raw_feedback_public_exposure_allowed' => false,
                'formal_report_mutation_allowed' => false,
            ],
            'read_model' => [
                'has_feedback' => false,
                'feedback_count' => 0,
                'latest_feedback_at' => null,
                'summary_status' => 'not_available_without_feedback_stream',
                'raw_feedback_included' => false,
            ],
            'claim_boundary' => [
                'feedback_is_measurement' => false,
                'feedback_changes_scores' => false,
                'feedback_changes_measured_holland_code' => false,
                'feedback_is_career_match' => false,
                'feedback_is_success_prediction' => false,
            ],
            'context' => [
                'form_code' => (string) ($payload['form_code'] ?? data_get($projectionV2, 'form.form_code', '')),
                'score_space_version' => (string) ($payload['score_space_version'] ?? data_get($projectionV2, 'form.score_space_version', '')),
            ],
        ];
    }
}
