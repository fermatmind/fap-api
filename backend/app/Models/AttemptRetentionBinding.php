<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttemptRetentionBinding extends Model
{
    protected $table = 'attempt_retention_bindings';

    protected $fillable = [
        'attempt_id',
        'retention_policy_id',
        'bound_by',
        'bound_at',
    ];

    protected $casts = [
        'retention_policy_id' => 'integer',
        'bound_at' => 'datetime',
    ];
}
