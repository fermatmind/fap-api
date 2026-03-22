<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportArtifactSlot extends Model
{
    protected $fillable = [
        'attempt_id',
        'slot_code',
        'required_by_product',
        'current_version_id',
        'render_state',
        'delivery_state',
        'access_state',
        'integrity_state',
        'last_error_code',
        'last_materialized_at',
        'last_verified_at',
    ];

    protected $casts = [
        'required_by_product' => 'boolean',
        'current_version_id' => 'integer',
        'last_materialized_at' => 'datetime',
        'last_verified_at' => 'datetime',
    ];
}
