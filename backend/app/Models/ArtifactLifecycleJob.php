<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArtifactLifecycleJob extends Model
{
    protected $table = 'artifact_lifecycle_jobs';

    protected $fillable = [
        'attempt_id',
        'artifact_slot_id',
        'job_type',
        'state',
        'reason_code',
        'blocked_reason_code',
        'idempotency_key',
        'request_payload_json',
        'result_payload_json',
        'attempt_count',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'artifact_slot_id' => 'integer',
        'attempt_count' => 'integer',
        'request_payload_json' => 'array',
        'result_payload_json' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
