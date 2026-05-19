<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Riasec;

use App\Services\Riasec\RiasecDeepCopySlotRegistry;
use PHPUnit\Framework\TestCase;

final class RiasecTop3ChainSlotRegistryTest extends TestCase
{
    public function test_top3_chain_contract_covers_all_unordered_combos(): void
    {
        $registry = new RiasecDeepCopySlotRegistry;
        $slots = $registry->top3ChainSlots();

        $this->assertSame(RiasecDeepCopySlotRegistry::TOP3_COMBOS, array_keys($slots));

        foreach ($slots as $top3Key => $slot) {
            $this->assertSame('triad_blend_copy', $slot['slot_key']);
            $this->assertSame($top3Key, $slot['top3_key']);
            $this->assertFalse($slot['frontend_fallback_allowed']);

            foreach ($registry->top3ChainRequiredFields() as $requiredField) {
                $this->assertArrayHasKey($requiredField, $slot);
                $this->assertNotEmpty($slot[$requiredField]);
            }

            $this->assertSame([], $registry->validateSlot($slot), 'Top3 chain '.$top3Key.' should be contract-clean.');
        }
    }

    public function test_all_top3_chains_have_authored_runtime_copy_from_file_backed_asset(): void
    {
        $registry = new RiasecDeepCopySlotRegistry;

        foreach (RiasecDeepCopySlotRegistry::TOP3_COMBOS as $top3Key) {
            $slot = $registry->resolveTop3ChainSlot($top3Key);

            $this->assertSame('authored', $slot['content_status']);
            $this->assertSame('reviewed_content_copy', $slot['source_status']);
            $this->assertSame('content_review', $slot['review_status']);
            $this->assertSame('expert_reviewed', $slot['evidence_level']);
            $this->assertSame('riasec_top3_code_chain_strategy_v1.zh-CN', $slot['content_version']);

            foreach ($registry->authoredTop3ChainRequiredFields() as $requiredField) {
                $this->assertArrayHasKey($requiredField, $slot);
                $this->assertNotEmpty($slot[$requiredField]);
            }

            $this->assertSame([], $registry->validateSlot($slot));
        }
    }

    public function test_top3_key_selection_is_deterministic_for_unordered_fixture(): void
    {
        $registry = new RiasecDeepCopySlotRegistry;

        $this->assertSame('R_I_A', $registry->resolveTop3ChainSlot(['A', 'R', 'I'])['top3_key']);
        $this->assertSame('R_S_C', $registry->resolveTop3ChainSlot('C×S×R')['top3_key']);
        $this->assertSame('I_E_C', $registry->resolveTop3ChainSlot('E-I-C')['top3_key']);
    }

    public function test_top3_chain_rejects_identity_career_and_success_claims(): void
    {
        $registry = new RiasecDeepCopySlotRegistry;
        $slot = $registry->resolveTop3ChainSlot('R_I_A');
        $slot['body'] = '你就是某类人，职业推荐很明确，职业成功概率很高。';

        $errors = $registry->validateSlot($slot);

        $this->assertContains('forbidden_claim_phrase_non_ascii', $errors);
    }

    public function test_unknown_top3_chain_fails_closed_without_frontend_fallback(): void
    {
        $slot = (new RiasecDeepCopySlotRegistry)->resolveTop3ChainSlot('R_I_Z');

        $this->assertSame('unavailable', $slot['content_status']);
        $this->assertSame('omitted', $slot['module_state']);
        $this->assertSame('omit_module', $slot['fallback_behavior']);
        $this->assertFalse($slot['frontend_fallback_allowed']);
    }
}
