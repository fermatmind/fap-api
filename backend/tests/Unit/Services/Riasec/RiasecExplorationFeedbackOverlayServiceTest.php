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
        $this->assertSame('safe_static_content_bridge_v0_1', $overlay['status']);
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

    public function test_overlay_emits_safe_static_action_lab_and_next_node_payloads_without_raw_feedback(): void
    {
        $overlay = (new RiasecExplorationFeedbackOverlayService)->build(
            new Result([
                'scale_code' => 'RIASEC',
                'type_code' => 'IAS',
                'result_json' => [
                    'form_code' => 'riasec_60',
                    'score_space_version' => 'riasec_60_likert5_activity_sum_space.v1',
                ],
            ]),
            [
                'holland_code' => [
                    'code' => 'IAS',
                ],
                'form' => [
                    'form_code' => 'riasec_60',
                    'score_space_version' => 'riasec_60_likert5_activity_sum_space.v1',
                ],
            ],
            true
        );

        $actionLab = data_get($overlay, 'action_lab_v1');
        $this->assertIsArray($actionLab);
        $this->assertSame('riasec.feedback_action_lab_payload.v1', $actionLab['schema_version']);
        $this->assertSame('available_static_safe_bridge', $actionLab['status']);
        $this->assertSame('static_starter_actions_without_feedback_read_model', $actionLab['availability']);
        $this->assertFalse($actionLab['public_raw_feedback_allowed']);
        $this->assertFalse($actionLab['affects_measured_code']);
        $this->assertFalse($actionLab['affects_score']);
        $this->assertFalse($actionLab['affects_snapshot']);
        $this->assertFalse($actionLab['share_pdf_history_measured_payload_mutation_allowed']);
        $this->assertFalse($actionLab['raw_feedback_included']);
        $this->assertFalse($actionLab['frontend_fallback_allowed']);
        $this->assertSame('unavailable_without_feedback_stream', data_get($actionLab, 'read_model_dependent_actions.status'));
        $this->assertCount(18, $actionLab['starter_actions']);

        foreach ($actionLab['starter_actions'] as $action) {
            $this->assertFalse($action['score_mutation_allowed']);
            $this->assertFalse($action['measured_holland_code_mutation_allowed']);
            $this->assertFalse($action['snapshot_mutation_allowed']);
            $this->assertFalse($action['share_pdf_exposure_allowed']);
            $this->assertFalse($action['frontend_fallback_allowed']);
            $this->assertArrayNotHasKey('raw_feedback', $action);
            $this->assertArrayNotHasKey('user_feedback', $action);
            $this->assertArrayNotHasKey('snapshot_id', $action);
        }

        $nodes = data_get($overlay, 'next_exploration_nodes_v1');
        $this->assertIsArray($nodes);
        $this->assertSame('riasec.next_exploration_nodes_payload.v1', $nodes['schema_version']);
        $this->assertSame('available_static_safe_bridge', $nodes['status']);
        $this->assertSame('top_code_dimension_static_starter_nodes_without_feedback_read_model', $nodes['selection_mode']);
        $this->assertFalse($nodes['public_raw_feedback_allowed']);
        $this->assertFalse($nodes['affects_measured_code']);
        $this->assertFalse($nodes['affects_score']);
        $this->assertFalse($nodes['affects_snapshot']);
        $this->assertFalse($nodes['creates_career_match']);
        $this->assertFalse($nodes['share_pdf_history_measured_payload_mutation_allowed']);
        $this->assertFalse($nodes['frontend_fallback_allowed']);
        $this->assertCount(6, $nodes['nodes']);

        foreach ($nodes['nodes'] as $node) {
            $this->assertContains($node['dimension_hint'], ['I', 'A', 'S']);
            $this->assertFalse($node['creates_score_change']);
            $this->assertFalse($node['creates_career_match']);
            $this->assertFalse($node['frontend_fallback_allowed']);
            $this->assertArrayNotHasKey('raw_feedback', $node);
            $this->assertArrayNotHasKey('snapshot_id', $node);
        }
    }

    public function test_overlay_rejects_unsafe_feedback_and_next_node_rows(): void
    {
        $service = new RiasecExplorationFeedbackOverlayService;
        $reflection = new \ReflectionClass($service);
        $feedbackValidator = $reflection->getMethod('isValidFeedbackActionRow');
        $nextNodeValidator = $reflection->getMethod('isValidNextExplorationNodeRow');

        $safeFeedbackRow = [
            'schema_version' => 'riasec.feedback_action_lab.v1',
            'asset_version' => 'riasec_feedback_action_lab_v1.zh-CN',
            'event_type' => 'saved_activity',
            'activity_key' => 'analyze_complex_problems',
            'user_copy' => '已记录：收藏活动。',
            'system_response' => '这条记录只影响探索路径。',
            'next_step_copy' => '下一步做一个低风险验证。',
            'score_mutation_allowed' => false,
            'measured_holland_code_mutation_allowed' => false,
            'snapshot_mutation_allowed' => false,
            'share_pdf_exposure_allowed' => false,
            'frontend_fallback_allowed' => false,
        ];
        $unsafeFeedbackRow = array_merge($safeFeedbackRow, [
            'score_mutation_allowed' => true,
        ]);

        $safeNextNodeRow = [
            'schema_version' => 'riasec.next_exploration_node.v1',
            'asset_version' => 'riasec_next_exploration_nodes_v1.zh-CN',
            'node_id' => 'node_test',
            'node_type' => 'quick_check',
            'dimension_hint' => 'I',
            'title' => '快速检查：研究型线索',
            'summary' => '看一个低风险任务是否仍有兴趣。',
            'instruction' => '记录任务、环境和角色责任。',
            'estimated_time' => '15 分钟',
            'creates_score_change' => false,
            'creates_career_match' => false,
            'frontend_fallback_allowed' => false,
        ];
        $unsafeNextNodeRow = array_merge($safeNextNodeRow, [
            'creates_career_match' => true,
        ]);

        $this->assertTrue($feedbackValidator->invoke($service, $safeFeedbackRow));
        $this->assertFalse($feedbackValidator->invoke($service, $unsafeFeedbackRow));
        $this->assertTrue($nextNodeValidator->invoke($service, $safeNextNodeRow));
        $this->assertFalse($nextNodeValidator->invoke($service, $unsafeNextNodeRow));
    }

    public function test_overlay_fails_closed_when_safe_content_rows_are_unavailable(): void
    {
        $service = new RiasecExplorationFeedbackOverlayService;
        $reflection = new \ReflectionClass($service);
        foreach (['feedbackActionRowsCache', 'nextExplorationNodeRowsCache'] as $propertyName) {
            $property = $reflection->getProperty($propertyName);
            $property->setValue($service, []);
        }

        $overlay = $service->build(
            new Result([
                'scale_code' => 'RIASEC',
                'type_code' => 'IAS',
                'result_json' => [
                    'form_code' => 'riasec_60',
                    'score_space_version' => 'riasec_60_likert5_activity_sum_space.v1',
                ],
            ]),
            [
                'holland_code' => [
                    'code' => 'IAS',
                ],
                'form' => [
                    'form_code' => 'riasec_60',
                    'score_space_version' => 'riasec_60_likert5_activity_sum_space.v1',
                ],
            ],
            true
        );

        $this->assertSame('unavailable', data_get($overlay, 'action_lab_v1.status'));
        $this->assertSame([], data_get($overlay, 'action_lab_v1.starter_actions'));
        $this->assertSame('omit_module_fail_closed', data_get($overlay, 'action_lab_v1.missing_content_behavior'));
        $this->assertSame('unavailable', data_get($overlay, 'next_exploration_nodes_v1.status'));
        $this->assertSame([], data_get($overlay, 'next_exploration_nodes_v1.nodes'));
        $this->assertSame('omit_module_fail_closed', data_get($overlay, 'next_exploration_nodes_v1.missing_content_behavior'));
    }
}
