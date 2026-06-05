<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use Tests\TestCase;

class FoundationClaimBoundaryTest extends TestCase
{
    private function artifact(): array
    {
        $path = dirname(__DIR__, 3).'/docs/operations/generated/foundation-claim-boundary.v1.json';

        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_foundation_claim_boundary_blocks_official_relationship_and_impact_claims(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('foundation_claim_boundary.v1', $artifact['version']);
        $this->assertFalse($artifact['publishable_copy_created']);

        foreach ([
            'UN official partner',
            '联合国官方合作',
            'official endorsement',
            '官方背书',
            'certified by',
            '官方认证',
            'guaranteed impact',
            'formal affiliation',
            'authorized by UN',
            'fundraising for UN',
            'registered foundation',
            'stable daily giving before public records exist',
        ] as $claim) {
            $this->assertContains($claim, $artifact['forbidden_claims']);
        }
    }

    public function test_claims_require_evidence_before_public_records_can_be_amplified(): void
    {
        $artifact = $this->artifact();

        $this->assertContains('public_api_records_count_greater_than_zero', $artifact['evidence_required_claims']['public_ledger_exists']);
        $this->assertContains('proof_gate_passed', $artifact['evidence_required_claims']['public_proof_available']);
        $this->assertContains('stable daily giving operation', $artifact['before_public_records']['forbidden']);
        $this->assertContains('trust badge', $artifact['before_public_records']['forbidden']);
        $this->assertContains('trust badge without separate readiness', $artifact['after_public_records']['still_forbidden']);
    }

    public function test_social_sync_and_withheld_proof_do_not_unlock_badges_or_amplification(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('manual_only', $artifact['social_sync_constraints']['mode']);
        $this->assertFalse($artifact['social_sync_constraints']['automatic_posting_allowed']);
        $this->assertFalse($artifact['social_sync_constraints']['credential_handling_allowed']);
        $this->assertTrue($artifact['proof_withheld_constraints']['withheld_allowed']);
        $this->assertTrue($artifact['proof_withheld_constraints']['reviewer_reason_required']);
        $this->assertFalse($artifact['proof_withheld_constraints']['can_power_trust_badge']);
        $this->assertFalse($artifact['proof_withheld_constraints']['can_support_high_amplification']);
        $this->assertTrue($artifact['validation']['daily_giving_noindex_retained']);
    }
}
