<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use App\Models\DailyGivingRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyGivingRecordPublicationGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_publishable_record_requires_all_fields(): void
    {
        $record = DailyGivingRecord::factory()->completed()->make([
            'recipient_official_url' => null,
            'is_public' => true,
            'published_at' => now(),
        ]);

        $this->assertFalse($record->isPublishable());
    }

    public function test_publishable_record_is_true_when_all_criteria_met(): void
    {
        $record = DailyGivingRecord::factory()->completed()->make();

        $this->assertTrue($record->isPublishable());
    }

    public function test_planned_record_is_not_publishable(): void
    {
        $record = DailyGivingRecord::factory()->planned()->make([
            'is_public' => true,
            'published_at' => now(),
        ]);

        $this->assertFalse($record->isPublishable());
    }

    public function test_voided_record_is_not_publishable(): void
    {
        $record = DailyGivingRecord::factory()->voided()->make([
            'is_public' => true,
            'published_at' => now(),
        ]);

        $this->assertFalse($record->isPublishable());
    }

    public function test_record_not_public_is_not_publishable(): void
    {
        $record = DailyGivingRecord::factory()->completed()->make([
            'is_public' => false,
            'published_at' => now(),
        ]);

        $this->assertFalse($record->isPublishable());
    }

    public function test_record_without_published_at_is_not_publishable(): void
    {
        $record = DailyGivingRecord::factory()->completed()->make([
            'is_public' => true,
            'published_at' => null,
        ]);

        $this->assertFalse($record->isPublishable());
    }

    public function test_record_with_future_published_at_is_not_publishable(): void
    {
        $record = DailyGivingRecord::factory()->completed()->make([
            'is_public' => true,
            'published_at' => now()->addDay(),
        ]);

        $this->assertFalse($record->isPublishable());
    }

    public function test_record_without_recipient_name_is_not_publishable(): void
    {
        $record = DailyGivingRecord::factory()->completed()->make([
            'recipient_name' => '',
            'is_public' => true,
            'published_at' => now(),
        ]);

        $this->assertFalse($record->isPublishable());
    }

    public function test_record_without_recipient_url_is_not_publishable(): void
    {
        $record = DailyGivingRecord::factory()->completed()->make([
            'recipient_official_url' => '',
            'is_public' => true,
            'published_at' => now(),
        ]);

        $this->assertFalse($record->isPublishable());
    }

    public function test_scope_published_public_excludes_voided(): void
    {
        $completed = DailyGivingRecord::factory()->completed()->create();
        DailyGivingRecord::factory()->voided()->create();

        $records = DailyGivingRecord::publishedPublic()->get();

        $this->assertCount(1, $records);
        $this->assertSame($completed->id, $records->first()->id);
    }

    public function test_scope_published_public_excludes_planned(): void
    {
        $completed = DailyGivingRecord::factory()->completed()->create();
        DailyGivingRecord::factory()->planned()->create();

        $records = DailyGivingRecord::publishedPublic()->get();

        $this->assertCount(1, $records);
        $this->assertSame($completed->id, $records->first()->id);
    }

    public function test_scope_published_public_excludes_not_public(): void
    {
        DailyGivingRecord::factory()->completed()->create();
        DailyGivingRecord::factory()->notPublic()->create();

        $records = DailyGivingRecord::publishedPublic()->get();

        $this->assertCount(1, $records);
    }

    public function test_scope_published_public_excludes_future_published(): void
    {
        DailyGivingRecord::factory()->completed()->create();
        DailyGivingRecord::factory()->completed()->create([
            'published_at' => now()->addDay(),
        ]);

        $records = DailyGivingRecord::publishedPublic()->get();

        $this->assertCount(1, $records);
    }

    public function test_verified_record_is_publishable(): void
    {
        $record = DailyGivingRecord::factory()->verified()->make();

        $this->assertTrue($record->isPublishable());
    }

    public function test_to_public_array_excludes_private_fields(): void
    {
        $record = DailyGivingRecord::factory()->completed()->make([
            'proof_private_path' => '/secret/path',
            'receipt_reference_private' => 'FULL-REF-999',
            'internal_notes' => 'secret',
            'proof_redaction_notes' => 'admin only',
            'created_by_admin_user_id' => 1,
            'updated_by_admin_user_id' => 2,
        ]);

        $public = $record->toPublicArray();

        $this->assertArrayHasKey('record_code', $public);
        $this->assertArrayHasKey('donation_date', $public);
        $this->assertArrayHasKey('recipient_name', $public);
        $this->assertArrayNotHasKey('proof_private_path', $public);
        $this->assertArrayNotHasKey('receipt_reference_private', $public);
        $this->assertArrayNotHasKey('internal_notes', $public);
        $this->assertArrayNotHasKey('proof_redaction_notes', $public);
        $this->assertArrayNotHasKey('created_by_admin_user_id', $public);
        $this->assertArrayNotHasKey('updated_by_admin_user_id', $public);
    }

    public function test_to_public_array_includes_all_allowed_fields(): void
    {
        $record = DailyGivingRecord::factory()->completed()->make();

        $public = $record->toPublicArray();

        $this->assertArrayHasKey('record_code', $public);
        $this->assertArrayHasKey('donation_date', $public);
        $this->assertArrayHasKey('recipient_name', $public);
        $this->assertArrayHasKey('recipient_official_url', $public);
        $this->assertArrayHasKey('amount_minor', $public);
        $this->assertArrayHasKey('currency', $public);
        $this->assertArrayHasKey('donation_status', $public);
        $this->assertArrayHasKey('proof_status', $public);
        $this->assertArrayHasKey('proof_public_url', $public);
        $this->assertArrayHasKey('receipt_reference_redacted', $public);
        $this->assertArrayHasKey('social_x_url', $public);
        $this->assertArrayHasKey('social_linkedin_url', $public);
        $this->assertArrayHasKey('social_weibo_url', $public);
        $this->assertArrayHasKey('social_xiaohongshu_url', $public);
        $this->assertArrayHasKey('social_other_links', $public);
        $this->assertArrayHasKey('public_notes', $public);
        $this->assertArrayHasKey('published_at', $public);
    }

    public function test_operator_approved_pending_proof_status_is_not_publishable(): void
    {
        $record = DailyGivingRecord::factory()->completed()->make([
            'proof_status' => DailyGivingRecord::PROOF_OPERATOR_APPROVED_PENDING,
            'is_public' => true,
            'published_at' => now(),
        ]);

        $this->assertFalse($record->isPublishable());
    }

    public function test_withheld_proof_status_is_publishable(): void
    {
        $record = DailyGivingRecord::factory()->completed()->make([
            'proof_status' => DailyGivingRecord::PROOF_WITHHELD,
            'is_public' => true,
            'published_at' => now(),
        ]);

        $this->assertTrue($record->isPublishable());
    }
}
