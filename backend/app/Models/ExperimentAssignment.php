<?php

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Model;

class ExperimentAssignment extends Model
{
    use HasOrgScope;

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

    public static function allowOrgZeroContext(): bool
    {
        return true;
    }
}
