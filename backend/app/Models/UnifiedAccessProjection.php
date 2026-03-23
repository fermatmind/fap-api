<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UnifiedAccessProjection extends Model
{
    protected $table = 'unified_access_projections';

    protected $fillable = [
        'attempt_id',
        'access_state',
        'report_state',
        'pdf_state',
        'reason_code',
        'projection_version',
        'actions_json',
        'payload_json',
        'produced_at',
        'refreshed_at',
    ];

    protected $casts = [
        'projection_version' => 'integer',
        'actions_json' => 'array',
        'payload_json' => 'array',
        'produced_at' => 'datetime',
        'refreshed_at' => 'datetime',
    ];
}
