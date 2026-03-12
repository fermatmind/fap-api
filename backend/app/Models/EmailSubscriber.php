<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class EmailSubscriber extends Model
{
    use HasUuids;

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
        'marketing_consent',
        'transactional_recovery_enabled',
        'first_context_json',
        'last_context_json',
        'unsubscribed_at',
    ];

    protected $casts = [
        'marketing_consent' => 'bool',
        'transactional_recovery_enabled' => 'bool',
        'first_context_json' => 'array',
        'last_context_json' => 'array',
        'unsubscribed_at' => 'datetime',
    ];

    public function preference(): HasOne
    {
        return $this->hasOne(EmailPreference::class, 'subscriber_id', 'id');
    }
}
