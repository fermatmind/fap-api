<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Event extends Model
{
    use HasUuids;

    protected $table = 'events';

    // UUID 主键
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'event_code',
        'anon_id',
        'attempt_id',
        'occurred_at',
        'meta_json',
    ];

    protected $casts = [
        'meta_json' => 'array',
        'occurred_at' => 'datetime',
    ];
}