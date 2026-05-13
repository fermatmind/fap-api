<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Riasec;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Riasec\RiasecCompareGuardService;
use App\Services\Riasec\RiasecDeepCopySlotRegistry;
use App\Services\Riasec\RiasecMeasurementContract;
use PHPUnit\Framework\TestCase;

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

            foreach ($registry->structuralDifferenceRequiredFields() as $field) {
                $this->assertArrayHasKey($field, $slot);
                $this->assertNotEmpty($slot[$field]);
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

        $errors = $registry->validateSlot($slot);

        $this->assertContains('forbidden_claim_phrase_non_ascii', $errors);
        $this->assertContains('forbidden_claim_phrase_raw_score_delta', $errors);
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
}
