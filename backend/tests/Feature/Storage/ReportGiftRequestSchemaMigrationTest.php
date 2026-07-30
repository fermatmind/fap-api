<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Support\Database\SchemaIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ReportGiftRequestSchemaMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_gift_requests_columns_and_indexes_exist(): void
    {
        $this->assertTrue(Schema::hasTable('report_gift_requests'));

        foreach ([
            'id',
            'public_token_hash',
            'org_id',
            'target_attempt_id',
            'recipient_user_id',
            'recipient_anon_id',
            'scale_code',
            'sku',
            'status',
            'expires_at',
            'purchased_order_id',
            'purchased_by_user_id',
            'purchased_by_anon_id',
            'fulfilled_at',
            'canceled_at',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('report_gift_requests', $column),
                sprintf('report_gift_requests should have column %s', $column)
            );
        }

        foreach ([
            'report_gift_requests_public_token_hash_unique',
            'report_gift_requests_purchased_order_id_unique',
            'report_gift_requests_org_id_idx',
            'report_gift_requests_target_attempt_id_idx',
            'report_gift_requests_status_idx',
            'report_gift_requests_expires_at_idx',
        ] as $index) {
            $this->assertTrue(
                SchemaIndex::indexExists('report_gift_requests', $index),
                sprintf('report_gift_requests should have index %s', $index)
            );
        }

        foreach ([
            'invite_code',
            'required_invitees',
            'completed_invitees',
            'meta_json',
        ] as $legacyInviteColumn) {
            $this->assertFalse(
                Schema::hasColumn('report_gift_requests', $legacyInviteColumn),
                sprintf('report_gift_requests should not reuse invite column %s', $legacyInviteColumn)
            );
        }
    }
}
