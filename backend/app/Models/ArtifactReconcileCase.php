<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArtifactReconcileCase extends Model
{
    protected $fillable = [
        'attempt_id',
        'slot_code',
        'case_type',
        'status',
        'suspected_cause',
        'opened_by',
        'assigned_to',
        'resolution_code',
        'resolution_notes',
        'payload_json',
        'opened_at',
        'resolved_at',
    ];

    protected $casts = [
        'opened_by' => 'integer',
        'assigned_to' => 'integer',
        'payload_json' => 'array',
        'opened_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];
}
