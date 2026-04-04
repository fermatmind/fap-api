<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AttemptInviteUnlock extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'attempt_invite_unlocks';

    protected $fillable = [
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
    ];

    protected $casts = [
        'target_org_id' => 'integer',
        'required_invitees' => 'integer',
        'completed_invitees' => 'integer',
        'expires_at' => 'datetime',
        'meta_json' => 'array',
    ];
}
