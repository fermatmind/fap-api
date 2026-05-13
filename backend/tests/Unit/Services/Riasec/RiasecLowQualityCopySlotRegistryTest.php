<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Riasec;

use App\Services\Riasec\RiasecDeepCopySlotRegistry;
use App\Services\Riasec\RiasecQualityRuleContract;
use App\Services\Riasec\RiasecReportModuleSelector;
use PHPUnit\Framework\TestCase;

final class RiasecLowQualityCopySlotRegistryTest extends TestCase
{
    public function test_low_quality_and_cautious_reading_slots_are_backend_authored(): void
    {
        $registry = new RiasecDeepCopySlotRegistry;
        $slots = $registry->lowQualitySlots();

        foreach ([
            'top_notice',
            'user_not_blamed_message',
            'what_happened_explanation',
            'hidden_modules_explanation',
            'retake_guidance',
            'share_pdf_boundary',
            'next_step',
            'cautious_reading_notice',
            'minimal_quality_boundary_60q',
        ] as $slotName) {
            $slot = $slots[$slotName] ?? null;
            $this->assertIsArray($slot, $slotName.' slot should exist.');
            $this->assertSame('quality_copy', $slot['slot_group']);
            $this->assertSame('authored', $slot['content_status']);
            $this->assertFalse($slot['frontend_fallback_allowed']);

            foreach ($registry->qualityCopyRequiredFields() as $field) {
                $this->assertArrayHasKey($field, $slot);
                $this->assertNotEmpty($slot[$field]);
            }

            $this->assertSame([], $registry->validateSlot($slot), $slotName.' should be contract-clean.');
        }
    }

    public function test_low_quality_downgrade_policy_hides_strong_modules(): void
    {
        $policy = (new RiasecDeepCopySlotRegistry)->lowQualityModuleDowngradePolicy();

        foreach (['hero_activity_chain', 'pair_blend', 'activity_explorer', 'occupation_examples', '140q_cta', '140q_three_cards'] as $module) {
            $this->assertContains($module, $policy['hidden_modules']);
        }

        $this->assertFalse($policy['score_mutation_allowed']);
        $this->assertFalse($policy['measured_holland_code_mutation_allowed']);
        $this->assertFalse($policy['frontend_fallback_allowed']);
    }

    public function test_module_selector_hides_strong_modules_for_low_quality(): void
    {
        $policy = (new RiasecReportModuleSelector)->build([
            'quality' => ['quality_state' => 'low_quality'],
            'interpretation_state' => ['profile_shape' => 'low_quality'],
            'form' => ['form_code' => 'riasec_140'],
        ]);

        foreach (['hero_activity_chain', 'pair_blend', 'activity_explorer', 'occupation_examples', '140q_cta', '140q_context_cards'] as $moduleKey) {
            $this->assertSame('hidden', $this->visibility($policy, $moduleKey));
        }
    }

    public function test_quality_rule_does_not_mutate_scores_or_measured_code(): void
    {
        $state = (new RiasecQualityRuleContract)->build([
            'form_code' => 'riasec_140',
            'answer_count' => 140,
            'quality_flags' => ['attention_133_failed', 'attention_137_failed'],
        ]);

        $this->assertSame('low_quality', $state['quality_state']);
        $this->assertFalse($state['score_mutation_allowed']);
        $this->assertFalse($state['measured_holland_code_mutation_allowed']);
        $this->assertFalse($state['module_policy']['allow_140q_cta']);
    }

    public function test_60q_minimal_quality_boundary_does_not_overclaim_low_quality(): void
    {
        $state = (new RiasecQualityRuleContract)->build([
            'form_code' => 'riasec_60',
            'answer_count' => 60,
            'quality_flags' => ['weak_signal_only'],
        ]);

        $this->assertSame('caution', $state['quality_state']);
        $this->assertSame('cautious_reading', $state['reading_strength']);
    }

    public function test_low_quality_copy_rejects_user_blame_and_140q_upsell(): void
    {
        $registry = new RiasecDeepCopySlotRegistry;
        $slot = $registry->lowQualitySlots()['top_notice'];
        $slot['summary'] = '你答得不好，不认真。请购买 140Q 得到更准结果。';

        $errors = $registry->validateSlot($slot);

        $this->assertContains('forbidden_claim_phrase_non_ascii', $errors);
    }

    /**
     * @param  array<string,mixed>  $policy
     */
    private function visibility(array $policy, string $moduleKey): ?string
    {
        foreach ((array) ($policy['modules'] ?? []) as $module) {
            if (($module['key'] ?? null) === $moduleKey) {
                return $module['visibility'] ?? null;
            }
        }

        return null;
    }
}
