<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArtifactLifecycleEvent extends Model
{
    protected $table = 'artifact_lifecycle_events';

    protected $fillable = [
        'job_id',
        'attempt_id',
        'artifact_slot_id',
        'event_type',
        'from_state',
        'to_state',
        'reason_code',
        'payload_json',
        'occurred_at',
    ];

    protected $casts = [
        'job_id' => 'integer',
        'artifact_slot_id' => 'integer',
        'payload_json' => 'array',
        'occurred_at' => 'datetime',
    ];
}
