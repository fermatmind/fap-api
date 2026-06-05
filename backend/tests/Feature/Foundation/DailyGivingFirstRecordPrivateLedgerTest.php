<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use PHPUnit\Framework\TestCase;

final class DailyGivingFirstRecordPrivateLedgerTest extends TestCase
{
    public function test_private_ledger_artifact_records_first_private_draft_without_public_release(): void
    {
        $artifact = $this->artifact();
        $record = $artifact['private_draft_record'];

        $this->assertSame('FM-GIVING-2026-06-001', $record['record_code']);
        $this->assertSame('2026-06-05', $record['donation_date']);
        $this->assertSame('18:52:53', $record['donation_time_local']);
        $this->assertSame('联合国儿童基金会（UNICEF）', $record['recipient_name']);
        $this->assertSame('https://www.unicef.cn/', $record['recipient_official_url']);
        $this->assertSame(7500, $record['amount_minor']);
        $this->assertSame('CNY', $record['currency']);
        $this->assertSame('completed', $record['donation_status']);
        $this->assertFalse($record['verified']);
        $this->assertSame('operator_approved_pending', $record['proof_status']);
        $this->assertNull($record['proof_public_url']);
        $this->assertFalse($record['is_public']);
        $this->assertFalse($record['is_indexable']);
        $this->assertNull($record['published_at']);
    }

    public function test_private_execution_keeps_raw_proof_and_private_ledger_out_of_repository(): void
    {
        $artifact = $this->artifact();
        $execution = $artifact['private_execution'];

        $this->assertTrue($execution['raw_proof_copied_to_private_local_storage']);
        $this->assertTrue($execution['raw_transaction_proof_copied_to_private_local_storage']);
        $this->assertTrue($execution['raw_receipt_proof_copied_to_private_local_storage']);
        $this->assertTrue($execution['private_ledger_created_outside_repository']);

        foreach ([
            'raw_proof_committed',
            'raw_receipt_proof_committed',
            'private_ledger_committed',
            'receipt_id_committed',
            'transaction_serial_committed',
            'account_details_committed',
            'balance_committed',
            'local_device_metadata_committed',
            'production_database_record_created',
            'cms_mutation_performed',
            'deploy_performed',
        ] as $field) {
            $this->assertFalse($execution[$field], $field);
        }
    }

    public function test_public_release_and_claim_boundaries_remain_blocked(): void
    {
        $artifact = $this->artifact();

        foreach ($artifact['public_release'] as $field => $value) {
            $this->assertFalse($value, $field);
        }

        foreach ($artifact['claim_boundary'] as $field => $value) {
            $this->assertFalse($value, $field);
        }

        $this->assertSame('DAILY-GIVING-ORIGINAL-PUBLIC-PROOF-01', $artifact['next_authorization_required']);
    }

    public function test_committed_documents_do_not_leak_private_transaction_details(): void
    {
        $combined = $this->committedPublicText();

        foreach ([
            '/Users/rainie',
            'xwechat_files',
            'eca1115f898f1c4daa70009c585f7b1e',
            'ACQS',
            '621768',
            '1338',
            '6114063',
            '4,380.55',
            '4380.55',
            'proof_private_path',
            'receipt_reference_private',
            'proof_redaction_notes',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $combined, $forbidden);
        }
    }

    public function test_prior_operator_intent_is_superseded_by_actual_proof(): void
    {
        $artifact = $this->artifact();

        $this->assertSame(1000, $artifact['supersedes_prior_operator_intent']['planned_amount_minor']);
        $this->assertSame('United Nations Foundation', $artifact['supersedes_prior_operator_intent']['planned_recipient_name']);
        $this->assertStringContainsString('UNICEF', $artifact['supersedes_prior_operator_intent']['reason']);
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = $this->backendPath('docs/operations/generated/daily-giving-first-record-private-ledger.v1.json');
        $payload = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($payload);

        return $payload;
    }

    private function committedPublicText(): string
    {
        return implode("\n", [
            (string) file_get_contents($this->backendPath('docs/operations/daily-giving-first-record-private-ledger.md')),
            (string) file_get_contents($this->backendPath('docs/operations/generated/daily-giving-first-record-private-ledger.v1.json')),
        ]);
    }

    private function backendPath(string $path): string
    {
        return dirname(__DIR__, 3).'/'.$path;
    }
}
