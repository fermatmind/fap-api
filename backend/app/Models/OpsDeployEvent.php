<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpsDeployEvent extends Model
{
    protected $table = 'ops_deploy_events';

    protected $fillable = [
        'env',
        'revision',
        'status',
        'actor',
        'meta_json',
        'occurred_at',
    ];

    protected $casts = [
        'meta_json' => 'array',
        'occurred_at' => 'datetime',
    ];
}
