<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class MbtiCompareInvite extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'mbti_compare_invites';

    protected $fillable = [
        'id',
        'share_id',
        'inviter_attempt_id',
        'inviter_scale_code',
        'locale',
        'inviter_type_code',
        'invitee_attempt_id',
        'invitee_anon_id',
        'invitee_user_id',
        'invitee_order_no',
        'status',
        'meta_json',
        'accepted_at',
        'completed_at',
        'purchased_at',
    ];

    protected $casts = [
        'meta_json' => 'array',
        'invitee_user_id' => 'integer',
        'accepted_at' => 'datetime',
        'completed_at' => 'datetime',
        'purchased_at' => 'datetime',
    ];
}
