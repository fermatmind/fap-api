<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use Tests\TestCase;

final class FoundationCmsFieldMapTest extends TestCase
{
    private function artifact(): array
    {
        $path = dirname(__DIR__, 3).'/docs/operations/generated/foundation-cms-field-map.v1.json';

        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_existing_content_page_fields_cover_foundation_page_basics(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('foundation_cms_field_map.v1', $artifact['version']);
        $this->assertFalse($artifact['schema_change_performed']);
        $this->assertFalse($artifact['cms_mutation_performed']);
        $this->assertFalse($artifact['publishable_copy_created']);

        foreach (['slug', 'path', 'locale', 'canonical_path'] as $field) {
            $this->assertContains($field, $artifact['content_pages_existing_fields']['route_identity']);
        }

        foreach (['content_md', 'content_html', 'headings_json'] as $field) {
            $this->assertContains($field, $artifact['content_pages_existing_fields']['visible_body']);
        }

        foreach (['review_state', 'owner', 'legal_review_required', 'last_reviewed_at'] as $field) {
            $this->assertContains($field, $artifact['content_pages_existing_fields']['review']);
        }
    }

    public function test_governance_gaps_remain_explicit_operator_requirements(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'claim_boundary_version',
            'public_benefit_policy_version',
            'proof_policy_summary',
            'daily_giving_state_summary',
            'faq_schema_enabled',
            'operator_review_checklist',
        ] as $field) {
            $this->assertContains($field, $artifact['missing_dedicated_governance_fields']);
        }

        $this->assertTrue($artifact['gpt_constraints']['preserve_missing_fields_as_operator_requirements']);
        $this->assertTrue($artifact['gpt_constraints']['faq_questions_only_until_cms_approved_answers_exist']);
    }

    public function test_daily_giving_boundary_blocks_ledger_and_badge_claims(): void
    {
        $artifact = $this->artifact();

        $this->assertFalse($artifact['daily_giving_boundary']['stored_in_content_pages']);
        $this->assertSame('daily_giving_records', $artifact['daily_giving_boundary']['record_authority']);
        $this->assertContains('proof_private_path', $artifact['daily_giving_boundary']['relevant_fields']);
        $this->assertContains('proof_redaction_notes', $artifact['daily_giving_boundary']['relevant_fields']);
        $this->assertFalse($artifact['daily_giving_boundary']['foundation_may_claim_public_ledger_now']);
        $this->assertFalse($artifact['daily_giving_boundary']['foundation_may_claim_trust_badge_now']);
        $this->assertTrue($artifact['gpt_constraints']['daily_giving_noindex_state_required']);
        $this->assertTrue($artifact['gpt_constraints']['zero_public_record_state_required']);
    }
}
