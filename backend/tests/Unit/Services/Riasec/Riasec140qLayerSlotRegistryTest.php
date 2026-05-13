<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Riasec;

use App\Services\Riasec\RiasecDeepCopySlotRegistry;
use PHPUnit\Framework\TestCase;

final class Riasec140qLayerSlotRegistryTest extends TestCase
{
    public function test_140q_three_card_and_state_slots_are_backend_authored(): void
    {
        $registry = new RiasecDeepCopySlotRegistry;
        $slots = $registry->layer140qSlots();

        foreach ([
            'task_activity_card',
            'environment_card',
            'role_responsibility_card',
            'layer_agreement',
            'layer_tension',
            'layer_unavailable',
            '140q_cta',
            '140q_not_recommended',
        ] as $slotName) {
            $slot = $slots[$slotName] ?? null;
            $this->assertIsArray($slot, $slotName.' slot should exist.');
            $this->assertSame('140q_layer_copy', $slot['slot_group']);
            $this->assertSame('authored', $slot['content_status']);
            $this->assertSame('reviewed_content_copy', $slot['source_status']);
            $this->assertFalse($slot['frontend_fallback_allowed']);

            foreach ($registry->layer140qRequiredFields() as $field) {
                $this->assertArrayHasKey($field, $slot);
                $this->assertNotEmpty($slot[$field]);
            }

            $this->assertSame([], $registry->validateSlot($slot), $slotName.' should be contract-clean.');
        }
    }

    public function test_140q_layer_states_are_backend_authoritative(): void
    {
        $registry = new RiasecDeepCopySlotRegistry;

        $this->assertSame('agreement', $registry->resolve140qLayerSlot('layer_agreement')['layer_state']);
        $this->assertSame('tension', $registry->resolve140qLayerSlot('layer_tension')['layer_state']);
        $this->assertSame('not_applicable_60q_only', $registry->resolve140qLayerSlot('layer_unavailable')['layer_state']);
        $this->assertSame('insufficient_quality', $registry->resolve140qLayerSlot('140q_not_recommended')['layer_state']);
    }

    public function test_140q_copy_rejects_accuracy_override_job_and_raw_delta_claims(): void
    {
        $registry = new RiasecDeepCopySlotRegistry;
        $slot = $registry->resolve140qLayerSlot('140q_cta');
        $slot['summary'] = '140Q 更准确，会推翻 60Q，是最终答案，还会显示 raw delta 和岗位胜任。';

        $errors = $registry->validateSlot($slot);

        $this->assertContains('forbidden_claim_phrase_non_ascii', $errors);
        $this->assertContains('forbidden_claim_phrase_raw_delta', $errors);
    }

    public function test_low_quality_state_uses_not_recommended_slot_without_upsell(): void
    {
        $slot = (new RiasecDeepCopySlotRegistry)->resolve140qLayerSlot('140q_not_recommended');

        $this->assertSame('insufficient_quality', $slot['layer_state']);
        $this->assertStringContainsString('暂不展示 140Q 三张卡', $slot['summary']);
        $this->assertStringContainsString('建议稍后重测', $slot['summary']);
        $this->assertStringNotContainsString('购买', $slot['summary']);
    }

    public function test_unknown_140q_layer_slot_fails_closed(): void
    {
        $slot = (new RiasecDeepCopySlotRegistry)->resolve140qLayerSlot('unknown_layer');

        $this->assertSame('unavailable', $slot['content_status']);
        $this->assertSame('omitted', $slot['module_state']);
        $this->assertSame('omit_module', $slot['fallback_behavior']);
        $this->assertFalse($slot['frontend_fallback_allowed']);
    }
}
