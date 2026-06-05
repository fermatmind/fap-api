<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use Tests\TestCase;

final class DailyGivingProofRedactionSopTest extends TestCase
{
    private function artifact(): array
    {
        $path = dirname(__DIR__, 3).'/docs/operations/generated/daily-giving-proof-redaction-sop.v1.json';

        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_private_proof_paths_stay_private_and_original_public_proof_is_allowed(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('daily_giving_proof_public_approval_sop.v1', $artifact['version']);
        $this->assertFalse($artifact['record_created']);
        $this->assertFalse($artifact['proof_uploaded']);
        $this->assertFalse($artifact['proof_processed']);
        $this->assertFalse($artifact['publish_performed']);

        $this->assertSame('private_disk_or_private_bucket', $artifact['storage_classes']['raw_proof']['storage']);
        $this->assertFalse($artifact['storage_classes']['raw_proof']['public_access']);
        $this->assertFalse($artifact['storage_classes']['operator_approved_public_proof']['separate_redacted_derivative_required']);
    }

    public function test_sensitive_fields_and_private_api_fields_are_forbidden_publicly(): void
    {
        $artifact = $this->artifact();

        foreach (['private_storage_path', 'backend_only_ledger_field', 'auth_token', 'private_receipt_url', 'signed_private_url'] as $field) {
            $this->assertContains($field, $artifact['public_forbidden_system_fields']);
        }

        foreach (['proof_private_path', 'proof_redaction_notes', 'receipt_reference_private', 'internal_notes', 'created_by_admin_user_id', 'updated_by_admin_user_id'] as $field) {
            $this->assertContains($field, $artifact['public_api_forbidden_fields']);
        }
    }

    public function test_withheld_proof_cannot_power_badges_or_amplification(): void
    {
        $artifact = $this->artifact();

        $this->assertTrue($artifact['storage_classes']['withheld_proof']['reviewer_reason_required']);
        $this->assertFalse($artifact['storage_classes']['withheld_proof']['can_power_trust_badge']);
        $this->assertFalse($artifact['storage_classes']['withheld_proof']['can_support_high_amplification']);
        $this->assertFalse($artifact['trust_badge_allowed']);
        $this->assertContains('is_indexable_false_retained', $artifact['release_review_required']);
    }
}
