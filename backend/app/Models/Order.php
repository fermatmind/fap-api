<?php

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasOrgScope;

    protected $table = 'orders';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'order_no',
        'provider',
        'provider_order_id',
        'status',
        'amount_total',
        'amount_cents',
        'amount_refunded',
        'currency',
        'item_sku',
        'sku',
        'quantity',
        'user_id',
        'anon_id',
        'device_id',
        'org_id',
        'request_id',
        'created_ip',
        'idempotency_key',
        'target_attempt_id',
        'external_trade_no',
        'metadata',
        'meta_json',
        'paid_at',
        'fulfilled_at',
        'refunded_at',
    ];

    protected $casts = [
        'amount_total' => 'int',
        'amount_cents' => 'int',
        'amount_refunded' => 'int',
        'quantity' => 'int',
        'metadata' => 'array',
        'meta_json' => 'array',
        'paid_at' => 'datetime',
        'fulfilled_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    public function paymentEvents(): HasMany
    {
        return $this->hasMany(PaymentEvent::class, 'order_no', 'order_no');
    }

    public function benefitGrants(): HasMany
    {
        return $this->hasMany(BenefitGrant::class, 'order_no', 'order_no');
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(Attempt::class, 'target_attempt_id', 'id');
    }

    public static function allowOrgZeroContext(): bool
    {
        return true;
    }
}
