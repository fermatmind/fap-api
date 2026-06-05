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

    public function test_raw_proof_is_private_and_public_proof_is_separate_redacted_media(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('daily_giving_proof_redaction_sop.v1', $artifact['version']);
        $this->assertFalse($artifact['record_created']);
        $this->assertFalse($artifact['proof_uploaded']);
        $this->assertFalse($artifact['proof_processed']);
        $this->assertFalse($artifact['publish_performed']);

        $this->assertSame('private_disk_or_private_bucket', $artifact['storage_classes']['raw_proof']['storage']);
        $this->assertFalse($artifact['storage_classes']['raw_proof']['public_access']);
        $this->assertTrue($artifact['storage_classes']['redacted_public_proof']['must_be_separate_from_raw_proof']);
    }

    public function test_sensitive_fields_and_private_api_fields_are_forbidden_publicly(): void
    {
        $artifact = $this->artifact();

        foreach (['donor_email', 'payment_account_id', 'full_transaction_number', 'auth_token', 'private_receipt_url'] as $field) {
            $this->assertContains($field, $artifact['redaction_required_fields']);
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
