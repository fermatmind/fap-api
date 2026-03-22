<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportArtifactPosture extends Model
{
    protected $fillable = [
        'attempt_id',
        'slot_code',
        'current_version_id',
        'active_job_id',
        'render_state',
        'delivery_state',
        'access_state',
        'integrity_state',
        'attention_state',
        'blocked_reason_code',
        'payload_json',
        'projection_fresh_at',
    ];

    protected $casts = [
        'current_version_id' => 'integer',
        'active_job_id' => 'integer',
        'payload_json' => 'array',
        'projection_fresh_at' => 'datetime',
    ];
}
