<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Riasec;

use App\Models\Result;
use App\Services\Riasec\RiasecExplorationFeedbackOverlayService;
use Tests\TestCase;

final class RiasecFeedbackActionLabPreflightTest extends TestCase
{
    private const EXPECTED_FEEDBACK_RECORDS = 204;

    private const EXPECTED_NEXT_NODE_RECORDS = 270;

    private const VALID_DIMENSIONS = ['R', 'I', 'A', 'S', 'E', 'C'];

    private const REQUIRED_FEEDBACK_ACTIONS = [
        'viewed_activity',
        'saved_activity',
        'excluded_scene',
        'completed_experiment',
        'marked_energizing',
        'marked_draining',
        'wants_more_environment_evidence',
        'wants_more_role_evidence',
        'disagrees_with_result',
        'saved_report',
        'downloaded_pdf',
        'shared_public_safe_report',
        'wants_major_or_course_path',
        'wants_workplace_daily_life',
        'wants_public_safe_share',
        'retake_trigger',
        'take_140q_trigger',
        'save_pdf_trigger',
    ];

    private const REQUIRED_NEXT_NODE_TYPES = [
        'try_15min_task_verbs',
        'try_30min_micro_output',
        'try_real_feedback_context',
        'compare_two_environments',
        'map_role_responsibility',
        'save_and_revisit',
        'quick_check',
        'first_micro_experiment',
        'compare_two_activities',
        'read_task_examples',
        'view_examples_only_occupations',
        'take_140q',
        'retake_same_form',
        'save_report',
        'download_pdf',
        'share_safe_summary',
        'revisit_history',
        'counselor_discussion_prompt',
        'ask_for_more_activity_evidence',
        'ask_for_environment_evidence',
        'ask_for_role_evidence',
    ];

    private const FEEDBACK_REQUIRED_FIELDS = [
        'schema_version',
        'asset_version',
        'asset_status',
        'event_type',
        'activity_key',
        'user_copy',
        'system_response',
        'next_step_copy',
        'score_mutation_allowed',
        'measured_holland_code_mutation_allowed',
        'snapshot_mutation_allowed',
        'share_pdf_exposure_allowed',
        'review_status',
        'required_boundaries',
        'forbidden_claims',
        'frontend_fallback_allowed',
    ];

    private const NEXT_NODE_REQUIRED_FIELDS = [
        'schema_version',
        'asset_version',
        'asset_status',
        'node_id',
        'node_type',
        'dimension_hint',
        'title',
        'summary',
        'instruction',
        'estimated_time',
        'creates_score_change',
        'creates_career_match',
        'review_status',
        'required_boundaries',
        'forbidden_claims',
        'frontend_fallback_allowed',
    ];

    private const FORBIDDEN_USER_CLAIMS = [
        'career match',
        'occupation match',
        'job fit',
        'fit score',
        'success prediction',
        'success probability',
        'recommended career',
        'best career',
        'career recommendation',
        'occupation ranking',
        'hiring suitability',
        'ability proof',
        'skill inference',
        '140Q more accurate',
        'more accurate',
        'raw score delta',
        '60Q wrong',
        '职业匹配',
        '岗位匹配',
        '匹配度',
        '适合度',
        '最适合',
        '推荐职业',
        '职业推荐',
        '岗位胜任',
        '成功概率',
        '职业成功',
        '更准确',
        '更准',
        '140题更准确',
        '60题错了',
        '推翻',
        '最终答案',
        '你就是',
        '天生适合',
        '能力证明',
        '技能证明',
        '招聘筛选',
        '录取依据',
        '晋升依据',
        '淘汰依据',
    ];

    private const MUTATION_PHRASES = [
        '反馈会改分',
        '改写分数',
        '改写 measured Holland Code',
        '改变 measured Holland Code',
        '改变报告快照',
        '公开原始反馈',
    ];

    public function test_v7_3_feedback_action_lab_fixture_is_complete_and_non_mutating(): void
    {
        $records = $this->feedbackRows();
        $eventTypes = array_values(array_unique(array_column($records, 'event_type')));

        $this->assertCount(self::EXPECTED_FEEDBACK_RECORDS, $records);
        foreach (self::REQUIRED_FEEDBACK_ACTIONS as $eventType) {
            $this->assertContains($eventType, $eventTypes);
        }

        foreach ($records as $index => $record) {
            foreach (self::FEEDBACK_REQUIRED_FIELDS as $field) {
                $this->assertArrayHasKey($field, $record, 'feedback line '.($index + 1)." missing {$field}");
            }

            $this->assertSame('riasec.feedback_action_lab.v1', $record['schema_version']);
            $this->assertSame('riasec_feedback_action_lab_v1.zh-CN', $record['asset_version']);
            $this->assertContains($record['event_type'], self::REQUIRED_FEEDBACK_ACTIONS);
            $this->assertFalse($record['score_mutation_allowed']);
            $this->assertFalse($record['measured_holland_code_mutation_allowed']);
            $this->assertFalse($record['snapshot_mutation_allowed']);
            $this->assertFalse($record['share_pdf_exposure_allowed']);
            $this->assertFalse($record['frontend_fallback_allowed']);
        }
    }

    public function test_v7_3_next_exploration_nodes_fixture_is_complete_and_safe(): void
    {
        $records = $this->nextNodeRows();
        $nodeTypes = array_values(array_unique(array_column($records, 'node_type')));

        $this->assertCount(self::EXPECTED_NEXT_NODE_RECORDS, $records);
        foreach (self::REQUIRED_NEXT_NODE_TYPES as $nodeType) {
            $this->assertContains($nodeType, $nodeTypes);
        }

        foreach ($records as $index => $record) {
            foreach (self::NEXT_NODE_REQUIRED_FIELDS as $field) {
                $this->assertArrayHasKey($field, $record, 'next node line '.($index + 1)." missing {$field}");
            }

            $this->assertSame('riasec.next_exploration_node.v1', $record['schema_version']);
            $this->assertSame('riasec_next_exploration_nodes_v1.zh-CN', $record['asset_version']);
            $this->assertContains($record['dimension_hint'], self::VALID_DIMENSIONS);
            $this->assertFalse($record['creates_score_change']);
            $this->assertFalse($record['creates_career_match']);
            $this->assertFalse($record['frontend_fallback_allowed']);
        }
    }

    public function test_visible_copy_has_no_forbidden_claims_or_technical_keys(): void
    {
        $hits = [];

        foreach ($this->visibleRows() as $source => $texts) {
            foreach ($texts as $text) {
                foreach (self::FORBIDDEN_USER_CLAIMS as $claim) {
                    if ($this->containsTerm($text, $claim)) {
                        $hits[] = "{$source}: {$claim} in {$text}";
                    }
                }

                foreach (self::MUTATION_PHRASES as $phrase) {
                    if ($this->containsTerm($text, $phrase) && ! $this->isNegatedBoundary($text, $phrase)) {
                        $hits[] = "{$source}: mutation phrase {$phrase} in {$text}";
                    }
                }

                $this->assertDoesNotMatchRegularExpression(
                    '/\b[a-z]+(?:_[a-z0-9]+)+\b/',
                    $text,
                    "{$source} exposes a technical key in user-facing copy",
                );
            }
        }

        $this->assertSame([], $hits);
    }

    public function test_current_feedback_overlay_contract_blocks_score_snapshot_and_public_feedback_mutation(): void
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
                'holland_code' => ['code' => 'IAS'],
                'form' => [
                    'form_code' => 'riasec_60',
                    'score_space_version' => 'riasec_60_likert5_activity_sum_space.v1',
                ],
            ],
            true
        );

        $this->assertSame('safe_static_content_bridge_v0_1', $overlay['status']);
        $this->assertSame('not_connected_v0_1', $overlay['feedback_stream_status']);
        $this->assertFalse((bool) data_get($overlay, 'measured_result_guard.scores_mutation_allowed'));
        $this->assertFalse((bool) data_get($overlay, 'measured_result_guard.holland_code_mutation_allowed'));
        $this->assertFalse((bool) data_get($overlay, 'measured_result_guard.report_snapshot_mutation_allowed'));
        $this->assertFalse((bool) data_get($overlay, 'surface_policy.share_pdf_exposure_allowed'));
        $this->assertFalse((bool) data_get($overlay, 'surface_policy.raw_feedback_public_exposure_allowed'));
        $this->assertFalse((bool) data_get($overlay, 'read_model.raw_feedback_included'));
        $this->assertFalse((bool) data_get($overlay, 'claim_boundary.feedback_is_career_match'));
        $this->assertFalse((bool) data_get($overlay, 'claim_boundary.feedback_is_success_prediction'));
    }

    public function test_preflight_decision_is_conditional_go_and_stops_before_import(): void
    {
        $this->assertFileExists(base_path('docs/riasec/feedback-action-lab-pack-10-preflight.md'));

        $report = file_get_contents(base_path('docs/riasec/feedback-action-lab-pack-10-preflight.md'));
        $this->assertIsString($report);
        $this->assertStringContainsString('Decision for PACK-10-BE: CONDITIONAL GO', $report);
        $this->assertStringContainsString('PACK-10-FE decision: REQUIRED AFTER BACKEND PAYLOAD CONTRACT', $report);
        $this->assertStringContainsString('This preflight does not import runtime feedback_action_lab or next_exploration_nodes content.', $report);
        $this->assertStringContainsString('Current backend overlay gap', $report);
        $this->assertStringContainsString('Current fap-web consumption gap', $report);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function feedbackRows(): array
    {
        return $this->loadJsonl(base_path('tests/Fixtures/Riasec/feedback_action_lab_v1.zh-CN.jsonl'));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function nextNodeRows(): array
    {
        return $this->loadJsonl(base_path('tests/Fixtures/Riasec/next_exploration_nodes_v1.zh-CN.jsonl'));
    }

    /**
     * @return array<string,list<string>>
     */
    private function visibleRows(): array
    {
        $visible = [];
        foreach ($this->feedbackRows() as $index => $record) {
            $visible['feedback line '.($index + 1)] = [
                (string) $record['user_copy'],
                (string) $record['system_response'],
                (string) $record['next_step_copy'],
            ];
        }

        foreach ($this->nextNodeRows() as $index => $record) {
            $visible['next node line '.($index + 1)] = [
                (string) $record['title'],
                (string) $record['summary'],
                (string) $record['instruction'],
            ];
        }

        return $visible;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadJsonl(string $path): array
    {
        $this->assertFileExists($path);

        $rows = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $lineNumber => $line) {
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $this->assertIsArray($decoded, 'line '.($lineNumber + 1).' must decode to an object');
            $rows[] = $decoded;
        }

        return $rows;
    }

    private function containsTerm(string $text, string $term): bool
    {
        return mb_stripos($text, $term) !== false;
    }

    private function isNegatedBoundary(string $text, string $term): bool
    {
        return mb_stripos($text, '不会'.$term) !== false
            || mb_stripos($text, '不'.$term) !== false
            || mb_stripos($text, '不能'.$term) !== false;
    }
}
