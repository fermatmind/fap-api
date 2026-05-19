<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Riasec;

use App\Services\Riasec\RiasecDeepCopySlotRegistry;
use PHPUnit\Framework\TestCase;

final class Riasec140qLayerAssetRegistryTest extends TestCase
{
    public function test_140q_layer_asset_imports_all_dimension_layer_state_records(): void
    {
        $registry = new RiasecDeepCopySlotRegistry;
        $slots = $registry->layer140qAssetSlots();

        $this->assertCount(126, $slots);

        foreach ($slots as $slotName => $slot) {
            $this->assertSame($slotName, $slot['slot_name']);
            $this->assertSame('140q_layer_copy', $slot['slot_group']);
            $this->assertSame('authored', $slot['content_status']);
            $this->assertSame('reviewed_content_copy', $slot['source_status']);
            $this->assertSame('content_review', $slot['review_status']);
            $this->assertSame('expert_reviewed', $slot['evidence_level']);
            $this->assertSame('riasec_140q_task_environment_role_v1.zh-CN', $slot['content_version']);
            $this->assertFalse($slot['frontend_fallback_allowed']);
            $this->assertContains($slot['layer_dimension'], RiasecDeepCopySlotRegistry::DIMENSIONS);
            $this->assertContains($slot['layer'], RiasecDeepCopySlotRegistry::LAYER_140Q_DIMENSION_LAYERS);
            $this->assertContains($slot['layer_state'], RiasecDeepCopySlotRegistry::LAYER_140Q_STATES);

            foreach ($registry->layer140qRequiredFields() as $requiredField) {
                $this->assertArrayHasKey($requiredField, $slot);
                $this->assertNotEmpty($slot[$requiredField]);
            }

            $this->assertSame([], $registry->validateSlot($slot), '140Q layer slot '.$slotName.' should be contract-clean.');
        }
    }

    public function test_140q_dimension_layer_slot_resolves_task_environment_and_role_cards(): void
    {
        $registry = new RiasecDeepCopySlotRegistry;

        $task = $registry->resolve140qDimensionLayerSlot('R', 'task', 'agreement');
        $environment = $registry->resolve140qDimensionLayerSlot('R', 'environment', 'agreement');
        $role = $registry->resolve140qDimensionLayerSlot('R', 'role', 'agreement');

        $this->assertSame('140q_task_card_copy', $task['slot_key']);
        $this->assertSame('R_task_agreement', $task['slot_name']);
        $this->assertSame('140q_environment_card_copy', $environment['slot_key']);
        $this->assertSame('R_environment_agreement', $environment['slot_name']);
        $this->assertSame('140q_role_card_copy', $role['slot_key']);
        $this->assertSame('R_role_agreement', $role['slot_name']);
    }

    public function test_140q_asset_preserves_more_specific_not_more_accurate_boundary(): void
    {
        $registry = new RiasecDeepCopySlotRegistry;
        $slot = $registry->resolve140qDimensionLayerSlot('I', 'task', 'tension');

        $this->assertSame('authored', $slot['content_status']);
        $this->assertStringContainsString('更具体', $slot['summary']);
        $this->assertStringContainsString('不覆盖 60Q', $slot['summary']);
        $this->assertStringContainsString('不比较原始分', $slot['summary']);
        $this->assertStringNotContainsString('更准确', $slot['summary']);
        $this->assertStringNotContainsString('推翻', $slot['summary']);
    }

    public function test_140q_unknown_dimension_layer_slot_fails_closed(): void
    {
        $slot = (new RiasecDeepCopySlotRegistry)->resolve140qDimensionLayerSlot('Z', 'task', 'agreement');

        $this->assertSame('unavailable', $slot['content_status']);
        $this->assertSame('omitted', $slot['module_state']);
        $this->assertSame('omit_module', $slot['fallback_behavior']);
        $this->assertFalse($slot['frontend_fallback_allowed']);
    }
}
