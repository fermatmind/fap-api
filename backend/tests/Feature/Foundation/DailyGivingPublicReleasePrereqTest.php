<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use Tests\TestCase;

final class DailyGivingPublicReleasePrereqTest extends TestCase
{
    public function test_release_prereq_artifact_keeps_execution_non_mutating(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'record_created',
            'proof_uploaded',
            'proof_processed',
            'cms_mutation_performed',
            'publish_performed',
            'search_submission_performed',
            'deploy_performed',
            'trust_badge_allowed_now',
            'public_amplification_allowed_now',
        ] as $field) {
            $this->assertFalse($artifact[$field], $field);
        }

        $this->assertSame('DAILY-GIVING-FIRST-RECORD-PRIVATE-LEDGER-01', $artifact['next_authorization_required']);
    }

    public function test_public_release_requires_records_months_and_safe_public_projection(): void
    {
        $artifact = $this->artifact();
        $api = $artifact['required_public_api_gates'];

        $this->assertSame(1, $api['records_api_min_count']);
        $this->assertSame(1, $api['months_api_min_count']);
        $this->assertSame(1, $api['completed_or_verified_public_records_min_count']);
        $this->assertSame(1, $api['verified_public_records_min_count_for_trust_badge_consideration']);

        foreach (['proof_private_path', 'proof_redaction_notes', 'receipt_reference_private', 'internal_notes', 'created_by_admin_user_id', 'updated_by_admin_user_id'] as $field) {
            $this->assertContains($field, $api['forbidden_public_fields']);
        }
    }

    public function test_release_requires_noindex_and_sitemap_llms_exclusion(): void
    {
        $indexability = $this->artifact()['required_indexability_gates'];

        $this->assertTrue($indexability['daily_giving_page_noindex']);
        $this->assertFalse($indexability['daily_giving_sitemap_inclusion_allowed']);
        $this->assertFalse($indexability['daily_giving_llms_inclusion_allowed']);
    }

    public function test_claim_lint_forbids_endorsement_and_guaranteed_impact_claims(): void
    {
        $forbidden = $this->artifact()['required_claim_lint_forbidden_claims'];

        foreach (['UN official partner', '联合国官方合作', 'official endorsement', 'guaranteed impact'] as $claim) {
            $this->assertContains($claim, $forbidden);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/operations/generated/daily-giving-public-release-prereq.v1.json');
        $payload = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($payload);

        return $payload;
    }
}
