<?php

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use HasOrgScope;

    public const PAYMENT_RECOVERY_PURPOSE = 'payment_recovery';

    public const PAYMENT_RECOVERY_TOKEN_TTL_SECONDS = 2592000;

    public const STATUS_CREATED = 'created';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_FULFILLED = 'fulfilled';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELED = 'canceled';

    public const STATUS_REFUNDED = 'refunded';

    public const PAYMENT_STATE_CREATED = 'created';

    public const PAYMENT_STATE_PENDING = 'pending';

    public const PAYMENT_STATE_PAID = 'paid';

    public const PAYMENT_STATE_FAILED = 'failed';

    public const PAYMENT_STATE_CANCELED = 'canceled';

    public const PAYMENT_STATE_EXPIRED = 'expired';

    public const PAYMENT_STATE_REFUNDED = 'refunded';

    public const GRANT_STATE_NOT_STARTED = 'not_started';

    public const GRANT_STATE_GRANTED = 'granted';

    public const GRANT_STATE_GRANT_FAILED = 'grant_failed';

    public const GRANT_STATE_REVOKED = 'revoked';

    protected $table = 'orders';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'order_no',
        'provider',
        'channel',
        'provider_app',
        'provider_order_id',
        'status',
        'payment_state',
        'grant_state',
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
        'scale_code_v2',
        'scale_uid',
        'external_trade_no',
        'provider_trade_no',
        'contact_email_hash',
        'external_user_ref',
        'metadata',
        'meta_json',
        'paid_at',
        'expired_at',
        'closed_at',
        'last_payment_event_at',
        'last_reconciled_at',
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
        'expired_at' => 'datetime',
        'closed_at' => 'datetime',
        'last_payment_event_at' => 'datetime',
        'last_reconciled_at' => 'datetime',
        'fulfilled_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    public static function paymentStateFromLegacyStatus(?string $status): string
    {
        return match (strtolower(trim((string) $status))) {
            self::STATUS_PENDING => self::PAYMENT_STATE_PENDING,
            self::STATUS_PAID,
            self::STATUS_FULFILLED => self::PAYMENT_STATE_PAID,
            self::STATUS_FAILED => self::PAYMENT_STATE_FAILED,
            self::STATUS_CANCELED,
            'cancelled' => self::PAYMENT_STATE_CANCELED,
            'expired' => self::PAYMENT_STATE_EXPIRED,
            self::STATUS_REFUNDED => self::PAYMENT_STATE_REFUNDED,
            default => self::PAYMENT_STATE_CREATED,
        };
    }

    public static function normalizePaymentState(?string $paymentState, ?string $legacyStatus = null): string
    {
        $normalized = strtolower(trim((string) $paymentState));
        $legacyResolved = self::paymentStateFromLegacyStatus($legacyStatus);

        return in_array($normalized, [
            self::PAYMENT_STATE_CREATED,
            self::PAYMENT_STATE_PENDING,
            self::PAYMENT_STATE_PAID,
            self::PAYMENT_STATE_FAILED,
            self::PAYMENT_STATE_CANCELED,
            self::PAYMENT_STATE_EXPIRED,
            self::PAYMENT_STATE_REFUNDED,
        ], true)
            ? ($normalized === self::PAYMENT_STATE_CREATED && $legacyResolved !== self::PAYMENT_STATE_CREATED
                ? $legacyResolved
                : $normalized)
            : $legacyResolved;
    }

    public static function normalizeGrantState(?string $grantState, ?string $legacyStatus = null): string
    {
        $normalized = strtolower(trim((string) $grantState));
        $legacyResolved = strtolower(trim((string) $legacyStatus)) === self::STATUS_FULFILLED
            ? self::GRANT_STATE_GRANTED
            : self::GRANT_STATE_NOT_STARTED;

        if (in_array($normalized, [
            self::GRANT_STATE_NOT_STARTED,
            self::GRANT_STATE_GRANTED,
            self::GRANT_STATE_GRANT_FAILED,
            self::GRANT_STATE_REVOKED,
        ], true)) {
            return $normalized === self::GRANT_STATE_NOT_STARTED && $legacyResolved === self::GRANT_STATE_GRANTED
                ? $legacyResolved
                : $normalized;
        }

        return $legacyResolved;
    }

    public static function normalizeChannel(?string $channel): ?string
    {
        $normalized = strtolower(trim((string) $channel));

        return match ($normalized) {
            'web', 'website', 'browser', 'h5' => 'web',
            'wechat', 'wechatpay', 'wechat_miniapp', 'wechat-miniapp', 'wx_miniapp', 'wx-miniapp', 'miniprogram', 'mini_program' => 'wechat_miniapp',
            'alipay', 'alipay_miniapp', 'alipay-miniapp' => 'alipay_miniapp',
            default => null,
        };
    }

    public function resolvedPaymentState(): string
    {
        return self::normalizePaymentState(
            $this->getAttribute('payment_state'),
            (string) $this->getAttribute('status')
        );
    }

    public function resolvedGrantState(): string
    {
        return self::normalizeGrantState(
            $this->getAttribute('grant_state'),
            (string) $this->getAttribute('status')
        );
    }

    public function paymentEvents(): HasMany
    {
        return $this->hasMany(PaymentEvent::class, 'order_no', 'order_no');
    }

    public function paymentAttempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class, 'order_no', 'order_no');
    }

    public function latestPaymentAttempt(): HasOne
    {
        return $this->hasOne(PaymentAttempt::class, 'order_no', 'order_no')
            ->ofMany('attempt_no', 'max');
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
        return self::allowOrgZeroWithResolvedContext();
    }
}
