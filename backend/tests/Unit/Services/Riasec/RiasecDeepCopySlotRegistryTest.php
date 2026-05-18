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
        unset($slot['core_drive'], $slot['medium_score_reading'], $slot['work_activity_examples']);

        $errors = $registry->validateSlot($slot);

        $this->assertContains('missing_core_drive', $errors);
        $this->assertContains('missing_medium_score_reading', $errors);
        $this->assertContains('missing_work_activity_examples', $errors);
    }
}
