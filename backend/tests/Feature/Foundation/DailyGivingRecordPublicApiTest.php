<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use App\Models\DailyGivingRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyGivingRecordPublicApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_only_published_public_records(): void
    {
        $published = DailyGivingRecord::factory()->completed()->create([
            'donation_date' => now()->subDays(10),
        ]);
        DailyGivingRecord::factory()->planned()->create();
        DailyGivingRecord::factory()->voided()->create();
        DailyGivingRecord::factory()->notPublic()->create();

        $response = $this->getJson('/api/v0.5/foundation/giving-records');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.record_code', $published->record_code)
            ->assertJsonPath('pagination.total', 1);
    }

    public function test_index_excludes_voided_records(): void
    {
        DailyGivingRecord::factory()->completed()->create();
        DailyGivingRecord::factory()->voided()->create();

        $response = $this->getJson('/api/v0.5/foundation/giving-records');

        $response->assertOk()
            ->assertJsonCount(1, 'items');
    }

    public function test_index_excludes_planned_records(): void
    {
        DailyGivingRecord::factory()->completed()->create();
        DailyGivingRecord::factory()->planned()->create();

        $response = $this->getJson('/api/v0.5/foundation/giving-records');

        $response->assertOk()
            ->assertJsonCount(1, 'items');
    }

    public function test_index_supports_pagination(): void
    {
        DailyGivingRecord::factory()->count(25)->completed()->create();

        $response = $this->getJson('/api/v0.5/foundation/giving-records?per_page=10');

        $response->assertOk()
            ->assertJsonCount(10, 'items')
            ->assertJsonPath('pagination.per_page', 10)
            ->assertJsonPath('pagination.total', 25)
            ->assertJsonPath('pagination.last_page', 3);
    }

    public function test_show_returns_public_record(): void
    {
        $record = DailyGivingRecord::factory()->completed()->create();

        $response = $this->getJson("/api/v0.5/foundation/giving-records/{$record->record_code}");

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('record.record_code', $record->record_code)
            ->assertJsonPath('record.donation_status', $record->donation_status);
    }

    public function test_show_returns_404_for_non_public_record(): void
    {
        $record = DailyGivingRecord::factory()->planned()->create();

        $response = $this->getJson("/api/v0.5/foundation/giving-records/{$record->record_code}");

        $response->assertNotFound()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'NOT_FOUND');
    }

    public function test_show_returns_404_for_voided_record(): void
    {
        $record = DailyGivingRecord::factory()->voided()->create();

        $response = $this->getJson("/api/v0.5/foundation/giving-records/{$record->record_code}");

        $response->assertNotFound()
            ->assertJsonPath('ok', false);
    }

    public function test_show_never_exposes_private_fields(): void
    {
        $record = DailyGivingRecord::factory()->completed()->create([
            'proof_private_path' => '/private/proofs/secret.pdf',
            'receipt_reference_private' => 'FULL-REF-12345',
            'internal_notes' => 'secret admin note',
            'proof_redaction_notes' => 'redaction secret',
            'created_by_admin_user_id' => 42,
            'updated_by_admin_user_id' => 99,
        ]);

        $response = $this->getJson("/api/v0.5/foundation/giving-records/{$record->record_code}");

        $response->assertOk();
        $data = $response->json('record');

        $this->assertArrayNotHasKey('proof_private_path', $data);
        $this->assertArrayNotHasKey('receipt_reference_private', $data);
        $this->assertArrayNotHasKey('internal_notes', $data);
        $this->assertArrayNotHasKey('proof_redaction_notes', $data);
        $this->assertArrayNotHasKey('created_by_admin_user_id', $data);
        $this->assertArrayNotHasKey('updated_by_admin_user_id', $data);
    }

    public function test_show_exposes_allowed_public_fields(): void
    {
        $record = DailyGivingRecord::factory()->completed()->create([
            'proof_public_url' => 'https://media.fermatmind.com/foundation/daily-giving/public/original-2026-06-05.png',
            'receipt_reference_redacted' => 'REF-REDACTED',
            'social_x_url' => 'https://x.com/fermatmind/status/123',
            'social_linkedin_url' => 'https://linkedin.com/feed/update/456',
            'social_weibo_url' => 'https://weibo.com/789',
            'social_xiaohongshu_url' => 'https://xiaohongshu.com/explore/abc',
            'public_notes' => 'FermatMind independent giving record.',
        ]);

        $response = $this->getJson("/api/v0.5/foundation/giving-records/{$record->record_code}");

        $response->assertOk();
        $data = $response->json('record');

        $this->assertSame($record->proof_public_url, $data['proof_public_url']);
        $this->assertSame($record->receipt_reference_redacted, $data['receipt_reference_redacted']);
        $this->assertSame($record->social_x_url, $data['social_x_url']);
        $this->assertSame($record->public_notes, $data['public_notes']);
    }

    public function test_months_returns_distinct_months(): void
    {
        DailyGivingRecord::factory()->completed()->create(['donation_date' => '2026-06-15']);
        DailyGivingRecord::factory()->completed()->create(['donation_date' => '2026-06-20']);
        DailyGivingRecord::factory()->completed()->create(['donation_date' => '2026-05-10']);

        $response = $this->getJson('/api/v0.5/foundation/giving-records/months');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('months.0', '2026-06')
            ->assertJsonPath('months.1', '2026-05');
    }

    public function test_months_excludes_non_public_records(): void
    {
        DailyGivingRecord::factory()->completed()->create(['donation_date' => '2026-06-15']);
        DailyGivingRecord::factory()->planned()->create(['donation_date' => '2026-07-01']);

        $response = $this->getJson('/api/v0.5/foundation/giving-records/months');

        $response->assertOk()
            ->assertJsonMissing(['2026-07']);
    }

    public function test_month_records_returns_records_for_month(): void
    {
        DailyGivingRecord::factory()->completed()->create(['donation_date' => '2026-06-15', 'record_code' => 'FM-GIVING-2026-06-001']);
        DailyGivingRecord::factory()->completed()->create(['donation_date' => '2026-06-20', 'record_code' => 'FM-GIVING-2026-06-002']);
        DailyGivingRecord::factory()->completed()->create(['donation_date' => '2026-05-10']);

        $response = $this->getJson('/api/v0.5/foundation/giving-records/months/2026-06');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('month', '2026-06')
            ->assertJsonCount(2, 'items');
    }

    public function test_month_records_rejects_invalid_format(): void
    {
        $response = $this->getJson('/api/v0.5/foundation/giving-records/months/invalid');

        $response->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'INVALID_ARGUMENT');
    }
}
