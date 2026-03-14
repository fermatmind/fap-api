<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ReferralRewardIssuance extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'referral_reward_issuances';

    protected $fillable = [
        'id',
        'org_id',
        'compare_invite_id',
        'share_id',
        'trigger_order_no',
        'inviter_attempt_id',
        'invitee_attempt_id',
        'inviter_user_id',
        'invitee_user_id',
        'inviter_anon_id',
        'invitee_anon_id',
        'reward_sku',
        'reward_quantity',
        'status',
        'reason_code',
        'attribution_json',
        'granted_at',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'reward_quantity' => 'integer',
        'attribution_json' => 'array',
        'granted_at' => 'datetime',
    ];
}
