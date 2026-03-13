<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class EmailSubscriber extends Model
{
    use HasUuids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_UNSUBSCRIBED = 'unsubscribed';

    public const STATUS_SUPPRESSED = 'suppressed';

    protected $table = 'email_subscribers';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'pii_email_key_version',
        'email_enc',
        'email_hash',
        'locale',
        'first_source',
        'last_source',
        'status',
        'marketing_consent',
        'transactional_recovery_enabled',
        'first_context_json',
        'last_context_json',
        'first_captured_at',
        'last_captured_at',
        'last_marketing_consent_at',
        'last_transactional_recovery_change_at',
        'last_preferences_changed_at',
        'last_preferences_confirmation_sent_at',
        'last_unsubscribe_confirmation_sent_at',
        'last_lifecycle_email_sent_at',
        'unsubscribed_at',
    ];

    protected $casts = [
        'marketing_consent' => 'bool',
        'transactional_recovery_enabled' => 'bool',
        'first_context_json' => 'array',
        'last_context_json' => 'array',
        'first_captured_at' => 'datetime',
        'last_captured_at' => 'datetime',
        'last_marketing_consent_at' => 'datetime',
        'last_transactional_recovery_change_at' => 'datetime',
        'last_preferences_changed_at' => 'datetime',
        'last_preferences_confirmation_sent_at' => 'datetime',
        'last_unsubscribe_confirmation_sent_at' => 'datetime',
        'last_lifecycle_email_sent_at' => 'datetime',
        'unsubscribed_at' => 'datetime',
    ];

    public function allowsMarketing(): bool
    {
        return (bool) $this->marketing_consent;
    }

    public function allowsTransactionalRecovery(): bool
    {
        return (bool) $this->transactional_recovery_enabled;
    }

    public function preference(): HasOne
    {
        return $this->hasOne(EmailPreference::class, 'subscriber_id', 'id');
    }

    public function scopeOutsideLifecycleCooldown(Builder $query, CarbonInterface $threshold): Builder
    {
        return $query->where(function (Builder $builder) use ($threshold): void {
            $builder->whereNull('last_lifecycle_email_sent_at')
                ->orWhere('last_lifecycle_email_sent_at', '<=', $threshold);
        });
    }

    public function lifecycleOutboxUserId(): string
    {
        return 'subscriber_'.(string) $this->getKey();
    }

    public function recordLifecycleSend(string $templateKey, CarbonInterface $sentAt): void
    {
        $this->last_lifecycle_email_sent_at = $sentAt;

        if ($templateKey === 'preferences_updated') {
            $this->last_preferences_confirmation_sent_at = $sentAt;
        }

        if ($templateKey === 'unsubscribe_confirmation') {
            $this->last_unsubscribe_confirmation_sent_at = $sentAt;
        }
    }
}
