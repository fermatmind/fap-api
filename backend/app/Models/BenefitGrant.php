<?php

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Model;

class BenefitGrant extends Model
{
    use HasOrgScope;

    protected $table = 'benefit_grants';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'org_id',
        'user_id',
        'benefit_code',
        'scope',
        'attempt_id',
        'status',
        'expires_at',
        'benefit_type',
        'benefit_ref',
        'source_order_id',
        'source_event_id',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'expires_at' => 'datetime',
    ];

    public static function allowOrgZeroContext(): bool
    {
        return true;
    }
}
