<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Riasec;

use App\Services\Riasec\RiasecDeepCopySlotRegistry;
use PHPUnit\Framework\TestCase;

final class RiasecPairBlendSlotRegistryTest extends TestCase
{
    public function test_pair_blend_contract_covers_all_unordered_pairs(): void
    {
        $registry = new RiasecDeepCopySlotRegistry;
        $slots = $registry->pairBlendSlots();

        $this->assertSame(RiasecDeepCopySlotRegistry::PAIRS, array_keys($slots));

        foreach ($slots as $pairKey => $slot) {
            $this->assertSame('pair_blend_copy', $slot['slot_key']);
            $this->assertSame($pairKey, $slot['pair_key']);
            $this->assertFalse($slot['frontend_fallback_allowed']);

            foreach ($registry->pairRequiredFields() as $requiredField) {
                $this->assertArrayHasKey($requiredField, $slot);
                $this->assertNotEmpty($slot[$requiredField]);
            }

            $this->assertSame([], $registry->validateSlot($slot), 'Pair '.$pairKey.' should be contract-clean.');
        }
    }

    public function test_ias_pair_blends_have_authored_runtime_copy(): void
    {
        $registry = new RiasecDeepCopySlotRegistry;

        foreach (['I_A', 'I_S', 'A_S'] as $pairKey) {
            $slot = $registry->resolvePairBlendSlot($pairKey);

            $this->assertSame('authored', $slot['content_status']);
            $this->assertSame('reviewed_content_copy', $slot['source_status']);
            $this->assertSame('approved_for_staging', $slot['review_status']);
            $this->assertSame('expert_reviewed', $slot['evidence_level']);

            foreach ($registry->authoredPairRequiredFields() as $requiredField) {
                $this->assertArrayHasKey($requiredField, $slot);
                $this->assertNotEmpty($slot[$requiredField]);
            }

            $this->assertSame([], $registry->validateSlot($slot));
        }
    }

    public function test_non_authored_pairs_are_explicitly_pending_and_fail_closed(): void
    {
        $slot = (new RiasecDeepCopySlotRegistry)->resolvePairBlendSlot('R_E');

        $this->assertSame('pending', $slot['content_status']);
        $this->assertSame('omitted', $slot['module_state']);
        $this->assertSame('omit_module', $slot['fallback_behavior']);
        $this->assertSame('docs_only_candidate', $slot['source_status']);
        $this->assertFalse($slot['frontend_fallback_allowed']);
    }

    public function test_pair_key_selection_is_deterministic_for_ias_fixture(): void
    {
        $registry = new RiasecDeepCopySlotRegistry;

        $this->assertSame('I_A', $registry->resolvePairBlendSlot(['A', 'I'])['pair_key']);
        $this->assertSame('I_S', $registry->resolvePairBlendSlot('S×I')['pair_key']);
        $this->assertSame('A_S', $registry->resolvePairBlendSlot('A-S')['pair_key']);
    }

    public function test_pair_blend_rejects_identity_career_and_job_claims(): void
    {
        $registry = new RiasecDeepCopySlotRegistry;
        $slot = $registry->resolvePairBlendSlot('I_A');
        $slot['body'] = '你就是某类人，适合某职业，职业匹配很高，并且岗位胜任。';

        $errors = $registry->validateSlot($slot);

        $this->assertContains('forbidden_claim_phrase_non_ascii', $errors);
    }

    public function test_unknown_pair_fails_closed_without_frontend_fallback(): void
    {
        $slot = (new RiasecDeepCopySlotRegistry)->resolvePairBlendSlot('I_Z');

        $this->assertSame('unavailable', $slot['content_status']);
        $this->assertSame('omitted', $slot['module_state']);
        $this->assertSame('omit_module', $slot['fallback_behavior']);
        $this->assertFalse($slot['frontend_fallback_allowed']);
    }
}
