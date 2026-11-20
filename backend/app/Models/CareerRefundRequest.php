<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CareerRefundRequest extends Model
{
    use HasUuids;

    protected $table = 'career_refund_requests';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    public function order(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_no', 'order_no')->withoutGlobalScopes()->where('org_id', 0);
    }

    protected $casts = [
        'amount_cents' => 'integer',
        'org_id' => 'integer',
        'submitted_at' => 'datetime',
        'checked_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];
}
