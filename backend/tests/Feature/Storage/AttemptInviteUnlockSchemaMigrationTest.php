<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Support\Database\SchemaIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AttemptInviteUnlockSchemaMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_invite_unlock_tables_columns_and_indexes_exist(): void
    {
        $this->assertTrue(Schema::hasTable('attempt_invite_unlocks'));
        $this->assertTrue(Schema::hasTable('attempt_invite_unlock_completions'));

        foreach ([
            'id',
            'target_org_id',
            'invite_code',
            'target_attempt_id',
            'target_scale_code',
            'inviter_user_id',
            'inviter_anon_id',
            'status',
            'required_invitees',
            'completed_invitees',
            'qualification_rule_version',
            'expires_at',
            'meta_json',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('attempt_invite_unlocks', $column));
        }

        foreach ([
            'attempt_invite_unlocks_invite_code_unique',
            'attempt_invite_unlocks_target_org_attempt_unique',
            'attempt_invite_unlocks_target_org_id_idx',
            'attempt_invite_unlocks_target_scale_code_idx',
            'attempt_invite_unlocks_status_idx',
            'attempt_invite_unlocks_expires_at_idx',
        ] as $index) {
            $this->assertTrue(SchemaIndex::indexExists('attempt_invite_unlocks', $index));
        }

        foreach ([
            'id',
            'invite_id',
            'invite_code',
            'target_attempt_id',
            'invitee_attempt_id',
            'invitee_org_id',
            'invitee_user_id',
            'invitee_anon_id',
            'invitee_identity_key',
            'qualified',
            'qualified_reason',
            'qualification_status',
            'counted',
            'counted_identity_key',
            'idempotency_key',
            'meta_json',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('attempt_invite_unlock_completions', $column));
        }

        foreach ([
            'attempt_invite_unlock_completions_idempotency_key_unique',
            'attempt_invite_unlock_completions_invite_attempt_unique',
            'attempt_invite_unlock_completions_invite_identity_unique',
            'attempt_invite_unlock_completions_counted_identity_unique',
            'attempt_invite_unlock_completions_invite_id_idx',
            'attempt_invite_unlock_completions_invite_code_idx',
            'attempt_invite_unlock_completions_target_attempt_idx',
            'attempt_invite_unlock_completions_status_idx',
            'attempt_invite_unlock_completions_reason_idx',
        ] as $index) {
            $this->assertTrue(SchemaIndex::indexExists('attempt_invite_unlock_completions', $index));
        }
    }
}
