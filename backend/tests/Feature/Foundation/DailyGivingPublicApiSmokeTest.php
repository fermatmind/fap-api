<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use App\Models\DailyGivingRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DailyGivingPublicApiSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_api_smoke_returns_fixture_record_month_and_no_private_fields(): void
    {
        $record = DailyGivingRecord::factory()->verified()->create([
            'record_code' => 'FM-GIVING-2026-06-SMOKE',
            'donation_date' => '2026-06-05',
            'proof_status' => DailyGivingRecord::PROOF_REDACTED_AVAILABLE,
            'proof_public_url' => 'https://media.fermatmind.com/foundation/daily-giving/public/redacted-smoke-2026-06-05.pdf',
            'proof_private_path' => 'daily-giving/private/2026-06-05/raw-receipt-smoke.pdf',
            'proof_redaction_notes' => 'Test fixture reviewer note.',
            'receipt_reference_private' => 'PRIVATE-SMOKE-REF',
            'internal_notes' => 'Test fixture internal note.',
            'created_by_admin_user_id' => 1,
            'updated_by_admin_user_id' => 2,
            'is_public' => true,
            'is_indexable' => false,
            'published_at' => now()->subMinute(),
        ]);

        $index = $this->getJson('/api/v0.5/foundation/giving-records');
        $index->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('items.0.record_code', $record->record_code);

        $months = $this->getJson('/api/v0.5/foundation/giving-records/months');
        $months->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('months.0', '2026-06');

        $show = $this->getJson("/api/v0.5/foundation/giving-records/{$record->record_code}");
        $show->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('record.record_code', $record->record_code)
            ->assertJsonPath('record.donation_status', DailyGivingRecord::DONATION_VERIFIED)
            ->assertJsonPath('record.proof_status', DailyGivingRecord::PROOF_REDACTED_AVAILABLE);

        $publicRecord = $show->json('record');

        foreach ($this->forbiddenPublicFields() as $field) {
            $this->assertArrayNotHasKey($field, $publicRecord);
        }
    }

    public function test_public_api_smoke_artifact_keeps_runtime_actions_blocked(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'production_record_created',
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

        $this->assertSame('DAILY-GIVING-INDEXABILITY-GATE-01', $artifact['next_pr']);
    }

    public function test_public_api_smoke_artifact_matches_private_field_contract(): void
    {
        $artifact = $this->artifact();

        foreach ($this->forbiddenPublicFields() as $field) {
            $this->assertContains($field, $artifact['forbidden_public_fields']);
        }
    }

    /**
     * @return list<string>
     */
    private function forbiddenPublicFields(): array
    {
        return [
            'proof_private_path',
            'proof_redaction_notes',
            'receipt_reference_private',
            'internal_notes',
            'created_by_admin_user_id',
            'updated_by_admin_user_id',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/operations/generated/daily-giving-public-api-smoke.v1.json');
        $payload = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($payload);

        return $payload;
    }
}
