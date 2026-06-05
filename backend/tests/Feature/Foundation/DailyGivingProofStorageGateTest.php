<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use App\Models\DailyGivingRecord;
use Tests\TestCase;

final class DailyGivingProofStorageGateTest extends TestCase
{
    public function test_storage_gate_accepts_private_raw_proof_and_reviewed_redacted_public_proof(): void
    {
        $record = new DailyGivingRecord([
            'proof_status' => DailyGivingRecord::PROOF_REDACTED_AVAILABLE,
            'proof_private_path' => 'daily-giving/private/2026-06-05/raw-receipt.pdf',
            'proof_public_url' => 'https://media.fermatmind.com/foundation/daily-giving/public/redacted-2026-06-05.pdf',
        ]);

        $this->assertSame([], $record->proofStorageGateViolations());
    }

    public function test_storage_gate_rejects_public_raw_proof_paths_and_private_public_urls(): void
    {
        $record = new DailyGivingRecord([
            'proof_status' => DailyGivingRecord::PROOF_REDACTED_AVAILABLE,
            'proof_private_path' => 'https://media.fermatmind.com/raw-receipt.pdf',
            'proof_public_url' => 'https://media.fermatmind.com/foundation/daily-giving/private/raw-receipt.pdf',
        ]);

        $violations = $record->proofStorageGateViolations();

        $this->assertContains('proof_private_path must point to a private disk/bucket path, not a public URL/path', $violations);
        $this->assertContains('proof_public_url must point to reviewed redacted public proof only', $violations);
    }

    public function test_public_url_requires_redacted_available_and_withheld_requires_reason(): void
    {
        $recordWithPublicUrl = new DailyGivingRecord([
            'proof_status' => DailyGivingRecord::PROOF_REDACTED_PENDING,
            'proof_public_url' => 'https://media.fermatmind.com/foundation/daily-giving/public/redacted-2026-06-05.pdf',
        ]);

        $this->assertContains('proof_public_url requires proof_status=redacted_available', $recordWithPublicUrl->proofStorageGateViolations());

        $withheld = new DailyGivingRecord([
            'proof_status' => DailyGivingRecord::PROOF_WITHHELD,
        ]);

        $this->assertContains('withheld proof requires admin-only proof_redaction_notes reviewer reason', $withheld->proofStorageGateViolations());
    }

    public function test_public_projection_never_returns_private_proof_fields(): void
    {
        $record = new DailyGivingRecord([
            'record_code' => 'FM-GIVING-2026-06-001',
            'proof_status' => DailyGivingRecord::PROOF_REDACTED_AVAILABLE,
            'proof_public_url' => 'https://media.fermatmind.com/foundation/daily-giving/public/redacted-2026-06-05.pdf',
            'proof_private_path' => 'daily-giving/private/2026-06-05/raw-receipt.pdf',
            'proof_redaction_notes' => 'Private reviewer note.',
            'receipt_reference_private' => 'private-receipt-id',
            'internal_notes' => 'Private internal note.',
            'created_by_admin_user_id' => 1,
            'updated_by_admin_user_id' => 1,
        ]);

        $public = $record->toPublicArray();

        foreach (['proof_private_path', 'proof_redaction_notes', 'receipt_reference_private', 'internal_notes', 'created_by_admin_user_id', 'updated_by_admin_user_id'] as $field) {
            $this->assertArrayNotHasKey($field, $public);
        }
    }
}
