<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use Tests\TestCase;

final class DailyGivingFirstRecordReviewTemplateTest extends TestCase
{
    public function test_first_record_review_template_blocks_all_runtime_mutations(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'production_record_created',
            'proof_uploaded',
            'proof_processed',
            'private_proof_path_created',
            'redacted_public_proof_created',
            'cms_mutation_performed',
            'publish_performed',
            'search_submission_performed',
            'social_distribution_performed',
            'payment_provider_call_performed',
            'deploy_performed',
            'trust_badge_allowed_now',
            'public_amplification_allowed_now',
            'daily_giving_indexable_allowed_now',
            'daily_giving_sitemap_inclusion_allowed_now',
            'daily_giving_llms_inclusion_allowed_now',
        ] as $field) {
            $this->assertFalse($artifact[$field], $field);
        }
    }

    public function test_first_record_review_template_keeps_initial_record_private_and_noindex(): void
    {
        $artifact = $this->artifact();

        $this->assertSame(['planned'], $artifact['initial_private_record_state']['donation_status_allowed']);
        $this->assertSame(['none', 'redacted_pending'], $artifact['initial_private_record_state']['proof_status_allowed']);
        $this->assertFalse($artifact['initial_private_record_state']['is_public']);
        $this->assertFalse($artifact['initial_private_record_state']['is_indexable']);
        $this->assertNull($artifact['initial_private_record_state']['published_at']);
        $this->assertSame('DAILY-GIVING-FIRST-RECORD-PRIVATE-LEDGER-01', $artifact['next_authorization_required']);
        $this->assertTrue($artifact['authorization_prompt_required']);
    }

    public function test_first_record_review_template_preserves_claim_and_public_field_boundaries(): void
    {
        $artifact = $this->artifact();
        $doc = (string) file_get_contents(base_path('docs/operations/daily-giving-first-record-review-template.md'));

        foreach ([
            'official_relationship_claim_allowed',
            'endorsement_claim_allowed',
            'certification_claim_allowed',
            'guaranteed_impact_claim_allowed',
            'unsupported_stable_daily_operation_claim_allowed',
        ] as $field) {
            $this->assertFalse($artifact['claim_boundary'][$field], $field);
        }

        foreach ([
            'proof_private_path',
            'proof_redaction_notes',
            'receipt_reference_private',
            'internal_notes',
            'created_by_admin_user_id',
            'updated_by_admin_user_id',
        ] as $field) {
            $this->assertContains($field, $artifact['forbidden_public_fields']);
        }

        foreach ([
            'UN official partner',
            'United Nations official partner',
            'official endorsement',
            'guaranteed impact',
            'officially certified',
        ] as $forbiddenClaim) {
            $this->assertStringNotContainsString($forbiddenClaim, $doc);
        }
    }

    public function test_first_record_review_template_tracks_operator_intent_without_creating_record(): void
    {
        $artifact = $this->artifact();

        $this->assertSame(1000, $artifact['operator_intent']['planned_amount_minor']);
        $this->assertSame('CNY', $artifact['operator_intent']['planned_currency']);
        $this->assertSame('United Nations Foundation', $artifact['operator_intent']['recipient_name']);
        $this->assertSame('recipient_only', $artifact['operator_intent']['recipient_role']);
        $this->assertTrue($artifact['operator_intent']['requires_receipt_confirmation']);
        $this->assertFalse($artifact['operator_intent']['official_relationship_claim_allowed']);
        $this->assertFalse($artifact['production_record_created']);
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/operations/generated/daily-giving-first-record-review-template.v1.json');
        $payload = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($payload);

        return $payload;
    }
}
