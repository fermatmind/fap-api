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
