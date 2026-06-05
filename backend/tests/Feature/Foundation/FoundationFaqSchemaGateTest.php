<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use Tests\TestCase;

final class FoundationFaqSchemaGateTest extends TestCase
{
    private function artifact(): array
    {
        $path = dirname(__DIR__, 3).'/docs/operations/generated/foundation-faq-schema-gate.v1.json';

        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_faq_schema_is_blocked_without_visible_approved_answers(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('foundation_faq_schema_gate.v1', $artifact['version']);
        $this->assertFalse($artifact['faq_schema_allowed_now']);
        $this->assertFalse($artifact['faq_answers_created']);
        $this->assertFalse($artifact['jsonld_runtime_change_performed']);
        $this->assertFalse($artifact['cms_mutation_performed']);
        $this->assertFalse($artifact['publishable_copy_created']);

        $this->assertContains('visible_cms_backend_authoritative_faq_answers_exist', $artifact['gate_requirements']);
        $this->assertContains('faq_answers_reviewed_and_approved', $artifact['gate_requirements']);
        $this->assertContains('schema_entries_match_visible_answers', $artifact['gate_requirements']);
    }

    public function test_question_inventory_and_frontend_fallback_do_not_unlock_schema(): void
    {
        $artifact = $this->artifact();

        $this->assertTrue($artifact['current_state']['faq_question_inventory_allowed']);
        $this->assertFalse($artifact['schema_activation_policy']['question_inventory_unlocks_schema']);
        $this->assertFalse($artifact['schema_activation_policy']['frontend_fallback_unlocks_schema']);
        $this->assertTrue($artifact['schema_activation_policy']['visible_approved_cms_answers_required']);
        $this->assertTrue($artifact['schema_activation_policy']['claim_lint_required']);
    }

    public function test_gpt_outputs_are_limited_to_inventory_and_review_inputs(): void
    {
        $artifact = $this->artifact();

        $this->assertContains('faq_question_inventory', $artifact['gpt_allowed_outputs']);
        $this->assertContains('reviewer_checklist', $artifact['gpt_allowed_outputs']);

        foreach ([
            'final_faq_answers',
            'final_faqpage_jsonld',
            'cta_copy',
            'social_copy',
            'trust_badge_copy',
            'official_partnership_claim',
            'guaranteed_impact_claim',
            'stable_daily_giving_operation_claim',
        ] as $forbidden) {
            $this->assertContains($forbidden, $artifact['gpt_forbidden_outputs']);
        }
    }
}
