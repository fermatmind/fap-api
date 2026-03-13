<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailPreference extends Model
{
    use HasUuids;

    protected $table = 'email_preferences';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'subscriber_id',
        'marketing_updates',
        'report_recovery',
        'product_updates',
    ];

    protected $casts = [
        'marketing_updates' => 'bool',
        'report_recovery' => 'bool',
        'product_updates' => 'bool',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function allowsMarketing(): bool
    {
        return (bool) ($this->marketing_updates || $this->product_updates);
    }

    public function allowsReportRecovery(): bool
    {
        return (bool) $this->report_recovery;
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(EmailSubscriber::class, 'subscriber_id', 'id');
    }
}
