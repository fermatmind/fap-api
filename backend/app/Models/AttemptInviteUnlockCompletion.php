<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AttemptInviteUnlockCompletion extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'attempt_invite_unlock_completions';

    protected $fillable = [
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
    ];

    protected $casts = [
        'invitee_org_id' => 'integer',
        'qualified' => 'boolean',
        'counted' => 'boolean',
        'meta_json' => 'array',
    ];
}
