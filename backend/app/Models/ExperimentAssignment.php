<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExperimentAssignment extends Model
{
    protected $fillable = [
        'org_id',
        'anon_id',
        'user_id',
        'experiment_key',
        'variant',
        'assigned_at',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'user_id' => 'integer',
        'assigned_at' => 'datetime',
    ];
}
