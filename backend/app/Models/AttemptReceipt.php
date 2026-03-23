<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttemptReceipt extends Model
{
    protected $table = 'attempt_receipts';

    protected $fillable = [
        'attempt_id',
        'seq',
        'receipt_type',
        'source_system',
        'source_ref',
        'actor_type',
        'actor_id',
        'idempotency_key',
        'payload_json',
        'occurred_at',
        'recorded_at',
    ];

    protected $casts = [
        'seq' => 'integer',
        'payload_json' => 'array',
        'occurred_at' => 'datetime',
        'recorded_at' => 'datetime',
    ];
}
