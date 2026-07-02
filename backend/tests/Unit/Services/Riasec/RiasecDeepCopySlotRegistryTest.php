<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Riasec;

use App\Services\Riasec\RiasecDeepCopySlotRegistry;
use PHPUnit\Framework\TestCase;

final class RiasecDeepCopySlotRegistryTest extends TestCase
{
    public function test_dimension_deep_copy_slots_cover_all_six_dimensions(): void
    {
        $registry = new RiasecDeepCopySlotRegistry;
        $slots = $registry->dimensionSlots();

        $this->assertSame(['R', 'I', 'A', 'S', 'E', 'C'], array_keys($slots));

        foreach ($slots as $dimension => $slot) {
            $this->assertSame('dimension_deep_copy', $slot['slot_key']);
            $this->assertSame([$dimension], $slot['applicable_dimensions']);
            $this->assertSame($dimension, $slot['dimension_code']);
            $this->assertSame('reviewed_content_copy', $slot['source_status']);
            $this->assertSame('approved_for_production', $slot['review_status']);
            $this->assertSame('expert_reviewed', $slot['evidence_level']);
            $this->assertFalse($slot['frontend_fallback_allowed']);

            foreach ($registry->dimensionRequiredFields() as $requiredField) {
                $this->assertArrayHasKey($requiredField, $slot);
                $this->assertNotEmpty($slot[$requiredField]);
            }
            $this->assertArrayHasKey('medium_score_reading', $slot);
            $this->assertNotEmpty($slot['medium_score_reading']);
            $this->assertCount(3, $slot['interest_activity_focus']);
            $this->assertCount(3, $slot['context_costs']);
            $this->assertCount(3, $slot['misread_guardrails']);
            $this->assertCount(3, $slot['validation_questions']);
            $this->assertGreaterThanOrEqual(6, count($slot['work_activity_examples']));
            $this->assertStringContainsString('活动', $slot['core_drive']);
            $this->assertStringContainsString('不代表人格身份、能力水平、资格条件或职业答案', $slot['core_drive']);
            $this->assertStringContainsString('现实', $slot['real_world_cost']);
            $this->assertStringContainsString('常见误读', $slot['common_misread']);
            $this->assertStringContainsString('不测能力、人格品质、资质或职业结果', $slot['user_visible_boundary']);
            foreach ($slot['validation_questions'] as $question) {
                $this->assertStringEndsWith('？', $question);
            }

            $this->assertSame([], $registry->validateSlot($slot), 'Dimension '.$dimension.' slot should be contract-clean.');
        }
    }

    public function test_dimension_slot_contract_rejects_forbidden_claims(): void
    {
        $registry = new RiasecDeepCopySlotRegistry;
        $slot = $registry->resolveDimensionSlot('I');
        $slot['body'] = 'This invalid slot says job fit, success probability, and career recommendation.';
        $slot['job_fit'] = true;

        $errors = $registry->validateSlot($slot);

        $this->assertContains('forbidden_field_job_fit', $errors);
        $this->assertContains('forbidden_claim_phrase_job_fit', $errors);
        $this->assertContains('forbidden_claim_phrase_success_probability', $errors);
        $this->assertContains('forbidden_claim_phrase_career_recommendation', $errors);
    }

    public function test_missing_dimension_content_fails_closed_without_frontend_fallback(): void
    {
        $slot = (new RiasecDeepCopySlotRegistry)->resolveDimensionSlot('X');

        $this->assertSame('dimension_deep_copy', $slot['slot_key']);
        $this->assertSame('X', $slot['dimension_code']);
        $this->assertSame('unavailable', $slot['content_status']);
        $this->assertSame('omitted', $slot['module_state']);
        $this->assertSame('omit_module', $slot['fallback_behavior']);
        $this->assertFalse($slot['frontend_fallback_allowed']);
    }

    public function test_dimension_slot_requires_deep_copy_fields(): void
    {
        $registry = new RiasecDeepCopySlotRegistry;
        $slot = $registry->resolveDimensionSlot('A');
        unset($slot['core_drive'], $slot['medium_score_reading'], $slot['work_activity_examples'], $slot['validation_questions']);

        $errors = $registry->validateSlot($slot);

        $this->assertContains('missing_core_drive', $errors);
        $this->assertContains('missing_medium_score_reading', $errors);
        $this->assertContains('missing_work_activity_examples', $errors);
        $this->assertContains('missing_validation_questions', $errors);
    }
}
