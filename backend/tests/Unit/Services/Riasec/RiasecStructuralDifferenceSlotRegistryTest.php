<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Riasec;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\ReviewGovernance\PublicReviewContract;
use App\Services\Riasec\RiasecActivityExplorerService;
use App\Services\Riasec\RiasecCompareGuardService;
use App\Services\Riasec\RiasecDeepCopySlotRegistry;
use App\Services\Riasec\RiasecExplorationFeedbackOverlayService;
use App\Services\Riasec\RiasecInterpretationRuleContract;
use App\Services\Riasec\RiasecLifecycleCopyService;
use App\Services\Riasec\RiasecMeasurementContract;
use App\Services\Riasec\RiasecPublicProjectionService;
use App\Services\Riasec\RiasecQualityRuleContract;
use App\Services\Riasec\RiasecReportModuleSelector;
use Tests\TestCase;

final class RiasecStructuralDifferenceSlotRegistryTest extends TestCase
{
    public function test_structural_difference_slots_are_backend_authored_and_safe(): void
    {
        $registry = new RiasecDeepCopySlotRegistry;
        $slots = $registry->structuralDifferenceSlots();

        foreach ([
            'summary',
            'task_layer_explanation',
            'environment_layer_explanation',
            'role_layer_explanation',
            'correct_reading',
            'forbidden_reading',
            'next_validation_step',
        ] as $slotName) {
            $slot = $slots[$slotName] ?? null;
            $this->assertIsArray($slot, $slotName.' slot should exist.');
            $this->assertSame('structural_difference_copy', $slot['slot_group']);
            $this->assertSame('authored', $slot['content_status']);
            $this->assertFalse($slot['frontend_fallback_allowed']);
            $this->assertTrue($slot['emphasis_difference_only']);
            $this->assertFalse($slot['correctness_ranking_allowed']);
            $this->assertFalse($slot['raw_score_comparison_allowed']);
            $this->assertFalse($slot['result_override_allowed']);
            $this->assertFalse($slot['code_conversion_allowed']);
            $this->assertSame('task_environment_role_emphasis_only', $slot['selection_basis']);

            foreach ($registry->structuralDifferenceRequiredFields() as $field) {
                $this->assertArrayHasKey($field, $slot);
                if (str_ends_with($field, '_allowed')) {
                    $this->assertIsBool($slot[$field]);
                } else {
                    $this->assertNotEmpty($slot[$field]);
                }
            }

            $this->assertSame([], $registry->validateSlot($slot), $slotName.' should be contract-clean.');
        }
    }

    public function test_structural_difference_state_enum_covers_required_states(): void
    {
        foreach ([
            'same_structure',
            'different_emphasis',
            'layer_tension',
            'insufficient_basis',
            'cross_form_not_comparable',
        ] as $state) {
            $this->assertContains($state, RiasecDeepCopySlotRegistry::STRUCTURAL_DIFFERENCE_STATES);
        }
    }

    public function test_structural_difference_missing_content_fails_closed(): void
    {
        $missing = (new RiasecDeepCopySlotRegistry)->resolveStructuralDifferenceSlot('unsupported_slot');

        $this->assertSame('unavailable', $missing['content_status']);
        $this->assertSame('omitted', $missing['module_state']);
        $this->assertSame('omit_module', $missing['fallback_behavior']);
        $this->assertFalse($missing['frontend_fallback_allowed']);
    }

    public function test_structural_difference_copy_rejects_raw_delta_accuracy_override_and_code_conversion_claims(): void
    {
        $registry = new RiasecDeepCopySlotRegistry;
        $slot = $registry->structuralDifferenceSlots()['summary'];
        $slot['summary'] = '60Q 错了，140Q 更准并推翻 60Q；你从 IAS 变成 SEC，分数上升，raw score delta 可比较。';
        $slot['emphasis_difference_only'] = false;
        $slot['correctness_ranking_allowed'] = true;
        $slot['raw_score_comparison_allowed'] = true;
        $slot['result_override_allowed'] = true;
        $slot['code_conversion_allowed'] = true;

        $errors = $registry->validateSlot($slot);

        $this->assertContains('forbidden_claim_phrase_non_ascii', $errors);
        $this->assertContains('forbidden_claim_phrase_raw_score_delta', $errors);
        $this->assertContains('structural_difference_emphasis_difference_only_must_be_true', $errors);
        $this->assertContains('structural_difference_correctness_ranking_allowed_must_be_false', $errors);
        $this->assertContains('structural_difference_raw_score_comparison_allowed_must_be_false', $errors);
        $this->assertContains('structural_difference_result_override_allowed_must_be_false', $errors);
        $this->assertContains('structural_difference_code_conversion_allowed_must_be_false', $errors);
    }

    public function test_compare_guard_still_blocks_cross_form_raw_score_comparison(): void
    {
        $guard = (new RiasecCompareGuardService(new RiasecMeasurementContract))->evaluate(
            $this->attempt('attempt_60', 'riasec_60', 60),
            $this->makeResult('attempt_60', 'riasec_60'),
            $this->attempt('attempt_140', 'riasec_140', 140),
            $this->makeResult('attempt_140', 'riasec_140')
        );

        $this->assertFalse($guard['can_compare']);
        $this->assertFalse($guard['raw_score_delta_allowed']);
        $this->assertSame('cross_form_score_space_mismatch', $guard['reason']);
        $this->assertArrayNotHasKey('raw_scores_delta', $guard);
        $this->assertArrayNotHasKey('domains_delta', $guard);
    }

    public function test_public_projection_exposes_structural_difference_as_emphasis_only(): void
    {
        $result = new Result;
        $result->scale_code = 'RIASEC';
        $result->type_code = 'IAS';
        $result->scores_pct = ['I' => 92, 'A' => 78, 'S' => 64, 'R' => 30, 'E' => 22, 'C' => 18];
        $result->result_json = [
            'top_code' => 'IAS',
            'form_code' => 'riasec_140',
            'answer_count' => 140,
            'structural_difference_state' => 'different_emphasis',
            'riasec_140q_layer_states' => [
                'task' => 'agreement',
                'environment' => 'tension',
                'role' => 'agreement',
            ],
        ];

        $projection = $this->projectionService()->buildV2FromResult($result);
        $policy = $projection['structural_difference'];

        $this->assertTrue($policy['emphasis_difference_only']);
        $this->assertFalse($policy['correctness_ranking_allowed']);
        $this->assertFalse($policy['raw_score_comparison_allowed']);
        $this->assertFalse($policy['result_override_allowed']);
        $this->assertFalse($policy['code_conversion_allowed']);
        $this->assertSame('task_environment_role_emphasis_only', $policy['basis']);

        $summarySlot = (new RiasecDeepCopySlotRegistry)->structuralDifferenceSlots()['summary'];
        $this->assertTrue($summarySlot['emphasis_difference_only']);
        $this->assertFalse($summarySlot['correctness_ranking_allowed']);
        $this->assertFalse($summarySlot['raw_score_comparison_allowed']);
        $this->assertFalse($summarySlot['result_override_allowed']);
        $this->assertFalse($summarySlot['code_conversion_allowed']);
        $this->assertSame('task_environment_role_emphasis_only', $summarySlot['selection_basis']);
    }

    public function test_140q_projection_fails_closed_when_layer_states_are_missing_or_invalid(): void
    {
        $cases = [
            'EAR' => [
                'scores' => ['E' => 63, 'A' => 58, 'R' => 55, 'I' => 50, 'S' => 47, 'C' => 42],
                'breakdown' => ['activity_E' => 63, 'env_E' => 83, 'role_E' => 42, 'role_R' => 67, 'role_S' => 67],
            ],
            'ICA' => [
                'scores' => ['I' => 66, 'C' => 62, 'A' => 58, 'R' => 50, 'S' => 45, 'E' => 40],
                'breakdown' => ['activity_I' => 77, 'env_I' => 8, 'env_C' => 75, 'role_I' => 50, 'role_A' => 83],
            ],
            'AIC' => [
                'scores' => ['A' => 64, 'I' => 61, 'C' => 59, 'R' => 52, 'S' => 50, 'E' => 38],
                'breakdown' => ['activity_A' => 64, 'env_A' => 33, 'env_C' => 67, 'env_I' => 67, 'env_R' => 67, 'env_S' => 67, 'role_A' => 50, 'role_I' => 83],
            ],
        ];

        foreach ($cases as $topCode => $case) {
            $result = new Result;
            $result->scale_code = 'RIASEC';
            $result->type_code = $topCode;
            $result->scores_pct = $case['scores'];
            $result->result_json = array_merge($case['breakdown'], [
                'top_code' => $topCode,
                'form_code' => 'riasec_140',
                'answer_count' => 140,
                'task_layer_state' => 'unknown_state',
            ]);

            $service = $this->projectionService();
            $v1 = $service->buildFromResult($result);
            $projection = $service->buildV2FromResult($result);

            foreach ($case['breakdown'] as $key => $value) {
                $path = str_starts_with($key, 'activity_')
                    ? 'activity.'.substr($key, 9)
                    : (str_starts_with($key, 'env_') ? 'environment.'.substr($key, 4) : 'role.'.substr($key, 5));
                $this->assertSame((float) $value, data_get($v1, 'enhanced_breakdown.'.$path));
            }

            $this->assertSame([
                'task' => 'unavailable',
                'environment' => 'unavailable',
                'role' => 'unavailable',
            ], data_get($projection, 'structural_difference.layer_states'));

            $slotIds = array_column((array) data_get($projection, 'deep_content_slots_v1.slots', []), 'slot_id');
            $this->assertNotContains('140q_layer_agreement_copy:layer_agreement', $slotIds);
            $this->assertNotContains('140q_tension_copy:layer_tension', $slotIds);
            $this->assertEmpty(array_filter(
                $slotIds,
                static fn (string $slotId): bool => str_contains($slotId, '_agreement') || str_contains($slotId, '_tension')
            ));
            $this->assertContains('140q_task_card_copy:task_activity_card', $slotIds);
            $this->assertContains('140q_environment_card_copy:environment_card', $slotIds);
            $this->assertContains('140q_role_card_copy:role_responsibility_card', $slotIds);
        }
    }

    public function test_quality_display_localizes_reasons_actions_and_boundary_without_raw_flags(): void
    {
        $result = new Result;
        $result->scale_code = 'RIASEC';
        $result->type_code = 'RIA';
        $result->scores_pct = ['R' => 80, 'I' => 70, 'A' => 60, 'S' => 50, 'E' => 40, 'C' => 30];
        $result->result_json = [
            'top_code' => 'RIA',
            'form_code' => 'riasec_140',
            'answer_count' => 140,
            'quality_grade' => 'C',
            'quality_flags' => ['idealization', 'low_consistency', 'broad_agreement'],
            'too_fast' => true,
            'neutral_overuse' => true,
        ];

        $zh = data_get($this->projectionService()->buildV2FromResult($result, 'zh-CN'), 'quality.display_v1');
        $this->assertSame('riasec.quality_display.v1', $zh['schema_version']);
        $this->assertSame('zh-CN', $zh['locale']);
        $this->assertStringContainsString('初步线索', $zh['headline']);
        $this->assertCount(5, $zh['reasons']);
        $this->assertCount(5, $zh['improvements']);
        $this->assertStringContainsString('理想状态', implode('', $zh['reasons']));
        $this->assertStringContainsString('如何', '如何让下次结果更稳定');
        $this->assertStringContainsString('不评价你的能力、人格或个人价值', $zh['reading_boundary']);
        $this->assertStringNotContainsString('idealization', json_encode($zh, JSON_UNESCAPED_UNICODE));

        $en = data_get($this->projectionService()->buildV2FromResult($result, 'en'), 'quality.display_v1');
        $this->assertSame('en', $en['locale']);
        $this->assertStringContainsString('initial signal', $en['headline']);
        $this->assertStringContainsString('ideal self', implode('', $en['reasons']));
        $this->assertDoesNotMatchRegularExpression('/[\x{4e00}-\x{9fff}]/u', json_encode($en, JSON_UNESCAPED_UNICODE));
    }

    public function test_quality_display_follows_derived_state_and_handles_unknown_attention_and_missing_signals(): void
    {
        $base = new Result;
        $base->scale_code = 'RIASEC';
        $base->type_code = 'RIA';
        $base->scores_pct = ['R' => 80, 'I' => 70, 'A' => 60, 'S' => 50, 'E' => 40, 'C' => 30];

        $tooFast = clone $base;
        $tooFast->result_json = [
            'top_code' => 'RIA', 'form_code' => 'riasec_140', 'answer_count' => 140,
            'quality_grade' => 'A', 'too_fast' => true,
        ];
        $tooFastProjection = $this->projectionService()->buildV2FromResult($tooFast, 'zh-CN');
        $this->assertSame('caution', data_get($tooFastProjection, 'quality.quality_state'));
        $this->assertStringContainsString('轻度', data_get($tooFastProjection, 'quality.display_v1.headline'));
        $this->assertStringContainsString('速度明显偏快', implode('', data_get($tooFastProjection, 'quality.display_v1.reasons')));

        $unknown = clone $base;
        $unknown->result_json = [
            'top_code' => 'RIA', 'form_code' => 'riasec_140', 'answer_count' => 140,
            'quality_grade' => 'A', 'quality_flags' => ['future_quality_signal'],
        ];
        $unknownDisplay = data_get($this->projectionService()->buildV2FromResult($unknown, 'zh-CN'), 'quality.display_v1');
        $this->assertStringContainsString('轻度', $unknownDisplay['headline']);
        $this->assertNotEmpty($unknownDisplay['reasons']);
        $this->assertNotEmpty($unknownDisplay['improvements']);
        $this->assertStringNotContainsString('future_quality_signal', json_encode($unknownDisplay, JSON_UNESCAPED_UNICODE));

        $attention = clone $base;
        $attention->result_json = [
            'top_code' => 'RIA', 'form_code' => 'riasec_140', 'answer_count' => 140,
            'quality_grade' => 'C', 'quality_flags' => ['attention_133_failed', 'attention_137_failed'],
        ];
        $attentionProjection = $this->projectionService()->buildV2FromResult($attention, 'zh-CN');
        $this->assertSame('low_quality', data_get($attentionProjection, 'quality.quality_state'));
        $this->assertSame(1, count(data_get($attentionProjection, 'quality.display_v1.reasons')));
        $this->assertStringContainsString('两道注意力检查题均未通过', data_get($attentionProjection, 'quality.display_v1.reasons.0'));

        $incomplete = clone $base;
        $incomplete->result_json = [
            'top_code' => 'RIA', 'form_code' => 'riasec_60', 'answer_count' => 52,
            'quality_grade' => 'A', 'quality_flags' => [],
        ];
        $incompleteProjection = $this->projectionService()->buildV2FromResult($incomplete, 'zh-CN');
        $this->assertSame('low_quality', data_get($incompleteProjection, 'quality.quality_state'));
        $this->assertStringContainsString('未完成题目', implode('', data_get($incompleteProjection, 'quality.display_v1.reasons')));
    }

    public function test_dimension_projection_selects_one_backend_owned_score_band_and_prioritizes_top_three(): void
    {
        $result = new Result;
        $result->scale_code = 'RIASEC';
        $result->type_code = 'RIA';
        $result->scores_pct = ['R' => 90, 'I' => 75, 'A' => 67, 'S' => 67, 'E' => 34, 'C' => 20];
        $result->result_json = [
            'top_code' => 'RIA', 'form_code' => 'riasec_60', 'answer_count' => 60,
            'quality_grade' => 'A', 'quality_flags' => [],
        ];

        $projection = $this->projectionService()->buildV2FromResult($result, 'zh-CN');
        $bandContract = data_get($projection, 'dimension_score_band_contract_v1');
        $this->assertSame('riasec.dimension_score_band.v1', $bandContract['schema_version']);
        $this->assertSame('criterion_referenced_normalized_score_range', $bandContract['method']);
        $this->assertSame('equal_width_normalized_response_scale_ranges', $bandContract['descriptive_basis']);
        $this->assertFalse($bandContract['percentile_interpretation_allowed']);
        $this->assertFalse($bandContract['normative_interpretation_allowed']);
        $this->assertSame(67, data_get($bandContract, 'thresholds.high.min_inclusive'));
        $slots = array_values(array_filter(
            (array) data_get($projection, 'deep_content_slots_v1.slots'),
            static fn (array $slot): bool => ($slot['slot_key'] ?? null) === 'dimension_deep_copy'
        ));

        $this->assertCount(6, $slots);
        $expected = [
            'R' => [1, true, 'high', 'high_score_reading'],
            'I' => [2, true, 'high', 'high_score_reading'],
            'A' => [3, true, 'high', 'high_score_reading'],
            'S' => [3, true, 'high', 'high_score_reading'],
            'E' => [5, false, 'medium', 'medium_score_reading'],
            'C' => [6, false, 'low', 'low_score_safe_reading'],
        ];
        foreach ($slots as $slot) {
            $code = (string) data_get($slot, 'selection_v1.dimension_code');
            [$rank, $isTopThree, $band, $detailKey] = $expected[$code];
            $this->assertSame($rank, data_get($slot, 'selection_v1.rank'));
            $this->assertSame($isTopThree, data_get($slot, 'selection_v1.is_top_three'));
            $this->assertSame($band, data_get($slot, 'selection_v1.score_band'));
            $this->assertSame($detailKey, data_get($slot, 'selection_v1.selected_detail_key'));
            $this->assertSame($isTopThree ? 'visible' : 'collapsed', $slot['slot_visibility']);
            $this->assertArrayHasKey($detailKey, $slot['content']);
            $this->assertCount(1, array_intersect(
                ['high_score_reading', 'medium_score_reading', 'low_score_safe_reading'],
                array_keys($slot['content'])
            ));
        }

        $lowQualityPayload = $result->result_json;
        $lowQualityPayload['answer_count'] = 52;
        $result->result_json = $lowQualityPayload;
        $lowQualityProjection = $this->projectionService()->buildV2FromResult($result, 'zh-CN');
        $this->assertEmpty(array_filter(
            (array) data_get($lowQualityProjection, 'deep_content_slots_v1.slots'),
            static fn (array $slot): bool => ($slot['slot_key'] ?? null) === 'dimension_deep_copy'
        ));

        foreach ([['R' => 101], ['R' => -1], ['R' => 'not-a-score']] as $invalid) {
            $invalidResult = clone $result;
            $invalidPayload = $invalidResult->result_json;
            $invalidPayload['answer_count'] = 60;
            $invalidResult->result_json = $invalidPayload;
            $invalidResult->scores_pct = array_merge(['R' => 90, 'I' => 75, 'A' => 67, 'S' => 60, 'E' => 34, 'C' => 20], $invalid);
            $invalidProjection = $this->projectionService()->buildV2FromResult($invalidResult, 'zh-CN');
            $this->assertEmpty(array_filter(
                (array) data_get($invalidProjection, 'deep_content_slots_v1.slots'),
                static fn (array $slot): bool => ($slot['slot_key'] ?? null) === 'dimension_deep_copy'
            ));
        }

        foreach ([[33.99, 'low'], [34, 'medium'], [66.99, 'medium'], [67, 'high']] as [$score, $expectedBand]) {
            $boundaryResult = clone $result;
            $boundaryPayload = $boundaryResult->result_json;
            $boundaryPayload['answer_count'] = 60;
            $boundaryResult->result_json = $boundaryPayload;
            $boundaryResult->scores_pct = ['R' => (float) $score, 'I' => 90, 'A' => 80, 'S' => 70, 'E' => 60, 'C' => 50];
            $boundaryProjection = $this->projectionService()->buildV2FromResult($boundaryResult, 'zh-CN');
            $rSlot = collect((array) data_get($boundaryProjection, 'deep_content_slots_v1.slots'))
                ->firstWhere('slot_id', 'dimension_deep_copy:R');
            $this->assertSame($expectedBand, data_get($rSlot, 'selection_v1.score_band'));
        }

        $missingDimension = clone $result;
        $missingDimension->scores_pct = ['R' => 90, 'I' => 75, 'A' => 67, 'S' => 60, 'E' => 34];
        $completePayload = $missingDimension->result_json;
        $completePayload['answer_count'] = 60;
        $missingDimension->result_json = $completePayload;
        $missingProjection = $this->projectionService()->buildV2FromResult($missingDimension, 'zh-CN');
        $this->assertEmpty(array_filter(
            (array) data_get($missingProjection, 'deep_content_slots_v1.slots'),
            static fn (array $slot): bool => ($slot['slot_key'] ?? null) === 'dimension_deep_copy'
        ));
    }

    private function attempt(string $id, string $formCode, int $questionCount): Attempt
    {
        $attempt = new Attempt;
        $attempt->id = $id;
        $attempt->org_id = 0;
        $attempt->scale_code = 'RIASEC';
        $attempt->question_count = $questionCount;
        $attempt->answers_summary_json = [
            'meta' => [
                'form_code' => $formCode,
            ],
        ];

        return $attempt;
    }

    private function makeResult(string $attemptId, string $formCode): Result
    {
        $result = new Result;
        $result->attempt_id = $attemptId;
        $result->scale_code = 'RIASEC';
        $result->result_json = [
            'form_code' => $formCode,
            'measurement_contract_v1' => (new RiasecMeasurementContract)->forFormCode($formCode),
        ];

        return $result;
    }

    private function projectionService(): RiasecPublicProjectionService
    {
        return new RiasecPublicProjectionService(
            new RiasecMeasurementContract,
            new RiasecActivityExplorerService,
            new RiasecExplorationFeedbackOverlayService,
            new RiasecLifecycleCopyService,
            new RiasecInterpretationRuleContract,
            new RiasecQualityRuleContract,
            new RiasecReportModuleSelector,
            new RiasecDeepCopySlotRegistry,
            new PublicReviewContract,
        );
    }
}
