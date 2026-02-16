<?php

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'order_no',
        'source_order_id',
        'source_event_id',
        'meta_json',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'expires_at' => 'datetime',
        'meta_json' => 'array',
    ];

    public static function allowOrgZeroContext(): bool
    {
        return true;
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(Attempt::class, 'attempt_id', 'id');
    }
}
