<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use App\Models\DailyGivingRecord;
use Tests\TestCase;

final class DailyGivingRedactedPublicProofTest extends TestCase
{
    public function test_artifact_keeps_runtime_and_public_amplification_actions_blocked(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'redacted_public_proof_created',
            'proof_uploaded',
            'production_record_mutated',
            'cms_mutation_performed',
            'publish_performed',
            'indexability_enabled',
            'search_submission_performed',
            'social_distribution_performed',
            'deploy_performed',
        ] as $field) {
            $this->assertFalse($artifact['runtime_actions'][$field], $field);
        }

        $this->assertFalse($artifact['claim_boundaries']['trust_badge_allowed_now']);
        $this->assertFalse($artifact['claim_boundaries']['public_amplification_allowed_now']);
        $this->assertSame('reviewed_redacted_public_media_url_exists', $artifact['blocked_until']);
    }

    public function test_artifact_requires_redaction_of_sensitive_proof_fields(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'full_receipt_id',
            'full_transaction_serial',
            'account_card_wallet_or_payment_account_details',
            'balance',
            'auth_token',
            'session_id',
            'private_receipt_url',
            'local_device_ui_metadata',
            'private_local_paths',
            'admin_comments',
        ] as $field) {
            $this->assertContains($field, $artifact['required_redactions']);
        }
    }

    public function test_artifact_matches_storage_gate_for_public_proof_url_shape(): void
    {
        $artifact = $this->artifact();
        $gate = $artifact['proof_public_url_gate'];

        $this->assertTrue($gate['requires_https']);
        $this->assertTrue($gate['requires_reviewed_redacted_artifact']);
        $this->assertSame(DailyGivingRecord::PROOF_REDACTED_AVAILABLE, $gate['requires_proof_status']);
        $this->assertTrue($gate['must_not_equal_proof_private_path']);
        $this->assertContains('redacted', $gate['accepted_shape_markers']);
        $this->assertContains('/media/', $gate['accepted_shape_markers']);
        $this->assertContains('/private/', $gate['forbidden_markers']);
        $this->assertContains('raw-receipt', $gate['forbidden_markers']);
    }

    public function test_model_gate_accepts_reviewed_redacted_public_media_url_only(): void
    {
        $safeRecord = new DailyGivingRecord([
            'proof_status' => DailyGivingRecord::PROOF_REDACTED_AVAILABLE,
            'proof_private_path' => 'daily-giving/private/2026-06-05/raw-receipt.pdf',
            'proof_public_url' => 'https://media.fermatmind.com/foundation/daily-giving/public/redacted-2026-06-05.pdf',
        ]);

        $this->assertSame([], $safeRecord->proofStorageGateViolations());

        $unsafeRecord = new DailyGivingRecord([
            'proof_status' => DailyGivingRecord::PROOF_REDACTED_AVAILABLE,
            'proof_private_path' => 'daily-giving/private/2026-06-05/raw-receipt.pdf',
            'proof_public_url' => 'https://media.fermatmind.com/foundation/daily-giving/private/raw-receipt-2026-06-05.pdf',
        ]);

        $this->assertContains('proof_public_url must point to reviewed redacted public proof only', $unsafeRecord->proofStorageGateViolations());
    }

    public function test_public_api_forbidden_fields_remain_private_only(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'proof_private_path',
            'proof_redaction_notes',
            'receipt_reference_private',
            'internal_notes',
            'created_by_admin_user_id',
            'updated_by_admin_user_id',
        ] as $field) {
            $this->assertContains($field, $artifact['public_api_forbidden_fields']);
        }
    }

    public function test_no_endorsement_or_official_partnership_claims_are_allowed(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'unicef_or_un_endorsement',
            'official_partnership',
            'certification',
            'guaranteed_impact',
        ] as $claim) {
            $this->assertFalse($artifact['claim_boundaries'][$claim], $claim);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/operations/generated/daily-giving-redacted-public-proof.v1.json');
        $payload = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($payload);

        return $payload;
    }
}
