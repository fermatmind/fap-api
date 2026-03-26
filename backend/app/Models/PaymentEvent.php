<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentEvent extends Model
{
    use HasOrgScope;

    protected $table = 'payment_events';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'org_id',
        'provider',
        'provider_event_id',
        'order_id',
        'payment_attempt_id',
        'order_no',
        'event_type',
        'signature_ok',
        'status',
        'attempts',
        'last_error_code',
        'last_error_message',
        'processed_at',
        'handled_at',
        'handle_status',
        'reason',
        'requested_sku',
        'effective_sku',
        'scale_code_v2',
        'scale_uid',
        'entitlement_id',
        'payload_json',
        'payload_size_bytes',
        'payload_sha256',
        'payload_s3_key',
        'payload_excerpt',
        'received_at',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'signature_ok' => 'boolean',
        'attempts' => 'integer',
        'payload_json' => 'array',
        'payload_size_bytes' => 'integer',
        'processed_at' => 'datetime',
        'handled_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_no', 'order_no');
    }

    public function paymentAttempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class, 'payment_attempt_id', 'id');
    }

    public static function allowOrgZeroContext(): bool
    {
        return self::allowOrgZeroWithResolvedContext();
    }
}
