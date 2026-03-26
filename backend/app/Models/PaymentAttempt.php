<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentAttempt extends Model
{
    use HasOrgScope;

    public const STATE_INITIATED = 'initiated';

    public const STATE_PROVIDER_CREATED = 'provider_created';

    public const STATE_CLIENT_PRESENTED = 'client_presented';

    public const STATE_CALLBACK_RECEIVED = 'callback_received';

    public const STATE_VERIFIED = 'verified';

    public const STATE_PAID = 'paid';

    public const STATE_FAILED = 'failed';

    public const STATE_CANCELED = 'canceled';

    public const STATE_EXPIRED = 'expired';

    protected $table = 'payment_attempts';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'org_id',
        'order_id',
        'order_no',
        'attempt_no',
        'provider',
        'channel',
        'provider_app',
        'pay_scene',
        'state',
        'external_trade_no',
        'provider_trade_no',
        'provider_session_ref',
        'amount_expected',
        'currency',
        'payload_meta_json',
        'latest_payment_event_id',
        'initiated_at',
        'provider_created_at',
        'client_presented_at',
        'callback_received_at',
        'verified_at',
        'finalized_at',
        'last_error_code',
        'last_error_message',
        'meta_json',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'attempt_no' => 'integer',
        'amount_expected' => 'integer',
        'payload_meta_json' => 'array',
        'meta_json' => 'array',
        'initiated_at' => 'datetime',
        'provider_created_at' => 'datetime',
        'client_presented_at' => 'datetime',
        'callback_received_at' => 'datetime',
        'verified_at' => 'datetime',
        'finalized_at' => 'datetime',
    ];

    public static function normalizedState(?string $state): string
    {
        $normalized = strtolower(trim((string) $state));

        return in_array($normalized, [
            self::STATE_INITIATED,
            self::STATE_PROVIDER_CREATED,
            self::STATE_CLIENT_PRESENTED,
            self::STATE_CALLBACK_RECEIVED,
            self::STATE_VERIFIED,
            self::STATE_PAID,
            self::STATE_FAILED,
            self::STATE_CANCELED,
            self::STATE_EXPIRED,
        ], true)
            ? $normalized
            : self::STATE_INITIATED;
    }

    public static function isFinalState(?string $state): bool
    {
        return in_array(self::normalizedState($state), [
            self::STATE_PAID,
            self::STATE_FAILED,
            self::STATE_CANCELED,
            self::STATE_EXPIRED,
        ], true);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }

    public function latestPaymentEvent(): BelongsTo
    {
        return $this->belongsTo(PaymentEvent::class, 'latest_payment_event_id', 'id');
    }

    public function paymentEvents(): HasMany
    {
        return $this->hasMany(PaymentEvent::class, 'payment_attempt_id', 'id');
    }

    public static function allowOrgZeroContext(): bool
    {
        return self::allowOrgZeroWithResolvedContext();
    }
}
